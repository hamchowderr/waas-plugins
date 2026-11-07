# AnyPress plugins: agent guide

WordPress plugins for AnyPress, one Composer package each under `packages/`:

| Package | Runs on | Notes |
|---|---|---|
| `anypress-host` | The storefront (a seller's WordPress shop, e.g. shop.anypress.io) | Sells stores on FluentCart (main route) or WooCommerce; talks to the provisioning API |
| `anypress-client` | Every customer store, via the store template | One-click login, plan roles, the site-editing API and its WordPress abilities |
| `anypress-payments` | The storefront | Authorize.Net card payments for FluentCart |
| `anypress-export` | Any WordPress site being moved to AnyPress | Exports the site to an import code |
| `anypress-updater` | Shared | `scripts/sync-updater.sh` copies it into host and payments; never edit those copies |

How they fit together: `docs/architecture/`. Releases and the update channel: `docs/releases.md`.

## Building and testing

- In the package you change: `composer install`, then `composer test -- --exclude-group mock` (the `mock` group needs network). CI runs PHP 8.1 and 8.3, so keep code valid on 8.1.
- `scripts/playground`: `npm ci`, then `npm run smoke` (AnyPress Client's site-editing API and abilities on WordPress Playground with the AnyPress theme), `npm run export` (AnyPress Export end to end), `npm run forms` (AnyPress Client's forms switch from Contact Form 7, WPForms and Ninja Forms to Fluent Forms), `npm run shop-switch` (AnyPress Client's shop switch from WooCommerce to FluentCart, with HPOS off and on), `npm run crm-switch` (AnyPress Client's CRM switch: WooCommerce buyers, MailPoet and Newsletter subscribers into FluentCRM, with HPOS off and on), `npm run moving` (AnyPress Client's support, affiliate and community switches: Awesome Support, SliceWP and BuddyPress into Fluent Support, FluentAffiliate and FluentCommunity), `npm run phone` (AnyPress Client's phone receptionist routes: FluentBooking times and bookings, callers into FluentCRM) and `npm run shop` (a copy of shop.anypress.io with its design from `scripts/test-storefront/remote/design`; `-- --shots` screenshots every page). Playground uses SQLite; plugins that need MySQL warn there but most still work.
- `scripts/test-env.sh` and `docker-compose.yml`: a full local storefront with the Host plugin.
- After changing `packages/anypress-updater`, run `scripts/sync-updater.sh`; CI fails when the copies drift.

## Releasing

Merge to `main`, then run the **Tag and release plugin** workflow (`tag-release.yml`) with the tag and changelog, from the session (GitHub `actions_run_trigger`). Tags: `anypress-host-vX.Y.Z`, `anypress-client-vX.Y.Z`, `anypress-payments-vX.Y.Z`, `anypress-export-vX.Y.Z`; `-beta.N` goes to the beta channel only. Bump the plugin header and its version constant in the same PR as the change.

AnyPress Client never updates itself in stores: a new release reaches stores only through a new store template version built from `waas-provisioning-api/templates/anypress-store`.

## Building for WordPress

- Use the WordPress skills in `.claude/skills/` (list in its README): `wp-plugin-development` for structure and security, `wp-rest-api` for routes, `wp-abilities-api` and `wp-abilities-verify` for abilities, `wp-playground` and `blueprint` for tests and previews, `wp-phpstan` for static analysis.
- Stores run WordPress 7.1 on PHP 8.3 with the **AnyPress** block theme (`hamchowderr/anypress-theme`; stores made before it run Ollie). Anything AnyPress Client adds to a store's pages must be block markup that uses the theme's patterns and its `theme.json` colour, font and spacing slugs, not fixed values (`wp-block-themes`, `wp-patterns`). Store pages are edited in WordPress, never headless.
- Every Playground blueprint and preview installs the AnyPress theme from `scripts/playground/preview-zips/anypress.zip` (kept at the latest theme release), never Ollie or Twenty Twenty-Five.
- Every write route checks a capability or the request signature, every input is sanitized and every output escaped. Site-editing writes keep a revision so they can be undone.
- Sites must keep working when the API can't be reached: cache answers, fail quietly in the front end, and show admins a plain notice.

## Rules for every change

- **Playground only for tests.** Never create a store, product (seller), reseller or ready-made storefront on the server to try, check or demo something. Check on WordPress Playground (the WordPress skills in `.claude/skills/`, and the plugins repo's `scripts/playground`). The API's own tests cover the server building stores. If a check truly can't run on Playground, ask the owner first, say why, and delete the store as soon as the check is done. Never keep test stores.
- **Beads, every time.** Each piece of work gets a Beads issue before it starts (check for a duplicate first). Put the id in the branch, the PR and the status, and close the issue when the PR merges or the change is live. Run `bd` in the session doing the work; the SessionStart hook in `.claude/settings.json` loads the Dolt login, then `bd dolt push` syncs it. This repository's issues are in DoltHub `chowderr/waas (ids `anypress-*`)`.
- **Pull requests:** open them ready for review, not as drafts, so CI runs. Merge a PR yourself once CI is green and the work was asked for.
- **No "WPCS" wording.** The old platform is gone. Say "the AnyPress API" or "the API's key and secret login". Don't add the word anywhere.
- **Public docs are for users.** READMEs and `docs/` explain how to use and run the code. Internal notes (server and Coolify ids, Beads, deployment steps) go in the obsidian-vault repository under `1. Projects/AnyPress/`. Never put how or why AnyPress started in public copy.
- **Verify before claiming.** Check npm, wordpress.org or the official docs before calling a version or spec "the latest", and check a fix on the running system before calling it fixed.
