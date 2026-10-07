#!/usr/bin/env bash
# Prints a release's manifest.json, the form the provisioning API reads (src/releases.ts there).
#
#   scripts/release/manifest.sh <package> <version> <zip> <signature> [<changelog file>]
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
. "$here/lib.sh"

[ $# -ge 4 ] || { echo "usage: $0 <package> <version> <zip> <signature> [<changelog file>]" >&2; exit 2; }
package_info "$1"
version=$2 zip=$3 signature=$4
changelog=""
[ -n "${5:-}" ] && [ -f "$5" ] && changelog=$(cat "$5")

# Requirements from the plugin header, when it has them.
header() { grep -E "^[[:space:]]*\*?[[:space:]]*$1:" "$root/packages/$PACKAGE/$MAIN" | head -1 | sed -E "s/.*$1:[[:space:]]*//" | tr -d '\r' || true; }

jq -n \
  --arg slug "$SLUG" \
  --arg version "$version" \
  --arg zip "releases/$SLUG/$version/$SLUG.zip" \
  --arg sha256 "$(sha256sum "$zip" | cut -d' ' -f1)" \
  --arg signature "$signature" \
  --arg requires "$(header 'Requires at least')" \
  --arg requiresPhp "$(header 'Requires PHP')" \
  --arg tested "$(header 'Tested up to')" \
  --arg releasedAt "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --arg changelog "$changelog" \
  '{slug: $slug, version: $version, zip: $zip, sha256: $sha256, signature: $signature,
    requires: $requires, requiresPhp: $requiresPhp, tested: $tested, releasedAt: $releasedAt, changelog: $changelog}
   | with_entries(select(.value != ""))'
