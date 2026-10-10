# Shared by the release scripts. Source it; don't run it.

# Sets PACKAGE, SLUG, MAIN (main plugin file) for a package directory name. Fails on anything else.
package_info() {
  PACKAGE=$1
  case "$PACKAGE" in
    waas-host)     SLUG=wpcs-waas-host;   MAIN=index.php ;;
    waas-client)   SLUG=wpcs-waas-client; MAIN=index.php ;;
    waas-payments) SLUG=waas-payments;    MAIN=waas-payments.php ;;
    *) echo "unknown package: $PACKAGE (waas-host, waas-client or waas-payments)" >&2; return 1 ;;
  esac
}

# Release versions: 1.2.3 (stable) or 1.2.3-beta.4 (beta only). Same rule as the API and the Updater.
valid_version() {
  [[ $1 =~ ^[0-9]+\.[0-9]+\.[0-9]+(-beta\.[0-9]+)?$ ]]
}

channel_of() {
  if [[ $1 == *-beta.* ]]; then echo beta; else echo stable; fi
}

# Exit status 0 when version $1 is newer than $2 (PHP's version_compare, as WordPress compares them).
newer_than() {
  php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$1" "$2"
}

# --- The releases bucket (R2, through the AWS CLI). Needs R2_ENDPOINT, R2_BUCKET and the AWS_* key.

# Strips all whitespace, line breaks included, from the bucket settings and the R2 key. A secret
# saved with a trailing newline (gh secret set NAME < file) otherwise breaks the request signature:
# "Invalid header value ... Credential=...\n/...". Never prints the values.
clean_r2_env() {
  local name value
  for name in R2_ENDPOINT R2_BUCKET AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY; do
    value=${!name-}
    printf -v "$name" '%s' "${value//[[:space:]]/}"
    export "${name?}"
  done
}

s3() { aws --endpoint-url "$R2_ENDPOINT" "$@"; }

# Exit status 0 when the object exists, 1 when it doesn't; any other error stops the script.
object_exists() {
  local out
  if out=$(s3 s3api head-object --bucket "$R2_BUCKET" --key "$1" 2>&1); then return 0; fi
  if grep -qE '404|Not Found|NoSuchKey' <<<"$out"; then return 1; fi
  echo "checking s3://$R2_BUCKET/$1 failed: $out" >&2
  exit 1
}

get_object() { # <key> <file>
  s3 s3 cp "s3://$R2_BUCKET/$1" "$2" --only-show-errors
}

put_object() { # <file> <key> <content type>
  s3 s3 cp "$1" "s3://$R2_BUCKET/$2" --content-type "$3" --cache-control no-cache --only-show-errors
}

# Points releases/<slug>/channels/<channel>.json at a manifest, only forward: a channel already at a
# newer release keeps it. A stable release also moves beta forward, so beta sites don't stay on an
# older pre-release.
advance_channel() { # <slug> <channel> <manifest file>
  local slug=$1 channel=$2 manifest=$3 version current key tmp
  version=$(jq -r .version "$manifest")
  key="releases/$slug/channels/$channel.json"
  tmp=$(mktemp)
  if object_exists "$key"; then
    get_object "$key" "$tmp"
    current=$(jq -r .version "$tmp")
    if ! newer_than "$version" "$current"; then
      echo "$channel stays at $current ($version is not newer)"
      rm -f "$tmp"
      return 0
    fi
  fi
  rm -f "$tmp"
  put_object "$manifest" "$key" application/json
  echo "$channel -> $version"
}
