#!/usr/bin/env bash
# Build WP Pugmill distribution zip.
# Usage: ./build.sh
# Output: wp-pugmill-{version}.zip in the project root.

set -euo pipefail

PLUGIN_DIR="wp-pugmill"
PLUGIN_FILE="$PLUGIN_DIR/wp-pugmill.php"

# ── Read version from plugin header ───────────────────────────────────────────
VERSION=$(grep -m1 '^\s*\* Version:' "$PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
ZIP_NAME="wp-pugmill-${VERSION}.zip"

echo "Building WP Pugmill v${VERSION}..."

# ── Compile JS ─────────────────────────────────────────────────────────────────
(cd "$PLUGIN_DIR" && npm run build)

# ── Create zip (distribution files only) ──────────────────────────────────────
rm -f "$ZIP_NAME"

zip -r "$ZIP_NAME" "$PLUGIN_DIR" \
  --exclude "$PLUGIN_DIR/node_modules/*" \
  --exclude "$PLUGIN_DIR/src/*" \
  --exclude "$PLUGIN_DIR/build-backup/*" \
  --exclude "$PLUGIN_DIR/tests/*" \
  --exclude "$PLUGIN_DIR/package.json" \
  --exclude "$PLUGIN_DIR/package-lock.json" \
  --exclude "$PLUGIN_DIR/requirements.md" \
  --exclude "$PLUGIN_DIR/.DS_Store" \
  --exclude "*/.DS_Store"

echo "Done: $ZIP_NAME"
