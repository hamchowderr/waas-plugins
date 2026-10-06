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

## Building

Run the following command to build the plugins

```shell
bash scripts/build.sh
```
