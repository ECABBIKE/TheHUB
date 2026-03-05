-- Migration 076: Add display_size to event_sponsors and add 'partner' to placement ENUM
-- 2026-03-05

-- Add 'partner' to placement ENUM for event_sponsors
ALTER TABLE event_sponsors MODIFY COLUMN placement ENUM('header', 'sidebar', 'footer', 'content', 'partner') DEFAULT 'sidebar';

-- Add display_size column to event_sponsors (for partner logos large/small)
ALTER TABLE event_sponsors ADD COLUMN display_size ENUM('large', 'small') NOT NULL DEFAULT 'small' AFTER display_order;
