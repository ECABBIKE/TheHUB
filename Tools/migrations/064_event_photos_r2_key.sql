-- Migration 064: Cloudflare R2 storage key for event photos
-- Lagrar R2-objektnyckeln så att bilder kan raderas från R2

ALTER TABLE event_photos
ADD COLUMN IF NOT EXISTS r2_key VARCHAR(300) DEFAULT NULL AFTER thumbnail_url;

-- Index för att hitta bilder per R2-nyckel
ALTER TABLE event_photos
ADD INDEX IF NOT EXISTS idx_r2_key (r2_key);
