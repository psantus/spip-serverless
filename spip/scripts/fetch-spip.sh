#!/usr/bin/env bash
# Populate the (git-ignored) SPIP core into spip/src/ for LOCAL development:
# IDE indexing, running plugins against core, `make run-local`.
#
# SPIP core is NOT committed to this repo. At build time the Dockerfile fetches it
# itself (see the `spip-core` stage). This script mirrors that fetch on disk so your
# editor and local tooling can see the core sources.
#
# Usage:
#   spip/scripts/fetch-spip.sh            # uses the pinned version in spip/SPIP_VERSION
#   spip/scripts/fetch-spip.sh 4.4.22     # explicit version
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERSION="${1:-$(cat "$ROOT/spip/SPIP_VERSION")}"
DEST="$ROOT/spip/src"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

URL="https://files.spip.net/spip/archives/spip-v${VERSION}.zip"
echo "→ Downloading $URL"
curl -fSL "$URL" -o "$TMP/spip.zip"

echo "→ Extracting to spip/src/"
unzip -q "$TMP/spip.zip" -d "$TMP/extracted"
SRCDIR="$TMP/extracted"
[ -d "$TMP/extracted/spip/ecrire" ] && SRCDIR="$TMP/extracted/spip"
[ -d "$SRCDIR/ecrire" ] || { echo "Unexpected archive layout" >&2; exit 1; }

mkdir -p "$DEST"
rsync -a --delete "$SRCDIR/" "$DEST/"

if grep -q "spip_version_branche = '$VERSION'" "$DEST/ecrire/inc_version.php"; then
  echo "✓ SPIP core $VERSION is in spip/src/ (git-ignored)."
else
  echo "✗ Fetched core does not report $VERSION" >&2
  exit 1
fi
