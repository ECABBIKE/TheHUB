-- Migration 086: Festival checkout integration
-- Adds support for festival activities and passes in order system

-- 1. Add activity_registration_id and festival_pass_id to order_items
ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS activity_registration_id INT NULL AFTER registration_id,
    ADD COLUMN IF NOT EXISTS festival_pass_id INT NULL AFTER activity_registration_id;

-- 2. Extend item_type to support festival types
-- Note: item_type may be ENUM or VARCHAR - use MODIFY to ensure all types are covered
ALTER TABLE order_items
    MODIFY COLUMN item_type VARCHAR(30) DEFAULT 'registration';

-- 3. Add indexes for festival lookups
ALTER TABLE order_items
    ADD INDEX IF NOT EXISTS idx_activity_reg (activity_registration_id),
    ADD INDEX IF NOT EXISTS idx_festival_pass (festival_pass_id);

-- 4. Add index on festival_activity_registrations for order lookups
ALTER TABLE festival_activity_registrations
    ADD INDEX IF NOT EXISTS idx_order (order_id),
    ADD INDEX IF NOT EXISTS idx_rider (rider_id),
    ADD INDEX IF NOT EXISTS idx_activity (activity_id);

-- 5. Add index on festival_passes for order lookups
ALTER TABLE festival_passes
    ADD INDEX IF NOT EXISTS idx_order (order_id),
    ADD INDEX IF NOT EXISTS idx_rider (rider_id),
    ADD INDEX IF NOT EXISTS idx_festival (festival_id);
