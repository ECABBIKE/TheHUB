-- Migration 025: Add course tracks (bansträckningar) tab to events
-- Adds fields for course/track descriptions

-- Add course_tracks columns to events table
ALTER TABLE events
ADD COLUMN IF NOT EXISTS course_tracks TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS course_tracks_use_global TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS course_tracks_hidden TINYINT(1) DEFAULT 0;

-- Add global text entry for course tracks
INSERT INTO global_texts (field_key, field_name, field_category, content, is_active, sort_order)
VALUES ('course_tracks', 'Bansträckningar', 'practical', '', 1, 50)
ON DUPLICATE KEY UPDATE field_name = VALUES(field_name), field_category = VALUES(field_category);
