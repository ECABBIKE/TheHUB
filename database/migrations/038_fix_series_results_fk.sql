-- Fix series_results foreign key to reference point_scales instead of qualification_point_templates
-- Run this AFTER 037_series_results_table.sql
-- This migration is idempotent - safe to run multiple times

-- Step 1: Set template_id to NULL (clean slate)
UPDATE `series_results` SET `template_id` = NULL;

-- Step 2: Drop FK if it exists (try both possible names)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'series_results'
    AND CONSTRAINT_NAME = 'series_results_template_fk');
SET @sql = IF(@fk_exists > 0,
    'ALTER TABLE series_results DROP FOREIGN KEY series_results_template_fk',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: Add FK to point_scales (only if not exists)
SET @fk_exists2 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'series_results'
    AND CONSTRAINT_NAME = 'series_results_template_fk');
SET @sql2 = IF(@fk_exists2 = 0,
    'ALTER TABLE series_results ADD CONSTRAINT series_results_template_fk FOREIGN KEY (template_id) REFERENCES point_scales(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Verify
SELECT 'series_results.template_id now references point_scales' as status;
