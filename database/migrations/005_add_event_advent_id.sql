-- Add advent_id field to events table for result imports
-- Migration: 005_add_event_advent_id.sql

ALTER TABLE events
ADD COLUMN advent_id VARCHAR(50) AFTER name,
ADD INDEX idx_advent_id (advent_id);
