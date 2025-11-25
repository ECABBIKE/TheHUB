-- Migration: Fix ranking_snapshots schema
-- Description: Ensures ranking_snapshots has the discipline column if it's missing
-- Date: 2025-11-24

-- Note: This migration may produce errors if columns/indexes already exist - this is expected and safe to ignore

-- Step 1: Add discipline column if it doesn't exist (may error if exists - safe to ignore)
ALTER TABLE ranking_snapshots ADD COLUMN discipline VARCHAR(50) NOT NULL DEFAULT 'GRAVITY' AFTER rider_id;

-- Step 2: Find and drop foreign key constraints that depend on the unique index
-- Get the constraint name (may vary), then drop it
SET @constraint_name = (SELECT CONSTRAINT_NAME
                        FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'ranking_snapshots'
                        AND CONSTRAINT_NAME LIKE '%fk%'
                        AND COLUMN_NAME = 'rider_id'
                        LIMIT 1);

SET @drop_fk_sql = IF(@constraint_name IS NOT NULL,
                       CONCAT('ALTER TABLE ranking_snapshots DROP FOREIGN KEY ', @constraint_name),
                       'SELECT "No FK to drop" AS info');
PREPARE stmt FROM @drop_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: Drop old unique key if it exists (may error if not exists - safe to ignore)
ALTER TABLE ranking_snapshots DROP INDEX IF EXISTS unique_rider_snapshot;

-- Step 4: Add the correct unique key (may error if exists - safe to ignore)
ALTER TABLE ranking_snapshots ADD UNIQUE KEY IF NOT EXISTS unique_rider_discipline_snapshot (rider_id, discipline, snapshot_date);

-- Step 5: Add discipline ranking index (may error if exists - safe to ignore)
ALTER TABLE ranking_snapshots ADD INDEX IF NOT EXISTS idx_discipline_ranking (discipline, snapshot_date, ranking_position);

-- Step 6: Re-add foreign key constraint on rider_id
ALTER TABLE ranking_snapshots ADD CONSTRAINT IF NOT EXISTS fk_ranking_snapshots_rider
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE;
