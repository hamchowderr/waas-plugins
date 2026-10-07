#!/usr/bin/env bash
# Prints the base64 public key (32 raw bytes) of an ed25519 private key PEM file: the form the
# plugins' Updater::PUBLIC_KEYS holds.
#
#   scripts/release/public-key.sh <private key PEM file>
set -euo pipefail
openssl pkey -in "$1" -pubout -outform DER | tail -c 32 | openssl base64 -A
echo
