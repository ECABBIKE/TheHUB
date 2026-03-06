-- ============================================================================
-- Migration 081: Winback External Discount Codes
-- Adds support for category-based discount codes for external events
-- (events where registration is handled outside TheHUB)
-- ============================================================================

-- Table for category-based codes (max 10 per campaign)
CREATE TABLE IF NOT EXISTS winback_external_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    category_key VARCHAR(50) NOT NULL,
    category_label VARCHAR(100) NOT NULL,
    experience_min INT DEFAULT NULL,
    experience_max INT DEFAULT NULL,
    age_min INT DEFAULT NULL,
    age_max INT DEFAULT NULL,
    rider_count INT DEFAULT 0,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign_id),
    UNIQUE KEY uk_campaign_code (campaign_id, code),
    UNIQUE KEY uk_campaign_category (campaign_id, category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add external code settings to campaigns
ALTER TABLE winback_campaigns
    ADD COLUMN external_codes_enabled TINYINT(1) DEFAULT 0 AFTER allow_promotor_access,
    ADD COLUMN external_code_prefix VARCHAR(20) DEFAULT NULL AFTER external_codes_enabled,
    ADD COLUMN external_event_name VARCHAR(255) DEFAULT NULL AFTER external_code_prefix;

-- Add column for storing which external code was given (references winback_external_codes)
ALTER TABLE winback_responses
    ADD COLUMN external_code_id INT UNSIGNED NULL AFTER discount_code;
