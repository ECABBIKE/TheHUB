-- Add foreign key constraint for venue_id in events table
-- Run this migration on existing databases

-- Add index first (required for foreign key)
ALTER TABLE events ADD INDEX IF NOT EXISTS idx_venue (venue_id);

-- Add foreign key constraint
ALTER TABLE events
ADD CONSTRAINT fk_events_venue
FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL;
