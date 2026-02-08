-- Add pricing_template_id column to events table
-- This allows events to use pricing templates instead of legacy event_pricing_rules

ALTER TABLE events
ADD COLUMN IF NOT EXISTS pricing_template_id INT NULL
AFTER point_scale_id;

-- Add index for faster lookups
CREATE INDEX IF NOT EXISTS idx_pricing_template
ON events(pricing_template_id);
