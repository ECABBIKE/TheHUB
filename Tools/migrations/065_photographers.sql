-- Migration 065: Photographers table and album/photo foreign keys
-- Skapar en dedikerad tabell för fotografer med profildata och sociala medier

CREATE TABLE IF NOT EXISTS photographers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(200) DEFAULT NULL,
    email VARCHAR(200) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,
    website_url VARCHAR(500) DEFAULT NULL,
    instagram_url VARCHAR(500) DEFAULT NULL,
    facebook_url VARCHAR(500) DEFAULT NULL,
    youtube_url VARCHAR(500) DEFAULT NULL,
    rider_id INT DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_slug (slug),
    INDEX idx_name (name),
    INDEX idx_active (active),
    INDEX idx_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lägg till photographer_id i event_albums
ALTER TABLE event_albums ADD COLUMN photographer_id INT DEFAULT NULL AFTER photographer_url;
ALTER TABLE event_albums ADD INDEX idx_photographer (photographer_id);

-- Lägg till photographer_id i event_photos (per-foto fotograf)
ALTER TABLE event_photos ADD COLUMN photographer_id INT DEFAULT NULL AFTER photographer;
ALTER TABLE event_photos ADD INDEX idx_photo_photographer (photographer_id);

-- Backfill: Skapa fotografer från befintliga album-data
INSERT IGNORE INTO photographers (name, website_url, slug)
SELECT DISTINCT
    ea.photographer,
    MAX(ea.photographer_url),
    LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(ea.photographer, ' ', '-'), 'å', 'a'), 'ä', 'a'), 'ö', 'o'), 'é', 'e'))
FROM event_albums ea
WHERE ea.photographer IS NOT NULL
  AND ea.photographer != ''
GROUP BY ea.photographer;

-- Koppla befintliga album till nya photographer-poster
UPDATE event_albums ea
INNER JOIN photographers p ON ea.photographer = p.name
SET ea.photographer_id = p.id
WHERE ea.photographer IS NOT NULL AND ea.photographer != '';
