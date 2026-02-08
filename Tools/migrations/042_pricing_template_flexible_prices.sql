-- Add flexible pricing to pricing_template_rules
-- Allows both percentage-based AND manual pricing for early bird and late fee

-- Add manual price columns (nullable - if NULL, use percentage from template)
ALTER TABLE pricing_template_rules
ADD COLUMN IF NOT EXISTS early_bird_price DECIMAL(10,2) NULL DEFAULT NULL
COMMENT 'Manual early bird price (if NULL, calculated from template percentage)'
AFTER base_price;

ALTER TABLE pricing_template_rules
ADD COLUMN IF NOT EXISTS late_fee_price DECIMAL(10,2) NULL DEFAULT NULL
COMMENT 'Manual late fee price (if NULL, calculated from template percentage)'
AFTER early_bird_price;

-- Add pricing mode to template (percentage or manual)
ALTER TABLE pricing_templates
ADD COLUMN IF NOT EXISTS pricing_mode ENUM('percentage', 'manual') DEFAULT 'percentage'
COMMENT 'Use percentage calculation or manual prices per class'
AFTER late_fee_days_before;
