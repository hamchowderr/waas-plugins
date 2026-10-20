# Account API

WaaS Host has a REST API for the customer's websites, so a front end of your own can show what the account page shows and do what it does: list the websites, sign in to one, change its address, set up its email sender, and choose a new name when the first one was taken.

Base URL: `https://<your storefront>/wp-json/waas-host/v1/account`

`{id}` in a path is the subscription id (the cart's own id: a FluentCart subscription or a WooCommerce subscription). It works the same on both carts.

## Signing in

There are two ways to call the API as the customer.

### On the storefront itself: cookie and nonce

WordPress's normal REST authentication:

- the customer's WordPress login cookie, and
- a nonce for the `wp_rest` action in the `X-WP-Nonce` header (or a `_wpnonce` parameter). Make it on a storefront page with `wp_create_nonce('wp_rest')`.

Without a valid nonce WordPress treats the call as signed out, and it gets `401`. The API sends no CORS headers, so another domain's browser code can't call it.

### From a front end on another domain: token sign-in

A site on another domain (a headless front end, say) signs the customer in through the storefront with the OAuth 2.0 authorization-code flow and PKCE, then calls the API from its **server** with `Authorization: Bearer <access token>`. Tokens never go to the browser: keep them in an encrypted, httpOnly cookie or a server-side session. Access tokens only work on the account API routes, not on the rest of the storefront's API.

**1. Register the front end** on the storefront with WP-CLI. The secret is shown once; keep it on the front end's server.

```sh
wp waas-host account-client add "My front end" --redirect-uris=https://front.example/api/auth/callback
wp waas-host account-client list
wp waas-host account-client remove <client_id>
```

Redirect URIs are matched exactly, must be `https` (`http` only for `localhost`) and can't have a `#fragment`.

**2. Send the customer to the storefront** to sign in:

```
https://<your storefront>/wp-login.php?action=waas-account-authorize
  &response_type=code
  &client_id=<client_id>
  &redirect_uri=<a registered redirect URI>
  &state=<random, checked on the way back>
  &code_challenge=<BASE64URL(SHA256(code_verifier))>
  &code_challenge_method=S256
```

The customer signs in to the storefront as usual (if they aren't already) and comes back to `redirect_uri?code=…&state=…`. The code works once, for one minute. An unknown client or redirect URI is refused on the storefront, never redirected. A request without a S256 `code_challenge` comes back with `?error=invalid_request`.

**3. Swap the code for tokens**, from the server:

```
POST https://<your storefront>/wp-json/waas-host/v1/account/token
grant_type=authorization_code&code=…&redirect_uri=…&code_verifier=…&client_id=…&client_secret=…
```

The client can send its id and secret in the body or as HTTP Basic auth.

```json
{ "access_token": "at.…", "token_type": "Bearer", "expires_in": 900, "refresh_token": "rt.…" }
```

**4. Refresh** before the access token runs out (15 minutes):

```
POST /wp-json/waas-host/v1/account/token
grant_type=refresh_token&refresh_token=…&client_id=…&client_secret=…
```

Each refresh gives a new access token **and** a new refresh token; the old ones stop working. Using an old refresh token again signs the customer out of that session (it means a copy was made), and so does trying a code a second time. A session lasts at most 90 days, and a refresh token unused for 30 days expires. Changing the password signs the customer out of every front end.

**5. Sign out**:

```
POST /wp-json/waas-host/v1/account/revoke
token=<access or refresh token>&client_id=…&client_secret=…
```

Always answers `200` with `{}`.

Token errors use the OAuth shape, `{ "error": "invalid_grant", "error_description": "…" }`: `400` `invalid_grant` (code or refresh token not valid: sign in again), `401` `invalid_client`, `400` `unsupported_grant_type`, and `429` `slow_down` with `Retry-After` after 20 failed requests in a minute from one IP. An account route called with an expired or unknown access token answers `401` with the code `waas_invalid_token`.

## Errors

Errors are JSON in WordPress's usual shape, with a message you can show to the customer as it is:

```json
{ "code": "waas_forbidden", "message": "This website is not in your account.", "data": { "status": 403 } }
```

| Status | When |
|---|---|
| `400` | The input isn't valid (`Please enter a valid domain name, like example.com.`), or the platform refused the change and said why |
| `401` | Not signed in, or the access token expired (`waas_invalid_token`) |
| `403` | The subscription isn't the customer's, or doesn't exist |
| `409` | The website isn't set up yet, isn't ready to sign in to, or doesn't need a new name |
| `429` | Too many sign-in links; wait for `Retry-After` seconds |
| `500` | Signing in isn't available for this website; the customer should contact support |
| `502` | The platform couldn't be reached; try again later |
| `503` | The shop has no supported cart active, or the website name couldn't be checked right now |

Every website route first checks that the subscription belongs to the signed-in customer. Responses are sent with `Cache-Control: no-store`.

## Routes

### `GET /me`

Who is signed in.

```json
{ "id": 7, "email": "pat@example.com", "name": "Pat" }
```

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
      "manage_plan_url": "https://shop.example.com/account/subscriptions/42",
      "buy_emails_url": "https://shop.example.com/?fluent-cart=instant_checkout&item_id=31&quantity=1&waas_website=42"
    }
  ],
  "custom_address_records": {
    "apex": [{ "type": "A", "value": "203.0.113.10" }],
    "subdomain": [{ "type": "CNAME", "value": "stores.example.net" }]
  }
}
```

- `status` is the website's state (`Queued`, `Provisioning`, `Ready`, `Suspended`, `Needs New Name`, …); show `status_label` to the customer.
- `active_until` is set when the subscription is cancelled and the website stays online until the end of the paid period, else `null`.
- `can_log_in`: the website is ready, so `POST /websites/{id}/login` works.
- `needs_new_name`: the website name was taken; ask for a new one with `POST /websites/{id}/name`.
- `buy_emails_url`: checkout for an email pack for this website (the pack with the fewest emails); send the customer there to buy more emails. `null` when the shop sells no email pack (FluentCart only) or the website has no store yet. See [Email packs](../README.md#email-packs).
- `manage_plan_url`: the cart's own page to change or cancel the plan.
- `custom_address_records`: the DNS records the customer adds before using their own domain as a website's address (the same for every website). `apex` for a bare domain (`example.com`: add each record), `subdomain` for one like `shop.example.com`. `null` when the storefront has no DNS settings: custom domains then go through support.

### `POST /websites/{id}/login`

A fresh one-time sign-in link to the website's WordPress admin. Open it right away: it works once and expires after `expires_in` seconds. Make a new one on each click; never put one in a page ahead of time.

```json
{ "url": "https://apex.example.com/wp-json/waas-client/v1/single_login/verify?token=…", "expires_in": 300 }
```

Limited to 10 links a minute per customer (`429` after that). `409` while the website isn't ready.

### `PUT /websites/{id}/address`

Makes the customer's own domain the website's address. Body: `{ "domain": "shop.example.com" }`. The customer adds the DNS records in `custom_address_records` (from `GET /websites`) first. The old address keeps working for five minutes.

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
