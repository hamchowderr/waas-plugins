# Site editing API (WaaS Client)

WaaS Client 2.3.0 adds REST routes under `/wp-json/waas-client/v1/site` so an AI agent, through the AnyPress connector, can change a store's design and content. The AnyPress API calls them on the agent's behalf; nothing else can.

## Who can call it

Every request carries an `X-Waas-Signature` header made with the store's **site key**, which the API gives the store's WordPress container as the `WAAS_SITE_KEY` environment variable (32 characters or more). Without that variable every route answers 401.

```
X-Waas-Signature: t=<unix time>,n=<32 hex characters, new for each request>,s=<hex HMAC-SHA256>
```

The HMAC (key: the site key) is over these five lines joined by `\n`:

1. `t`
2. `n`
3. the HTTP method in capitals
4. the route, e.g. `/waas-client/v1/site/pages/12`, plus, when there is a query, `?` and the query sorted by name, each name and value encoded as PHP's `rawurlencode` does (`/waas-client/v1/site/patterns?category=hero&search=big%20text`)
5. the hex SHA-256 of the request body (of the empty string when there is none)

A signature is accepted within 5 minutes of `t`, and each `n` only once. Signed requests run as the store's first administrator.

## Routes

| Method | Route | What it does |
|---|---|---|
| GET | `/site` | Site name, tagline, address, theme, home page |
| GET | `/site/pages` | Pages (id, title, slug, status, link, modified) |
| POST | `/site/pages` | New page `{ title, content?, status? }`; a draft unless `status` is `publish` |
| GET | `/site/pages/{id}` | A page with its outline (sections) and block markup |
| PATCH | `/site/pages/{id}` | `{ title?, slug?, status?, content? }` |
| POST | `/site/pages/{id}/sections` | Adds `{ pattern }` or `{ content }` before section `position`, or at the end |
| PUT | `/site/pages/{id}/sections/{n}` | Replaces section n with `{ pattern }` or `{ content }` |
| DELETE | `/site/pages/{id}/sections/{n}` | Removes section n |
| GET | `/site/pages/{id}/revisions` | The last 20 revisions, each with its outline |
| POST | `/site/pages/{id}/revisions/{revision}/restore` | Puts a revision back |
| GET | `/site/patterns?category=&search=` | Patterns the Site Editor offers |
| GET | `/site/patterns/{theme}/{name}` | One pattern's block markup |
| GET | `/site/styles` | The theme's colours, fonts, font sizes and spacing, the resulting styles, and the site's own changes |
| PATCH | `/site/styles` | Merges `{ settings?, styles? }` (theme.json form) into the site's changes, as Styles in the Site Editor does |
| GET, PUT | `/site/parts/header`, `/site/parts/footer` | The header or footer as blocks; PUT `{ content }` saves the site's own copy |
| GET | `/site/menus` | Navigation menus |
| PUT | `/site/menus/{id}` | `{ content, title? }`, navigation-link blocks |
| POST | `/site/media` | Copies an image from a public https link `{ url, alt? }` into the media library |

A section is a top-level block of a page (the whitespace between blocks doesn't count). Read a page first: its outline numbers the sections from 0.

## Safety

- Content goes through WordPress's post HTML filter (`wp_kses_post`), so scripts and event attributes are removed, as for an author without `unfiltered_html`. Style changes go through `WP_Theme_JSON::remove_insecure_properties`.
- No route runs code, installs plugins or themes, or changes settings outside design and content.
- WordPress keeps a revision of every page, header, footer, menu and style change.

## Sign in with your website

A store owner can connect an AI app to just their own website. On the connector's approve page they type the website's address, and the API sends them to `wp-admin/admin.php?page=waas-connect` on that website. The link is signed with the site key (`Core\ConnectLink`): HMAC-SHA256 over `waas-connect\n<request>\n<app>\n<to>\n<write>\n<back>\n<t>`.

The page (`Features\ConnectPage`) is for administrators only; WordPress asks anyone signed out to sign in first. It names the app and what it may do. Allow or Cancel sends the owner to `back`, the API's answer URL, with `request`, `answer` (`allow`, `allow-read` or `deny`) and `s`, an HMAC-SHA256 over `waas-connect-answer\n<request>\n<answer>`.

The API then gives the app a token for this website only, which can use the site routes above and nothing else. A link that was changed, was signed by another website, or is past `t` is refused.
