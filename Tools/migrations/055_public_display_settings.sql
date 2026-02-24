-- ============================================================================
-- Migration 055: Public Display Settings in Database
-- Moves public_riders_display setting from file-based to database-based
-- ============================================================================

-- Seed default values for public display settings
INSERT IGNORE INTO sponsor_settings (setting_key, setting_value, description) VALUES
('public_riders_display', 'with_results', 'Show all riders or only those with results (all/with_results)'),
('min_results_to_show', '1', 'Minimum number of results required to show rider');
