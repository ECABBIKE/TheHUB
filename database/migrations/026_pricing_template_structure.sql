-- Migration 026: Move pricing percentages/days to template level
-- Early Bird and Late Fee settings are the same for all classes in a template

-- Add percentage and days columns to pricing_templates (if they don't exist)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_templates' AND COLUMN_NAME = 'early_bird_percent');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pricing_templates ADD COLUMN early_bird_percent DECIMAL(5,2) DEFAULT 15 COMMENT ''Early bird rabatt i procent''',
    'SELECT ''Column early_bird_percent already exists'' AS Info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_templates' AND COLUMN_NAME = 'early_bird_days_before');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pricing_templates ADD COLUMN early_bird_days_before INT DEFAULT 21 COMMENT ''Dagar före event för early bird''',
    'SELECT ''Column early_bird_days_before already exists'' AS Info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_templates' AND COLUMN_NAME = 'late_fee_percent');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pricing_templates ADD COLUMN late_fee_percent DECIMAL(5,2) DEFAULT 25 COMMENT ''Efteranmälan tillägg i procent''',
    'SELECT ''Column late_fee_percent already exists'' AS Info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_templates' AND COLUMN_NAME = 'late_fee_days_before');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pricing_templates ADD COLUMN late_fee_days_before INT DEFAULT 3 COMMENT ''Dagar före event för efteranmälan''',
    'SELECT ''Column late_fee_days_before already exists'' AS Info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove columns from pricing_template_rules only if they exist
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_template_rules' AND COLUMN_NAME = 'early_bird_percent');
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE pricing_template_rules DROP COLUMN early_bird_percent',
    'SELECT ''Column early_bird_percent does not exist in pricing_template_rules'' AS Info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_template_rules' AND COLUMN_NAME = 'early_bird_days_before');
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE pricing_template_rules DROP COLUMN early_bird_days_before',
    'SELECT ''Column early_bird_days_before does not exist in pricing_template_rules'' AS Info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_template_rules' AND COLUMN_NAME = 'late_fee_percent');
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE pricing_template_rules DROP COLUMN late_fee_percent',
    'SELECT ''Column late_fee_percent does not exist in pricing_template_rules'' AS Info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_template_rules' AND COLUMN_NAME = 'late_fee_days_before');
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE pricing_template_rules DROP COLUMN late_fee_days_before',
    'SELECT ''Column late_fee_days_before does not exist in pricing_template_rules'' AS Info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
