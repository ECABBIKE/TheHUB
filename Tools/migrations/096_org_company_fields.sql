-- Migration 096: Company detail fields on admin_users for org lookup
-- Adds fields that can be auto-filled from Bolagsverket API

ALTER TABLE admin_users
    ADD COLUMN org_name VARCHAR(200) NULL AFTER org_number,
    ADD COLUMN org_address VARCHAR(200) NULL AFTER org_name,
    ADD COLUMN org_postal_code VARCHAR(10) NULL AFTER org_address,
    ADD COLUMN org_city VARCHAR(100) NULL AFTER org_postal_code;

-- Also add to payment_recipients for completeness
ALTER TABLE payment_recipients
    ADD COLUMN org_address VARCHAR(200) NULL AFTER org_number,
    ADD COLUMN org_postal_code VARCHAR(10) NULL AFTER org_address,
    ADD COLUMN org_city VARCHAR(100) NULL AFTER org_postal_code;
