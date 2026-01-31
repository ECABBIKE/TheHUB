-- ============================================================================
-- Migration 029: Winback Campaign Discount Target
-- Extends discount targeting to include specific events
-- ============================================================================

-- Add 'event' option to discount_applicable_to enum and add event_id column
ALTER TABLE winback_campaigns
    MODIFY COLUMN discount_applicable_to ENUM('all', 'brand', 'series', 'event') DEFAULT 'all',
    ADD COLUMN discount_event_id INT UNSIGNED NULL AFTER discount_series_id;

-- Add index for event lookups
ALTER TABLE winback_campaigns ADD INDEX idx_discount_event (discount_event_id);
