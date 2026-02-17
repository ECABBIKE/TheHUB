-- Migration 048: Sponsor Placement Custom Image & Rotation
-- Date: 2026-02-17
-- Description: Adds custom_media_id to sponsor_placements for custom images per placement
--              Enables ad rotation with custom images from media library

-- Add custom image override per placement
ALTER TABLE sponsor_placements ADD COLUMN custom_media_id INT NULL AFTER sponsor_id;

-- Add index for the media join
ALTER TABLE sponsor_placements ADD INDEX idx_custom_media (custom_media_id);
