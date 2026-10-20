# WaaS Host, Client & Payments Plugins

WordPress plugins for selling websites as a service: **WaaS Host** runs on the storefront and creates, suspends and restores each customer's website through the WaaS provisioning API; **WaaS Client** runs on the template and on every customer website (one-click login, plan roles); **WaaS Payments** adds card payments to FluentCart.

They are the WordPress side of [AnyPress](https://anypress.io) and are open source (GPL-3.0): the [provisioning API](https://github.com/hamchowderr/waas-provisioning-api) builds and runs the stores, and the [dashboard](https://github.com/hamchowderr/waas-dashboard) manages them. To run the whole platform yourself, see the API's [self-hosting guide](https://github.com/hamchowderr/waas-provisioning-api/blob/main/docs/self-hosting.md).

How the three plugins work and fit together, with diagrams: [docs/architecture](docs/architecture/README.md).

## Features

**WaaS Host** (storefront):

- Sells websites as subscription products on FluentCart (recommended) or WooCommerce with a subscriptions plugin (WP Swings' free Subscriptions for WooCommerce, or WooCommerce Subscriptions).
- Asks for a **website name** at checkout, checks it's free, and names the store `<website name>.<website domain>` (e.g. `shop.example.com`).
- Creates the website when the subscription starts; suspends, backs up and deletes it when the subscription ends (after the paid period, then a grace period); resumes or restores it if the customer comes back.
- **My website** in FluentCart's customer portal (the subscription page on WooCommerce): status, one-click login, the customer's own domain, and sending the store's email from their own domain. Where the customer's DNS host supports Domain Connect, a **Connect automatically** button adds the DNS records for them.
- Plan roles and snapshot groups per product.
- Updates itself through the provisioning API, which serves signed releases.

**WaaS Client** (in every store): one-click login from the storefront, plugin access by plan role (`roles.json`), and a site-editing API that lets the AnyPress connector change the store's pages, design, header, footer and menus for an AI agent.

**WaaS Payments**: Authorize.Net card payments for FluentCart, including subscriptions charged on renewal.

## Getting started

You need a WordPress site to sell from (the **storefront**: WordPress 6.4 or newer, PHP 8.1 or newer) and a running provisioning API (to run one, see the [self-hosting guide](https://github.com/hamchowderr/waas-provisioning-api/blob/main/docs/self-hosting.md)). The API's operator gives you an **API key** and **API secret** for your storefront.

1. **Install a cart.** FluentCart 1.7 or newer (recommended), or WooCommerce with WP Swings' free Subscriptions for WooCommerce or the paid WooCommerce Subscriptions.
2. **Install WaaS Host** on the storefront (and **WaaS Payments** if you want Authorize.Net card payments on FluentCart). Build the zips with Composer installed: `scripts/release/build.sh waas-host <version> dist`, and `scripts/release/build.sh waas-payments <version> dist` for WaaS Payments. Upload them under **Plugins → Add New → Upload Plugin**.
3. **Add a secret to `wp-config.php`:** `define('WAAS_HOST_KEY_ENCRYPTION_KEY', '…');` with a long random string (`openssl rand -base64 48`). Keep it stable ([Storefront secrets](#storefront-secrets)).
4. **Connect to the API.** In **WaaS → Settings**, on the **Connection** tab, enter the **API URL**, **API key** and **API secret**. Saving also registers the storefront for the API's webhooks ([Website status](#website-status)). On the **Websites** tab, set **Website domain** (the domain customers' websites are named under, e.g. `example.com`).
5. **Make a product.** Create a subscription product and turn on WaaS for it, choosing the plan role and snapshot group ([Storefront cart](#storefront-cart) shows where this is on each cart).
6. **Run cron every minute.** Websites are created in the background; on a quiet site WordPress's own cron waits for the next visitor. Define `DISABLE_WP_CRON` and run `wp action-scheduler run` and `wp cron event run --due-now` every minute from a real cron job.
7. **Test it.** Buy the product: checkout asks for a website name, and a few minutes after payment the customer finds the website, with a one-click login, under **My website**.

**WaaS Client** is not installed on the storefront. It goes into the template the customers' websites are made from, and comes with every website. The provisioning API's [`templates/starter-fluentcart`](https://github.com/hamchowderr/waas-provisioning-api/tree/main/templates/starter-fluentcart) builds a starter template with it.

## Storefront cart

The Host plugin works with **FluentCart** 1.7 or newer (recommended) or with **WooCommerce** plus a subscriptions plugin. If both carts are active it uses FluentCart; the `waas_commerce_adapter` filter can pick the other.

| | FluentCart | WooCommerce |
|---|---|---|
| Subscriptions | Built in | WP Swings' free **Subscriptions for WooCommerce** or the paid **WooCommerce Subscriptions**. If both are active, the free one is used (`waas_woocommerce_subscription_plugin` filter to change) |
| Turn WaaS on for a product | Make a subscription product, open **Integrations**, add **WaaS Provisioning**, choose the role and snapshot group | Make a subscription product, tick the WaaS product option, choose the role and snapshot group |
| Where the customer finds the website | **My website** in the customer portal: status, one-click login and their own domain. The website-ready email links there | The subscription's page in **My Account**: status, one-click login and domain form |

On both carts, checkout asks for a **Website name** whenever the cart holds a WaaS product (on WooCommerce in block and classic checkout alike) and checks that the name is free. The website is created in the background a few minutes after payment.

### When the subscription changes

"Offline" means the website is backed up, then deleted after the grace period set in the plugin's settings. Paying again before the deletion brings it back.

| What happens | The website |
|---|---|
| The subscription becomes active | Is created |
| The customer or an admin cancels | Stays up until the end of the period already paid for, then goes offline. The customer sees "Active until" and gets an email with the date. Reactivating before then keeps it |
| The order that bought the subscription is refunded | Goes offline at once |
| The subscription expires or reaches the end of its term | Goes offline at once |
| A renewal order is refunded | Stays up while the subscription is active |
| An expired subscription is paid late | Is resumed |
| The subscription is paused (FluentCart) or put on hold (WooCommerce) | Is suspended, not deleted, until the subscription is active again |

Notes:

- On paid WooCommerce Subscriptions, a cancel goes through its own pending-cancel status, which also keeps the website up, but without the "Active until" line or the email.
- On FluentCart, the WaaS integration's triggers decide the above. Integrations made with an older version get the "paid late" trigger added the first time they load, unless their triggers were changed by hand.
- On WooCommerce, website data is stored through WooCommerce's order API, so it works with High-Performance Order Storage on or off.
- Both carts fire the same actions, which developers can hook into: `waas_subscription_created`, `waas_subscription_suspended`, `waas_subscription_reactivated`, `waas_subscription_renewed`, `waas_subscription_expired` and `waas_subscription_canceled_until`.

## API base URL

The Host plugin talks to the provisioning API at the **API URL** on the **Connection** tab of **WaaS → Settings**, or the `WAAS_API_BASE_URL` constant in `wp-config.php` (the constant wins). Without either, the plugin has no API and sells nothing. (`WPCS_API_BASE_URL`, `WPCS_API_KEY` and the other `WPCS_` constants from before the rename still work.) The `waas_settings_api_base_url` filter can change it.

```php
define('WAAS_API_BASE_URL', 'https://api.example.com');
// For testing without an API, the contract's public Postman mock:
// define('WAAS_API_BASE_URL', 'https://2b5454f2-760b-4b86-8589-344ffae396d4.mock.pstmn.io');
```

The **API key** and **API secret** go on the same tab. **Website domain**, on the **Websites** tab, is the domain customers' websites are named under.

## Website status

The provisioning API tells the storefront when a new website is ready, so the customer gets the "website ready" email and their login button as soon as the website answers over HTTPS.

- **Registration.** Saving the **Connection** tab, when the API answers, registers the storefront's address `https://<storefront>/wp-json/waas-host/v1/webhook` with the API. The **Webhooks** section of that tab shows whether it is registered, and if not, why. **Register again** replaces the registration, for example after moving the site. With the API settings in `wp-config.php` there is nothing to save: the plugin registers itself within the hour, and does the same when the site's address or the API changes.
- **What the API reports.** A website that is ready or restored, a website whose domains changed, and a change that still failed after the API's retries. The last one shows as an admin notice until you dismiss it or the website becomes ready. Every request is signed with a secret that is stored encrypted with the key from [Storefront secrets](#storefront-secrets).
- **Checking as a backstop.** Websites being set up are still checked every minute. When the storefront isn't registered, they are checked every five seconds instead.
- **Local and test sites.** The API only calls `https://` addresses it can reach from the internet, so a storefront on `http://` or a private address isn't registered and checks every five seconds.

## Storefront secrets

Each store's one-click-login private key is stored encrypted on its subscription. The encryption key comes from `wp-config.php` on the storefront:

```php
// A long random string, e.g. the output of: openssl rand -base64 48
define('WAAS_HOST_KEY_ENCRYPTION_KEY', '...');
```

Without the constant, WordPress's auth salt is used instead. Either way, keep it stable: changing it makes the stored keys unreadable and one-click login stops working for existing stores. Keys saved by older versions in plain text are encrypted the first time they're used. The key's meta name, `_waas_tenant_private_key`, starts with an underscore so WooCommerce doesn't show it in the Custom Fields box or the REST API; keys under the old `WPCS_TENANT_PRIVATE_KEY_META` name move there when waas-host updates (or on first use), and WooCommerce Subscriptions renewal orders don't get a copy.

The webhook signing secret ([Website status](#website-status)) is encrypted with the same key. If it can no longer be read, the storefront registers again on its own.

WooCommerce products keep their WaaS settings through WooCommerce's product object, under `_waas_product_role` and `_waas_product_group_name`. Settings under the old `WPCS_PRODUCT_ROLE_META` and `WPCS_PRODUCT_GROUPNAME_META` names are still read, and move to the new names when waas-host updates.

## WaaS Payments

`packages/waas-payments` adds Authorize.Net card payments to FluentCart, including subscriptions charged automatically on renewal. Subscriptions need **FluentCart > Settings > Subscriptions: Store managed** with **Automatic charge** on. Setup: [packages/waas-payments/README.md](packages/waas-payments/README.md).

## Account API

WaaS Host has a REST API for the signed-in customer's websites (`waas-host/v1/account`): list them, sign in to one, change its address, set up its email sender, and choose a new name. Pages on the storefront call it with the login cookie; a front end on another domain signs the customer in with token sign-in (OAuth 2.0 with PKCE). How: [docs/account-api.md](docs/account-api.md).

## Site editing API

WaaS Client lets the AnyPress connector edit a store's design and content (`waas-client/v1/site`): pages and their sections, the theme's patterns, colours and fonts, the header and footer, menus, revisions and images. Only the AnyPress API can call it, with requests signed by the store's own site key. How: [docs/site-editing-api.md](docs/site-editing-api.md).

## Updates

WaaS Host and WaaS Payments update from the provisioning API they're connected to, never from wordpress.org. Every release is signed, and a plugin only installs a release signed with a key built into it (`Updater::PUBLIC_KEYS` in `packages/waas-updater`). The key built in today is AnyPress's release key. **If you run your own API**, either build the plugins with your own public key in that list and sign your releases with its private half, or set `WAAS_UPDATE_CHANNEL` to `off` and install new zips by hand. In `wp-config.php`, `WAAS_UPDATE_CHANNEL` picks `stable` (default), `beta` or `off`, and `WAAS_AUTO_UPDATE` set to `patch` installs bug-fix releases automatically.

WaaS Client never updates by itself on customer websites: it comes with the template version, and a website gets a newer one by moving to a newer version.

To build a zip locally: `scripts/release/build.sh waas-host <version> dist`. How this repo publishes signed releases (tags, signing, the release bucket): [docs/releases.md](docs/releases.md).

## Development

The rest of this page is for working on the plugins themselves.

### Local development

Each plugin is a Composer package under `packages/` (`waas-host`, `waas-client`, `waas-payments`, and the shared `waas-updater`). Run `composer install` in the one you work on. The quickest way to see the Host plugin running is the test environment below.

### Test environment

On Linux with Docker, `scripts/test-env.sh up` starts a throwaway WordPress (MariaDB + WordPress in Docker) at http://127.0.0.1 with this checkout's Host and Payments plugins, FluentCart, WooCommerce and Subscriptions for WooCommerce active. Log in as admin / admin. `scripts/test-env.sh wp ...` runs WP-CLI; `scripts/test-env.sh down` removes it.

Without Docker, `scripts/playground` runs WaaS Client on [WordPress Playground](https://developer.wordpress.org/playground/) (WordPress and PHP inside Node) with the Ollie theme and the store template's starter pages, and checks the site-editing API end to end with a throwaway site key. It needs Node 24.18 or newer and `composer install` in `packages/waas-client`:

```shell
cd scripts/playground
npm ci
npm run smoke    # the checks; exits 1 on a failure
npm run serve    # the same site left running at http://127.0.0.1:9400, signed in as admin
```

Playground keeps the database in SQLite rather than MariaDB, so it checks the plugin's PHP and WordPress behaviour; database-specific SQL still needs a real store or `test-env.sh`. Pull requests also get a "Preview in WordPress Playground" button: a throwaway storefront with that PR's WaaS Host, FluentCart and Ollie (`pr-preview-build.yml` and `pr-preview-publish.yml`).

### Tests

The plugins (and `packages/waas-updater`) have PHPUnit unit tests that run without WordPress: [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) stubs the WordPress functions and outside calls are mocked. They need PHP 8.1 or newer.

```shell
cd packages/waas-host   # or any other package
composer install
composer test
```

`waas-host` also has contract tests (`tests/Contract`, group `mock`) that run the plugin's real API client against the provisioning API: by default the public Postman mock, or the server in `WAAS_TEST_API_BASE_URL`, such as the local API. They need network access; run `vendor/bin/phpunit --exclude-group mock` to skip them.

Unit tests live in each package's `tests/Unit`. The `Tests` GitHub Actions workflow runs the unit tests of all four packages (host, client, payments, updater) on every pull request and on pushes to `main`, plus the Playground smoke test of WaaS Client; the contract tests run there too, in a separate job that reports but doesn't block, since they depend on the public mock. Release builds strip `tests/` and `phpunit.xml.dist`.

### Old `wpcs` names

The plugins' code, admin screens and hooks are named WaaS (`waas_*` actions and filters). Some names stored on sites or used between the plugins and the API still start with `wpcs`, from where the plugins started, because changing them would cut existing stores off:

- option names (`wpcs_credentials_*`, `wpcs_host_settings_*`, `wpcs_notification_settings_*`), order and subscription meta (`WPCS_TENANT_STATE` and friends), and scheduled-event hooks (`wpcs_run_store_jobs`, `wpcs_suspend_at_period_end`, …);
- the update slugs and `Update URI` (`wpcs-waas-host`, `wpcs-waas-client`), which the provisioning API and the release bucket use;
- the constants and environment variables the provisioning API sets on each store (`WPCS_IS_TENANT`, `WPCS_PUBLIC_KEY`, `WPCS_TENANT_ROLES`, `WPCS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS`);
- the `wpcs_choose_website_name` link parameters, which are in emails customers already have.

## License

GPL-3.0-or-later ([LICENCE](LICENCE)). WaaS Payments is GPL-2.0-or-later, as its own header says; that licence lets it be used under GPL-3.0 too, so the whole repository can be distributed under GPL-3.0.
