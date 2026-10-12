# WaaS Host & Client Plugins

Two plugins used to create a communication between a host and multiple tenants in a multi-tenant WordPress setup.

## Features

- Tenant Creation
- Subscription Management
- Role-based Plugins Activation
- Adding new Add-ons
- Changing Domains
- Root-Domain for created tenants
- Tenants domains
- Single-Sign On

## Work tracking (beads)

Work is tracked in [beads](https://github.com/steveyegge/beads) (prefix `waas`). The issue database syncs through a private DoltHub database, [`chowderr/waas`](https://www.dolthub.com/repositories/chowderr/waas): `bd dolt push` after changes, `bd dolt pull` to get others'. `.beads/issues.jsonl` is a local export (kept fresh by `export.auto`) and is not committed.

On a fresh clone, with DoltHub credentials in `~/.dolt/creds` (`dolt creds import`, or the JWK the cloud environment writes):

```shell
npm install -g @beads/bd
bd init --prefix waas --skip-agents --skip-hooks --remote chowderr/waas
bd dolt pull
bd ready
```

## Local Development

### Required tools

- Docker and Docker-Compose
- Composer
- `fswarch` & `rsync`

### Steps

- `git clone` the project
- Run `composer install` inside `src` directory
- Run to create dist directories  `mkdir dist && mkdir dist/host && mkdir dist/client`
- Run `docker-compose up`
- Run the following command to sync files between src and dist

## Test environment

`scripts/test-env.sh up` starts a throwaway WordPress (MariaDB + WordPress in Docker) at http://127.0.0.1 with this checkout's Host plugin, FluentCart, WooCommerce and Subscriptions for WooCommerce active. Log in as admin / admin. `scripts/test-env.sh wp ...` runs WP-CLI; `scripts/test-env.sh down` removes it. It works in Claude Code cloud sessions (it starts Docker and routes WordPress's outbound calls through the session proxy).

## Storefront cart

The Host plugin runs on **FluentCart** (1.7 or newer, subscriptions built in) or on **WooCommerce** with WooCommerce Subscriptions or Subscriptions for WooCommerce. When both are active, FluentCart is used; the `waas_commerce_adapter` filter can pick another.

On FluentCart:

1. Make a subscription product.
2. On the product, open **Integrations**, add **WaaS Provisioning**, and choose the role and snapshot group.
   - The default triggers create the website when the subscription is activated, and resume it when a subscription that expired is paid late (reactivated).
   - They take it offline (backed up, then deleted after the grace period in the settings) when the subscription is cancelled: at the end of the period the customer paid for, with an e-mail telling them the date and the portal showing "Active until" meanwhile; reactivating before then keeps it. Refunding the order that bought the subscription, expired access or the end of term takes it offline at once. Refunding a renewal order leaves the website up while the subscription stays active.
   - Feeds made before reactivation was a default get it added on the first load after the update, unless their triggers were changed by hand.
3. Checkout asks for a **Website name** whenever the cart holds such a product, and checks that the name is free.
4. Customers find their websites under **My website** in the customer portal, right after Dashboard: status, one-click login and their own domain. While a website is ready, the other portal pages show a notice with its login link, and each subscription links to it. The website-ready email links to this page; a customer who is logged out logs in first and lands there.

On WooCommerce:

1. Use WP Swings' free **Subscriptions for WooCommerce** (the default) or the paid **WooCommerce Subscriptions**. When both are active, Subscriptions for WooCommerce is used; the `waas_woocommerce_subscription_plugin` filter can pick the other.
2. Make a subscription product and tick the WaaS product option on it, with its role and snapshot group.
3. Checkout asks for a **Website name** when the cart holds such a product, in block checkout and classic checkout alike, and checks that the name is free.
4. The website is created when the subscription becomes active. Going on hold fires `waas_subscription_suspended`, becoming active again fires `waas_subscription_reactivated`, and cancellation or expiry fires `waas_subscription_expired`. On Subscriptions for WooCommerce, which cancels at once, a customer's or admin's cancel keeps the website until the next payment date (`waas_subscription_canceled_until`): the subscription page shows "Active until" and the cancellation e-mail goes out, as on FluentCart. Refunding or cancelling the order that bought the subscription takes the website offline at once. WooCommerce Subscriptions keeps the website through its own pending-cancel status too, without the "Active until" line or the e-mail.
5. Customers see the website's status, one-click login and domain form on the subscription's page in My Account.

Store data is kept with WooCommerce's order API, so it works with High-Performance Order Storage on (with or without sync) and off.

FluentCart creates the website in the background, through Action Scheduler, so it appears a few minutes after payment.

## API base URL

The Host plugin talks to the provisioning API at the URL in **Settings → API Base URL**, or the `WAAS_API_BASE_URL` constant in `wp-config.php` (the constant wins). Without either, the plugin has no API and sells nothing. (`WPCS_API_BASE_URL`, `WPCS_API_KEY` and the other `WPCS_` constants from before the rename still work.) The `waas_settings_api_base_url` filter can change it.

```php
define('WAAS_API_BASE_URL', 'https://2b5454f2-760b-4b86-8589-344ffae396d4.mock.pstmn.io'); // Postman mock
```

## Storefront secrets

Each store's one-click-login private key is stored encrypted on its subscription. The encryption key comes from `wp-config.php` on the storefront:

```php
// A long random string, e.g. the output of: openssl rand -base64 48
define('WAAS_HOST_KEY_ENCRYPTION_KEY', '...');
```

Without the constant, WordPress's auth salt is used instead. Either way, keep it stable: changing it makes the stored keys unreadable and one-click login stops working for existing stores. Keys saved by older versions in plain text are encrypted the first time they're used. The key's meta name, `_waas_tenant_private_key`, starts with an underscore so WooCommerce doesn't show it in the Custom Fields box or the REST API; keys under the old `WPCS_TENANT_PRIVATE_KEY_META` name move there when waas-host updates (or on first use), and WooCommerce Subscriptions renewal orders don't get a copy.

## Names kept from WPCS

The plugins came from WPCS's waas-wpcs. Their code, admin screens and hooks are named WaaS now (`waas_*` actions and filters). Names stored on sites or used between our systems keep their WPCS spelling, because changing them would cut existing stores off:

- option names (`wpcs_credentials_*`, `wpcs_host_settings_*`, `wpcs_notification_settings_*`), order and subscription meta (`WPCS_TENANT_STATE` and friends), and scheduled-event hooks (`wpcs_run_store_jobs`, `wpcs_suspend_at_period_end`, …);
- the update slugs and `Update URI` (`wpcs-waas-host`, `wpcs-waas-client`), which the provisioning API and the release bucket use;
- the constants and environment variables the provisioning API sets on each store (`WPCS_IS_TENANT`, `WPCS_PUBLIC_KEY`, `WPCS_TENANT_ROLES`, `WPCS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS`);
- the `wpcs_choose_website_name` link parameters, which are in emails customers already have.

## Tests

The plugins (and `packages/waas-updater`) have PHPUnit unit tests that run without WordPress: [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) stubs the WordPress functions and outside calls are mocked. They need PHP 8.1 or newer.

```shell
cd packages/waas-host   # or packages/waas-client
composer install
composer test
```

`waas-host` also has contract tests (`tests/Contract`, group `mock`) that run the plugin's real API client against the provisioning API: by default the public Postman mock, or the server in `WAAS_TEST_API_BASE_URL`, such as the local API. They need network access; run `vendor/bin/phpunit --exclude-group mock` to skip them.

Unit tests live in each package's `tests/Unit`. The `Tests` GitHub Actions workflow runs both packages' unit tests on every pull request and on pushes to `main`; the contract tests run there too, in a separate job that reports but doesn't block, since they depend on the public mock. Release builds strip `tests/` and `phpunit.xml.dist`.

## Releases and updates

Plugins update from our own channel only: the provisioning API and the `waas-releases` R2 bucket. Tag `waas-host-v2.3.0` (or `waas-payments-v…`, `waas-client-v…`; `-beta.N` for the beta channel) on `main` and the **Release plugin** workflow builds, signs and publishes it. Stores don't update waas-client themselves; it comes with their template version. See [docs/releases.md](docs/releases.md).

To build a zip locally: `scripts/release/build.sh waas-host 2.3.0 dist`.

### Card payments

`packages/waas-payments` adds Authorize.Net card payments to FluentCart, including subscriptions charged automatically on renewal. Subscriptions need **FluentCart > Settings > Subscriptions: Store managed** with **Automatic charge** on. See [packages/waas-payments/README.md](packages/waas-payments/README.md).
