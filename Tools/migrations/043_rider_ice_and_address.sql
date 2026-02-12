-- Migration 043: ICE (nödkontakt) och adressfält på riders
-- Datum: 2026-02-12
-- Beskrivning: Lägger till nödkontakt och adressfält som krävs för anmälan

-- Nödkontakt / ICE (In Case of Emergency)
ALTER TABLE riders
    ADD COLUMN IF NOT EXISTS ice_name VARCHAR(255) DEFAULT NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS ice_phone VARCHAR(50) DEFAULT NULL AFTER ice_name;

-- Adressfält (för leverans/faktura)
ALTER TABLE riders
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL AFTER ice_phone,
    ADD COLUMN IF NOT EXISTS postal_code VARCHAR(20) DEFAULT NULL AFTER address,
    ADD COLUMN IF NOT EXISTS postal_city VARCHAR(100) DEFAULT NULL AFTER postal_code;
