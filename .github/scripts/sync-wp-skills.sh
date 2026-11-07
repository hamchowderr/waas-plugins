#!/usr/bin/env bash
# Refreshes .claude/skills from WordPress/agent-skills (GPL-2.0-or-later).
# Usage: .github/scripts/sync-wp-skills.sh [path-to-agent-skills-checkout]
# Without a path it clones the latest main into a temporary folder.
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
dest="$root/.claude/skills"
src="${1:-}"; [ -n "$src" ] && src="$(cd "$src" && pwd)"
if [ -z "$src" ]; then
  src="$(mktemp -d)/agent-skills"
  git clone --quiet --depth 1 https://github.com/WordPress/agent-skills.git "$src"
fi

(cd "$src" && node shared/scripts/skillpack-build.mjs --clean --targets=claude >/dev/null)
built="$src/dist/claude/.claude/skills"
commit="$(git -C "$src" rev-parse --short=8 HEAD)"

# Replace every upstream skill; leave README.md and any skill of our own alone.
for dir in "$built"/*/; do
  name="$(basename "$dir")"
  rm -rf "${dest:?}/$name"
  cp -R "$dir" "$dest/$name"
done

# Skills removed upstream: listed, not deleted, so a person decides.
removed=""
for dir in "$dest"/*/; do
  name="$(basename "$dir")"
  if [ ! -d "$built/$name" ] && grep -q "| \`$name\` |" "$dest/README.md"; then
    removed="$removed $name"
  fi
done

sed -i -E "s/taken at commit \`[0-9a-f]+\`/taken at commit \`$commit\`/" "$dest/README.md"

added=""
for dir in "$built"/*/; do
  name="$(basename "$dir")"
  grep -q "| \`$name\` |" "$dest/README.md" || added="$added $name"
done

echo "commit=$commit"
echo "added=${added# }"
echo "removed=${removed# }"
