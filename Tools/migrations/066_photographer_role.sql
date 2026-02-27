-- Migration 066: Photographer role and self-service access
-- Lägger till 'photographer' som roll i admin_users och kopplingstabell för album-access

-- Uppdatera admin_users role ENUM för att inkludera photographer
ALTER TABLE admin_users MODIFY COLUMN role ENUM('super_admin', 'admin', 'promotor', 'photographer', 'editor') DEFAULT 'editor';

-- Lägg till admin_user_id i photographers-tabellen (koppling till inloggning)
ALTER TABLE photographers ADD COLUMN admin_user_id INT DEFAULT NULL AFTER rider_id;
ALTER TABLE photographers ADD INDEX idx_admin_user (admin_user_id);

-- Kopplingstabell: vilka album en fotograf har tillgång till
CREATE TABLE IF NOT EXISTS photographer_albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    album_id INT NOT NULL,
    can_upload TINYINT(1) DEFAULT 1,
    can_edit TINYINT(1) DEFAULT 1,
    granted_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_album (user_id, album_id),
    INDEX idx_user_id (user_id),
    INDEX idx_album_id (album_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auto-koppla befintliga album till fotografer via photographer_id
-- Om en photographer har admin_user_id satt, koppla deras album automatiskt
INSERT IGNORE INTO photographer_albums (user_id, album_id, can_upload, can_edit)
SELECT p.admin_user_id, ea.id, 1, 1
FROM photographers p
JOIN event_albums ea ON ea.photographer_id = p.id
WHERE p.admin_user_id IS NOT NULL;
