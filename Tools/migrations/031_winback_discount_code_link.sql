-- Migration 031: Link winback campaigns to discount_codes table
-- Also adds customizable email subject and body per campaign

-- Add discount code reference (replaces inline discount settings)
ALTER TABLE winback_campaigns
    ADD COLUMN discount_code_id INT UNSIGNED NULL AFTER discount_event_id,
    ADD COLUMN email_subject VARCHAR(255) NULL AFTER discount_code_id,
    ADD COLUMN email_body TEXT NULL AFTER email_subject;

-- Add index for discount code lookups
ALTER TABLE winback_campaigns
    ADD INDEX idx_discount_code (discount_code_id);

-- Note: Old columns (discount_type, discount_value, discount_applicable_to,
-- discount_series_id, discount_event_id) are kept for backwards compatibility
-- but discount_code_id takes precedence when set
