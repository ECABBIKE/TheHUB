#!/bin/bash
# Rebuilds /assets/css/bundle.css from source files
# Run this after editing any global CSS file
# head.php also auto-rebuilds if any source is newer than bundle

CSS_DIR="$(dirname "$0")/../assets/css"

cat \
  "$CSS_DIR/reset.css" \
  "$CSS_DIR/tokens.css" \
  "$CSS_DIR/theme.css" \
  "$CSS_DIR/effects.css" \
  "$CSS_DIR/layout.css" \
  "$CSS_DIR/components.css" \
  "$CSS_DIR/tables.css" \
  "$CSS_DIR/utilities.css" \
  "$CSS_DIR/badge-system.css" \
  "$CSS_DIR/pwa.css" \
  "$CSS_DIR/viewport.css" \
  > "$CSS_DIR/bundle.css"

echo "Bundle rebuilt: $(wc -c < "$CSS_DIR/bundle.css") bytes"
