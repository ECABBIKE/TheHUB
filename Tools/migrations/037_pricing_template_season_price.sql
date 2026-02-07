-- Add season_price column to pricing_template_rules
-- Allows setting a per-class season/series pass price in each pricing template
ALTER TABLE pricing_template_rules ADD COLUMN IF NOT EXISTS season_price DECIMAL(10,2) NULL DEFAULT NULL AFTER base_price;
