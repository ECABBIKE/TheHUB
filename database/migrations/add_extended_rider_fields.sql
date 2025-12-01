-- Migration: Add Extended Rider Fields for Private Data
-- Date: 2025-11-15
-- Updated: 2025-12-01 - Removed personnummer column (no longer stored)
-- Description: Add fields for full rider data including address, emergency contact, disciplines, etc.
-- IMPORTANT: This data is PRIVATE and should NOT be exposed publicly

-- Add new private fields to riders table
ALTER TABLE riders
  -- Address information
  -- NOTE: personnummer column removed 2025-12-01 - only birth_year is stored now
  ADD COLUMN IF NOT EXISTS address VARCHAR(255) AFTER city,
  ADD COLUMN IF NOT EXISTS postal_code VARCHAR(10) AFTER address,
  ADD COLUMN IF NOT EXISTS country VARCHAR(100) DEFAULT 'Sverige' AFTER postal_code,

  -- Emergency contact
  ADD COLUMN IF NOT EXISTS emergency_contact VARCHAR(255) AFTER phone,

  -- District and Team
  ADD COLUMN IF NOT EXISTS district VARCHAR(100) AFTER country,
  ADD COLUMN IF NOT EXISTS team VARCHAR(255) AFTER club_id,

  -- Disciplines (JSON format to store multiple: Road, Track, BMX, CX, Trial, Para, MTB, E-cycling, Gravel)
  ADD COLUMN IF NOT EXISTS disciplines JSON AFTER discipline,

  -- License year
  ADD COLUMN IF NOT EXISTS license_year INT AFTER license_valid_until;

-- Add indexes for searching
ALTER TABLE riders
  ADD INDEX IF NOT EXISTS idx_postal_code (postal_code),
  ADD INDEX IF NOT EXISTS idx_district (district);

-- Add comment to table to mark private fields
ALTER TABLE riders COMMENT = 'PRIVACY: Fields address, postal_code, phone, emergency_contact are PRIVATE and must not be exposed publicly';
