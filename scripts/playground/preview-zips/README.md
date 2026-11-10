# Preview zips

Installable zips that the blueprints here load in WordPress Playground, built from each project's `main`
with its own `bin/build-zip.sh` (the AnyPress Client zip is `packages/anypress-client` without tests). They stand in for
release downloads until those versions are released, and are much lighter for a phone browser than fetching
whole repositories.

- `anypress.zip`: AnyPress theme 0.20.0 (anypress-theme `f3cbbd2`). Every blueprint here, the PR preview and `smoke.mjs` install it from this file on `main`, so updating this zip moves them all to a new theme release
- `anypress-blocks.zip`: AnyPress Blocks 0.4.2, anypress-blocks `e9f8e4c`
- `anypress-client.zip`: AnyPress Client, this repository's `main`
- `anypress-host.zip`: AnyPress Host, built from `packages/anypress-host` without tests (Composer autoloader only), for `admin-preview/blueprint.json`
- `fluent-toolkit.zip`: FluentKit 2.1.4, the copy the AnyPress store template ships (`waas-provisioning-api/templates/anypress-store/plugins`, same checksum)
