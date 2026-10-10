#!/usr/bin/env bash
# Uploads a built and signed release to the releases bucket and moves its channel forward.
# Releases are immutable: a version that is already there is never overwritten.
#
#   scripts/release/publish.sh <package> <dir with <slug>.zip and manifest.json>
#
# Needs R2_ENDPOINT, R2_BUCKET, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY (a token for that bucket).
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
. "$here/lib.sh"

[ $# -eq 2 ] || { echo "usage: $0 <package> <dir>" >&2; exit 2; }
package_info "$1"
dir=$2
clean_r2_env
: "${R2_ENDPOINT:?R2_ENDPOINT is not set}" "${R2_BUCKET:?R2_BUCKET is not set}"

manifest="$dir/manifest.json"
version=$(jq -r .version "$manifest")
[ "$(jq -r .slug "$manifest")" = "$SLUG" ] || { echo "manifest is not for $SLUG" >&2; exit 1; }
valid_version "$version" || { echo "bad version in manifest: $version" >&2; exit 1; }
[ "$(sha256sum "$dir/$SLUG.zip" | cut -d' ' -f1)" = "$(jq -r .sha256 "$manifest")" ] || { echo "zip does not match the manifest" >&2; exit 1; }

prefix="releases/$SLUG/$version"
if object_exists "$prefix/manifest.json"; then
  echo "$SLUG $version is already released; tag a new version" >&2
  exit 1
fi
put_object "$dir/$SLUG.zip" "$prefix/$SLUG.zip" application/zip
put_object "$manifest" "$prefix/manifest.json" application/json
echo "uploaded $prefix"

advance_channel "$SLUG" "$(channel_of "$version")" "$manifest"
[ "$(channel_of "$version")" = stable ] && advance_channel "$SLUG" beta "$manifest"
exit 0
