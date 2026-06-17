#!/usr/bin/env bash
# Build a release zip suitable for GitHub Releases.
#
# Usage: ./bin/build-zip.sh [version]
#
# Produces dist/ai-content-rewriter.zip with a top-level folder named "ai-content-rewriter/".

set -euo pipefail

VERSION="${1:-}"
SLUG="ai-content-rewriter"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
STAGE_DIR="$DIST_DIR/stage"

rm -rf "$DIST_DIR"
mkdir -p "$STAGE_DIR/$SLUG"

rsync -a \
  --exclude '.git' \
  --exclude '.gitignore' \
  --exclude 'dist' \
  --exclude 'bin' \
  --exclude 'node_modules' \
  --exclude '.DS_Store' \
  "$ROOT_DIR"/ "$STAGE_DIR/$SLUG"/

if [ -n "$VERSION" ]; then
  # Update version header + AICR_VERSION constant
  sed -i.bak -E "s/(\\* Version:\\s+).*/\\1${VERSION}/" "$STAGE_DIR/$SLUG/$SLUG.php"
  sed -i.bak -E "s/(define\\( 'AICR_VERSION', ')[^']+(' \\);)/\\1${VERSION}\\2/" "$STAGE_DIR/$SLUG/$SLUG.php"
  sed -i.bak -E "s/(Stable tag:\\s+).*/\\1${VERSION}/" "$STAGE_DIR/$SLUG/readme.txt"
  rm -f "$STAGE_DIR/$SLUG"/*.bak
fi

cd "$STAGE_DIR"
zip -r "$DIST_DIR/$SLUG.zip" "$SLUG" >/dev/null
rm -rf "$STAGE_DIR"
echo "Built $DIST_DIR/$SLUG.zip"
