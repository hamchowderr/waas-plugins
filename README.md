# WaaS Host & Client Plugins

Two plugins used to create a communication between a host and multiple tenants in a multi-tenant WordPress setup.

## Features

- Tenant Creation
- Subscription Management
- Role-based Plugins Activation
- Adding new Add-ons
- Changing Domains
- Root-Domain for created tenants
- Tenants domains
- Single-Sign On

## Work tracking (beads)

Work is tracked in [beads](https://github.com/steveyegge/beads) (prefix `waas`). The issue database syncs through a private DoltHub database, [`chowderr/waas`](https://www.dolthub.com/repositories/chowderr/waas): `bd dolt push` after changes, `bd dolt pull` to get others'. `.beads/issues.jsonl` is a local export (kept fresh by `export.auto`) and is not committed.

On a fresh clone, with DoltHub credentials in `~/.dolt/creds` (`dolt creds import`, or the JWK the cloud environment writes):

```shell
npm install -g @beads/bd
bd init --prefix waas --skip-agents --skip-hooks --remote chowderr/waas
bd dolt pull
bd ready
```

## Local Development

### Required tools

- Docker and Docker-Compose
- Composer
- `fswarch` & `rsync`

### Steps

- `git clone` the project
- Run `composer install` inside `src` directory
- Run to create dist directories  `mkdir dist && mkdir dist/host && mkdir dist/client`
- Run `docker-compose up`
- Run the following command to sync files between src and dist

## Test environment

`scripts/test-env.sh up` starts a throwaway WordPress (MariaDB + WordPress in Docker) at http://127.0.0.1 with this checkout's Host plugin, FluentCart, WooCommerce and Subscriptions for WooCommerce active. Log in as admin / admin. `scripts/test-env.sh wp ...` runs WP-CLI; `scripts/test-env.sh down` removes it. It works in Claude Code cloud sessions (it starts Docker and routes WordPress's outbound calls through the session proxy).

## Storefront secrets

Each store's one-click-login private key is stored encrypted on its subscription. The encryption key comes from `wp-config.php` on the storefront:

```php
// A long random string, e.g. the output of: openssl rand -base64 48
define('WAAS_HOST_KEY_ENCRYPTION_KEY', '...');
```

Without the constant, WordPress's auth salt is used instead. Either way, keep it stable: changing it makes the stored keys unreadable and one-click login stops working for existing stores. Keys saved by older versions in plain text are encrypted the first time they're used.

## Tests

Both plugins have PHPUnit unit tests that run without WordPress: [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) stubs the WordPress functions and outside calls are mocked. They need PHP 8.1 or newer.

```shell
cd packages/waas-host   # or packages/waas-client
composer install
composer test
```

Tests live in each package's `tests/Unit`. The `Tests` GitHub Actions workflow runs both suites on every pull request and on pushes to `main`. Release builds strip `tests/` and `phpunit.xml.dist`.

## Building

Run the following command to build the plugins

```shell
bash scripts/build.sh
```
