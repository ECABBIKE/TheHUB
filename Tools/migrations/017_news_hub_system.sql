-- Migration 016: News Hub System
-- Date: 2026-01-18
-- Description: Extends race_reports for full News Hub functionality
--
-- This migration adds:
-- 1. content_type column for distinguishing different post types
-- 2. series_id for automatic series tagging
-- 3. YouTube video support columns
-- 4. Admin moderation notes
-- 5. Photo gallery support
-- 6. SEO meta fields

-- ============================================================================
-- 1. ENSURE BASE TABLES EXIST (idempotent)
-- ============================================================================

CREATE TABLE IF NOT EXISTS race_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    event_id INT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    content LONGTEXT NOT NULL,
    excerpt TEXT,
    featured_image VARCHAR(255),
    status ENUM('draft', 'pending', 'published', 'archived') DEFAULT 'draft',
    instagram_url VARCHAR(255),
    instagram_embed_code TEXT,
    is_from_instagram TINYINT(1) DEFAULT 0,
    is_featured TINYINT(1) DEFAULT 0,
    views INT DEFAULT 0,
    likes INT DEFAULT 0,
    reading_time_minutes INT DEFAULT 1,
    allow_comments TINYINT(1) DEFAULT 1,
    published_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reports_status (status, published_at),
    INDEX idx_reports_rider (rider_id),
    INDEX idx_reports_event (event_id),
    INDEX idx_reports_featured (is_featured, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS race_report_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    usage_count INT DEFAULT 0,
    color VARCHAR(7) NULL COMMENT 'Hex color for tag display',
    icon VARCHAR(50) NULL COMMENT 'Lucide icon name',
    is_system TINYINT(1) DEFAULT 0 COMMENT 'System tags cannot be deleted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS race_report_tag_relations (
    report_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (report_id, tag_id),
    INDEX idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS race_report_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    rider_id INT NULL,
    parent_comment_id INT NULL,
    comment_text TEXT NOT NULL,
    is_approved TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_comments_report (report_id, is_approved),
    INDEX idx_comments_parent (parent_comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS race_report_likes (
    report_id INT NOT NULL,
    rider_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id, rider_id),
    INDEX idx_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. ADD NEW COLUMNS TO race_reports (safe - uses ALTER IGNORE pattern)
-- ============================================================================

-- Content type for distinguishing different post types
ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS content_type ENUM('race_report', 'photo_gallery', 'news', 'video')
DEFAULT 'race_report' AFTER status;

-- Series connection for automatic tagging
ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS series_id INT NULL AFTER event_id;

-- YouTube support
ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS youtube_url VARCHAR(255) NULL AFTER instagram_embed_code;

ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS youtube_video_id VARCHAR(20) NULL AFTER youtube_url;

ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS is_from_youtube TINYINT(1) DEFAULT 0 AFTER youtube_video_id;

-- Admin moderation
ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS moderation_notes TEXT NULL AFTER allow_comments;

ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS moderated_by INT NULL AFTER moderation_notes;

ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS moderated_at DATETIME NULL AFTER moderated_by;

-- Photo gallery support
ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS gallery_images JSON NULL COMMENT 'Array of image URLs for galleries' AFTER featured_image;

-- SEO meta
ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS meta_description VARCHAR(160) NULL AFTER excerpt;

ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS meta_keywords VARCHAR(255) NULL AFTER meta_description;

-- Discipline for filtering (enduro, dh, xc, gravel etc)
ALTER TABLE race_reports
ADD COLUMN IF NOT EXISTS discipline VARCHAR(50) NULL AFTER content_type;

-- Add index for series
ALTER TABLE race_reports ADD INDEX IF NOT EXISTS idx_reports_series (series_id);

-- Add index for content_type
ALTER TABLE race_reports ADD INDEX IF NOT EXISTS idx_reports_content_type (content_type, status, published_at);

-- Add index for discipline
ALTER TABLE race_reports ADD INDEX IF NOT EXISTS idx_reports_discipline (discipline, status, published_at);

-- ============================================================================
-- 3. EXTEND race_report_tags WITH ADDITIONAL FIELDS
-- ============================================================================

ALTER TABLE race_report_tags
ADD COLUMN IF NOT EXISTS color VARCHAR(7) NULL COMMENT 'Hex color for tag display';

ALTER TABLE race_report_tags
ADD COLUMN IF NOT EXISTS icon VARCHAR(50) NULL COMMENT 'Lucide icon name';

ALTER TABLE race_report_tags
ADD COLUMN IF NOT EXISTS is_system TINYINT(1) DEFAULT 0 COMMENT 'System tags cannot be deleted';

ALTER TABLE race_report_tags
ADD COLUMN IF NOT EXISTS tag_type ENUM('discipline', 'event', 'series', 'location', 'general') DEFAULT 'general';

-- ============================================================================
-- 4. INSERT/UPDATE DEFAULT TAGS
-- ============================================================================

INSERT INTO race_report_tags (name, slug, color, icon, is_system, tag_type) VALUES
('Enduro', 'enduro', '#FFE009', 'mountain', 1, 'discipline'),
('Downhill', 'downhill', '#FF6B35', 'arrow-down', 1, 'discipline'),
('XC', 'xc', '#2E7D32', 'bike', 1, 'discipline'),
('Gravel', 'gravel', '#795548', 'map', 1, 'discipline'),
('Dual Slalom', 'dual-slalom', '#E91E63', 'split', 1, 'discipline'),
('SM', 'sm', '#FFD700', 'trophy', 1, 'event'),
('NM', 'nm', '#C0C0C0', 'flag', 1, 'event'),
('Rookie', 'rookie', '#4CAF50', 'star', 1, 'general'),
('Foton', 'foton', '#2196F3', 'camera', 1, 'general'),
('Video', 'video', '#FF0000', 'play', 1, 'general'),
('Teknik', 'teknik', '#9C27B0', 'settings', 1, 'general'),
('Utrustning', 'utrustning', '#607D8B', 'wrench', 1, 'general'),
('Are', 'are', '#00BCD4', 'map-pin', 0, 'location'),
('Isaberg', 'isaberg', '#00BCD4', 'map-pin', 0, 'location'),
('Kungsbacka', 'kungsbacka', '#00BCD4', 'map-pin', 0, 'location')
ON DUPLICATE KEY UPDATE
    color = VALUES(color),
    icon = VALUES(icon),
    is_system = VALUES(is_system),
    tag_type = VALUES(tag_type);

-- ============================================================================
-- 5. SPONSOR SETTINGS FOR NEWS HUB
-- ============================================================================

INSERT INTO sponsor_settings (setting_key, setting_value, description) VALUES
('news_ads_enabled', '1', 'Enable ads on news pages'),
('news_ads_frequency', '4', 'Show ad every N posts in feed'),
('news_google_adsense_client', '', 'Google AdSense client ID (ca-pub-xxx)'),
('news_google_adsense_slot', '', 'Google AdSense slot ID for news pages'),
('race_reports_public', '1', 'Show news/race reports to all visitors')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- ============================================================================
-- 6. CREATE NEWS PAGE VIEW TRACKING TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS news_page_views (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    visitor_hash VARCHAR(64) NOT NULL COMMENT 'Hashed IP for unique visitor tracking',
    session_id VARCHAR(100) NULL,
    user_id INT NULL,
    referer VARCHAR(255) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_date (report_id, created_at),
    INDEX idx_visitor (visitor_hash, report_id),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
