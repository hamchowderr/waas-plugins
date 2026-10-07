# WooCommerce test storefront

A second throwaway storefront, on WooCommerce, for testing the whole lifecycle end to end: buy → store created → one-click login → suspend → resume → cancel. It sits next to the FluentCart one ([test-storefront.md](test-storefront.md)) in the same Coolify project, is built by the same script with `--cart woo`, and holds no real customer data.

| | |
|---|---|
| URL | https://storefront-woo-test.191.101.15.236.sslip.io (Let's Encrypt, HTTP-01) |
| wp-admin | https://storefront-woo-test.191.101.15.236.sslip.io/wp-admin, user `admin` (admin@otakusolutions.io) |
| Admin password | Coolify → Projects → **WaaS Storefront (test)** → production → **storefront-woo-test** → Environment Variables → `WP_ADMIN_PASSWORD` (click the eye to show it) |
| Provisioning API | https://waas-api.otakusolutions.io, with the test API key (Coolify variables `WAAS_API_KEY` and `WAAS_API_SECRET`, locked) |
| Test product | **Website plan (test)**, $1.00 a month, `/product/website-plan-test/` (product 11), a WP Swings subscription marked as a WaaS product with the role `store` |
| Payments | Stripe (WooCommerce Stripe Gateway) in **test mode**, set up by the owner (see [Stripe](#stripe)); and **Pay later (test)**, Woo's Cash on delivery, for tests by hand |

## How it's built

One Coolify **Service** from [`scripts/test-storefront/compose-woo.yml`](../scripts/test-storefront/compose-woo.yml): `wordpress` (wordpress:7.1-php8.3-apache, since WooCommerce 11.1 needs WordPress 7.0 or later) and `db` (mariadb:11), with volumes `wp`, `db` and `wpcli`.

Plugins:

- WooCommerce, from wordpress.org. Installed from WP-CLI, it chose posts storage rather than High-Performance Order Storage (the `pages` step prints which). waas-host reads and writes orders and subscriptions through the order API only, so it works with either.
- Subscriptions for WooCommerce by WP Swings (free), from wordpress.org. waas-host's WooCommerce adapter uses it when it's active (`WpSwingsSubscriptions`). The `pages` step switches it on (`wps_sfw_enable_plugin`) and lets customers cancel from My Account.
- WooCommerce Stripe Gateway, from wordpress.org. It's the only gateway WP Swings' free version renews automatically.
- FluentSMTP, from wordpress.org, installed with no connection (see [Email](test-storefront.md#email); the steps are the same).
- waas-host, built from this repo's `packages/`. waas-payments is FluentCart-only and isn't installed.
- **Test storefront shim**: the must-use plugin [`waas-test-storefront-cod-shim.php`](../scripts/test-storefront/remote-woo/waas-test-storefront-cod-shim.php). WP Swings' free version hides every gateway except Stripe when the cart holds a subscription; the shim adds Cash on delivery to its list (filter `wps_sfw_supported_payment_gateway_for_woocommerce`), so the lifecycle can be tested by hand without a card. It is for this storefront only; never install it on a real store.

Everything else follows the FluentCart storefront ([How it's built](test-storefront.md#how-its-built)): commands run as on-demand scheduled tasks and drop to www-data (uid 33); the plugin package arrives in 20 KB chunks through `WAAS_PKG`; WordPress's cron is off (`DISABLE_WP_CRON`) and the every-minute `wp-cron` task runs Action Scheduler (where WP Swings schedules renewals) and WP-cron; secrets are only Coolify variables, all locked except `WP_ADMIN_PASSWORD`. The setup scripts are in [`remote-woo/`](../scripts/test-storefront/remote-woo/), and share `wp` and `check.php` with `remote/`.

## Coolify ids

| | |
|---|---|
| Project | `WaaS Storefront (test)`, `mruxjblciig9ofjy5tsfj3s5` (shared with storefront-test) |
| Environment | `production` |
| Service | `storefront-woo-test`, `07jytgxfsqeupxdljntktlpo` |
| Scheduled task | `wp-cron`, every minute |

The script finds the service by name, so the id changes after a rebuild.

## Rebuild, update, remove

The same commands as the FluentCart storefront, with `--cart woo`:

```shell
scripts/test-storefront/storefront.py --cart woo create   # service + variables in the existing project; needs WAAS_TEST_KEY / WAAS_TEST_SECRET
scripts/test-storefront/storefront.py --cart woo push     # build waas-host and copy it in
scripts/test-storefront/storefront.py --cart woo setup    # bootstrap install plugins configure pages product payments cron
```

- **New plugin code:** `push`, then `setup plugins check`.
- **Single steps:** `setup check`, `setup payments`, and so on. Every step can be re-run.
- **Make a renewal due now:** `storefront.py --cart woo run 'sh /opt/wpcli/x/setup.sh renew <subscription id>'`. The `wp-cron` task then creates the renewal order within a few minutes (and, with Stripe, charges the saved test card).
- **Remove:** `storefront.py --cart woo remove` deletes this service with its volumes. The project is deleted only when no service is left in it, so the FluentCart storefront stays.

`--cart woo` only ever finds, changes or deletes the service named `storefront-woo-test`.

## Owner steps

1. **Log in.** Copy the admin password from Coolify (see the table at the top) and log in as `admin`. Then lock `WP_ADMIN_PASSWORD` in Coolify if you like, as for the FluentCart storefront.
2. **Set up Stripe in test mode**, below.
3. **Email:** connect FluentSMTP ([Email](test-storefront.md#email)).

### Stripe

Never paste Stripe keys into a chat. Use one of these:

- **Connect flow (preferred).** In wp-admin, go to **WooCommerce** → **Settings** → **Payments** → **Stripe**. Choose the option to connect a **test** account (sandbox), log in to Stripe, and approve. The plugin stores the test keys and sets up the webhook itself. Keep **Test mode** on.
- **Keys as Coolify variables.** In the Stripe dashboard, switch to **Test mode** (or your sandbox), then open **Developers** → **API keys**. In Coolify, on **storefront-woo-test** → Environment Variables, add `STRIPE_TEST_PUBLISHABLE_KEY` (`pk_test_…`) and `STRIPE_TEST_SECRET_KEY` (`sk_test_…`), and lock both. Restart the service, then run `storefront.py --cart woo setup payments`. That step only accepts `pk_test_` / `sk_test_` keys and prints no secrets.

Stripe test card: `4242 4242 4242 4242`, any future expiry date, any CVC.

## Testing the lifecycle

Use a new customer each time (a made-up name and a `+tag` address on your own mailbox), never real customer data.

1. **Buy.** Open the test product, add it to the cart, and check out with **Pay later (test)** or the Stripe test card. Checkout asks for the **Website name**. With Pay later, mark the order **Processing** or **Completed** in WooCommerce → Orders; WP Swings then activates the subscription.
2. **Store created.** Within a minute or two (the `wp-cron` task), the subscription's page in **My Account** → **Subscriptions** shows the website status, and then **Ready** with a **Login as** link. The "Your website is ready" email goes out once FluentSMTP is connected.
3. **One-click login.** Click **Login as**: the store opens logged in.
4. **Suspend.** Make a renewal due (`setup.sh renew <id>`, above). With Pay later the renewal order stays pending: mark it **Failed** in WooCommerce → Orders. WP Swings puts the subscription **on hold** and waas-host suspends the store. With Stripe, a declined test card (`4000 0000 0000 0341` saved at checkout) does the same.
5. **Resume.** Mark the renewal order **Completed**, or use **Reactivate** on the subscription in WP Swings' subscription list. The subscription is active again and the store comes back.
6. **Cancel.** As the customer, cancel on the subscription's page. WP Swings cancels at once, but the customer has paid until the next payment date: waas-host keeps the store online until then, the page shows **Active until &lt;date&gt;**, and the "stays online until" email goes out. At that date the store is suspended, then deleted after the grace period in WaaS → Settings. To test the end without waiting, run the planned event now: `storefront.py --cart woo run 'sh /opt/wpcli/x/wp cron event run wpcs_suspend_at_period_end'`. (The delay needs waas-host with #35; before that, a cancel takes the store offline at once.)

WP Swings' free version can't undo a cancel: a cancelled subscription stays cancelled, and the customer buys again. Its only **Reactivate** is the admin action for an on-hold subscription. Customer pause and resume is a Pro feature.

## Notes

- **Plugin updates.** Don't apply updates WordPress offers for WaaS Host; update it with `push` from this repo. WooCommerce, WP Swings and Stripe can be updated from wp-admin.
- **WooCommerce Subscriptions** (paid) isn't installed. waas-host supports it too; with both active it prefers WP Swings.
