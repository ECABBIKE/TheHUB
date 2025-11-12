# Database Migrations

This folder contains SQL migration files for TheHUB database.

## Running Migrations

### Option 1: phpMyAdmin
1. Log in to phpMyAdmin
2. Select `if0_40400950_THEHUB` database
3. Go to "SQL" tab
4. Copy and paste the migration SQL
5. Click "Go"

### Option 2: Command line (if you have MySQL access)
```bash
mysql -u if0_40400950 -p if0_40400950_THEHUB < database/migrations/002_add_license_fields.sql
```

## Migration 002: Add License Fields

**File:** `002_add_license_fields.sql`

**Description:** Adds license management fields to cyclists table:
- `license_type` - Type of license (Elite, Youth, Hobby, etc)
- `license_category` - Specific category (Elite Men, Master Women 35+, etc)
- `discipline` - Cycling discipline (MTB, Road, Track, etc)
- `license_valid_until` - License expiry date

**Required:** Run this before using the new license features

**Status:** ⚠️ NOT YET APPLIED - Run in phpMyAdmin!

## After Running Migration

1. Test with sample data:
```sql
-- Insert test cyclist with new fields
INSERT INTO cyclists (
    firstname, lastname, birth_year, gender,
    license_type, license_category, discipline, license_valid_until
) VALUES (
    'Test', 'Cyclist', 1990, 'M',
    'Elite', 'Elite Men', 'MTB', '2025-12-31'
);
```

2. Verify fields exist:
```sql
DESCRIBE cyclists;
```

You should see the new columns in the output.

## Troubleshooting

**Error: "Duplicate column name"**
- The migration has already been run
- Check if columns exist: `SHOW COLUMNS FROM cyclists LIKE 'license_%';`

**Error: "Table doesn't exist"**
- Make sure you're in the correct database
- Run the base schema first: `database/schema.sql`
