-- ============================================================================
-- Migration 014: Performance indexes for Journey Analysis
-- Adds indexes required for efficient cohort calculations
-- ============================================================================

-- Index on results.cyclist_id for fast rider lookups
-- Using ALTER IGNORE to skip if exists
ALTER TABLE results ADD INDEX idx_results_cyclist (cyclist_id);

-- Index on results.event_id for fast event joins
ALTER TABLE results ADD INDEX idx_results_event (event_id);

-- Index on events.date for fast year filtering
ALTER TABLE events ADD INDEX idx_events_date (date);

-- Composite index for the cohort query
ALTER TABLE results ADD INDEX idx_results_cyclist_event (cyclist_id, event_id);
