-- Migration 040: Add license_class field to events
-- Controls which license types can register for the event
-- Separate from event_level which controls ranking points

-- Add license_class column
ALTER TABLE events
ADD COLUMN IF NOT EXISTS license_class ENUM('national', 'sportmotion', 'motion')
    DEFAULT 'national'
    COMMENT 'Licensklass: national=tävlingslicens krävs, sportmotion=engångs+motion ok, motion=alla licenser'
    AFTER event_level;

-- Copy existing event_level values to license_class as default
-- (Events that were sportmotion for ranking are likely also sportmotion for licensing)
UPDATE events SET license_class = event_level WHERE license_class IS NULL OR license_class = 'national';

-- Add index for filtering
ALTER TABLE events ADD INDEX IF NOT EXISTS idx_license_class (license_class);

-- Verify
SELECT 'License class added to events:' as status;
SELECT id, name, event_level, license_class FROM events LIMIT 10;
