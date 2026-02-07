-- Migration 036: Add registration_opens to events table
-- Adds registration_opens DATETIME column so admin can set when event registration opens
-- Also ensures registration_deadline supports time component

-- Add registration_opens as DATETIME (allows setting both date and time)
ALTER TABLE events ADD COLUMN IF NOT EXISTS registration_opens DATETIME NULL AFTER registration_deadline;

-- Ensure registration_deadline_time exists for time component of deadline
ALTER TABLE events ADD COLUMN IF NOT EXISTS registration_deadline_time TIME NULL AFTER registration_deadline;
