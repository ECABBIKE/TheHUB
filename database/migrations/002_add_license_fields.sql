-- Migration: Add license fields to cyclists table
-- Created: 2025-01-12
-- Description: Adds license_type, license_category, and discipline fields

ALTER TABLE cyclists
ADD COLUMN license_type VARCHAR(50) COMMENT 'License type: Elite, Youth, Hobby' AFTER license_number,
ADD COLUMN license_category VARCHAR(50) COMMENT 'License category: Elite Men, Base License Women, etc' AFTER license_type,
ADD COLUMN discipline VARCHAR(50) COMMENT 'Discipline: MTB, Road, Track, BMX, etc' AFTER license_category,
ADD COLUMN license_valid_until DATE COMMENT 'License expiry date' AFTER discipline;

-- Add indexes for performance
CREATE INDEX idx_license_type ON cyclists(license_type);
CREATE INDEX idx_license_category ON cyclists(license_category);
CREATE INDEX idx_discipline ON cyclists(discipline);
CREATE INDEX idx_license_valid ON cyclists(license_valid_until);

-- Update existing records with default values (optional)
-- UPDATE cyclists SET license_type = 'Elite' WHERE license_type IS NULL AND active = 1;
