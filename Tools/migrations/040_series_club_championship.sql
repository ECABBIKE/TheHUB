-- Add enable_club_championship flag to series table
-- This allows series admins to enable/disable club championship standings

ALTER TABLE series
ADD COLUMN IF NOT EXISTS enable_club_championship TINYINT(1) DEFAULT 1
COMMENT 'Visa klubbmästerskap för denna serie';
