-- ============================================================================
-- Migration: Add stage_names to events
-- Description: Allow custom naming of split time columns per event
-- Example: {"1":"SS1","2":"SS2","3":"SS3","4":"SS3-1","5":"SS4"}
-- Created: 2025-11-22
-- ============================================================================

-- Add stage_names column to events table
ALTER TABLE `events`
ADD COLUMN `stage_names` TEXT DEFAULT NULL COMMENT 'JSON mapping of stage numbers to display names';
