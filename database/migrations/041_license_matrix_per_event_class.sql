-- Migration 041: Add event_license_class to class_license_eligibility
-- This allows different license rules for national/sportmotion/motion events
-- The matrix becomes 3-dimensional: event_license_class + class + license_type

-- Add event_license_class column
ALTER TABLE class_license_eligibility
ADD COLUMN IF NOT EXISTS event_license_class ENUM('national', 'sportmotion', 'motion')
    DEFAULT 'national'
    COMMENT 'Which event license class this rule applies to'
    AFTER id;

-- Drop the old unique key and create a new one including event_license_class
-- First check if the old key exists and drop it
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
    AND table_name = 'class_license_eligibility'
    AND index_name = 'unique_class_license');

SET @sqlstmt := IF(@exist > 0,
    'ALTER TABLE class_license_eligibility DROP INDEX unique_class_license',
    'SELECT "Index does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create new unique key with event_license_class
ALTER TABLE class_license_eligibility
ADD UNIQUE KEY IF NOT EXISTS unique_event_class_license (event_license_class, class_id, license_type_code);

-- Add index for filtering by event_license_class
ALTER TABLE class_license_eligibility
ADD INDEX IF NOT EXISTS idx_event_license_class (event_license_class);

-- Copy existing data to all three event types (so nothing breaks)
-- First, update existing rows to be 'national' (they already have this as default)
UPDATE class_license_eligibility SET event_license_class = 'national' WHERE event_license_class IS NULL;

-- Then duplicate for sportmotion (copy all national rules)
INSERT IGNORE INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed, notes)
SELECT 'sportmotion', class_id, license_type_code, is_allowed, notes
FROM class_license_eligibility
WHERE event_license_class = 'national';

-- And for motion (copy all national rules)
INSERT IGNORE INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed, notes)
SELECT 'motion', class_id, license_type_code, is_allowed, notes
FROM class_license_eligibility
WHERE event_license_class = 'national';

-- Verify
SELECT 'License matrix structure updated:' as status;
SELECT event_license_class, COUNT(*) as rules_count
FROM class_license_eligibility
GROUP BY event_license_class;
