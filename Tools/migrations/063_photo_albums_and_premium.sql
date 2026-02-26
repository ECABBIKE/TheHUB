-- Migration 063: Photo albums, rider tagging & built-in premium
-- Bildbank med Google Photos-koppling och manuell rider-taggning
-- Premium-membership oberoende av betalningsleverantör

-- 1. Built-in premium: admin kan sätta premium direkt på rider
ALTER TABLE riders
ADD COLUMN IF NOT EXISTS premium_until DATE DEFAULT NULL AFTER experience_level;

-- 2. Event-album: kopplar event till Google Photos
CREATE TABLE IF NOT EXISTS event_albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    title VARCHAR(200) DEFAULT NULL,
    google_photos_url VARCHAR(500) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    photographer VARCHAR(150) DEFAULT NULL,
    photographer_url VARCHAR(500) DEFAULT NULL,
    cover_photo_id INT DEFAULT NULL,
    photo_count INT DEFAULT 0,
    is_published TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_event (event_id),
    INDEX idx_published (is_published),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Event-foton: enskilda bilder i albumet
CREATE TABLE IF NOT EXISTS event_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    album_id INT NOT NULL,
    media_id INT DEFAULT NULL,
    external_url VARCHAR(500) DEFAULT NULL,
    thumbnail_url VARCHAR(500) DEFAULT NULL,
    caption VARCHAR(500) DEFAULT NULL,
    photographer VARCHAR(150) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    is_highlight TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_album (album_id),
    INDEX idx_media (media_id),
    INDEX idx_highlight (is_highlight),
    FOREIGN KEY (album_id) REFERENCES event_albums(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Rider-taggning: koppla riders till foton
CREATE TABLE IF NOT EXISTS photo_rider_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    photo_id INT NOT NULL,
    rider_id INT NOT NULL,
    tagged_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tag (photo_id, rider_id),
    INDEX idx_rider (rider_id),
    INDEX idx_photo (photo_id),
    FOREIGN KEY (photo_id) REFERENCES event_photos(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
