# Releases and updates

The plugins update from one channel only: the provisioning API they are connected to, which serves releases from its release bucket (`RELEASES_S3_BUCKET`). Nothing comes from GitHub, wordpress.org or the upstream Daxez/waas-wpcs.

| Plugin | Slug | Updates |
|---|---|---|
| waas-host | `wpcs-waas-host` | Storefront, from the channel. An admin clicks Update. |
| waas-payments | `waas-payments` | Storefront, from the channel, with waas-host's API credentials. |
| waas-client | `wpcs-waas-client` | **Never by itself.** It comes with the store's template version; a store gets a newer one by moving to a newer version (`moveTenantToAVersion`). Its release zips are for template builds. |

## How a site updates

`packages/waas-updater/src/Updater.php` is the updater. `scripts/sync-updater.sh` copies it into waas-host and waas-payments, each in its own namespace (`src/Updater/Updater.php`; don't edit those copies). CI fails when a copy is out of date.

1. Every 12 hours (WordPress's update check), or on **Dashboard > Updates > Check again**, the plugin asks `GET /v1/plugins/<slug>/update` with the storefront's API key and secret, its version, channel, site URL and WordPress and PHP versions. The API records them.
2. The answer is cached for 12 hours, a failure for 1 hour. If the channel can't be reached, nothing is offered and nothing changes.
3. WordPress offers the update when the release is newer than the installed version. Its update data holds `waas-update://<slug>/<version>`, not a download link.
4. On **Update**, the plugin asks the API again for a fresh 10-minute download link, downloads the zip, and checks its sha256 and the ed25519 signature of `waas-release-v1\n<slug>\n<version>\n<sha256>` against `Updater::PUBLIC_KEYS`. Any mismatch stops the update before the installed plugin is touched.
5. The zip's folder is renamed to the installed folder if they differ, so the plugin stays active.

Settings in `wp-config.php`:

- `WAAS_UPDATE_CHANNEL`: `stable` (default), `beta`, or `off`.
- `WAAS_AUTO_UPDATE`: `patch` installs x.y.Z updates of the same x.y automatically. Otherwise an admin installs every update.

`Updater::PUBLIC_KEYS` holds AnyPress's release key (its private half is this repository's `WAAS_RELEASE_SIGNING_KEY` secret). With an empty list the updater registers nothing, since there would be nothing it could verify. Until the API has `RELEASES_S3_BUCKET`, every check gets 503 "not configured": the plugin caches that for an hour, offers nothing and shows no error.

## Releasing

1. Merge to `main`.
2. Tag the commit and push the tag: `waas-host-v2.3.0`, `waas-payments-v0.2.0`, `waas-client-v2.3.0`. A `-beta.N` suffix (`waas-host-v2.4.0-beta.1`) publishes to the beta channel only. An annotated tag's message becomes the changelog.
3. The **Release plugin** workflow builds the zip (`composer install --no-dev`, version stamped from the tag into the header and the version constant, no tests), signs it, checks the signature against the keys the plugins trust, and uploads `releases/<slug>/<version>/<slug>.zip` and `manifest.json`. Then it moves the channel forward: a stable release also moves beta, and a channel never moves back to an older release. A version that is already in the bucket is never overwritten.
4. To give a tested beta to stable sites without rebuilding it: **Promote release** (package, version).

Run **Release plugin** by hand with a tag name for a dry run: it builds the selected branch, signs with a throwaway key, verifies, keeps the zip as a workflow artifact, and uploads nothing. `scripts/release/test.sh` runs the whole flow locally against a fake bucket.

To hold one storefront at a release: `PUT /admin/products/<productId>/plugins/<slug>/pin` on the API (see its README). A pin never downgrades a site.

## One-time setup (your own release key)

To publish releases to your own API (for example from a fork), make your own key. Only the person who makes it keeps the private half.

1. Make the key pair on your own machine, with OpenSSL 3 (Linux, WSL, Git Bash; on macOS use Homebrew's `openssl@3`, not the system LibreSSL). This writes the private key to `waas-release-signing.pem` and prints the public key:

   ```sh
   openssl genpkey -algorithm ed25519 -out waas-release-signing.pem && openssl pkey -in waas-release-signing.pem -pubout -outform DER | tail -c 32 | openssl base64 -A; echo
   ```

2. Add the **whole PEM file** (including the `-----BEGIN PRIVATE KEY-----` and `-----END PRIVATE KEY-----` lines) as the repository's GitHub Actions secret `WAAS_RELEASE_SIGNING_KEY`, for example `gh secret set WAAS_RELEASE_SIGNING_KEY -R <owner>/<repo> < waas-release-signing.pem`. Keep the file offline, or delete it once the secret is set and accept that losing the secret means rotating the key.
3. Put the printed public key (44 characters ending in `=`) into `Updater::PUBLIC_KEYS` and run `scripts/sync-updater.sh`; releases built after that verify.
4. Create an R2 API token with **Object Read & Write** on your releases bucket only, and add it as the secrets `R2_RELEASES_ACCESS_KEY_ID` and `R2_RELEASES_SECRET_ACCESS_KEY`. Add the repository variables `R2_ENDPOINT` = `https://<account id>.r2.cloudflarestorage.com` and `R2_RELEASES_BUCKET` = your bucket name.
5. The API needs read access to the bucket: `RELEASES_S3_BUCKET` and a read token (`RELEASES_S3_ACCESS_KEY_ID`, `RELEASES_S3_SECRET_ACCESS_KEY`) in its environment.

Rotating the key: add the new public key next to the old one in `PUBLIC_KEYS` and release that, switch the secret to the new private key, and remove the old public key a release later.
