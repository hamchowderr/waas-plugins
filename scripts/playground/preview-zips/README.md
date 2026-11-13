# Preview zips

Installable zips that the blueprints here load in WordPress Playground, built from each project's `main`
with its own `bin/build-zip.sh` (the AnyPress Client zip is `packages/anypress-client` without tests). They stand in for
release downloads until those versions are released, and are much lighter for a phone browser than fetching
whole repositories.

- `anypress.zip`: AnyPress theme 0.23.1 (its release zip). Every blueprint here, the PR preview and `smoke.mjs` install it from this file on `main`, and the Update pins workflow (`.github/pins.json`) replaces it with each new theme release within the hour
- `anypress-blocks.zip`: AnyPress Blocks 0.4.3, its release zip
- `anypress-client.zip`: AnyPress Client, this repository's `main`
- `anypress-host.zip`: AnyPress Host, built from `packages/anypress-host` without tests (Composer autoloader only), for `admin-preview/blueprint.json`
- `fluent-toolkit.zip`: FluentKit 2.1.4, the copy the AnyPress store template ships (`waas-provisioning-api/templates/anypress-store/plugins`, same checksum)
