-- Migration 020: Add distance and elevation fields to events table
-- For tracking race distance and elevation gain

ALTER TABLE events
ADD COLUMN distance_km DECIMAL(6,2) DEFAULT NULL COMMENT 'Race distance in kilometers' AFTER discipline,
ADD COLUMN elevation_m INT DEFAULT NULL COMMENT 'Total elevation gain in meters' AFTER distance_km;
