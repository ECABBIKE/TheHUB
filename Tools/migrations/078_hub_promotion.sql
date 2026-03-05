-- Migration 078: TheHUB Promotion - targeted email campaigns
-- Creates tables for promotion campaigns and send tracking

CREATE TABLE IF NOT EXISTS promotion_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    email_subject VARCHAR(255) NOT NULL,
    email_body TEXT NOT NULL,

    -- Audience filters
    gender_filter VARCHAR(10) DEFAULT NULL COMMENT 'M, F, or NULL for all',
    age_min INT DEFAULT NULL COMMENT 'Minimum age (calculated from birth_year)',
    age_max INT DEFAULT NULL COMMENT 'Maximum age (calculated from birth_year)',
    region_filter VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated club regions',
    district_filter VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated rider districts',

    -- Optional discount code
    discount_code_id INT DEFAULT NULL,

    -- Status
    status ENUM('draft', 'sent', 'archived') DEFAULT 'draft',
    audience_count INT DEFAULT 0 COMMENT 'Cached count at send time',

    -- Tracking
    sent_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    skipped_count INT DEFAULT 0,
    sent_at DATETIME DEFAULT NULL,
    sent_by INT DEFAULT NULL,

    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promotion_sends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    rider_id INT NOT NULL,
    email_address VARCHAR(255) NOT NULL,
    status ENUM('sent', 'failed', 'skipped') DEFAULT 'sent',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_campaign (campaign_id),
    INDEX idx_rider (rider_id),
    UNIQUE KEY uk_campaign_rider (campaign_id, rider_id),
    FOREIGN KEY (campaign_id) REFERENCES promotion_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
