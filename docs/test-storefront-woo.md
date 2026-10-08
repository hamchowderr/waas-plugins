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

- **New plugin code:** `push`, then `setup plugins check`. The `plugins` step installs the wordpress.org plugins only when they're missing; update those from wp-admin.
- **Single steps:** `setup check`, `setup payments`, and so on. Every step can be re-run.
- **Make a renewal due now:** `storefront.py --cart woo run 'sh /opt/wpcli/x/setup.sh renew <subscription id>'`. The `wp-cron` task then creates the renewal order within a few minutes (and, with Stripe, charges the saved test card).
- **Watch a test:** `storefront.py --cart woo run 'sh /opt/wpcli/x/setup.sh watch'` prints, for the latest five subscriptions, WP Swings' status and next payment date, the parent order's status, and waas-host's store meta (store, state, suspend_at, delete_at, the planned end-of-period event). Read-only, and no customer data.
- **Remove:** `storefront.py --cart woo remove` deletes this service with its volumes. The project is deleted only when no service is left in it, so the FluentCart storefront stays.

`--cart woo` only ever finds, changes or deletes the service named `storefront-woo-test`.

## Owner steps

1. **Log in.** Copy the admin password from Coolify (see the table at the top) and log in as `admin`. Then lock `WP_ADMIN_PASSWORD` in Coolify if you like, as for the FluentCart storefront.
2. **Set up Stripe in test mode**, below.
3. **Email:** connect FluentSMTP ([Email](test-storefront.md#email)).

### Stripe

Never paste Stripe keys into a chat. Use one of these:

- **Connect flow (preferred).** In wp-admin, go to **WooCommerce** → **Settings** → **Payments** → **Stripe**. Choose the option to connect a **test** account (sandbox), log in to Stripe, and approve. The plugin stores the test keys and sets up the webhook itself. Keep **Test mode** on.
- **Keys as Coolify variables.** In the Stripe dashboard, switch to **Test mode** (or your sandbox), then open **Developers** → **API keys**. In Coolify, on **storefront-woo-test** → Environment Variables, `STRIPE_TEST_PUBLISHABLE_KEY` and `STRIPE_TEST_SECRET_KEY` are already listed, empty (they come from the compose file). Fill in the `pk_test_…` and `sk_test_…` values, and lock both. Restart the service, then run `storefront.py --cart woo setup payments`. That step only accepts `pk_test_` / `sk_test_` keys and prints no secrets.

Stripe test card: `4242 4242 4242 4242`, any future expiry date, any CVC.

#### Finishing Stripe (click-through)

For WooCommerce Stripe Gateway 11.0.1. Never paste keys or the signing secret into a chat.

1. **Open Stripe's settings.** wp-admin → **WooCommerce** → **Settings** → **Payments** → **Stripe**, or go straight to `/wp-admin/admin.php?page=wc-settings&tab=checkout&section=stripe`. It's WooCommerce's screen, not WP Swings'.
2. **Test mode and the account.** On the **Settings** tab, under **Account details**:
   - **Test mode** is ticked.
   - If no test account is connected yet, click **Configure connection** → **Test** → **Connect a test account**, log in to Stripe and approve. The plugin stores the test keys.
3. **Check the webhook.** Still under **Account details**, the **Webhooks** line says whether webhooks are set up:
   - "Your webhooks are configured and will be sent to: …?wc-api=wc_stripe" or "Webhooks have been setup successfully": nothing more to do. Connecting a test account creates the webhook in Stripe by itself. Go to step 5.
   - Otherwise, try **Configure webhooks** first. If that fails, set the webhook up by hand (step 4).
4. **Webhook by hand** (only if step 3 didn't show it configured):
   1. In the **Stripe Dashboard**, switch to **Test mode** (or the sandbox), then **Developers** → **Webhooks** → **Add endpoint**.
   2. **Endpoint URL:** `https://storefront-woo-test.191.101.15.236.sslip.io/?wc-api=wc_stripe`
   3. **Events:** "Select all events" works. The exact list the plugin registers itself:
      - `account.updated`, `review.opened`, `review.closed`
      - `source.chargeable`, `source.canceled`
      - `charge.succeeded`, `charge.failed`, `charge.captured`, `charge.refunded`, `charge.refund.updated`, `charge.dispute.created`, `charge.dispute.closed`
      - `payment_intent.processing`, `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.amount_capturable_updated`, `payment_intent.requires_action`
      - `setup_intent.succeeded`, `setup_intent.setup_failed`
      - `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`
   4. Save the endpoint, then click **Reveal** under **Signing secret** and copy the `whsec_…` value.
   5. Back in wp-admin: **Configure connection** → **Test** tab. Paste it into the **webhook secret** field below the test keys (the plugin's "Test Webhook Secret"), then **Save**.
   6. The **Webhooks** line should now say "Webhooks have been manually configured via a webhook secret."
   7. **Keep exactly one endpoint for this URL.** If an endpoint for `…?wc-api=wc_stripe` was already added by hand in Stripe's test mode and connecting also created one, two endpoints send every event twice, and the plugin can verify only one signing secret. In **Developers** → **Webhooks**, delete the extra one. Keep the one whose signing secret the plugin holds: the connect-created one if you connected, or the manual one if you pasted its secret.
5. **Turn Stripe on.** On the same screen, tick **Enable Stripe**, keep **Test mode** ticked, and click **Save changes**. Leave **Pay later (test)** on as well.
6. **Test purchase.** In a private window, buy **Website plan (test)** (`/product/website-plan-test/`) and pay with card `4242 4242 4242 4242`, any future expiry date and any CVC.
   - The order goes to **Processing**, and WP Swings activates the subscription.
   - The website is set up as with Pay later (see [Testing the lifecycle](#testing-the-lifecycle)).
   - In the Stripe Dashboard (Test), **Developers** → **Webhooks** → the endpoint shows the delivered events (`payment_intent.succeeded`, `charge.succeeded`) with status 200.

PayPal, for later: WP Swings' free version renews subscriptions through PayPal only with its own **WPS PayPal** gateway (`wps_paypal`, WooCommerce → Settings → Payments, with a PayPal REST app's client ID and secret, and a sandbox/test mode). The **WooCommerce PayPal Payments** plugin works with subscriptions only in WP Swings Pro.

## Testing the lifecycle

Use a new customer each time (a made-up name and a `+tag` address on your own mailbox), never real customer data.

1. **Buy.** Open the test product, add it to the cart, and check out with **Pay later (test)** or the Stripe test card. Checkout asks for the **Website name**. Guest checkout is off, so WooCommerce creates the customer's account and logs them in; its generated password goes out by email only once FluentSMTP is connected, so finish the test in the same browser session. Pay later puts the order straight into **Processing** (the product is virtual), and WP Swings activates the subscription on that; nothing needs marking paid.
2. **Store created.** Within a minute or two (the `wp-cron` task), the subscription's page in **My Account** → **Subscriptions** shows the website status, and then **Ready** with a **Login as** link. The "Your website is ready" email goes out once FluentSMTP is connected.
3. **One-click login.** Click **Login as**: the store opens logged in.
4. **Suspend.** Make a renewal due (`setup.sh renew <id>`, above). With Pay later the renewal order stays pending: mark it **Failed** in WooCommerce → Orders. WP Swings puts the subscription **on hold** and waas-host suspends the store. With Stripe, a declined test card (`4000 0000 0000 0341` saved at checkout) does the same.
5. **Resume.** Mark the renewal order **Completed**, or use **Reactivate** on the subscription in WP Swings' subscription list. The subscription is active again and the store comes back.
6. **Cancel.** As the customer, cancel on the subscription's page. WP Swings cancels at once, but the customer has paid until the next payment date: waas-host keeps the store online until then, the page shows **Active until &lt;date&gt;**, and the "stays online until" email goes out. At that date the store is suspended, then deleted after the grace period in WaaS → Settings. To test the end without waiting, run the planned event now: `storefront.py --cart woo run 'sh /opt/wpcli/x/wp cron event run wpcs_suspend_at_period_end'`. (The delay needs waas-host with #35; before that, a cancel takes the store offline at once.)

WP Swings' free version can't undo a cancel: a cancelled subscription stays cancelled, and the customer buys again. Its only **Reactivate** is the admin action for an on-hold subscription. Customer pause and resume is a Pro feature.

## Notes

- **Plugin updates.** Don't apply updates WordPress offers for WaaS Host; update it with `push` from this repo. WooCommerce, WP Swings and Stripe can be updated from wp-admin.
- **WooCommerce Subscriptions** (paid) isn't installed. waas-host supports it too; with both active it prefers WP Swings.
