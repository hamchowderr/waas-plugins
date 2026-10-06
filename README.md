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

Work is tracked in [beads](https://github.com/steveyegge/beads) (prefix `waas`). Its Dolt remote is this repo's GitHub origin; set `DOLT_REMOTE_INFO_BRANCH` to a blank value before `bd dolt push` so Dolt doesn't push an extra branch.

`.beads/issues.jsonl` is also committed, because Claude Code cloud sessions can't push Dolt's refs to GitHub (the session proxy only allows branch pushes). Rebuild from it on a fresh clone with no Dolt data:

```shell
npm install -g @beads/bd
bd init --prefix waas --skip-agents --skip-hooks --from-jsonl
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

## Building

Run the following command to build the plugins

```shell
bash scripts/build.sh
```
