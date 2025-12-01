-- =====================================================
-- MEDIA & SPONSOR SYSTEM
-- Migration 044 - Complete media library and sponsor management
-- =====================================================

-- Central media storage
CREATE TABLE IF NOT EXISTS media (
    id INT PRIMARY KEY AUTO_INCREMENT,
    filename VARCHAR(255) NOT NULL COMMENT 'Generated filename (unique)',
    original_filename VARCHAR(255) NOT NULL COMMENT 'Original upload name',
    filepath VARCHAR(500) NOT NULL COMMENT 'Relative path from root: uploads/media/...',
    mime_type VARCHAR(100) NOT NULL,
    size INT NOT NULL COMMENT 'File size in bytes',
    width INT COMMENT 'Image width in pixels',
    height INT COMMENT 'Image height in pixels',
    folder VARCHAR(100) DEFAULT 'general' COMMENT 'series|sponsors|ads|clubs|events|general',
    uploaded_by INT COMMENT 'Admin user ID',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    alt_text VARCHAR(255) COMMENT 'Alt text for accessibility',
    caption TEXT COMMENT 'Image caption/description',
    metadata JSON COMMENT 'Additional metadata',

    INDEX idx_folder (folder),
    INDEX idx_mime (mime_type),
    INDEX idx_uploaded_at (uploaded_at),
    INDEX idx_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track where media is used (audit trail)
CREATE TABLE IF NOT EXISTS media_usage (
    id INT PRIMARY KEY AUTO_INCREMENT,
    media_id INT NOT NULL,
    entity_type VARCHAR(50) NOT NULL COMMENT 'series|event|sponsor|club|page|ad',
    entity_id INT NOT NULL,
    field VARCHAR(50) NOT NULL COMMENT 'logo|header|banner|badge_logo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_media (media_id),
    INDEX idx_entity (entity_type, entity_id),

    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SPONSOR SYSTEM
-- =====================================================

-- Sponsors database
CREATE TABLE IF NOT EXISTS sponsors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE COMMENT 'URL-friendly name',
    logo_media_id INT COMMENT 'Logo from media library',
    website VARCHAR(255),
    tier ENUM('title', 'gold', 'silver', 'bronze') DEFAULT 'bronze',
    description TEXT,
    contact_name VARCHAR(255),
    contact_email VARCHAR(255),
    contact_phone VARCHAR(50),
    active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0 COMMENT 'Sort order in listings',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_tier (tier),
    INDEX idx_active (active),
    INDEX idx_display_order (display_order),
    INDEX idx_slug (slug),

    FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sponsor packages/contracts (revenue tracking)
CREATE TABLE IF NOT EXISTS sponsor_packages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sponsor_id INT NOT NULL,
    package_type ENUM('title', 'gold', 'silver', 'bronze', 'custom') NOT NULL,
    season VARCHAR(10) COMMENT 'Season: 2025, 2026, etc',
    price DECIMAL(10,2) COMMENT 'Package price in SEK',
    start_date DATE,
    end_date DATE,
    benefits TEXT COMMENT 'What sponsor gets',
    notes TEXT,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_sponsor (sponsor_id),
    INDEX idx_season (season),
    INDEX idx_active (active),

    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sponsor assignments to series
CREATE TABLE IF NOT EXISTS series_sponsors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    series_id INT NOT NULL,
    sponsor_id INT NOT NULL,
    placement ENUM('header', 'badge', 'sidebar', 'footer') DEFAULT 'sidebar',
    display_order INT DEFAULT 0,
    start_date DATE,
    end_date DATE,

    UNIQUE KEY unique_series_sponsor_placement (series_id, sponsor_id, placement),
    INDEX idx_series (series_id),
    INDEX idx_sponsor (sponsor_id),

    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sponsor assignments to events
CREATE TABLE IF NOT EXISTS event_sponsors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    sponsor_id INT NOT NULL,
    placement ENUM('header', 'sidebar', 'footer') DEFAULT 'sidebar',
    display_order INT DEFAULT 0,

    UNIQUE KEY unique_event_sponsor_placement (event_id, sponsor_id, placement),
    INDEX idx_event (event_id),
    INDEX idx_sponsor (sponsor_id),

    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AD PLACEMENT SYSTEM
-- =====================================================

-- Ad placements/banners with tracking
CREATE TABLE IF NOT EXISTS ad_placements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL COMMENT 'Ad campaign name',
    location VARCHAR(100) NOT NULL COMMENT 'homepage_header|series_sidebar|event_footer',
    media_id INT COMMENT 'Banner image from media library',
    link_url VARCHAR(500) COMMENT 'Click destination URL',
    sponsor_id INT COMMENT 'Associated sponsor',
    start_date DATE,
    end_date DATE,
    active BOOLEAN DEFAULT TRUE,
    impressions INT DEFAULT 0 COMMENT 'View count',
    clicks INT DEFAULT 0 COMMENT 'Click count',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_location (location),
    INDEX idx_active (active),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_sponsor (sponsor_id),

    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE SET NULL,
    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- EXTEND EXISTING TABLES (safe - only adds columns)
-- =====================================================

-- Add badge design fields to series (ignore if exists)
ALTER TABLE series
ADD COLUMN logo_light_media_id INT COMMENT 'Light theme logo',
ADD COLUMN logo_dark_media_id INT COMMENT 'Dark theme logo',
ADD COLUMN gradient_start VARCHAR(7) DEFAULT '#004A98' COMMENT 'Badge gradient start',
ADD COLUMN gradient_end VARCHAR(7) DEFAULT '#002a5c' COMMENT 'Badge gradient end',
ADD COLUMN accent_color VARCHAR(7) DEFAULT '#61CE70' COMMENT 'Badge accent color';

-- Add header image to events (ignore if exists)
ALTER TABLE events
ADD COLUMN header_media_id INT COMMENT 'Event header/banner image';

-- Add logos to clubs (ignore if exists)
ALTER TABLE clubs
ADD COLUMN logo_media_id INT COMMENT 'Club logo';
