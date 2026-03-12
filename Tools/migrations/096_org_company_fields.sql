-- Migration 096: Company detail fields on admin_users and payment_recipients
-- Adds fields that can be auto-filled from Bolagsverket API.
-- Uses ADD COLUMN IF NOT EXISTS (MariaDB) for idempotent execution.

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS org_name VARCHAR(200) NULL AFTER org_number;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS org_address VARCHAR(200) NULL AFTER org_name;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS org_postal_code VARCHAR(10) NULL AFTER org_address;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS org_city VARCHAR(100) NULL AFTER org_postal_code;

ALTER TABLE payment_recipients ADD COLUMN IF NOT EXISTS org_address VARCHAR(200) NULL AFTER org_number;
ALTER TABLE payment_recipients ADD COLUMN IF NOT EXISTS org_postal_code VARCHAR(10) NULL AFTER org_address;
ALTER TABLE payment_recipients ADD COLUMN IF NOT EXISTS org_city VARCHAR(100) NULL AFTER org_postal_code;
