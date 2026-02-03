-- Migration 034: Event Extended Fields
-- Adds support for:
-- 1. Event logo (for events without series)
-- 2. Multi-day events (end_date)
-- 3. Multiple formats/disciplines per event

-- Add logo field (stores media path from media archive)
ALTER TABLE events ADD COLUMN IF NOT EXISTS logo VARCHAR(255) NULL AFTER description;

-- Add media ID reference for logo (links to media table)
ALTER TABLE events ADD COLUMN IF NOT EXISTS logo_media_id INT NULL AFTER logo;

-- Add end_date for multi-day events (festivals, stage races)
ALTER TABLE events ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER date;

-- Add formats field for multiple disciplines (JSON array or comma-separated)
-- Example: "ENDURO,DH,XC" or ["ENDURO","DH","XC"]
ALTER TABLE events ADD COLUMN IF NOT EXISTS formats VARCHAR(500) NULL AFTER discipline;

-- Add event_type to distinguish regular events from festivals/multi-events
-- Values: 'single' (default), 'festival', 'stage_race', 'multi_event'
ALTER TABLE events ADD COLUMN IF NOT EXISTS event_type ENUM('single', 'festival', 'stage_race', 'multi_event') DEFAULT 'single' AFTER type;

-- Index for date range queries
CREATE INDEX IF NOT EXISTS idx_events_date_range ON events(date, end_date);
