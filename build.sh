#!/usr/bin/env bash
# Build AEO Pugmill distribution zip.
# Usage: ./build.sh
# Output: aeo-pugmill-{version}.zip in the project root.

set -euo pipefail

PLUGIN_DIR="aeo-pugmill"
PLUGIN_FILE="$PLUGIN_DIR/aeo-pugmill.php"

# ── Read version from plugin header ───────────────────────────────────────────
VERSION=$(grep -m1 '^\s*\* Version:' "$PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
ZIP_NAME="aeo-pugmill-${VERSION}.zip"

echo "Building AEO Pugmill v${VERSION}..."

# ── Compile JS ─────────────────────────────────────────────────────────────────
(cd "$PLUGIN_DIR" && npm run build)

# ── Create zip (distribution files only) ──────────────────────────────────────
rm -f "$ZIP_NAME"

zip -r "$ZIP_NAME" "$PLUGIN_DIR" \
  --exclude "$PLUGIN_DIR/node_modules/*" \
  --exclude "$PLUGIN_DIR/src/__tests__/*" \
  --exclude "$PLUGIN_DIR/src/scoring.test.js" \
  --exclude "$PLUGIN_DIR/build-backup/*" \
  --exclude "$PLUGIN_DIR/tests/*" \
  --exclude "$PLUGIN_DIR/package.json" \
  --exclude "$PLUGIN_DIR/package-lock.json" \
  --exclude "$PLUGIN_DIR/requirements.md" \
  --exclude "$PLUGIN_DIR/LICENSE" \
  --exclude "$PLUGIN_DIR/assets/pugmill-logo.svg" \
  --exclude "$PLUGIN_DIR/public" \
  --exclude "$PLUGIN_DIR/public/*" \
  --exclude "$PLUGIN_DIR/.distignore" \
  --exclude "$PLUGIN_DIR/.DS_Store" \
  --exclude "*/.DS_Store" \
  --exclude "$PLUGIN_DIR/vitest.config.js"

echo "Done: $ZIP_NAME"
