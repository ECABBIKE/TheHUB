-- Migration: Optimize ranking queries
-- Description: Adds indexes to speed up ranking calculations
-- Date: 2025-11-24

-- Add index on events.date for faster date range queries
ALTER TABLE events ADD INDEX IF NOT EXISTS idx_events_date_discipline (date, discipline);

-- Add composite index on results for faster ranking queries
ALTER TABLE results ADD INDEX IF NOT EXISTS idx_results_ranking (event_id, status, points, run_1_points, run_2_points);

-- Add index on riders.club_id for faster club aggregation
ALTER TABLE riders ADD INDEX IF NOT EXISTS idx_riders_club (club_id);
