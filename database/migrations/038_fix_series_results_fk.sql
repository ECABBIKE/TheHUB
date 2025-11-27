-- Fix series_results foreign key to reference point_scales instead of qualification_point_templates
-- Run this AFTER 037_series_results_table.sql

-- Step 1: Set template_id to NULL where it doesn't exist in point_scales
UPDATE `series_results`
SET `template_id` = NULL
WHERE `template_id` IS NOT NULL
  AND `template_id` NOT IN (SELECT id FROM point_scales);

-- Step 2: Drop the old foreign key constraint
ALTER TABLE `series_results`
DROP FOREIGN KEY `series_results_ibfk_5`;

-- Step 3: Add new foreign key referencing point_scales
ALTER TABLE `series_results`
ADD CONSTRAINT `series_results_template_fk`
FOREIGN KEY (`template_id`) REFERENCES `point_scales`(`id`) ON DELETE SET NULL;

-- Verify
SELECT 'Foreign key updated to reference point_scales' as status;
