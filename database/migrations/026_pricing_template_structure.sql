-- Migration 026: Move pricing percentages/days to template level
-- Early Bird and Late Fee settings are the same for all classes in a template

-- Add percentage and days columns to pricing_templates
ALTER TABLE pricing_templates
ADD COLUMN early_bird_percent DECIMAL(5,2) DEFAULT 15 COMMENT 'Early bird rabatt i procent',
ADD COLUMN early_bird_days_before INT DEFAULT 21 COMMENT 'Dagar före event för early bird',
ADD COLUMN late_fee_percent DECIMAL(5,2) DEFAULT 25 COMMENT 'Efteranmälan tillägg i procent',
ADD COLUMN late_fee_days_before INT DEFAULT 3 COMMENT 'Dagar före event för efteranmälan';

-- Remove these columns from pricing_template_rules (keep only base_price)
ALTER TABLE pricing_template_rules
DROP COLUMN early_bird_percent,
DROP COLUMN early_bird_days_before,
DROP COLUMN late_fee_percent,
DROP COLUMN late_fee_days_before;
