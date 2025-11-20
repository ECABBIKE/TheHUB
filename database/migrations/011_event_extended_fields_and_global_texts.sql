-- Migration 011: Extended Event Fields and Global Texts System
-- Adds comprehensive event information fields and global text management

-- ============================================================================
-- GLOBAL TEXTS TABLE
-- ============================================================================
-- Stores default/template texts that can be used across events

CREATE TABLE IF NOT EXISTS global_texts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_key VARCHAR(100) NOT NULL UNIQUE,
    field_name VARCHAR(200) NOT NULL,
    field_category VARCHAR(100) DEFAULT 'general',
    content TEXT,
    is_active BOOLEAN DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_field_key (field_key),
    INDEX idx_category (field_category),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- EXTENDED EVENT FIELDS
-- ============================================================================
-- Add new columns to events table for comprehensive event information

-- Location & Venue
ALTER TABLE events ADD COLUMN IF NOT EXISTS venue_details TEXT AFTER location;
ALTER TABLE events ADD COLUMN IF NOT EXISTS venue_coordinates VARCHAR(100) AFTER venue_details;
ALTER TABLE events ADD COLUMN IF NOT EXISTS venue_map_url TEXT AFTER venue_coordinates;

-- PM (Promemoria)
ALTER TABLE events ADD COLUMN IF NOT EXISTS pm_content TEXT AFTER description;
ALTER TABLE events ADD COLUMN IF NOT EXISTS pm_use_global BOOLEAN DEFAULT 0 AFTER pm_content;

-- Jury Communication
ALTER TABLE events ADD COLUMN IF NOT EXISTS jury_communication TEXT AFTER pm_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS jury_use_global BOOLEAN DEFAULT 0 AFTER jury_communication;

-- Competition Schedule
ALTER TABLE events ADD COLUMN IF NOT EXISTS competition_schedule TEXT AFTER jury_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS schedule_use_global BOOLEAN DEFAULT 0 AFTER competition_schedule;

-- Start Times
ALTER TABLE events ADD COLUMN IF NOT EXISTS start_times TEXT AFTER schedule_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS start_times_use_global BOOLEAN DEFAULT 0 AFTER start_times;

-- Map
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_content TEXT AFTER start_times_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_image_url TEXT AFTER map_content;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_use_global BOOLEAN DEFAULT 0 AFTER map_image_url;

-- Driver Meeting (Förarmöte)
ALTER TABLE events ADD COLUMN IF NOT EXISTS driver_meeting TEXT AFTER map_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS driver_meeting_use_global BOOLEAN DEFAULT 0 AFTER driver_meeting;

-- Competition Tracks (Tävlingssträckor)
ALTER TABLE events ADD COLUMN IF NOT EXISTS competition_tracks TEXT AFTER driver_meeting_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS tracks_use_global BOOLEAN DEFAULT 0 AFTER competition_tracks;

-- Competition Rules (Tävlingsregler)
ALTER TABLE events ADD COLUMN IF NOT EXISTS competition_rules TEXT AFTER tracks_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS rules_use_global BOOLEAN DEFAULT 0 AFTER competition_rules;

-- Insurance (Försäkring)
ALTER TABLE events ADD COLUMN IF NOT EXISTS insurance_info TEXT AFTER rules_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS insurance_use_global BOOLEAN DEFAULT 0 AFTER insurance_info;

-- Equipment (Utrustning)
ALTER TABLE events ADD COLUMN IF NOT EXISTS equipment_info TEXT AFTER insurance_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS equipment_use_global BOOLEAN DEFAULT 0 AFTER equipment_info;

-- Training (Träning)
ALTER TABLE events ADD COLUMN IF NOT EXISTS training_info TEXT AFTER equipment_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS training_use_global BOOLEAN DEFAULT 0 AFTER training_info;

-- Timing (Tidtagning)
ALTER TABLE events ADD COLUMN IF NOT EXISTS timing_info TEXT AFTER training_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS timing_use_global BOOLEAN DEFAULT 0 AFTER timing_info;

-- Lift
ALTER TABLE events ADD COLUMN IF NOT EXISTS lift_info TEXT AFTER timing_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS lift_use_global BOOLEAN DEFAULT 0 AFTER lift_info;

-- Entry Fees (Startavgifter) - extended
ALTER TABLE events ADD COLUMN IF NOT EXISTS entry_fees_detailed TEXT AFTER lift_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS fees_use_global BOOLEAN DEFAULT 0 AFTER entry_fees_detailed;

-- Results Information
ALTER TABLE events ADD COLUMN IF NOT EXISTS results_info TEXT AFTER fees_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS results_use_global BOOLEAN DEFAULT 0 AFTER results_info;

-- Hydration Stations (Vätskekontroller)
ALTER TABLE events ADD COLUMN IF NOT EXISTS hydration_stations TEXT AFTER results_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS hydration_use_global BOOLEAN DEFAULT 0 AFTER hydration_stations;

-- Toilets/Showers (Toaletter/Dusch)
ALTER TABLE events ADD COLUMN IF NOT EXISTS toilets_showers TEXT AFTER hydration_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS toilets_use_global BOOLEAN DEFAULT 0 AFTER toilets_showers;

-- Shops (Affärer)
ALTER TABLE events ADD COLUMN IF NOT EXISTS shops_info TEXT AFTER toilets_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS shops_use_global BOOLEAN DEFAULT 0 AFTER shops_info;

-- Bike Wash (Cykeltvätt)
ALTER TABLE events ADD COLUMN IF NOT EXISTS bike_wash TEXT AFTER shops_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS bike_wash_use_global BOOLEAN DEFAULT 0 AFTER bike_wash;

-- Food/Cafe (Mat/Café)
ALTER TABLE events ADD COLUMN IF NOT EXISTS food_cafe TEXT AFTER bike_wash_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS food_use_global BOOLEAN DEFAULT 0 AFTER food_cafe;

-- Exhibitors (Utställare)
ALTER TABLE events ADD COLUMN IF NOT EXISTS exhibitors TEXT AFTER food_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS exhibitors_use_global BOOLEAN DEFAULT 0 AFTER exhibitors;

-- Parking (Parkering) - extended
ALTER TABLE events ADD COLUMN IF NOT EXISTS parking_detailed TEXT AFTER exhibitors_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS parking_use_global BOOLEAN DEFAULT 0 AFTER parking_detailed;

-- Hotel/Accommodation (Hotell och boende)
ALTER TABLE events ADD COLUMN IF NOT EXISTS hotel_accommodation TEXT AFTER parking_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS hotel_use_global BOOLEAN DEFAULT 0 AFTER hotel_accommodation;

-- Local Information
ALTER TABLE events ADD COLUMN IF NOT EXISTS local_info TEXT AFTER hotel_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS local_use_global BOOLEAN DEFAULT 0 AFTER local_info;

-- Media Production
ALTER TABLE events ADD COLUMN IF NOT EXISTS media_production TEXT AFTER local_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS media_use_global BOOLEAN DEFAULT 0 AFTER media_production;

-- Medical (Sjukvård)
ALTER TABLE events ADD COLUMN IF NOT EXISTS medical_info TEXT AFTER media_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS medical_use_global BOOLEAN DEFAULT 0 AFTER medical_info;

-- Contacts
ALTER TABLE events ADD COLUMN IF NOT EXISTS contacts_info TEXT AFTER medical_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS contacts_use_global BOOLEAN DEFAULT 0 AFTER contacts_info;

-- SCF Representatives
ALTER TABLE events ADD COLUMN IF NOT EXISTS scf_representatives TEXT AFTER contacts_use_global;
ALTER TABLE events ADD COLUMN IF NOT EXISTS scf_use_global BOOLEAN DEFAULT 0 AFTER scf_representatives;

-- ============================================================================
-- INSERT DEFAULT GLOBAL TEXTS
-- ============================================================================

INSERT IGNORE INTO global_texts (field_key, field_name, field_category, content, sort_order) VALUES
-- Regler & Säkerhet
('competition_rules', 'Tävlingsregler', 'rules', 'Tävlingen genomförs enligt Svenska Cykelförbundets tävlingsregler för MTB. Samtliga deltagare måste ha giltig licens.', 1),
('insurance_info', 'Försäkring', 'rules', 'Alla deltagare måste ha giltig tävlingslicens med tillhörande försäkring. Kontrollera att din licens är giltig innan tävlingsdagen.', 2),
('equipment_info', 'Utrustning', 'rules', 'Godkänd hjälm är obligatorisk. Vi rekommenderar även ryggskydd, knä- och armbågsskydd.', 3),

-- Praktisk information
('driver_meeting', 'Förarmöte', 'practical', 'Förarmöte hålls vid tävlingsexpeditionen. Närvaro är obligatorisk för alla tävlande.', 10),
('training_info', 'Träning', 'practical', 'Banan är öppen för besiktning och träning enligt tidsschemat. Följ anvisningar från funktionärer.', 11),
('timing_info', 'Tidtagning', 'practical', 'Elektronisk tidtagning med transponder. Transponder delas ut vid tävlingsexpeditionen.', 12),
('results_info', 'Resultat', 'practical', 'Resultat publiceras löpande på TheHUB och anslås vid tävlingsexpeditionen.', 13),

-- Faciliteter
('hydration_stations', 'Vätskekontroller', 'facilities', 'Vätskekontroller finns utplacerade längs banan. Ta med egen vattenflaska.', 20),
('toilets_showers', 'Toaletter/Dusch', 'facilities', 'Toaletter finns vid tävlingscentrum. Dusch finns i anslutning till omklädningsrum.', 21),
('bike_wash', 'Cykeltvätt', 'facilities', 'Cykeltvätt finns tillgänglig efter målgång.', 22),
('food_cafe', 'Mat/Café', 'facilities', 'Mat och dryck finns att köpa vid tävlingscentrum.', 23),
('shops_info', 'Affärer', 'facilities', 'Cykelbutik finns på plats med reservdelar och service.', 24),

-- Logistik
('parking_detailed', 'Parkering', 'logistics', 'Parkering finns i anslutning till tävlingsområdet. Följ skyltar och anvisningar.', 30),
('hotel_accommodation', 'Hotell och boende', 'logistics', 'Kontakta lokal turistbyrå för boendealternativ i området.', 31),
('local_info', 'Lokal information', 'logistics', 'Mer information om orten finns på kommunens webbplats.', 32),

-- Kontakter
('contacts_info', 'Kontakter', 'contacts', 'Tävlingsledare: Se eventinformation\nSjukvård: 112 vid nödsituation', 40),
('scf_representatives', 'SCF Representanter', 'contacts', 'SCF-kommissarie och jury utses av Svenska Cykelförbundet.', 41),
('medical_info', 'Sjukvård', 'contacts', 'Sjukvårdspersonal finns på plats under tävlingen. Vid akut nödsituation ring 112.', 42),

-- Media
('media_production', 'Mediaproduktion', 'media', 'Foto och video kan förekomma under eventet. Genom deltagande godkänner du publicering.', 50);

-- ============================================================================
-- SUMMARY
-- ============================================================================
SELECT 'Migration 011 completed' as status,
       (SELECT COUNT(*) FROM global_texts) as global_texts_count;
