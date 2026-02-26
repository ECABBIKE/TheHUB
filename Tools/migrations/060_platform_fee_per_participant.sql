-- Migration 060: Add per_participant platform fee type
-- Extends platform_fee_type ENUM with 'per_participant' (fixed fee per registered rider)

ALTER TABLE payment_recipients
    MODIFY COLUMN platform_fee_type ENUM('percent', 'fixed', 'per_participant', 'both') DEFAULT 'percent';
