-- Migration: Add Badge System Fields
-- Date: 2025-12-01
-- Description: Add gradient colors to series table and create sponsor tables

-- ============================================================================
-- SERIES TABLE UPDATES - Add gradient colors for badge display
-- ============================================================================

ALTER TABLE series
    ADD COLUMN IF NOT EXISTS gradient_start VARCHAR(7) DEFAULT '#004A98' AFTER description,
    ADD COLUMN IF NOT EXISTS gradient_end VARCHAR(7) DEFAULT '#002a5c' AFTER gradient_start,
    ADD COLUMN IF NOT EXISTS accent_color VARCHAR(7) DEFAULT '#61CE70' AFTER gradient_end,
    ADD COLUMN IF NOT EXISTS logo_light VARCHAR(255) AFTER accent_color,
    ADD COLUMN IF NOT EXISTS logo_dark VARCHAR(255) AFTER logo_light;

-- Update existing series with brand colors based on discipline
-- GravitySeries / Default
UPDATE series SET
    gradient_start = '#004A98',
    gradient_end = '#002a5c',
    accent_color = '#61CE70'
WHERE gradient_start IS NULL OR gradient_start = '';

-- Enduro series - Green theme
UPDATE series SET
    gradient_start = '#16a34a',
    gradient_end = '#15803d',
    accent_color = '#FFD700'
WHERE (discipline LIKE '%enduro%' OR name LIKE '%Enduro%')
  AND gradient_start = '#004A98';

-- Downhill series - Orange/Red theme
UPDATE series SET
    gradient_start = '#ea580c',
    gradient_end = '#c2410c',
    accent_color = '#FFD700'
WHERE (discipline LIKE '%downhill%' OR discipline LIKE '%dh%' OR name LIKE '%Downhill%' OR name LIKE '%DH%')
  AND gradient_start = '#004A98';

-- XC / Cross Country series - Purple theme
UPDATE series SET
    gradient_start = '#7c3aed',
    gradient_end = '#6d28d9',
    accent_color = '#FFD700'
WHERE (discipline LIKE '%xc%' OR discipline LIKE '%cross%' OR name LIKE '%XC%' OR name LIKE '%Cross%')
  AND gradient_start = '#004A98';

-- ============================================================================
-- SPONSORS TABLE - Store sponsor information
-- ============================================================================

CREATE TABLE IF NOT EXISTS sponsors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100),
    logo VARCHAR(255),
    logo_dark VARCHAR(255),
    website VARCHAR(255),
    tier ENUM('title', 'gold', 'silver', 'bronze') DEFAULT 'bronze',
    description TEXT,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_slug (slug),
    INDEX idx_tier (tier),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- EVENT_SPONSORS - Junction table for event-sponsor relationships
-- ============================================================================

CREATE TABLE IF NOT EXISTS event_sponsors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    sponsor_id INT NOT NULL,
    placement ENUM('header', 'sidebar', 'footer', 'content') DEFAULT 'sidebar',
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_sponsor_placement (event_id, sponsor_id, placement),
    INDEX idx_event (event_id),
    INDEX idx_sponsor (sponsor_id),
    INDEX idx_placement (placement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SERIES_SPONSORS - Junction table for series-sponsor relationships
-- ============================================================================

CREATE TABLE IF NOT EXISTS series_sponsors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    series_id INT NOT NULL,
    sponsor_id INT NOT NULL,
    placement ENUM('header', 'sidebar', 'footer', 'content') DEFAULT 'sidebar',
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE,
    UNIQUE KEY unique_series_sponsor_placement (series_id, sponsor_id, placement),
    INDEX idx_series (series_id),
    INDEX idx_sponsor (sponsor_id),
    INDEX idx_placement (placement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- EXAMPLE SPONSORS (Optional - for testing)
-- ============================================================================

-- Uncomment to add example sponsors:
-- INSERT INTO sponsors (name, slug, logo, website, tier) VALUES
-- ('GravitySeries', 'gravityseries', '/assets/images/sponsors/gravityseries.svg', 'https://gravityseries.se', 'title'),
-- ('Example Gold Sponsor', 'example-gold', '/assets/images/sponsors/example-gold.svg', 'https://example.com', 'gold'),
-- ('Example Silver Sponsor', 'example-silver', '/assets/images/sponsors/example-silver.svg', 'https://example.com', 'silver'),
-- ('Example Bronze Sponsor', 'example-bronze', '/assets/images/sponsors/example-bronze.svg', 'https://example.com', 'bronze');
