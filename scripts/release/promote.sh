#!/usr/bin/env bash
# Points the stable channel at a release that is already in the bucket (usually a beta that was
# tested), without rebuilding it. The zip is downloaded and checked against its manifest and the
# keys the plugins trust first.
#
#   scripts/release/promote.sh <package> <version>
#
# Needs R2_ENDPOINT, R2_BUCKET, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
. "$here/lib.sh"

[ $# -eq 2 ] || { echo "usage: $0 <package> <version>" >&2; exit 2; }
package_info "$1"
version=$2
valid_version "$version" || { echo "not a release version: $version" >&2; exit 1; }
: "${R2_ENDPOINT:?R2_ENDPOINT is not set}" "${R2_BUCKET:?R2_BUCKET is not set}"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
prefix="releases/$SLUG/$version"
object_exists "$prefix/manifest.json" || { echo "$SLUG $version is not released" >&2; exit 1; }
get_object "$prefix/manifest.json" "$work/manifest.json"
get_object "$prefix/$SLUG.zip" "$work/$SLUG.zip"

[ "$(jq -r .version "$work/manifest.json")" = "$version" ] || { echo "manifest version mismatch" >&2; exit 1; }
[ "$(sha256sum "$work/$SLUG.zip" | cut -d' ' -f1)" = "$(jq -r .sha256 "$work/manifest.json")" ] || { echo "zip does not match its manifest" >&2; exit 1; }
php "$here/verify.php" "$SLUG" "$version" "$work/$SLUG.zip" "$(jq -r .signature "$work/manifest.json")"

advance_channel "$SLUG" stable "$work/manifest.json"
advance_channel "$SLUG" beta "$work/manifest.json"
