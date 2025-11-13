-- Add logo and organizer columns to series table
-- Run this migration on existing databases

ALTER TABLE series
ADD COLUMN IF NOT EXISTS logo VARCHAR(255) AFTER website,
ADD COLUMN IF NOT EXISTS organizer VARCHAR(255) AFTER logo;
