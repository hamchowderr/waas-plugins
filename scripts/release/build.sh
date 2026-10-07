#!/usr/bin/env bash
# Builds one plugin's release zip: production dependencies only, no tests, the version stamped into
# the plugin header and version constant, and one top folder named like the installed plugin.
#
#   scripts/release/build.sh <package> <version> <out dir>
#
# Writes <out dir>/<slug>.zip and prints its sha256.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
. "$here/lib.sh"

[ $# -eq 3 ] || { echo "usage: $0 <package> <version> <out dir>" >&2; exit 2; }
package_info "$1"
version=$2
valid_version "$version" || { echo "not a release version: $version" >&2; exit 1; }
mkdir -p "$3"
out=$(cd "$3" && pwd)

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
src="$work/$PACKAGE"
cp -R "$root/packages/$PACKAGE" "$src"
rm -rf "$src/vendor" "$src/tests" "$src/node_modules" "$src/data" "$src/phpunit.xml.dist" "$src/.phpunit.result.cache"
(cd "$src" && composer install --no-dev --no-interaction --no-progress --optimize-autoloader --quiet)

# Stamp the version: the header's "Version:" and "@version" lines and the *_VERSION constant.
main="$src/$MAIN"
sed -E -i.bak \
  -e "s/^([[:space:]]*\*?[[:space:]]*Version:[[:space:]]*).*/\1$version/" \
  -e "s/^([[:space:]]*\*[[:space:]]*@version[[:space:]]+).*/\1$version/" \
  -e "s/(define\([[:space:]]*'[A-Z_]+_VERSION',[[:space:]]*')[^']*(')/\1$version\2/" \
  "$main"
rm -f "$main.bak"
header=$(grep -E '^[[:space:]]*\*?[[:space:]]*Version:' "$main" | head -1 | sed -E 's/.*Version:[[:space:]]*//')
constant=$(grep -oE "define\([[:space:]]*'[A-Z_]+_VERSION',[[:space:]]*'[^']*'" "$main" | head -1 | sed -E "s/.*'([^']*)'$/\1/")
if [ "$header" != "$version" ] || [ "$constant" != "$version" ]; then
  echo "version stamp failed: header '$header', constant '$constant', wanted $version" >&2
  exit 1
fi

zip_file="$out/$SLUG.zip"
rm -f "$zip_file"
(cd "$work" && find "$PACKAGE" -type f | LC_ALL=C sort | zip -q -X -9 "$zip_file" -@)

# What a site would install: one folder, the autoloader, no dev dependencies or tests.
listing=$(unzip -Z1 "$zip_file")
bad=$(echo "$listing" | grep -v "^$PACKAGE/" || true)
[ -z "$bad" ] || { echo "files outside $PACKAGE/ in the zip: $bad" >&2; exit 1; }
echo "$listing" | grep -qx "$PACKAGE/vendor/autoload.php" || { echo "no vendor/autoload.php in the zip" >&2; exit 1; }
echo "$listing" | grep -qx "$PACKAGE/$MAIN" || { echo "no $MAIN in the zip" >&2; exit 1; }
! echo "$listing" | grep -qE "^$PACKAGE/(tests/|vendor/(phpunit|brain|mockery)/|phpunit\.xml)" || { echo "dev files in the zip" >&2; exit 1; }

sha256sum "$zip_file" | cut -d' ' -f1
