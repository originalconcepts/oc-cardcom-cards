#!/usr/bin/env bash
# Build the release zip the OC Pluginz hub serves as the update package.
#
# The hub reads the version from oc-cardcom-cards/oc-cardcom-cards.php inside the zip,
# and WordPress replaces the installed folder with the zip's top folder, so the
# top folder MUST be oc-cardcom-cards/.
#
# Usage: bash bin/build-zip.sh            → dist/oc-cardcom-cards.zip
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="oc-cardcom-cards"
out="$root/dist/$slug.zip"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/$slug" "$root/dist"

rsync -a "$root/" "$stage/$slug/" \
  --exclude '.git' --exclude '.github' --exclude 'bin' --exclude 'dist' --exclude 'docs' \
  --exclude '.gitignore' --exclude '.gitattributes' --exclude '*.zip' --exclude '*.bak' \
  --exclude '.DS_Store' --exclude 'Thumbs.db'

rm -f "$out"
( cd "$stage" && zip -qr "$out" "$slug" -x '*/.*' )

version="$(grep -m1 -E '^\s*\*\s*Version:' "$root/$slug.php" | sed -E 's/.*Version:\s*//' | tr -d '\r')"
echo "built $out (version $version)"
unzip -l "$out"
