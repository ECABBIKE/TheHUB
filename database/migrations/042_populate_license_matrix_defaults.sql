-- Migration 042: Ensure license matrix table has correct structure
-- The matrix should be configured manually via /admin/license-class-matrix.php
-- This migration only verifies the structure exists - does NOT auto-populate

-- Verify class_license_eligibility table exists with event_license_class column
-- (Should already exist from migration 041, but ensure it's correct)

-- Check structure
SELECT 'Verifying class_license_eligibility table structure:' as status;

SELECT
    COUNT(*) as total_rules,
    SUM(CASE WHEN event_license_class = 'national' THEN 1 ELSE 0 END) as national_rules,
    SUM(CASE WHEN event_license_class = 'sportmotion' THEN 1 ELSE 0 END) as sportmotion_rules,
    SUM(CASE WHEN event_license_class = 'motion' THEN 1 ELSE 0 END) as motion_rules
FROM class_license_eligibility;

-- Show instructions
SELECT 'IMPORTANT: License matrix must be configured manually!' as message;
SELECT 'Go to: /admin/license-class-matrix.php' as instructions;
SELECT 'Configure which license types can register for each class' as details;

-- Show available license types
SELECT 'Available license types:' as info;
SELECT code, name FROM license_types WHERE is_active = 1 ORDER BY priority DESC;

-- Show classes without any rules configured
SELECT 'Classes without license rules (need configuration):' as warning;
SELECT c.id, c.name, c.display_name, c.gender
FROM classes c
LEFT JOIN class_license_eligibility cle ON c.id = cle.class_id AND cle.event_license_class = 'national'
WHERE c.active = 1
  AND cle.id IS NULL
ORDER BY c.sort_order;
