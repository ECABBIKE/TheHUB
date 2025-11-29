#!/bin/bash
# V3.5 Migration Script - Convert gs-* classes to V3.5 structure
# FAS 2: Component Migration

echo "🔄 Starting V3.5 migration - Converting gs-* classes..."

# Find all PHP files with gs- classes (excluding already converted files)
FILES=$(grep -rl '\bgs-' --include="*.php" --exclude-dir=".git" --exclude-dir="vendor" . | grep -v "layout-header.php" | grep -v "layout-footer.php" | grep -v "navigation.php" | grep -v "index.php" | grep -v "convert-gs-to-v35.sh")

COUNT=0
TOTAL=$(echo "$FILES" | wc -l)

echo "Found $TOTAL files to convert"

for FILE in $FILES; do
    ((COUNT++))
    echo "[$COUNT/$TOTAL] Converting: $FILE"

    # Create backup
    cp "$FILE" "$FILE.bak"

    # Apply conversions in order (specific to general to avoid partial replacements)
    sed -i \
        -e 's/\bgs-admin-page\b/admin-page/g' \
        -e 's/\bgs-public-page\b/public-page/g' \
        -e 's/\bgs-content-with-sidebar\b/main-content/g' \
        -e 's/\bgs-main-content\b/main-content/g' \
        -e 's/\bgs-card-content-large\b/card-body/g' \
        -e 's/\bgs-card-content\b/card-body/g' \
        -e 's/\bgs-card-header\b/card-header/g' \
        -e 's/\bgs-card-title\b/card-title/g' \
        -e 's/\bgs-card-subtitle\b/card-subtitle/g' \
        -e 's/\bgs-card-footer\b/card-footer/g' \
        -e 's/\bgs-card\b/card/g' \
        -e 's/\bgs-container\b/container/g' \
        -e 's/\bgs-hero-title-white\b/hero-title/g' \
        -e 's/\bgs-hero\b/hero/g' \
        -e 's/\bgs-h1\b//g' \
        -e 's/\bgs-h2\b//g' \
        -e 's/\bgs-h3\b//g' \
        -e 's/\bgs-h4\b//g' \
        -e 's/\bgs-h5\b//g' \
        -e 's/\bgs-text-center\b/text-center/g' \
        -e 's/\bgs-text-right\b/text-right/g' \
        -e 's/\bgs-text-secondary\b/text-secondary/g' \
        -e 's/\bgs-text-primary\b/text-primary/g' \
        -e 's/\bgs-text-muted\b/text-muted/g' \
        -e 's/\bgs-text-accent\b/text-accent/g' \
        -e 's/\bgs-text-success\b/text-success/g' \
        -e 's/\bgs-text-error\b/text-error/g' \
        -e 's/\bgs-text-warning\b/text-warning/g' \
        -e 's/\bgs-text-xs\b/text-xs/g' \
        -e 's/\bgs-text-sm\b/text-sm/g' \
        -e 's/\bgs-text-base\b/text-base/g' \
        -e 's/\bgs-text-md\b/text-md/g' \
        -e 's/\bgs-text-lg\b/text-lg/g' \
        -e 's/\bgs-text-xl\b/text-xl/g' \
        -e 's/\bgs-text-2xl\b/text-2xl/g' \
        -e 's/\bgs-font-bold\b/font-bold/g' \
        -e 's/\bgs-font-semibold\b/font-semibold/g' \
        -e 's/\bgs-font-medium\b/font-medium/g' \
        -e 's/\bgs-mb-xl\b/mb-lg/g' \
        -e 's/\bgs-mb-lg\b/mb-lg/g' \
        -e 's/\bgs-mb-md\b/mb-md/g' \
        -e 's/\bgs-mb-sm\b/mb-sm/g' \
        -e 's/\bgs-mt-xl\b/mt-lg/g' \
        -e 's/\bgs-mt-lg\b/mt-lg/g' \
        -e 's/\bgs-mt-md\b/mt-md/g' \
        -e 's/\bgs-mt-sm\b/mt-sm/g' \
        -e 's/\bgs-ml-md\b/ml-md/g' \
        -e 's/\bgs-ml-sm\b/ml-sm/g' \
        -e 's/\bgs-mr-md\b/mr-md/g' \
        -e 's/\bgs-mr-sm\b/mr-sm/g' \
        -e 's/\bgs-p-lg\b/p-lg/g' \
        -e 's/\bgs-p-md\b/p-md/g' \
        -e 's/\bgs-p-sm\b/p-sm/g' \
        -e 's/\bgs-flex-col\b/flex-col/g' \
        -e 's/\bgs-flex-wrap\b/flex-wrap/g' \
        -e 's/\bgs-flex\b/flex/g' \
        -e 's/\bgs-grid-cols-1\b/grid-cols-1/g' \
        -e 's/\bgs-grid-cols-2\b/grid-cols-2/g' \
        -e 's/\bgs-grid-cols-3\b/grid-cols-3/g' \
        -e 's/\bgs-grid-cols-4\b/grid-cols-4/g' \
        -e 's/\bgs-md-grid-cols-2\b/md-grid-cols-2/g' \
        -e 's/\bgs-md-grid-cols-3\b/md-grid-cols-3/g' \
        -e 's/\bgs-grid\b/grid/g' \
        -e 's/\bgs-gap-xs\b/gap-xs/g' \
        -e 's/\bgs-gap-sm\b/gap-sm/g' \
        -e 's/\bgs-gap-md\b/gap-md/g' \
        -e 's/\bgs-gap-lg\b/gap-lg/g' \
        -e 's/\bgs-items-center\b/items-center/g' \
        -e 's/\bgs-items-start\b/items-start/g' \
        -e 's/\bgs-justify-between\b/justify-between/g' \
        -e 's/\bgs-justify-center\b/justify-center/g' \
        -e 's/\bgs-btn-primary\b/btn--primary/g' \
        -e 's/\bgs-btn-secondary\b/btn--secondary/g' \
        -e 's/\bgs-btn-outline\b/btn--secondary/g' \
        -e 's/\bgs-btn-sm\b/btn--sm/g' \
        -e 's/\bgs-btn\b/btn/g' \
        -e 's/\bgs-w-full\b/w-full/g' \
        -e 's/\bgs-form-group\b/form-group/g' \
        -e 's/\bgs-label\b/label/g' \
        -e 's/\bgs-input\b/input/g' \
        -e 's/\bgs-checkbox-label\b/checkbox-label/g' \
        -e 's/\bgs-checkbox\b/checkbox/g' \
        -e 's/\bgs-table-striped\b/table--striped/g' \
        -e 's/\bgs-table-hover\b/table--hover/g' \
        -e 's/\bgs-table\b/table/g' \
        -e 's/\bgs-alert-success\b/alert--success/g' \
        -e 's/\bgs-alert-error\b/alert--error/g' \
        -e 's/\bgs-alert-warning\b/alert--warning/g' \
        -e 's/\bgs-alert-info\b/alert--info/g' \
        -e 's/\bgs-alert\b/alert/g' \
        -e 's/\bgs-stat-card\b/stat-card/g' \
        -e 's/\bgs-stat-number\b/stat-number/g' \
        -e 's/\bgs-stat-value\b/stat-value/g' \
        -e 's/\bgs-stat-label\b/stat-label/g' \
        -e 's/\bgs-icon-lg\b/icon-lg/g' \
        -e 's/\bgs-icon-14\b/icon-sm/g' \
        -e 's/\bgs-list-styled\b/list-styled/g' \
        -e 's/\bgs-sidebar-overlay\b/sidebar-overlay/g' \
        -e 's/\bgs-sidebar\b/sidebar/g' \
        -e 's/\bgs-mobile-menu-toggle\b/mobile-menu-toggle/g' \
        -e 's/\bgs-menu-section\b/sidebar-section/g' \
        -e 's/\bgs-menu-title\b/sidebar-title/g' \
        -e 's/\bgs-menu-footer\b/sidebar-footer/g' \
        -e 's/\bgs-menu\b/sidebar-nav/g' \
        -e 's/\bgs-footer-version\b/footer-version/g' \
        -e 's/\bgs-footer\b/footer/g' \
        -e 's/\bgs-badge\b/badge/g' \
        -e 's/\bgs-chip\b/chip/g' \
        -e 's/\bgs-icon-sm\b/icon-sm/g' \
        -e 's/\bgs-icon-md\b/icon-md/g' \
        -e 's/\bgs-text-danger\b/text-error/g' \
        -e 's/\bgs-link\b/link/g' \
        -e 's/\bgs-primary\b/primary/g' \
        -e 's/\bgs-col-landscape\b/col-landscape/g' \
        -e 's/\bgs-hidden\b/hidden/g' \
        -e 's/\bgs-h6\b//g' \
        -e 's/\bgs-points-label\b/points-label/g' \
        -e 's/\bgs-col-tablet\b/col-tablet/g' \
        -e 's/\bgs-space-md\b/space-md/g' \
        -e 's/\bgs-space-sm\b/space-sm/g' \
        -e 's/\bgs-border\b/border/g' \
        -e 's/\bgs-tab\b/tab/g' \
        -e 's/\bgs-event-tab\b/event-tab/g' \
        -e 's/\bgs-section-divider\b/section-divider/g' \
        -e 's/\bgs-py-xl\b/py-xl/g' \
        -e 's/\bgs-py-lg\b/py-lg/g' \
        -e 's/\bgs-points-breakdown\b/points-breakdown/g' \
        "$FILE"

    # Clean up double spaces from removed classes
    sed -i -e 's/class=" /class="/g' -e 's/ "/"/g' -e 's/  / /g' "$FILE"
done

echo "✅ Conversion complete! Converted $COUNT files"
echo "📋 Backup files created with .bak extension"
echo ""
echo "To remove backup files: find . -name '*.bak' -delete"
