-- Migration 096: Company detail fields on admin_users for org lookup
-- Adds fields that can be auto-filled from Bolagsverket API

DROP PROCEDURE IF EXISTS run_migration_096;

DELIMITER //
CREATE PROCEDURE run_migration_096()
BEGIN
    -- admin_users columns
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'org_name') THEN
        ALTER TABLE admin_users ADD COLUMN org_name VARCHAR(200) NULL AFTER org_number;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'org_address') THEN
        ALTER TABLE admin_users ADD COLUMN org_address VARCHAR(200) NULL AFTER org_name;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'org_postal_code') THEN
        ALTER TABLE admin_users ADD COLUMN org_postal_code VARCHAR(10) NULL AFTER org_address;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'org_city') THEN
        ALTER TABLE admin_users ADD COLUMN org_city VARCHAR(100) NULL AFTER org_postal_code;
    END IF;

    -- payment_recipients columns
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_recipients' AND column_name = 'org_address') THEN
        ALTER TABLE payment_recipients ADD COLUMN org_address VARCHAR(200) NULL AFTER org_number;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_recipients' AND column_name = 'org_postal_code') THEN
        ALTER TABLE payment_recipients ADD COLUMN org_postal_code VARCHAR(10) NULL AFTER org_address;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_recipients' AND column_name = 'org_city') THEN
        ALTER TABLE payment_recipients ADD COLUMN org_city VARCHAR(100) NULL AFTER org_postal_code;
    END IF;
END //
DELIMITER ;

CALL run_migration_096();
DROP PROCEDURE IF EXISTS run_migration_096;
