-- Migration 027: Add bank account details to payment_recipients
-- Date: 2026-01-25
-- Description: Extends payment_recipients with bank account fields and gateway type

-- Add gateway_type column
ALTER TABLE payment_recipients
    ADD COLUMN gateway_type ENUM('swish', 'stripe', 'bank', 'manual') DEFAULT 'swish' AFTER swish_name;

-- Add bank account columns
ALTER TABLE payment_recipients
    ADD COLUMN bankgiro VARCHAR(20) NULL AFTER gateway_type,
    ADD COLUMN plusgiro VARCHAR(20) NULL AFTER bankgiro,
    ADD COLUMN bank_account VARCHAR(30) NULL AFTER plusgiro,
    ADD COLUMN bank_name VARCHAR(50) NULL AFTER bank_account,
    ADD COLUMN bank_clearing VARCHAR(10) NULL AFTER bank_name;

-- Add Stripe columns if they don't exist
ALTER TABLE payment_recipients
    ADD COLUMN IF NOT EXISTS stripe_account_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS stripe_account_status ENUM('pending', 'active', 'restricted', 'disabled') NULL;

-- Add contact info
ALTER TABLE payment_recipients
    ADD COLUMN contact_email VARCHAR(100) NULL AFTER bank_clearing,
    ADD COLUMN contact_phone VARCHAR(20) NULL AFTER contact_email;

-- Add organization number for invoicing
ALTER TABLE payment_recipients
    ADD COLUMN org_number VARCHAR(20) NULL AFTER contact_phone;

-- Index for gateway type
ALTER TABLE payment_recipients
    ADD INDEX idx_gateway_type (gateway_type);
