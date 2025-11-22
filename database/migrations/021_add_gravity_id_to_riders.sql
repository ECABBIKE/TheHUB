-- Migration 021: Add Gravity ID to riders table
-- Unique identifier for platform members with discount benefits

ALTER TABLE riders
ADD COLUMN gravity_id VARCHAR(20) DEFAULT NULL COMMENT 'Unique Gravity ID for platform members' AFTER license_number,
ADD COLUMN gravity_id_since DATE DEFAULT NULL COMMENT 'Date when Gravity ID was assigned' AFTER gravity_id;

-- Add index for faster lookups
CREATE INDEX idx_riders_gravity_id ON riders(gravity_id);
