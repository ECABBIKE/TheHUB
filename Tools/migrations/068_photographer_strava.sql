-- Add strava_url to photographers (same social media as riders)
ALTER TABLE photographers ADD COLUMN strava_url VARCHAR(255) DEFAULT NULL AFTER tiktok_url;
