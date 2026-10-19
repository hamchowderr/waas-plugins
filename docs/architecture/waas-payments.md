# WaaS Payments

WaaS Payments adds Authorize.Net card payments to FluentCart on the storefront, including subscriptions that the storefront charges on each renewal. Storefronts that can use FluentCart's own Stripe gateway do not need it. It does not talk to the provisioning API or to WaaS Host directly: it marks FluentCart orders and subscriptions paid, and WaaS Host reacts to those subscription events as usual. Paths are relative to `packages/waas-payments/`.

[Back to the overview](README.md)

## Inside the plugin

```mermaid
flowchart LR
  subgraph FC["FluentCart"]
    GM["Gateway manager"]
    SCS["SystemChargeService<br/>renewal schedule, retries, past due"]
    REF["Refund screen"]
  end

  subgraph WP["WaaS Payments: src/Gateway"]
    GW["AuthorizeNetGateway<br/>route waas_authorizenet"]
    SET["AuthorizeNetSettings<br/>test and live keys, follows FluentCart order mode"]
    PC["PaymentConfirmation<br/>ajax: charge the token"]
    RC["RenewalCharge"]
    CU["CardUpdate<br/>customer replaces the card"]
    SC["SavedCard<br/>subscription meta"]
    WH["Webhook<br/>REST route"]
    WS["WebhookSync"]
    FRR["FailedRefundRollback"]
  end

  subgraph DRV["src/Driver"]
    D["AuthorizeNetDriver<br/>implements RecurringGatewayDriver"]
    CL["AuthorizeNetClient<br/>JSON API, webhooks REST API"]
    D --> CL
  end

  ANET["Authorize.Net<br/>Accept hosted form, CIM, transactions"]

  GM -->|"register payment method"| GW
  GW --> SET
  GW --> PC
  GW --> CU
  GW --> WH
  SCS -->|"chargeRenewal, reconcileRenewalCharge"| RC
  REF -->|"processRefund"| GW
  GW -->|"refund failed"| FRR
  PC --> SC
  CU --> SC
  RC --> SC
  WH --> WS
  PC --> D
  RC --> D
  CU --> D
  WS --> D
  GW --> D
  CL <--> ANET
  ANET -->|"signed webhooks"| WH
```

Sources: `waas-payments.php`, `src/Gateway/*.php`, `src/Driver/*.php`, `src/Driver/AuthorizeNet/*.php`.

- Card numbers never reach the storefront. The browser sends the card to Authorize.Net's hosted form and gets back a one-time token. The driver only ever sees tokens and saved-profile ids (`src/Driver/GatewayDriver.php`).
- Saved cards are Authorize.Net customer profiles (CIM). FluentCart, not Authorize.Net, runs the renewal schedule, so there is no Authorize.Net recurring billing (ARB) and no cron in this plugin (`src/Driver/AuthorizeNet/AuthorizeNetDriver.php`).
- The gateway declares no gateway-billed subscription module, so FluentCart only offers it for subscriptions the storefront charges itself (`src/Gateway/AuthorizeNetGateway.php`).

## First purchase

```mermaid
sequenceDiagram
  autonumber
  actor C as Customer
  participant JS as authorizenet-checkout.js
  participant FC as FluentCart
  participant GW as AuthorizeNetGateway
  participant PC as PaymentConfirmation
  participant AN as Authorize.Net

  C->>JS: Choose Card
  JS->>GW: checkoutConfig (login id, public client key, sandbox)
  C->>JS: Click Pay
  JS->>FC: orderHandler() creates the order once
  FC->>GW: makePaymentFromPaymentInstance
  GW-->>JS: transaction_hash, nothing charged yet
  JS->>AN: Hosted card form (AcceptUI)
  AN-->>JS: opaqueData token
  JS->>PC: admin-ajax waas_payments_authorizenet_confirm (hash, token)
  PC->>PC: Check nonce, take a database lock on the transaction
  PC->>PC: Amount from the order total, never from the browser
  PC->>AN: createTransactionRequest authCapture<br/>save the card if this is a renewing subscription
  alt Approved
    AN-->>PC: transaction id, customer and payment profile ids
    PC->>PC: Record payment, SavedCard store
    PC->>FC: syncOrderStatuses: order paid, subscription active
    FC-->>C: Receipt page
    Note over FC: WaaS Host now sees the subscription become<br/>active and creates the website
  else Declined or held for review
    AN-->>PC: error
    PC-->>JS: 422, the customer can try again on the same order
  end
```

Sources: `assets/authorizenet-checkout.js`, `src/Gateway/AuthorizeNetGateway.php`, `src/Gateway/PaymentConfirmation.php`, `src/Gateway/SavedCard.php`.

- A free trial (total of 0) saves the card without charging.
- If the card could not be saved and no older card exists, the subscription is switched to manual payment so the customer is asked to pay at renewal instead of failing silently.

## Renewals

FluentCart decides when to charge. WaaS Payments makes sure a renewal is never charged twice, even if Authorize.Net does not answer.

```mermaid
sequenceDiagram
  autonumber
  participant FC as FluentCart SystemChargeService
  participant RC as RenewalCharge
  participant AN as Authorize.Net

  FC->>RC: chargeRenewal(payment, attempt)
  RC->>RC: SavedCard read, test or live must match
  RC->>RC: Take the transaction lock, stop if already paid
  alt A charge is already in flight or held
    RC->>AN: Look it up instead of charging again
  else Normal
    RC->>RC: Write "in flight" meta (invoice, amount) before sending
    RC->>AN: createTransactionRequest with the saved profile,<br/>stored credential, original network transaction id
  end
  alt Approved
    AN-->>RC: paid
    RC->>FC: Record payment, subscription renewed (returns true)
  else Declined
    AN-->>RC: declined
    RC-->>FC: WP_Error, FluentCart dunning retries
  else No answer, or held for fraud review
    RC-->>FC: processing
    loop Daily until settled
      FC->>RC: reconcileRenewalCharge
      RC->>AN: getTransactionListForCustomer by invoice and amount,<br/>or getTransactionDetails for a held charge
    end
  end
```

Source: `src/Gateway/RenewalCharge.php` (`charge`, `reconcile`, `settle`, `outcome`).

- After a decline, FluentCart's dunning retries at 25%, 60% and 90% of the grace period and emails the customer a Pay Now link. The subscription becomes past due on the due date and expired when the grace period ends. Expiry is a WaaS Host remove trigger, so the website is suspended with a backup and scheduled for deletion. A later payment reactivates the subscription, which resumes the website ([WaaS Host](waas-host.md#from-cart-events-to-website-actions), [waas-payments README](../../packages/waas-payments/README.md)).
- When a customer replaces a failing card on the card update page (`/?waas_payments_card_update=<subscription>`), any unpaid renewal is charged right away (`src/Gateway/CardUpdate.php`).

## Webhooks

Saving the settings with a Signature Key registers a webhook with Authorize.Net. It catches what happens outside the storefront: refunds made in the Authorize.Net dashboard and fraud reviews that finish later.

```mermaid
flowchart TB
  POST["POST /wp-json/waas-payments/v1/authorizenet/webhook"] --> SIG{"X-ANET-Signature<br/>HMAC-SHA512 matches?"}
  SIG -->|"current mode's key"| EV{"Payment event we handle?<br/>authcapture, fraud approved,<br/>fraud declined, refund, void"}
  SIG -->|"other mode's key"| IGN1["200, recorded and ignored"]
  SIG -->|"neither"| BAD["401"]
  EV -->|"no"| IGN2["200 Ignored"]
  EV -->|"yes"| READ["WebhookSync: re-read the transaction<br/>from Authorize.Net, never trust the payload"]
  READ --> KIND{"What is it?"}
  KIND -->|"refund"| R["Find the charge, record the refund once"]
  KIND -->|"charge paid"| P["Find the order by charge id, or by invoice,<br/>amount and customer. Mark paid,<br/>save the card on a first subscription payment"]
  KIND -->|"voided after paid"| V["Record the rest as refunded"]
  KIND -->|"held renewal declined"| D["Ask FluentCart to reconcile now"]
  KIND -->|"still pending"| W["Nothing yet"]
  READ -->|"Authorize.Net unreachable"| RETRY["503, Authorize.Net retries"]
```

Sources: `src/Gateway/Webhook.php`, `src/Gateway/WebhookSync.php`, `src/Driver/AuthorizeNet/AuthorizeNetDriver.php` (`registerWebhook`).

- The webhook needs pretty permalinks, because Authorize.Net rejects URLs with a query string.

## Refunds

```mermaid
sequenceDiagram
  actor A as Store admin
  participant FC as FluentCart
  participant GW as AuthorizeNetGateway
  participant AN as Authorize.Net

  A->>FC: Refund an order
  FC->>FC: Records the refund and refunded total first
  FC->>GW: processRefund
  GW->>AN: refundTransaction
  alt Not settled yet and full amount
    AN-->>GW: error 54
    GW->>AN: voidTransaction
  end
  alt Success
    AN-->>GW: transaction id
    GW-->>FC: refund recorded
  else Failure
    GW->>GW: FailedRefundRollback undo:<br/>delete the unconfirmed refund, restore the total
    GW-->>FC: WP_Error shown to the admin
  end
```

Sources: `src/Gateway/AuthorizeNetGateway.php` (`processRefund`), `src/Gateway/FailedRefundRollback.php`, `src/Driver/AuthorizeNet/AuthorizeNetDriver.php` (`refund`).

- A partial refund of a charge that has not settled is refused, because Authorize.Net can only void the whole amount at that point.

## Test and live

Each mode has its own API login, transaction key, signature key and public client key. Which set is used follows FluentCart's store **Order Mode** (`src/Gateway/AuthorizeNetSettings.php`). Every charge and saved card remembers its mode, so a test card is never charged with live keys after the switch.
