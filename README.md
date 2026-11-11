# AnyPress Host, Client & Payments Plugins

WordPress plugins for selling websites as a service: **AnyPress Host** runs on the storefront and creates, suspends and restores each customer's website through the AnyPress API; **AnyPress Client** runs on the template and on every customer website (one-click login, plan roles); **AnyPress Payments** adds card payments to FluentCart.

They are the WordPress side of [AnyPress](https://anypress.io) and are open source (GPL-3.0): the [AnyPress API](https://github.com/hamchowderr/waas-provisioning-api) builds and runs the stores, and the [dashboard](https://github.com/hamchowderr/waas-dashboard) manages them. To run the whole platform yourself, see the API's [self-hosting guide](https://github.com/hamchowderr/waas-provisioning-api/blob/main/docs/self-hosting.md).

How the three plugins work and fit together, with diagrams: [docs/architecture](docs/architecture/README.md).

## Features

**AnyPress Host** (storefront):

- Sells websites as subscription products on FluentCart (recommended) or WooCommerce with a subscriptions plugin (WP Swings' free Subscriptions for WooCommerce, or WooCommerce Subscriptions).
- Asks for a **website name** at checkout, checks it's free, and names the store `<website name>.<website domain>` (e.g. `shop.example.com`).
- Creates the website when the subscription starts; suspends, backs up and deletes it when the subscription ends (after the paid period, then a grace period); resumes or restores it if the customer comes back.
- **My website** in FluentCart's customer portal (the subscription page on WooCommerce): status, one-click login, the customer's own domain, and sending the store's email from their own domain. Where the customer's DNS host supports Domain Connect, a **Connect automatically** button adds the DNS records for them.
- Plan roles and snapshot groups per product.
- **Domains** (FluentCart): customers buy a new domain for their website at your checkout, in your brand and at your price; it is registered in their name, set up for the website and renewed with its yearly subscription ([Domains](#domains)).
- **Email packs** (FluentCart): one-time products that add emails to one of the customer's websites, bought from a **Buy more emails** link on each website ([Email packs](#email-packs)).
- Updates itself through the AnyPress API, which serves signed releases.

**AnyPress Client** (in every website):

- One-click login from the storefront and from the dashboard.
- Plugin access by plan role (`roles.json`).
- Site tools that let the AnyPress connector, the website assistant and AI apps change the website's pages, design, header, footer and menus, as REST routes, WordPress abilities and the website's own MCP server ([Site Editing API](docs/site-editing-api.md)).
- Routes for the phone receptionist: open times, bookings and callers.
- The website assistant panel beside the block editor.
- Switches that move a moved-in website's forms, WooCommerce shop, email lists, help desk, affiliates and community onto the Fluent plugins.
- On a reseller's websites, the reseller's brand in its name (for example "KnowBS Client").

**AnyPress Payments**: Authorize.Net card payments for FluentCart, including subscriptions charged on renewal.

## Getting Started

You need a WordPress site to sell from (the **storefront**: WordPress 6.4 or newer, PHP 8.1 or newer) and a running AnyPress API (to run one, see the [self-hosting guide](https://github.com/hamchowderr/waas-provisioning-api/blob/main/docs/self-hosting.md)). The API's operator gives you an **API key** and **API secret** for your storefront.

1. **Install a cart.** FluentCart 1.7 or newer (recommended), or WooCommerce with WP Swings' free Subscriptions for WooCommerce or the paid WooCommerce Subscriptions.
2. **Install AnyPress Host** on the storefront (and **AnyPress Payments** if you want Authorize.Net card payments on FluentCart). Build the zips with Composer installed: `scripts/release/build.sh anypress-host <version> dist`, and `scripts/release/build.sh anypress-payments <version> dist` for AnyPress Payments. Upload them under **Plugins → Add New → Upload Plugin**.
3. **Add a secret to `wp-config.php`:** `define('ANYPRESS_HOST_KEY_ENCRYPTION_KEY', '…');` with a long random string (`openssl rand -base64 48`). Keep it stable ([Storefront secrets](#storefront-secrets)).
4. **Connect to the API.** In **AnyPress → Settings**, on the **Connection** tab, enter the **API URL**, **API key** and **API secret**. Saving also registers the storefront for the API's webhooks ([Website status](#website-status)). On the **Websites** tab, set **Website domain** (the domain customers' websites are named under, e.g. `example.com`).
5. **Make a product.** Create a subscription product and turn on AnyPress for it, choosing the plan role and snapshot group ([Storefront cart](#storefront-cart) shows where this is on each cart).
6. **Run cron every minute.** Websites are created in the background; on a quiet site WordPress's own cron waits for the next visitor. Define `DISABLE_WP_CRON` and run `wp action-scheduler run` and `wp cron event run --due-now` every minute from a real cron job. AnyPress Host starts each new website's build as soon as checkout ends (a signed request the storefront sends itself), so cron is the fallback; the storefront must be able to reach its own address over HTTPS.
7. **Test it.** Buy the product: checkout asks for a website name, and a few minutes after payment the customer finds the website, with a one-click login, under **My website**.

**AnyPress Client** is not installed on the storefront. It goes into the template the customers' websites are made from, and comes with every website. The AnyPress API's [`templates/starter-fluentcart`](https://github.com/hamchowderr/waas-provisioning-api/tree/main/templates/starter-fluentcart) builds a starter template with it.

## Storefront Cart

The Host plugin works with **FluentCart** 1.7 or newer (recommended) or with **WooCommerce** plus a subscriptions plugin. If both carts are active it uses FluentCart; the `anypress_commerce_adapter` filter can pick the other.

| | FluentCart | WooCommerce |
|---|---|---|
| Subscriptions | Built in | WP Swings' free **Subscriptions for WooCommerce** or the paid **WooCommerce Subscriptions**. If both are active, the free one is used (`anypress_woocommerce_subscription_plugin` filter to change) |
| Turn AnyPress on for a product | Make a subscription product, open **Integrations**, add **AnyPress Provisioning**, choose the role and snapshot group | Make a subscription product, tick the AnyPress product option, choose the role and snapshot group |
| Where the customer finds the website | **My website** in the customer portal: status, one-click login and their own domain. The website-ready email links there | The subscription's page in **My Account**: status, one-click login and domain form |

On both carts, checkout asks for a **Website name** whenever the cart holds an AnyPress product (on WooCommerce in block and classic checkout alike) and checks that the name is free. The website is created in the background a few minutes after payment.

### When the Subscription Changes

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
- On FluentCart, the AnyPress integration's triggers decide the above. Integrations made with an older version get the "paid late" trigger added the first time they load, unless their triggers were changed by hand.
- On WooCommerce, website data is stored through WooCommerce's order API, so it works with High-Performance Order Storage on or off.
- Both carts fire the same actions, which developers can hook into: `anypress_subscription_created`, `anypress_subscription_suspended`, `anypress_subscription_reactivated`, `anypress_subscription_renewed`, `anypress_subscription_expired` and `anypress_subscription_canceled_until`.

### Email Packs

On FluentCart, a website can buy more email than its plan sends each month. Pack emails never expire: the website uses them once its plan's monthly emails run out, and they let its paused FluentCRM campaigns send again.

**Set up a pack.** Make a one-time (not subscription) product, open **Integrations**, add **AnyPress Provisioning**, set **Product type** to **Email pack** and **Emails per pack** to the number of emails one pack adds (1 to 1,000,000). Role, snapshot group, size and triggers don't apply to a pack. That's all: there is no setting to turn on, and the links appear once a published pack product exists. Several pack products (e.g. 5,000 and 50,000 emails) each get their own link. Integrations made before **Product type** existed are website plans, and a pack is never treated as one: it gets no website name field at checkout, creates or suspends no website, and doesn't count toward or get the volume discount.

**How a customer buys one.** Each website under **My website** that has a store gets a **Buy more emails** link: FluentCart's instant checkout for the pack, carrying the website's subscription id, e.g. `https://shop.example.com/?fluent-cart=instant_checkout&item_id=<pack variation id>&quantity=1&anypress_website=<subscription id>`. FluentCart passes `anypress_website` on to the checkout page, where it is kept in the cart, then on the order (`_anypress_email_pack_website`). The customer can change the quantity at checkout.

**When the order is paid**, each pack line adds *emails per pack × quantity* to the website through the AnyPress API (`POST /v1/tenants/email-packs`), with the reference `fluentcart-order-<order id>-<line id>`, so the API adds a line once however often it is sent. The order gets a note for each line with the emails added and the website's total bought in packs.

- The website must belong to the order's customer. If it doesn't, or it has been deleted, nothing is added and the order gets a note.
- An order without a website (a pack bought from the shop page) goes to the customer's only live website. With none or several, nothing is added and the order gets a note for the shop owner to add the emails by hand.
- If the API call fails, the order gets a note, the error is logged, and it is retried after 1, 5, 15, 30 and 60 minutes. After that the order is listed in an admin notice with a **Retry** link, and `anypress_email_packs_failed` fires. Retrying is safe: lines already added are skipped and the reference stops a line being added twice.

### Domains

On FluentCart, customers can buy a new domain for a website without leaving your shop. Turn it on under **AnyPress → Settings → Domains** (**Sell domains**), and set what to add to each year's price. It needs FluentCart and a payment method that takes subscriptions. Your storefront must be allowed to sell domains on the AnyPress API: a reseller's storefronts are, and pay for each domain on the reseller's monthly bill.

**How a customer buys one.** Each live website under **My website** gets a **Get a new domain** panel. The customer searches for a name, sees what each one costs a year (the AnyPress price plus your amount; a first year that costs more is added as a first-year fee), picks one and fills in who it belongs to. **Continue to checkout** checks the name again and opens FluentCart's checkout for a yearly subscription called *Domain &lt;name&gt;*.

**When the order is paid**, the AnyPress API registers the domain in the customer's name with WHOIS privacy on, points it at the website and makes it the website's address (`POST /v1/tenants/domains/buy`, reference `fluentcart-order-<order id>-domain`). **Each renewal** renews it for another year (`POST /v1/tenants/domains/bought/renew`, reference `fluentcart-renewal-<renewal order id>`). A cancelled subscription lets the domain expire at the end of its year.

- Failed calls are retried after 1, 5, 15, 30 and 60 minutes, then listed in an admin notice with a **Retry** link, and `anypress_domain_failed` fires.
- If the name was taken before the order was paid, the order gets a note to refund it, and nothing is charged for the domain.
- `anypress_domain_price` filters the yearly price shown and charged (cents, from the AnyPress price in cents).

## API Base URL

The Host plugin talks to the AnyPress API at the **API URL** on the **Connection** tab of **AnyPress → Settings**, or the `ANYPRESS_API_BASE_URL` constant in `wp-config.php` (the constant wins). Without either, the plugin has no API and sells nothing. (The same constants under the old platform's prefix, from before the rename, still work.) The `anypress_settings_api_base_url` filter can change it.

```php
define('ANYPRESS_API_BASE_URL', 'https://api.example.com');
// For testing without an API, the contract's public Postman mock:
// define('ANYPRESS_API_BASE_URL', 'https://2b5454f2-760b-4b86-8589-344ffae396d4.mock.pstmn.io');
```

The **API key** and **API secret** go on the same tab. **Website domain**, on the **Websites** tab, is the domain customers' websites are named under.

## Website Status

The AnyPress API tells the storefront when a new website is ready, so the customer gets the "website ready" email and their login button as soon as the website answers over HTTPS.

- **Registration.** Saving the **Connection** tab, when the API answers, registers the storefront's address `https://<storefront>/wp-json/anypress-host/v1/webhook` with the API. The **Webhooks** section of that tab shows whether it is registered, and if not, why. **Register again** replaces the registration, for example after moving the site. With the API settings in `wp-config.php` there is nothing to save: the plugin registers itself within the hour, and does the same when the site's address or the API changes.
- **What the API reports.** A website that is ready or restored, a website whose domains changed, and a change that still failed after the API's retries. The last one shows as an admin notice until you dismiss it or the website becomes ready. Every request is signed with a secret that is stored encrypted with the key from [Storefront secrets](#storefront-secrets).
- **Checking as a backstop.** Websites being set up are still checked every minute. When the storefront isn't registered, they are checked every five seconds instead.
- **Local and test sites.** The API only calls `https://` addresses it can reach from the internet, so a storefront on `http://` or a private address isn't registered and checks every five seconds.

## Storefront Secrets

Each store's one-click-login private key is stored encrypted on its subscription. The encryption key comes from `wp-config.php` on the storefront:

```php
// A long random string, e.g. the output of: openssl rand -base64 48
define('ANYPRESS_HOST_KEY_ENCRYPTION_KEY', '...');
```

Without the constant, WordPress's auth salt is used instead. Either way, keep it stable: changing it makes the stored keys unreadable and one-click login stops working for existing stores. Keys saved by older versions in plain text are encrypted the first time they're used. The key's meta name, `_anypress_tenant_private_key`, starts with an underscore so WooCommerce doesn't show it in the Custom Fields box or the REST API; keys under the old name move there when anypress-host updates (or on first use), and WooCommerce Subscriptions renewal orders don't get a copy.

The webhook signing secret ([Website status](#website-status)) is encrypted with the same key. If it can no longer be read, the storefront registers again on its own.

WooCommerce products keep their AnyPress settings through WooCommerce's product object, under `_anypress_product_role` and `_anypress_product_group_name`. Settings under the old names are still read, and move to the new names when anypress-host updates.

## AnyPress Payments

`packages/anypress-payments` adds Authorize.Net card payments to FluentCart, including subscriptions charged automatically on renewal. Subscriptions need **FluentCart > Settings > Subscriptions: Store managed** with **Automatic charge** on. Setup: [packages/anypress-payments/README.md](packages/anypress-payments/README.md).

## AnyPress Export

`packages/anypress-export` moves a WordPress site from another host into an AnyPress store. On AnyPress, *Move a site in* creates a store that waits for the site and shows an import code. The site owner installs AnyPress Export on the old site, opens **Tools → Move to AnyPress** and pastes the code, or runs `wp anypress-export send <code>`. The plugin packs the database and `wp-content` in short steps that carry on after a timeout, uploads them and starts the move; the old site is never changed. The store keeps the site's own plugins and themes. It needs PHP 7.4+, zlib and cURL, and doesn't move multisite networks. More: [packages/anypress-export/README.md](packages/anypress-export/README.md).

## AnyPress Move

`packages/anypress-move` carries AnyPress Client's moving switches on their own, for any WordPress site: forms, a WooCommerce shop, contacts, help desk, affiliates, a community, LearnDash, Tutor LMS or LifterLMS courses, a Circle community and MemberPress members into the Fluent plugins. **Tools → Move to Fluent** has Check, Switch and Undo for each, and the same switches run as `wp anypress <switch> switch` and as abilities. With AnyPress Client active it does nothing. `scripts/build-move.sh` copies the switches in at build time. More: [packages/anypress-move/README.md](packages/anypress-move/README.md).

## Account API

AnyPress Host has a REST API for the signed-in customer's websites (`anypress-host/v1/account`): list them, sign in to one, change its address, set up its email sender, and choose a new name. Pages on the storefront call it with the login cookie; a front end on another domain signs the customer in with token sign-in (OAuth 2.0 with PKCE). How: [docs/account-api.md](docs/account-api.md).

## Site Editing API

AnyPress Client lets the AnyPress connector edit a store's design and content (`anypress-client/v1/site`): pages and their sections, the theme's patterns, colours and fonts, the header and footer, menus, revisions and images. Only the AnyPress API can call it, with requests signed by the store's own site key. The same actions are also WordPress abilities (category `anypress`) for a signed-in user with the right capabilities, so WordPress's MCP Adapter and AI agents on the site can use them. Stores come with the MCP Adapter installed but off; the owner can turn on an "AnyPress website" MCP server from the Your plan page. How: [docs/site-editing-api.md](docs/site-editing-api.md).

## Updates

AnyPress Host and AnyPress Payments update from the AnyPress API they're connected to, never from wordpress.org. Every release is signed, and a plugin only installs a release signed with a key built into it (`Updater::PUBLIC_KEYS` in `packages/anypress-updater`). The key built in today is AnyPress's release key. **If you run your own API**, either build the plugins with your own public key in that list and sign your releases with its private half, or set `ANYPRESS_UPDATE_CHANNEL` to `off` and install new zips by hand. In `wp-config.php`, `ANYPRESS_UPDATE_CHANNEL` picks `stable` (default), `beta` or `off`, and `ANYPRESS_AUTO_UPDATE` set to `patch` installs bug-fix releases automatically.

AnyPress Client never updates by itself on customer websites: it comes with the template version, and a website gets a newer one by moving to a newer version.

To build a zip locally: `scripts/release/build.sh anypress-host <version> dist`. How this repo publishes signed releases (tags, signing, the release bucket): [docs/releases.md](docs/releases.md).

## Development

The rest of this page is for working on the plugins themselves.

### Local Development

Each plugin is a Composer package under `packages/` (`anypress-host`, `anypress-client`, `anypress-payments`, `anypress-export`, `anypress-move` (run `scripts/build-move.sh` first), and the shared `anypress-updater`). Run `composer install` in the one you work on. The quickest way to see the Host plugin running is the test environment below.

### Test Environment

On Linux with Docker, `scripts/test-env.sh up` starts a throwaway WordPress (MariaDB + WordPress in Docker) at http://127.0.0.1 with this checkout's Host and Payments plugins, FluentCart, WooCommerce and Subscriptions for WooCommerce active. Log in as admin / admin. `scripts/test-env.sh wp ...` runs WP-CLI; `scripts/test-env.sh down` removes it.

Without Docker, `scripts/playground` runs AnyPress Client on [WordPress Playground](https://developer.wordpress.org/playground/) (WordPress and PHP inside Node) with the Ollie theme and the store template's starter pages, and checks the site-editing API and its abilities end to end with a throwaway site key. It needs Node 24.18 or newer and `composer install` in `packages/anypress-client`:

```shell
cd scripts/playground
npm ci
npm run smoke    # the checks; exits 1 on a failure
npm run serve    # the same site left running at http://127.0.0.1:9400, signed in as admin
```

Playground keeps the database in SQLite rather than MariaDB, so it checks the plugin's PHP and WordPress behaviour; database-specific SQL still needs a real store or `test-env.sh`. Pull requests also get a "Preview in WordPress Playground" button: a throwaway storefront with that PR's AnyPress Host, FluentCart and Ollie. FluentCart there shows a "requires MySQL/MariaDB" notice because Playground only has SQLite; its pages work, but check real orders and payments on a store (`pr-preview-build.yml` and `pr-preview-publish.yml`).

### Tests

The plugins (and `packages/anypress-updater`) have PHPUnit unit tests that run without WordPress: [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) stubs the WordPress functions and outside calls are mocked. They need PHP 8.1 or newer.

```shell
cd packages/anypress-host   # or any other package
composer install
composer test
```

`anypress-host` also has contract tests (`tests/Contract`, group `mock`) that run the plugin's real API client against the AnyPress API: by default the public Postman mock, or the server in `ANYPRESS_TEST_API_BASE_URL`, such as the local API. They need network access; run `vendor/bin/phpunit --exclude-group mock` to skip them.

Unit tests live in each package's `tests/Unit`. The `Tests` GitHub Actions workflow runs the unit tests of all four packages (host, client, payments, updater) on every pull request and on pushes to `main`, plus the Playground smoke test of AnyPress Client; the contract tests run there too, in a separate job that reports but doesn't block, since they depend on the public mock. Release builds strip `tests/` and `phpunit.xml.dist`.

### Names

Everything the plugins install, save or schedule is named after AnyPress: the plugin folders and update slugs (`anypress-host`, `anypress-client`, `anypress-payments`), their REST routes (`/wp-json/anypress-host/v1`, `/wp-json/anypress-client/v1`), settings (`anypress_api_key`, `anypress_root_domain`, …), order, subscription and product meta (`anypress_website_name`, `anypress_tenant_state`, `_is_anypress_product`, …), scheduled jobs (`anypress_run_store_jobs`, …) and the wp-config constants a store is given (`ANYPRESS_TENANT_ROLES`, `ANYPRESS_PUBLIC_KEY`, `ANYPRESS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS`; the AnyPress API sets `ANYPRESS_IS_TENANT` and `ANYPRESS_SITE_KEY`). A storefront that ran the plugin before it was called AnyPress Host gets its settings, meta and scheduled jobs moved once, on the first load of 2.11.1 (`AnyPressHost\Migrations\OldNames`).

## License

GPL-3.0-or-later ([LICENCE](LICENCE)). AnyPress Payments is GPL-2.0-or-later, as its own header says; that licence lets it be used under GPL-3.0 too, so the whole repository can be distributed under GPL-3.0.
