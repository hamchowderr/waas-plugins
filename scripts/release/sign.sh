#!/usr/bin/env bash
# Prints the base64 ed25519 signature of a release, as the plugins' Updater checks it:
# the signed message is "waas-release-v1\n<slug>\n<version>\n<sha256 hex>".
#
#   scripts/release/sign.sh <slug> <version> <sha256> <private key PEM file>
#
# The key is an OpenSSL ed25519 private key (openssl genpkey -algorithm ed25519). Needs OpenSSL 3.
set -euo pipefail

if [ $# -ne 4 ]; then
  echo "usage: $0 <slug> <version> <sha256> <private key PEM file>" >&2
  exit 2
fi
slug=$1 version=$2 sha256=$3 key=$4

message=$(mktemp)
trap 'rm -f "$message"' EXIT
printf 'waas-release-v1\n%s\n%s\n%s' "$slug" "$version" "$sha256" > "$message"
openssl pkeyutl -sign -rawin -inkey "$key" -in "$message" | openssl base64 -A
echo
