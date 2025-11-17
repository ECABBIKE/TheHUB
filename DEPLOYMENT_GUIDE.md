# Deployment Guide - TheHUB Admin Improvements

## Critical Files to Deploy

Deploy these files from branch `claude/thehub-admin-improvements-01MDzbMdJNWXBkSTwAjMQfj4` to your live server:

### 1. New Files (Must Upload)
```
admin/point-templates.php              (Manage qualification point templates)
admin/series-events.php                (Manage events in series)
admin/debug-series.php                 (Debug script for troubleshooting)
admin/check-files.php                  (Check which files are deployed)
admin/migrations/add_series_format.php (Add format column to series)
admin/migrations/create_series_events_and_point_templates.php (Create new tables)
```

### 2. Updated Files (Must Replace)
```
admin/series.php                       (Fixed to work without migrations)
admin/events.php                       (Year filter first, then series filter)
admin/import-results.php               (SweCup CSV support + auto-create classes)
admin/import.php                       (Updated documentation)
admin/download-templates.php           (Updated template format)
includes/navigation.php                (Added Poängmallar menu item)
```

### 3. Optional Files (Enhanced features)
```
admin/riders.php                       (Various improvements)
admin/venues.php                       (Various improvements)
admin/results.php                      (Various improvements)
admin/classes.php                      (Various improvements)
admin/clubs.php                        (Various improvements)
```

## Deployment Steps

### Step 1: Upload Files
Upload all files listed above to your live server at:
```
https://thehub.infinityfree.me/
```

Methods:
- **FTP/SFTP**: Use FileZilla or similar
- **File Manager**: Use your hosting control panel
- **Git**: If git is available on live server: `git pull origin claude/thehub-admin-improvements-01MDzbMdJNWXBkSTwAjMQfj4`

### Step 2: Verify Files
Visit this URL to check which files are present:
```
https://thehub.infinityfree.me/admin/check-files.php
```

All files should show ✅ EXISTS

### Step 3: Run Migrations (In Order!)
After files are uploaded, run these migrations **in this exact order**:

**First:**
```
https://thehub.infinityfree.me/admin/migrations/add_series_format.php
```
Expected output: "✓ Added 'format' column to series table"

**Second:**
```
https://thehub.infinityfree.me/admin/migrations/create_series_events_and_point_templates.php
```
Expected output:
- "✓ Created qualification_point_templates table"
- "✓ Created series_events table"
- "✓ Created template: SweCup Standard"
- "✓ Created template: UCI Standard"
- "✓ Created template: Top 10"

### Step 4: Test
After migrations, test these pages:

```
https://thehub.infinityfree.me/admin/series.php
https://thehub.infinityfree.me/admin/point-templates.php
https://thehub.infinityfree.me/admin/events.php
https://thehub.infinityfree.me/admin/import-results.php
```

## Troubleshooting

### White Screen on series.php
- Run debug: `https://thehub.infinityfree.me/admin/debug-series.php`
- This will show which tables/columns are missing

### Migration Files Give White Screen
- Check if files exist: `https://thehub.infinityfree.me/admin/check-files.php`
- Check PHP error logs on your hosting
- Ensure you're logged in as admin

### Files Are Deployed But Still White
- Check PHP version (requires PHP 7.4+)
- Check file permissions (should be 644 for .php files)
- Check error_log in your hosting panel

## What This Update Includes

### Features:
✅ Series page transformed to table view with year filtering
✅ Events page with year-first filtering
✅ SweCup CSV import format support
✅ Automatic class creation from CSV
✅ Many-to-many series-events relationship
✅ Qualification point templates system
✅ Series-events management interface
✅ Format field for series (Championship/Team)
✅ Time format standardization (mm:ss.cc)

### Database Changes:
- New column: `series.format` (ENUM)
- New table: `qualification_point_templates`
- New table: `series_events`
- 3 default point templates created

## Need Help?

If deployment fails or you encounter issues, check:
1. File permissions
2. PHP error logs
3. Database credentials in config.php
4. Admin authentication
