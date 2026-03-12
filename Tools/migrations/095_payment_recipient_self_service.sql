-- Migration 095: Self-service betalningsuppgifter + avräkningsfrekvens
-- Promotorer fyller i egna betalningsuppgifter via sin profil.
-- Betalningsmottagare kan välja avräkningsfrekvens.

-- Betalningsuppgifter på admin_users (promotorens profil)
-- Each column added individually with IF NOT EXISTS logic via procedure

DROP PROCEDURE IF EXISTS run_migration_095;

DELIMITER //
CREATE PROCEDURE run_migration_095()
BEGIN
    -- admin_users columns
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'org_number') THEN
        ALTER TABLE admin_users ADD COLUMN org_number VARCHAR(20) NULL AFTER full_name;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'contact_phone') THEN
        ALTER TABLE admin_users ADD COLUMN contact_phone VARCHAR(20) NULL AFTER org_number;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'swish_number') THEN
        ALTER TABLE admin_users ADD COLUMN swish_number VARCHAR(20) NULL AFTER contact_phone;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'swish_name') THEN
        ALTER TABLE admin_users ADD COLUMN swish_name VARCHAR(100) NULL AFTER swish_number;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'bankgiro') THEN
        ALTER TABLE admin_users ADD COLUMN bankgiro VARCHAR(20) NULL AFTER swish_name;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'plusgiro') THEN
        ALTER TABLE admin_users ADD COLUMN plusgiro VARCHAR(20) NULL AFTER bankgiro;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'bank_account') THEN
        ALTER TABLE admin_users ADD COLUMN bank_account VARCHAR(30) NULL AFTER plusgiro;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'bank_name') THEN
        ALTER TABLE admin_users ADD COLUMN bank_name VARCHAR(100) NULL AFTER bank_account;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'bank_clearing') THEN
        ALTER TABLE admin_users ADD COLUMN bank_clearing VARCHAR(10) NULL AFTER bank_name;
    END IF;

    -- payment_recipients columns
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_recipients' AND column_name = 'settlement_frequency') THEN
        ALTER TABLE payment_recipients ADD COLUMN settlement_frequency ENUM('monthly','after_close') NOT NULL DEFAULT 'monthly' AFTER active;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_recipients' AND column_name = 'settlement_notified_at') THEN
        ALTER TABLE payment_recipients ADD COLUMN settlement_notified_at DATETIME NULL AFTER settlement_frequency;
    END IF;
END //
DELIMITER ;

CALL run_migration_095();
DROP PROCEDURE IF EXISTS run_migration_095;
