#!/usr/bin/env bash
# Copies AnyPress Client's moving switches (forms, shop, CRM and the support, affiliate, community, course,
# Circle and MemberPress imports) into AnyPress Move's lib/ folder, in AnyPress Move's own namespace and text
# domain, so the two plugins never load each other's classes. lib/ is built, not kept in git: edit the
# switches in packages/anypress-client and run this again.
#
#   scripts/build-move.sh [<plugin folder>]   default: packages/anypress-move
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
client="$root/packages/anypress-client/src"
target=${1:-"$root/packages/anypress-move"}
lib="$target/lib"

rm -rf "$lib"
mkdir -p "$lib/Core"
cp -R "$client/Moving" "$client/Forms" "$client/Shop" "$client/Crm" "$lib/"
cp "$client/Core/FluentPlugin.php" "$lib/Core/"
# The HighLevel link belongs to AnyPress stores (it needs the store's site key), not to the switches.
rm -f "$lib/Crm/HighLevelLink.php"

find "$lib" -name '*.php' -print0 | while IFS= read -r -d '' file; do
  sed -i \
    -e 's/AnyPressClient\\Api\\SiteAbilities/AnyPressMove\\Category/g' \
    -e 's/\bSiteAbilities::CATEGORY\b/Category::CATEGORY/g' \
    -e 's/AnyPressClient\\/AnyPressMove\\Lib\\/g' \
    -e "s/'anypress-client'/'anypress-move'/g" \
    -e '1a\
\
// Built by scripts/build-move.sh from packages/anypress-client: edit that copy, not this one.' \
    -e '/^namespace .*;$/a\
\
if (!defined('"'"'ABSPATH'"'"')) {\
    exit;\
}' \
    "$file"
done

left=$(grep -rlE "AnyPressClient|SiteAbilities|'anypress-client'" "$lib" || true)
[ -z "$left" ] || { echo "Client names left in: $left" >&2; exit 1; }
echo "built $(find "$lib" -name '*.php' | wc -l | tr -d ' ') files into $lib"
