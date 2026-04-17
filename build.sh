#!/usr/bin/env bash
# Build AEO Pugmill distribution zips.
# Usage: ./build.sh
# Output (both in project root):
#   aeo-pugmill-{version}.zip          — WordPress.org submission (no update-checker.php)
#   aeo-pugmill-{version}-self-hosted.zip — Website distribution (includes update-checker.php)

set -euo pipefail

PLUGIN_DIR="aeo-pugmill"
PLUGIN_FILE="$PLUGIN_DIR/aeo-pugmill.php"

# ── Read version from plugin header ───────────────────────────────────────────
VERSION=$(grep -m1 '^\s*\* Version:' "$PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
ZIP_WPORG="aeo-pugmill-${VERSION}.zip"
ZIP_SELF="aeo-pugmill.zip"

echo "Building AEO Pugmill v${VERSION}..."

# ── Compile JS ─────────────────────────────────────────────────────────────────
(cd "$PLUGIN_DIR" && npm run build)

# ── Shared excludes (applied to both zips) ────────────────────────────────────
EXCLUDES=(
  --exclude "$PLUGIN_DIR/node_modules/*"
  --exclude "$PLUGIN_DIR/src/__tests__/*"
  --exclude "$PLUGIN_DIR/src/scoring.test.js"
  --exclude "$PLUGIN_DIR/build-backup/*"
  --exclude "$PLUGIN_DIR/tests/*"
  --exclude "$PLUGIN_DIR/package.json"
  --exclude "$PLUGIN_DIR/package-lock.json"
  --exclude "$PLUGIN_DIR/requirements.md"
  --exclude "$PLUGIN_DIR/LICENSE"
  --exclude "$PLUGIN_DIR/assets/pugmill-logo.svg"
  --exclude "$PLUGIN_DIR/public"
  --exclude "$PLUGIN_DIR/public/*"
  --exclude "$PLUGIN_DIR/.distignore"
  --exclude "$PLUGIN_DIR/.DS_Store"
  --exclude "*/.DS_Store"
  --exclude "$PLUGIN_DIR/vitest.config.js"
)

# ── WordPress.org zip (excludes update-checker.php — WP.org handles updates) ──
rm -f "$ZIP_WPORG"
zip -r "$ZIP_WPORG" "$PLUGIN_DIR" "${EXCLUDES[@]}" \
  --exclude "$PLUGIN_DIR/includes/update-checker.php"
echo "Done: $ZIP_WPORG  (WordPress.org submission)"

# ── Self-hosted zip (includes update-checker.php) ─────────────────────────────
rm -f "$ZIP_SELF"
zip -r "$ZIP_SELF" "$PLUGIN_DIR" "${EXCLUDES[@]}"
echo "Done: $ZIP_SELF  (self-hosted / website — fixed filename for updater)"
