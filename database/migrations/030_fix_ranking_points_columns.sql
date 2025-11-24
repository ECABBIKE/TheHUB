-- Migration: Fix ranking_points missing columns
-- Description: Add missing discipline and event_level_multiplier columns to ranking_points table
-- Date: 2025-11-24

-- Add discipline column if missing
ALTER TABLE ranking_points
ADD COLUMN IF NOT EXISTS discipline VARCHAR(50) NOT NULL DEFAULT 'GRAVITY' AFTER class_id;

-- Add event_level_multiplier column if missing
ALTER TABLE ranking_points
ADD COLUMN IF NOT EXISTS event_level_multiplier DECIMAL(5,4) NOT NULL DEFAULT 1.0000 AFTER field_multiplier;

-- Add index on discipline if missing
ALTER TABLE ranking_points
ADD INDEX IF NOT EXISTS idx_discipline (discipline);
