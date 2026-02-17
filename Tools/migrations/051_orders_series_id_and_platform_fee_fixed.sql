-- Migration 051: Add series_id to orders + platform_fee_fixed to payment_recipients
-- 2026-02-17
--
-- 1. Adds series_id to orders so series registrations can be properly tracked
-- 2. Adds platform_fee_fixed to payment_recipients for fixed-amount platform fees
-- 3. Adds platform_fee_type to payment_recipients (percent, fixed, both)
-- 4. Backfills series_id from order_items → series_registrations

-- Add series_id to orders
ALTER TABLE orders
ADD COLUMN series_id INT NULL AFTER event_id;

-- Add index for series lookups
ALTER TABLE orders
ADD INDEX idx_orders_series_id (series_id);

-- Add platform fee fixed amount and type to payment_recipients
ALTER TABLE payment_recipients
ADD COLUMN platform_fee_fixed DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Fast plattformsavgift per anmälan i SEK';

ALTER TABLE payment_recipients
ADD COLUMN platform_fee_type ENUM('percent', 'fixed', 'both') DEFAULT 'percent' COMMENT 'Typ av plattformsavgift';

-- Backfill: set series_id on orders that have series_registration items
UPDATE orders o
JOIN order_items oi ON oi.order_id = o.id AND oi.item_type = 'series_registration'
JOIN series_registrations sr ON sr.id = oi.series_registration_id
SET o.series_id = sr.series_id
WHERE o.series_id IS NULL;
