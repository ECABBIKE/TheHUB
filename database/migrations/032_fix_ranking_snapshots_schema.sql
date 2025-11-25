-- Migration: Fix ranking_snapshots schema
-- Description: Ensures ranking_snapshots has the discipline column if it's missing
-- Date: 2025-11-24

-- Note: This migration may produce errors if columns/indexes already exist - this is expected and safe to ignore

-- Add discipline column if it doesn't exist (may error if exists - safe to ignore)
ALTER TABLE ranking_snapshots ADD COLUMN discipline VARCHAR(50) NOT NULL DEFAULT 'GRAVITY' AFTER rider_id;

-- Drop old unique key if it exists (may error if not exists - safe to ignore)
ALTER TABLE ranking_snapshots DROP INDEX unique_rider_snapshot;

-- Add the correct unique key (may error if exists - safe to ignore)
ALTER TABLE ranking_snapshots ADD UNIQUE KEY unique_rider_discipline_snapshot (rider_id, discipline, snapshot_date);

-- Add index (may error if exists - safe to ignore)
ALTER TABLE ranking_snapshots ADD INDEX idx_discipline_ranking (discipline, snapshot_date, ranking_position);
