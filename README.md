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

The Host plugin runs on **FluentCart** (1.7 or newer, subscriptions built in) or on **WooCommerce** with WooCommerce Subscriptions or Subscriptions for WooCommerce. When both are active, FluentCart is used; the `wpcs_commerce_adapter` filter can pick another.

On FluentCart:

1. Make a subscription product.
2. On the product, open **Integrations**, add **WaaS Provisioning**, and choose the role and snapshot group.
   - The default triggers create the website when the subscription is activated.
   - They remove it when it's canceled or reaches its end of term, and on refund or expired access.
3. Checkout asks for a **Website name** whenever the cart holds such a product, and checks that the name is free.
4. Customers find their websites under **Website** in the customer portal: status, one-click login and their own domain. Each subscription there also links to them.

On WooCommerce:

1. Use WP Swings' free **Subscriptions for WooCommerce** (the default) or the paid **WooCommerce Subscriptions**. When both are active, Subscriptions for WooCommerce is used; the `wpcs_woocommerce_subscription_plugin` filter can pick the other.
2. Make a subscription product and tick the WaaS product option on it, with its role and snapshot group.
3. Checkout asks for a **Website name** when the cart holds such a product, in block checkout and classic checkout alike, and checks that the name is free.
4. The website is created when the subscription becomes active. Going on hold fires `wpcs_subscription_suspended`, becoming active again fires `wpcs_subscription_reactivated`, and cancellation or expiry fires `wpcs_subscription_expired`.
5. Customers see the website's status, one-click login and domain form on the subscription's page in My Account.

Store data is kept with WooCommerce's order API, so it works with High-Performance Order Storage on (with or without sync) and off.

FluentCart creates the website in the background, through Action Scheduler, so it appears a few minutes after payment.

## API base URL

The Host plugin talks to the provisioning API at the URL in **Settings → API Base URL**, or the `WPCS_API_BASE_URL` constant in `wp-config.php` (the constant wins). Without either, it falls back to the old WPCS host for the selected region. The `wpcs_settings_api_base_url` filter can change it.

```php
define('WPCS_API_BASE_URL', 'https://2b5454f2-760b-4b86-8589-344ffae396d4.mock.pstmn.io'); // Postman mock
```

## Storefront secrets

Each store's one-click-login private key is stored encrypted on its subscription. The encryption key comes from `wp-config.php` on the storefront:

```php
// A long random string, e.g. the output of: openssl rand -base64 48
define('WAAS_HOST_KEY_ENCRYPTION_KEY', '...');
```

Without the constant, WordPress's auth salt is used instead. Either way, keep it stable: changing it makes the stored keys unreadable and one-click login stops working for existing stores. Keys saved by older versions in plain text are encrypted the first time they're used.

## Tests

Both plugins have PHPUnit unit tests that run without WordPress: [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) stubs the WordPress functions and outside calls are mocked. They need PHP 8.1 or newer.

```shell
cd packages/waas-host   # or packages/waas-client
composer install
composer test
```

`waas-host` also has contract tests (`tests/Contract`, group `mock`) that run the plugin's real API client against the provisioning API: by default the public Postman mock, or the server in `WAAS_TEST_API_BASE_URL`, such as the local API. They need network access; run `vendor/bin/phpunit --exclude-group mock` to skip them.

Unit tests live in each package's `tests/Unit`. The `Tests` GitHub Actions workflow runs both packages' unit tests on every pull request and on pushes to `main`; the contract tests run there too, in a separate job that reports but doesn't block, since they depend on the public mock. Release builds strip `tests/` and `phpunit.xml.dist`.

## Building

Run the following command to build the plugins

```shell
bash scripts/build.sh
```
