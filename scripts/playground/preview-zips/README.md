# Preview zips

Installable zips that the blueprints here load in WordPress Playground, built from each project's `main`
with its own `bin/build-zip.sh` (the WaaS Client zip is `packages/waas-client` without tests). They stand in for
release downloads until those versions are released, and are much lighter for a phone browser than fetching
whole repositories.

- `anypress.zip`: AnyPress theme 0.7.0 (anypress-theme `e022c74`). Every blueprint here, the PR preview and `smoke.mjs` install it from this file on `main`, so updating this zip moves them all to a new theme release
- `anypress-blocks.zip`: AnyPress Blocks, anypress-blocks `85d20c0`
- `waas-client.zip`: WaaS Client, this repository's `main`
- `waas-host.zip`: WaaS Host, built from `packages/waas-host` without tests (Composer autoloader only), for `admin-preview/blueprint.json`
- `fluent-toolkit.zip`: FluentKit 2.1.4, the copy the AnyPress store template ships (`waas-provisioning-api/templates/anypress-store/plugins`, same checksum)
