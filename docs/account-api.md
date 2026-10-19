# Account API

WaaS Host has a REST API for the customer's websites, so a front end of your own can show what the account page shows and do what it does: list the websites, sign in to one, change its address, set up its email sender, and choose a new name when the first one was taken.

Base URL: `https://<your storefront>/wp-json/waas-host/v1/account`

`{id}` in a path is the subscription id (the cart's own id: a FluentCart subscription or a WooCommerce subscription). It works the same on both carts.

## Signing in

Calls are made as the signed-in customer, with WordPress's normal REST authentication:

- the customer's WordPress login cookie, and
- a nonce for the `wp_rest` action in the `X-WP-Nonce` header (or a `_wpnonce` parameter). Make it on a storefront page with `wp_create_nonce('wp_rest')`.

Without a valid nonce WordPress treats the call as signed out, and it gets `401`.

So the API is for pages served by the storefront itself (same site, same cookie). The API sends no CORS headers, so another domain's browser code can't call it.

## Errors

Errors are JSON in WordPress's usual shape, with a message you can show to the customer as it is:

```json
{ "code": "waas_forbidden", "message": "This website is not in your account.", "data": { "status": 403 } }
```

| Status | When |
|---|---|
| `400` | The input isn't valid (`Please enter a valid domain name, like example.com.`), or the platform refused the change and said why |
| `401` | Not signed in |
| `403` | The subscription isn't the customer's, or doesn't exist |
| `409` | The website isn't set up yet, isn't ready to sign in to, or doesn't need a new name |
| `429` | Too many sign-in links; wait for `Retry-After` seconds |
| `500` | Signing in isn't available for this website; the customer should contact support |
| `502` | The platform couldn't be reached; try again later |
| `503` | The shop has no supported cart active, or the website name couldn't be checked right now |

Every website route first checks that the subscription belongs to the signed-in customer. Responses are sent with `Cache-Control: no-store`.

## Routes

### `GET /websites`

The customer's websites, newest first: those with a website, or one being set up.

```json
{
  "websites": [
    {
      "id": 42,
      "domain": "apex.example.com",
      "status": "Ready",
      "status_label": "Ready",
      "active_until": "2027-01-01T00:00:00+00:00",
      "can_log_in": true,
      "needs_new_name": false,
      "manage_plan_url": "https://shop.example.com/account/subscriptions/42"
    }
  ]
}
```

- `status` is the website's state (`Queued`, `Provisioning`, `Ready`, `Suspended`, `Needs New Name`, …); show `status_label` to the customer.
- `active_until` is set when the subscription is cancelled and the website stays online until the end of the paid period, else `null`.
- `can_log_in`: the website is ready, so `POST /websites/{id}/login` works.
- `needs_new_name`: the website name was taken; ask for a new one with `POST /websites/{id}/name`.
- `manage_plan_url`: the cart's own page to change or cancel the plan.

### `POST /websites/{id}/login`

A fresh one-time sign-in link to the website's WordPress admin. Open it right away: it works once and expires after `expires_in` seconds. Make a new one on each click; never put one in a page ahead of time.

```json
{ "url": "https://apex.example.com/wp-json/waas-client/v1/single_login/verify?token=…", "expires_in": 300 }
```

Limited to 10 links a minute per customer (`429` after that). `409` while the website isn't ready.

### `PUT /websites/{id}/address`

Makes the customer's own domain the website's address. Body: `{ "domain": "shop.example.com" }`. The customer adds the DNS records shown on the account page first. The old address keeps working for five minutes.

```json
{ "domain": "shop.example.com" }
```

### `GET /websites/{id}/email-sender`

The domain the website's email (receipts, password resets) is sent from, with the DNS records to add. `null` while it sends from the platform's address.

```json
{
  "email_sender": {
    "domain": "example.com",
    "from": "no-reply@example.com",
    "status": "pending",
    "records": [
      { "type": "TXT", "name": "send.example.com", "value": "v=spf1 …", "priority": null },
      { "type": "MX", "name": "send.example.com", "value": "feedback.example.net", "priority": 10 }
    ]
  }
}
```

`status`: `pending` (waiting for the DNS records; checked every 10 minutes, and the website switches over on its own), `verified` (sending from this domain) or `removing`.

### `PUT /websites/{id}/email-sender`

Sends the website's email from `no-reply@<domain>` once its DNS records are verified. Body: `{ "domain": "example.com" }`. Answers like `GET`, with the records to add.

### `DELETE /websites/{id}/email-sender`

Moves the website's email back to the platform's address (within 10 minutes).

```json
{ "removed": true }
```

### `GET /websites/{id}/domain-connect?domain=&service=`

A "Connect automatically" link, for DNS hosts that support Domain Connect: it opens the DNS host's page, which adds the records once the customer approves. `service` is `website` (for the address, the default) or `email` (for the email sender).

```json
{ "supported": true, "url": "https://dns-host.example/…", "dns_provider": "Example DNS", "conflict": null }
```

When the DNS host can't, `supported` is `false` and `url` is `null`: show the records to add by hand. When the DNS host could but one-click setup would go wrong (the domain already has a different DKIM key, say), `conflict` says why and what to do, written for the customer: show it with the records.

### `POST /websites/{id}/name`

A new website name, when the one picked at checkout was taken (`needs_new_name` is `true`). Body: `{ "name": "Apex Research" }`. It gets the same checks as at checkout, and the website is then set up with the new name.

```json
{ "message": "Thank you! We are setting up your website with its new name." }
```

`400` when the name is taken or not valid, `409` when the website doesn't need a new name or the subscription isn't active, `503` when the name couldn't be checked right now.
