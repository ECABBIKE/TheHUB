-- Migration 055: Segment Sponsors
-- Allows sponsors to be linked to map segments (stages)
-- Shows as "SS1 By Sponsor" on the map with optional banner
-- Uses existing sponsors table from schema.sql

-- Add sponsor_id to event_track_segments (uses existing sponsors table)
ALTER TABLE event_track_segments
ADD COLUMN sponsor_id INT NULL AFTER segment_name;

-- Add foreign key constraint
ALTER TABLE event_track_segments
ADD CONSTRAINT fk_segment_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE SET NULL;

-- Add index for faster lookups
CREATE INDEX idx_segments_sponsor ON event_track_segments(sponsor_id);
