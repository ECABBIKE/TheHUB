-- Migration 026: Populate series_events from events.series_id
-- Date: 2026-01-25
-- Description: Syncs events with series_id to series_events table for many-to-many relationship

-- Insert events that have series_id but aren't in series_events yet
INSERT INTO series_events (series_id, event_id, template_id, sort_order)
SELECT
    e.series_id,
    e.id,
    NULL,
    ROW_NUMBER() OVER (PARTITION BY e.series_id ORDER BY e.date ASC)
FROM events e
WHERE e.series_id IS NOT NULL
AND e.id NOT IN (
    SELECT event_id
    FROM series_events
    WHERE series_id = e.series_id
)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- Update sort_order for all series to be chronological
-- This is a helper statement - run if needed to fix ordering
-- UPDATE series_events se
-- JOIN events e ON se.event_id = e.id
-- SET se.sort_order = (
--     SELECT COUNT(*)
--     FROM series_events se2
--     JOIN events e2 ON se2.event_id = e2.id
--     WHERE se2.series_id = se.series_id
--     AND e2.date <= e.date
-- );
