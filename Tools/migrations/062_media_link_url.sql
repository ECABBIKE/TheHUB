-- Migration 062: Add link_url column to media table
-- Allows associating a website URL with media files (e.g., sponsor logos linking to sponsor website)

ALTER TABLE media
ADD COLUMN IF NOT EXISTS link_url VARCHAR(500) DEFAULT NULL AFTER caption;
