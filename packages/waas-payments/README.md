# WaaS Payments

Card payments for FluentCart through Authorize.Net. The card is typed into Authorize.Net's hosted form (Accept.js / AcceptUI) and never reaches this site; the site only handles single-use tokens and Authorize.Net's references to saved cards.

It covers one-time payments, refunds, and FluentCart subscriptions billed by the store, with renewals charged automatically to the saved card.

## Setup

1. **FluentCart > Settings > Payments > Authorize.Net**: enter the API Login ID and Transaction Key (Merchant Interface: Account > Settings > Security Settings > API Credentials and Keys) and choose sandbox or live. Saving checks the credentials and fetches the account's Public Client Key, which the hosted form needs.
2. **For subscriptions**, go to **FluentCart > Settings > Subscriptions** and set:
   - **Subscription management: Store managed.** FluentCart runs the billing schedule.
   - **Automatic charge: on.** FluentCart charges each renewal to the card saved at checkout. With it off, renewals are invoices the customer pays at checkout.

   These settings apply to the whole store. Every payment method's subscriptions become store-billed, not just this gateway's.

   Under "Gateway managed" (FluentCart's default), the card gateway is not offered for subscription carts. That mode needs the payment processor to run the schedule, and this gateway doesn't use Authorize.Net's recurring billing (ARB).

## How subscriptions work

| Step | What happens |
| --- | --- |
| Checkout | The first payment is charged, and the same request saves the card at Authorize.Net (Customer Information Manager, CIM): a customer profile with a payment profile. FluentCart activates the subscription. A subscription with nothing to pay today saves the card without charging it. |
| Renewal | FluentCart creates the renewal invoice and, on the due date, calls the gateway (`chargeRenewal`), which charges the saved card. A paid invoice renews the subscription. |
| Declined renewal | FluentCart's dunning takes over. The invoice goes back to pending, retries run at 25%, 60% and 90% of the grace period (7 days for monthly), and the customer gets a charge-failed email with a Pay Now link. The subscription becomes `past_due` on the due date and `expired` when the grace period ends. A later payment reactivates it. |
| No answer from Authorize.Net | The charge is reported as still processing, not as failed, so FluentCart doesn't retry and charge twice. Its daily reconciliation (`reconcileRenewalCharge`) looks the charge up by invoice number and amount. A charge held by fraud filters is settled the same way once the merchant decides. |
| Card update | The customer portal's "update payment method" opens a page on this site (`/?waas_payments_card_update=<subscription>`, for the logged-in owner only). The new card is saved on the customer's Authorize.Net profile and the old one is deleted. If a renewal is waiting because the old card was declined, it is charged to the new card right away. Paying an invoice with Pay Now and a new card also replaces the saved card. |
| Cancel | FluentCart cancels locally. Authorize.Net has nothing scheduled to cancel. |
| Refund | Renewals refund like any other payment: a refund once Authorize.Net has settled the charge (it settles once a day), or a void of the full amount before that. |

FluentCart stays the only source of subscription status. Authorize.Net has no schedule of its own, so nothing can drift between the two. Authorize.Net webhooks aren't needed for any of this (follow-up: beads `waas-hju.10`).

Saved cards belong to the Authorize.Net environment that saved them: a sandbox card is never charged with live credentials. Switching modes leaves existing subscriptions unable to auto-charge until customers save a card again.

## Tests

```shell
composer install
composer test -- --testsuite unit      # no network
node --test 'tests/js/*.test.mjs'      # checkout and card-update scripts
```

The `sandbox` suite runs against the real Authorize.Net sandbox; see [tests/Sandbox/README.md](tests/Sandbox/README.md).
