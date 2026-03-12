-- Migration 095: Self-service betalningsuppgifter + avräkningsfrekvens
-- Promotorer fyller i egna betalningsuppgifter via sin profil.
-- Uses ADD COLUMN IF NOT EXISTS (MariaDB) for idempotent execution.

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS org_number VARCHAR(20) NULL AFTER full_name;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(20) NULL AFTER org_number;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS swish_number VARCHAR(20) NULL AFTER contact_phone;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS swish_name VARCHAR(100) NULL AFTER swish_number;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS bankgiro VARCHAR(20) NULL AFTER swish_name;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS plusgiro VARCHAR(20) NULL AFTER bankgiro;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS bank_account VARCHAR(30) NULL AFTER plusgiro;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) NULL AFTER bank_account;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS bank_clearing VARCHAR(10) NULL AFTER bank_name;

ALTER TABLE payment_recipients ADD COLUMN IF NOT EXISTS settlement_frequency ENUM('monthly','after_close') NOT NULL DEFAULT 'monthly';
ALTER TABLE payment_recipients ADD COLUMN IF NOT EXISTS settlement_notified_at DATETIME NULL;
