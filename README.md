# WaaS Host, Client & Payments Plugins

WordPress plugins for selling websites as a service: **WaaS Host** runs on the storefront and creates, suspends and restores each customer's website through the WaaS provisioning API; **WaaS Client** runs on the template and on every customer website (one-click login, plan roles); **WaaS Payments** adds card payments to FluentCart.

They are the WordPress side of [AnyPress](https://anypress.io) and are open source (GPL-3.0): the [provisioning API](https://github.com/hamchowderr/waas-provisioning-api) builds and runs the stores, and the [dashboard](https://github.com/hamchowderr/waas-dashboard) manages them. To run the whole platform yourself, see the API's [self-hosting guide](https://github.com/hamchowderr/waas-provisioning-api/blob/main/docs/self-hosting.md).

## Features

**WaaS Host** (storefront):

- Sells websites as subscription products on FluentCart (recommended) or WooCommerce with a subscriptions plugin (WP Swings' free Subscriptions for WooCommerce, or WooCommerce Subscriptions).
- Asks for a **website name** at checkout, checks it's free, and names the store `<website name>.<website domain>` (e.g. `shop.example.com`).
- Creates the website when the subscription starts; suspends, backs up and deletes it when the subscription ends (after the paid period, then a grace period); resumes or restores it if the customer comes back.
- **My website** in FluentCart's customer portal (the subscription page on WooCommerce): status, one-click login, the customer's own domain, and sending the store's email from their own domain.
- Plan roles and snapshot groups per product.
- Updates itself through the provisioning API, which serves signed releases.

**WaaS Client** (in every store): one-click login from the storefront, and plugin access by plan role (`roles.json`).

**WaaS Payments**: Authorize.Net card payments for FluentCart, including subscriptions charged on renewal.

## Getting started

You need a WordPress site to sell from (the **storefront**: WordPress 6.4 or newer, PHP 8.1 or newer) and a running provisioning API (to run one, see the [self-hosting guide](https://github.com/hamchowderr/waas-provisioning-api/blob/main/docs/self-hosting.md)). The API's operator gives you an **API key** and **API secret** for your storefront.

1. **Install a cart.** FluentCart 1.7 or newer (recommended), or WooCommerce with WP Swings' free Subscriptions for WooCommerce or the paid WooCommerce Subscriptions.
2. **Install WaaS Host** on the storefront (and **WaaS Payments** if you want Authorize.Net card payments on FluentCart). Build the zips with Composer installed: `scripts/release/build.sh waas-host <version> dist`, and `scripts/release/build.sh waas-payments <version> dist` for WaaS Payments. Upload them under **Plugins → Add New → Upload Plugin**.
3. **Add a secret to `wp-config.php`:** `define('WAAS_HOST_KEY_ENCRYPTION_KEY', '…');` with a long random string (`openssl rand -base64 48`). Keep it stable ([Storefront secrets](#storefront-secrets)).
4. **Connect to the API.** In **WaaS → Settings**, on the **Connection** tab, enter the **API URL**, **API key** and **API secret**. On the **Websites** tab, set **Website domain** (the domain customers' websites are named under, e.g. `example.com`).
5. **Make a product.** Create a subscription product and turn on WaaS for it, choosing the plan role and snapshot group ([Storefront cart](#storefront-cart) has the steps for each cart).
6. **Run cron every minute.** Websites are created in the background; on a quiet site WordPress's own cron waits for the next visitor. Define `DISABLE_WP_CRON` and run `wp action-scheduler run` and `wp cron event run --due-now` every minute from a real cron job.
7. **Test it.** Buy the product: checkout asks for a website name, and a few minutes after payment the customer finds the website, with a one-click login, under **My website**.

**WaaS Client** is not installed on the storefront. It goes into the template the customers' websites are made from, and comes with every website. The provisioning API's [`templates/starter-fluentcart`](https://github.com/hamchowderr/waas-provisioning-api/tree/main/templates/starter-fluentcart) builds a starter template with it.

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

The Host plugin talks to the provisioning API at the **API URL** on the **Connection** tab of **WaaS → Settings**, or the `WAAS_API_BASE_URL` constant in `wp-config.php` (the constant wins). Without either, the plugin has no API and sells nothing. (`WPCS_API_BASE_URL`, `WPCS_API_KEY` and the other `WPCS_` constants from before the rename still work.) The `waas_settings_api_base_url` filter can change it.

```php
define('WAAS_API_BASE_URL', 'https://api.example.com');
// For testing without an API, the contract's public Postman mock:
// define('WAAS_API_BASE_URL', 'https://2b5454f2-760b-4b86-8589-344ffae396d4.mock.pstmn.io');
```

The **API key** and **API secret** go on the same tab. **Website domain**, on the **Websites** tab, is the domain customers' websites are named under.

## Storefront secrets

Each store's one-click-login private key is stored encrypted on its subscription. The encryption key comes from `wp-config.php` on the storefront:

```php
// A long random string, e.g. the output of: openssl rand -base64 48
define('WAAS_HOST_KEY_ENCRYPTION_KEY', '...');
```

Without the constant, WordPress's auth salt is used instead. Either way, keep it stable: changing it makes the stored keys unreadable and one-click login stops working for existing stores. Keys saved by older versions in plain text are encrypted the first time they're used. The key's meta name, `_waas_tenant_private_key`, starts with an underscore so WooCommerce doesn't show it in the Custom Fields box or the REST API; keys under the old `WPCS_TENANT_PRIVATE_KEY_META` name move there when waas-host updates (or on first use), and WooCommerce Subscriptions renewal orders don't get a copy.

## WaaS Payments

`packages/waas-payments` adds Authorize.Net card payments to FluentCart, including subscriptions charged automatically on renewal. Subscriptions need **FluentCart > Settings > Subscriptions: Store managed** with **Automatic charge** on. Setup: [packages/waas-payments/README.md](packages/waas-payments/README.md).

## Updates

WaaS Host and WaaS Payments update from the provisioning API they're connected to, never from wordpress.org. Every release is signed, and a plugin only installs a release signed with a key built into it (`Updater::PUBLIC_KEYS` in `packages/waas-updater`). The key built in today is AnyPress's release key. **If you run your own API**, either build the plugins with your own public key in that list and sign your releases with its private half, or set `WAAS_UPDATE_CHANNEL` to `off` and install new zips by hand. In `wp-config.php`, `WAAS_UPDATE_CHANNEL` picks `stable` (default), `beta` or `off`, and `WAAS_AUTO_UPDATE` set to `patch` installs bug-fix releases automatically.

WaaS Client never updates by itself on customer websites: it comes with the template version, and a website gets a newer one by moving to a newer version.

To build a zip locally: `scripts/release/build.sh waas-host <version> dist`. How this repo publishes signed releases (tags, signing, the release bucket): [docs/releases.md](docs/releases.md).

## Development

The rest of this page is for working on the plugins themselves.

### Local development

Each plugin is a Composer package under `packages/` (`waas-host`, `waas-client`, `waas-payments`, and the shared `waas-updater`). Run `composer install` in the one you work on. The quickest way to see the Host plugin running is the test environment below.

### Test environment

On Linux with Docker, `scripts/test-env.sh up` starts a throwaway WordPress (MariaDB + WordPress in Docker) at http://127.0.0.1 with this checkout's Host plugin, FluentCart, WooCommerce and Subscriptions for WooCommerce active. Log in as admin / admin. `scripts/test-env.sh wp ...` runs WP-CLI; `scripts/test-env.sh down` removes it.

### Tests

The plugins (and `packages/waas-updater`) have PHPUnit unit tests that run without WordPress: [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) stubs the WordPress functions and outside calls are mocked. They need PHP 8.1 or newer.

```shell
cd packages/waas-host   # or packages/waas-client
composer install
composer test
```

`waas-host` also has contract tests (`tests/Contract`, group `mock`) that run the plugin's real API client against the provisioning API: by default the public Postman mock, or the server in `WAAS_TEST_API_BASE_URL`, such as the local API. They need network access; run `vendor/bin/phpunit --exclude-group mock` to skip them.

Unit tests live in each package's `tests/Unit`. The `Tests` GitHub Actions workflow runs the unit tests of all four packages (host, client, payments, updater) on every pull request and on pushes to `main`; the contract tests run there too, in a separate job that reports but doesn't block, since they depend on the public mock. Release builds strip `tests/` and `phpunit.xml.dist`.

### Names kept from WPCS

The plugins came from WPCS's waas-wpcs. Their code, admin screens and hooks are named WaaS now (`waas_*` actions and filters). Names stored on sites or used between the plugins and the API keep their WPCS spelling, because changing them would cut existing stores off:

- option names (`wpcs_credentials_*`, `wpcs_host_settings_*`, `wpcs_notification_settings_*`), order and subscription meta (`WPCS_TENANT_STATE` and friends), and scheduled-event hooks (`wpcs_run_store_jobs`, `wpcs_suspend_at_period_end`, …);
- the update slugs and `Update URI` (`wpcs-waas-host`, `wpcs-waas-client`), which the provisioning API and the release bucket use;
- the constants and environment variables the provisioning API sets on each store (`WPCS_IS_TENANT`, `WPCS_PUBLIC_KEY`, `WPCS_TENANT_ROLES`, `WPCS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS`);
- the `wpcs_choose_website_name` link parameters, which are in emails customers already have.

## License

GPL-3.0-or-later ([LICENCE](LICENCE)). WaaS Payments is GPL-2.0-or-later.
