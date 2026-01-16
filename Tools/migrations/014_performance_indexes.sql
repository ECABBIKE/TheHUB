-- ============================================================================
-- Migration 014: Performance indexes for Journey Analysis
-- Adds indexes required for efficient cohort calculations
-- ============================================================================

-- Index on results.cyclist_id for fast rider lookups
CREATE INDEX IF NOT EXISTS idx_results_cyclist ON results(cyclist_id);

-- Index on results.event_id for fast event joins
CREATE INDEX IF NOT EXISTS idx_results_event ON results(event_id);

-- Index on events.date for fast year filtering
CREATE INDEX IF NOT EXISTS idx_events_date ON events(date);

-- Composite index for the cohort query
CREATE INDEX IF NOT EXISTS idx_results_cyclist_event ON results(cyclist_id, event_id);
