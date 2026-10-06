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

The plan lives in [beads](https://github.com/steveyegge/beads): `.beads/issues.jsonl` is the saved copy, committed with the code. The beads database itself is local and not committed.

```shell
npm install -g @beads/bd
bd init --prefix waas --skip-agents --skip-hooks --from-jsonl   # fresh clone: rebuild from issues.jsonl
bd ready                                                        # what can be worked on now
bd export -o .beads/issues.jsonl                                # before committing tracker changes
```

`bd init` adds this repo as a Dolt remote and commits that change; drop that commit. Beads only pushes to the remote on `bd dolt push` or `bd sync`, so don't run those.

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
