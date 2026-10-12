#!/usr/bin/env bash
# Tests the release scripts end to end against a fake bucket (a directory) and a throwaway key:
# build, stamp, sign, verify, manifest, publish (immutable, channels only move forward) and promote.
#
#   scripts/release/test.sh
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
pass=0
ok() { pass=$((pass + 1)); echo "ok - $1"; }
fail() { echo "FAIL - $1" >&2; exit 1; }

# A fake `aws` that keeps objects under $FAKE_S3.
mkdir -p "$work/bin" "$work/s3"
cat > "$work/bin/aws" <<'FAKE'
#!/usr/bin/env bash
args=()
while [ $# -gt 0 ]; do
  case "$1" in
    --endpoint-url|--content-type|--cache-control) shift 2 ;;
    --only-show-errors) shift ;;
    *) args+=("$1"); shift ;;
  esac
done
set -- "${args[@]}"
# Like the real CLI (botocore), refuse a key or endpoint with whitespace in it.
for v in "$AWS_ACCESS_KEY_ID" "$AWS_SECRET_ACCESS_KEY" "$R2_ENDPOINT" "$R2_BUCKET"; do
  if [[ $v =~ [[:space:]] ]]; then echo "aws: [ERROR]: Invalid header value (whitespace in a key or setting)" >&2; exit 255; fi
done
path() { echo "$FAKE_S3/${1#s3://}"; }
case "$1 $2" in
  "s3api head-object")
    [ -f "$FAKE_S3/$4/$6" ] && { echo '{}'; exit 0; }
    echo "An error occurred (404) when calling the HeadObject operation: Not Found" >&2; exit 254 ;;
  "s3 cp")
    if [[ $3 == s3://* ]]; then
      [ -f "$(path "$3")" ] || { echo "download failed: (404) Not Found" >&2; exit 1; }
      cp "$(path "$3")" "$4"
    else
      mkdir -p "$(dirname "$(path "$4")")"; cp "$3" "$(path "$4")"
    fi ;;
  *) echo "fake aws: unsupported $*" >&2; exit 2 ;;
esac
FAKE
chmod +x "$work/bin/aws"
export PATH="$work/bin:$PATH" FAKE_S3="$work/s3" R2_ENDPOINT=https://r2.invalid R2_BUCKET=waas-releases
# Saved the way `gh secret set NAME < file` saves them: with a trailing newline, and worse.
export AWS_ACCESS_KEY_ID=$'test-access-key\n' AWS_SECRET_ACCESS_KEY=$' test-secret-key \r\n'
export R2_ENDPOINT=$'https://r2.invalid\n' R2_BUCKET=$'waas-releases\n'

openssl genpkey -algorithm ed25519 -out "$work/key.pem" 2>/dev/null
public=$("$here/public-key.sh" "$work/key.pem")
openssl genpkey -algorithm ed25519 -out "$work/other.pem" 2>/dev/null
other=$("$here/public-key.sh" "$work/other.pem")

release() { # <package> <slug> <version>: build, sign, verify and write a manifest into $work/dist-<version>
  local dir="$work/dist-$3" sha sig
  sha=$("$here/build.sh" "$1" "$3" "$dir" | tail -1)
  sig=$("$here/sign.sh" "$2" "$3" "$sha" "$work/key.pem")
  php "$here/verify.php" "$2" "$3" "$dir/$2.zip" "$sig" "$public" > /dev/null
  echo "* Release $3" > "$work/changelog"
  "$here/manifest.sh" "$1" "$3" "$dir/$2.zip" "$sig" "$work/changelog" > "$dir/manifest.json"
}
bucket() { cat "$FAKE_S3/waas-releases/releases/waas-payments/$1"; }

# Build and stamp.
release waas-payments waas-payments 0.2.0
zip="$work/dist-0.2.0/waas-payments.zip"
main=$(unzip -p "$zip" waas-payments/waas-payments.php)
grep -q '^ \* Version: 0.2.0$' <<<"$main" && grep -q "define('WAAS_PAYMENTS_VERSION', '0.2.0');" <<<"$main" || fail "version not stamped"
ok "the version is stamped into the header and the constant"
unzip -Z1 "$zip" | grep -qE '^waas-payments/(tests/|vendor/phpunit/)' && fail "dev files in the zip"
unzip -Z1 "$zip" | grep -qx 'waas-payments/src/Updater/Updater.php' || fail "updater missing"
ok "the zip has one folder, production dependencies, the updater, no tests"
for package in waas-host waas-client; do
  "$here/build.sh" "$package" 9.9.9 "$work/other-builds" > /dev/null
done
unzip -p "$work/other-builds/wpcs-waas-host.zip" waas-host/index.php | grep -q "define( 'WAAS_HOST_VERSION', '9.9.9' );" || fail "waas-host not stamped"
unzip -p "$work/other-builds/wpcs-waas-host.zip" waas-host/index.php | grep -q '^ \* @version 9.9.9$' || fail "waas-host @version not stamped"
unzip -p "$work/other-builds/wpcs-waas-client.zip" waas-client/index.php | grep -q "define( 'WAAS_CLIENT_VERSION', '9.9.9' );" || fail "waas-client not stamped"
ok "waas-host and waas-client build and stamp too"
"$here/build.sh" waas-payments latest "$work/x" 2>/dev/null && fail "accepted a bad version"
ok "a bad version is refused"

# Signatures.
sig=$(jq -r .signature "$work/dist-0.2.0/manifest.json")
php "$here/verify.php" waas-payments 0.2.0 "$zip" "$sig" "$other" 2>/dev/null && fail "verified with the wrong key"
php "$here/verify.php" waas-payments 0.2.1 "$zip" "$sig" "$public" 2>/dev/null && fail "verified another version"
php "$here/verify.php" waas-payments 0.2.0 "$zip" "$sig" 2>/dev/null && fail "verified with no trusted keys"
ok "signatures verify only with the right key, version and trusted keys"

# Manifest.
m="$work/dist-0.2.0/manifest.json"
[ "$(jq -r '.slug + " " + .version + " " + .zip + " " + .requiresPhp + " " + .changelog' "$m")" = "waas-payments 0.2.0 releases/waas-payments/0.2.0/waas-payments.zip 7.4 * Release 0.2.0" ] || fail "manifest: $(cat "$m")"
[ "$(jq -r .sha256 "$m")" = "$(sha256sum "$zip" | cut -d' ' -f1)" ] || fail "manifest checksum"
ok "the manifest has what the API reads"

# Publish.
( . "$here/lib.sh"; s3 s3api head-object --bucket "$R2_BUCKET" --key x ) 2>/dev/null && fail "the fake aws accepted a key with a newline"
out=$("$here/publish.sh" waas-payments "$work/dist-0.2.0" 2>&1) || fail "publish with newline-terminated secrets: $out"
grep -q 'test-access-key\|test-secret-key' <<<"$out" && fail "publish printed a key"
ok "keys and settings with trailing newlines and spaces are cleaned, and never printed"
cmp -s "$zip" "$FAKE_S3/waas-releases/releases/waas-payments/0.2.0/waas-payments.zip" || fail "zip not uploaded"
[ "$(bucket channels/stable.json | jq -r .version)" = 0.2.0 ] && [ "$(bucket channels/beta.json | jq -r .version)" = 0.2.0 ] || fail "channels after 0.2.0"
ok "a stable release goes to stable and beta"
"$here/publish.sh" waas-payments "$work/dist-0.2.0" 2>/dev/null && fail "re-published 0.2.0"
ok "a released version is never overwritten"
release waas-payments waas-payments 0.3.0-beta.1
"$here/publish.sh" waas-payments "$work/dist-0.3.0-beta.1" > /dev/null
[ "$(bucket channels/stable.json | jq -r .version)" = 0.2.0 ] && [ "$(bucket channels/beta.json | jq -r .version)" = 0.3.0-beta.1 ] || fail "channels after the beta"
ok "a beta goes to beta only"
release waas-payments waas-payments 0.2.1
"$here/publish.sh" waas-payments "$work/dist-0.2.1" > /dev/null
[ "$(bucket channels/stable.json | jq -r .version)" = 0.2.1 ] && [ "$(bucket channels/beta.json | jq -r .version)" = 0.3.0-beta.1 ] || fail "channels after 0.2.1"
ok "a patch moves stable but not a newer beta back"
jq '.sha256 = "0000000000000000000000000000000000000000000000000000000000000000"' "$work/dist-0.2.1/manifest.json" > "$work/dist-0.2.1/m2" && mv "$work/dist-0.2.1/m2" "$work/dist-0.2.1/manifest.json"
"$here/publish.sh" waas-payments "$work/dist-0.2.1" 2>/dev/null && fail "published a zip that doesn't match its manifest"
ok "a zip that doesn't match its manifest is refused"

# Promote.
"$here/promote.sh" waas-payments 0.3.0-beta.1 2>/dev/null && fail "promoted without trusted keys"
WAAS_RELEASE_TEST_PUBLIC_KEYS="$other" "$here/promote.sh" waas-payments 0.3.0-beta.1 2>/dev/null && fail "promoted with an untrusted signature"
[ "$(bucket channels/stable.json | jq -r .version)" = 0.2.1 ] || fail "stable moved on a refused promote"
ok "promote refuses a release the trusted keys didn't sign"
WAAS_RELEASE_TEST_PUBLIC_KEYS="$other $public" "$here/promote.sh" waas-payments 0.3.0-beta.1 > /dev/null
[ "$(bucket channels/stable.json | jq -r .version)" = 0.3.0-beta.1 ] || fail "promote"
ok "promote points stable at a tested release"
WAAS_RELEASE_TEST_PUBLIC_KEYS="$public" "$here/promote.sh" waas-payments 0.2.0 > /dev/null
[ "$(bucket channels/stable.json | jq -r .version)" = 0.3.0-beta.1 ] || fail "promote moved stable back"
ok "promote never moves a channel back"
"$here/promote.sh" waas-payments 9.9.9 2>/dev/null && fail "promoted a missing release"
ok "promote refuses a release that isn't there"

echo "$pass passed"
