-- Migration 095: Self-service betalningsuppgifter + avräkningsfrekvens
-- Promotorer fyller i egna betalningsuppgifter via sin profil.
-- Betalningsmottagare kan välja avräkningsfrekvens.

-- Betalningsuppgifter på admin_users (promotorens profil)
ALTER TABLE admin_users
    ADD COLUMN org_number VARCHAR(20) NULL AFTER full_name,
    ADD COLUMN contact_phone VARCHAR(20) NULL AFTER org_number,
    ADD COLUMN swish_number VARCHAR(20) NULL AFTER contact_phone,
    ADD COLUMN swish_name VARCHAR(100) NULL AFTER swish_number,
    ADD COLUMN bankgiro VARCHAR(20) NULL AFTER swish_name,
    ADD COLUMN plusgiro VARCHAR(20) NULL AFTER bankgiro,
    ADD COLUMN bank_account VARCHAR(30) NULL AFTER plusgiro,
    ADD COLUMN bank_name VARCHAR(100) NULL AFTER bank_account,
    ADD COLUMN bank_clearing VARCHAR(10) NULL AFTER bank_name;

-- Avräkningsfrekvens på betalningsmottagare
ALTER TABLE payment_recipients
    ADD COLUMN settlement_frequency ENUM('monthly','after_close') NOT NULL DEFAULT 'monthly' AFTER active,
    ADD COLUMN settlement_notified_at DATETIME NULL AFTER settlement_frequency;
