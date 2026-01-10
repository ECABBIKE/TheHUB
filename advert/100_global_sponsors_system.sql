-- Migration 100: Global Sponsors & Race Reports System
-- Utökar befintligt sponsorsystem med globala platser och bloggfunktion

-- ==============================================
-- DEL 1: UTÖKAD SPONSOR TIERS
-- ==============================================

-- Uppdatera sponsors-tabellen med nya tiers och global flagga
ALTER TABLE sponsors 
    MODIFY tier ENUM('title_gravityseries', 'title_series', 'gold', 'silver', 'branch') DEFAULT 'silver',
    ADD COLUMN is_global TINYINT(1) DEFAULT 0 AFTER active,
    ADD COLUMN display_priority INT DEFAULT 50 AFTER is_global,
    ADD COLUMN banner_image VARCHAR(255) AFTER logo_dark,
    ADD COLUMN contact_email VARCHAR(255) AFTER website,
    ADD COLUMN contact_phone VARCHAR(50) AFTER contact_email,
    ADD INDEX idx_global (is_global),
    ADD INDEX idx_priority (display_priority);

-- ==============================================
-- DEL 2: GLOBALA SPONSORPLACERINGAR
-- ==============================================

-- Ny tabell för att styra var sponsorer visas globalt
CREATE TABLE IF NOT EXISTS sponsor_placements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sponsor_id INT NOT NULL,
    page_type ENUM(
        'home',           -- Startsidan
        'results',        -- Resultat-översikt
        'series_list',    -- Serieoversikt
        'series_single',  -- Enskild serie-sida
        'database',       -- Databas (riders/clubs)
        'ranking',        -- Ranking
        'calendar',       -- Kalender
        'all'             -- Visas överallt
    ) NOT NULL,
    position ENUM(
        'header_banner',  -- Stor banner överst
        'sidebar_top',    -- Sidebar topp
        'sidebar_mid',    -- Sidebar mitt
        'content_top',    -- Innehåll topp
        'content_mid',    -- Innehåll mitt
        'content_bottom', -- Innehåll botten
        'footer'          -- Footer
    ) NOT NULL,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    start_date DATE NULL,
    end_date DATE NULL,
    impressions_target INT NULL,
    impressions_current INT DEFAULT 0,
    clicks INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE,
    INDEX idx_page_position (page_type, position),
    INDEX idx_active (is_active),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_sponsor (sponsor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- DEL 3: SPONSORNIVÅ-RÄTTIGHETER
-- ==============================================

-- Tabell för att definiera vad varje nivå får
CREATE TABLE IF NOT EXISTS sponsor_tier_benefits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tier ENUM('title_gravityseries', 'title_series', 'gold', 'silver', 'branch') NOT NULL,
    benefit_key VARCHAR(100) NOT NULL,  -- ex: 'home_banner', 'sidebar_all_pages'
    benefit_value TEXT,                 -- JSON eller beskrivning
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tier_benefit (tier, benefit_key),
    INDEX idx_tier (tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sätt in default-rättigheter
INSERT INTO sponsor_tier_benefits (tier, benefit_key, benefit_value, display_order) VALUES
-- Titelsponsor GravitySeries (högst nivå)
('title_gravityseries', 'name_in_logo', 'Varumärke i GravitySeries logotyp', 10),
('title_gravityseries', 'home_banner_exclusive', 'Exklusiv startsidesplacering', 20),
('title_gravityseries', 'all_pages_header', 'Header-placering alla sidor', 30),
('title_gravityseries', 'jersey_integration', 'Integration i tröjor/priser', 40),
('title_gravityseries', 'max_placements', '10', 50),

-- Titelsponsor Serie (individuell serie)
('title_series', 'series_name_integration', 'Varumärke i serienamn', 10),
('title_series', 'series_banner', 'Banner på seriesidor', 20),
('title_series', 'event_branding', 'Branding på seriers evenemang', 30),
('title_series', 'max_placements', '5', 40),

-- Guldsponsor
('gold', 'home_sidebar', 'Sidebar startsida', 10),
('gold', 'all_results_pages', 'Resultsidor', 20),
('gold', 'ranking_sidebar', 'Ranking sidebar', 30),
('gold', 'max_placements', '3', 40),

-- Silversponsor
('silver', 'selected_pages', 'Valda sidor', 10),
('silver', 'content_bottom', 'Content bottom', 20),
('silver', 'max_placements', '2', 30),

-- Branschsponsor (specifik bransch som cykelbutiker, verkstäder etc)
('branch', 'database_sidebar', 'Databas sidebar', 10),
('branch', 'footer_rotation', 'Footer rotation', 20),
('branch', 'max_placements', '2', 30);

-- ==============================================
-- DEL 4: RACE REPORTS / BLOGG-SYSTEM
-- ==============================================

-- Huvudtabell för race reports
CREATE TABLE IF NOT EXISTS race_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rider_id INT NOT NULL,
    event_id INT NULL,                  -- Kan kopplas till event (optional)
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    excerpt TEXT,                       -- Kort sammanfattning
    featured_image VARCHAR(255),
    status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    published_at DATETIME NULL,
    views INT DEFAULT 0,
    likes INT DEFAULT 0,
    
    -- Instagram integration
    instagram_url VARCHAR(255),
    instagram_embed_code TEXT,
    is_from_instagram TINYINT(1) DEFAULT 0,
    
    -- Metadata
    reading_time_minutes INT DEFAULT 5,
    allow_comments TINYINT(1) DEFAULT 1,
    is_featured TINYINT(1) DEFAULT 0,  -- Featured på startsidan
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
    UNIQUE KEY unique_slug (slug),
    INDEX idx_rider (rider_id),
    INDEX idx_event (event_id),
    INDEX idx_status (status),
    INDEX idx_published (published_at),
    INDEX idx_featured (is_featured),
    FULLTEXT INDEX idx_content (title, content, excerpt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taggar för race reports
CREATE TABLE IF NOT EXISTS race_report_tags (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_slug (slug),
    INDEX idx_usage (usage_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction-tabell för report-tags
CREATE TABLE IF NOT EXISTS race_report_tag_relations (
    report_id INT NOT NULL,
    tag_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id, tag_id),
    FOREIGN KEY (report_id) REFERENCES race_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES race_report_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kommentarer på race reports
CREATE TABLE IF NOT EXISTS race_report_comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_id INT NOT NULL,
    rider_id INT NULL,                  -- NULL = anonymt
    author_name VARCHAR(100),           -- För anonyma
    comment_text TEXT NOT NULL,
    is_approved TINYINT(1) DEFAULT 1,   -- Moderering
    parent_comment_id INT NULL,         -- För svar på kommentarer
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES race_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_comment_id) REFERENCES race_report_comments(id) ON DELETE CASCADE,
    INDEX idx_report (report_id),
    INDEX idx_rider (rider_id),
    INDEX idx_approved (is_approved),
    INDEX idx_parent (parent_comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Likes på race reports
CREATE TABLE IF NOT EXISTS race_report_likes (
    report_id INT NOT NULL,
    rider_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id, rider_id),
    FOREIGN KEY (report_id) REFERENCES race_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- DEL 5: SPONSORSTATISTIK
-- ==============================================

-- Detaljerad tracking av sponsorexponering
CREATE TABLE IF NOT EXISTS sponsor_analytics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sponsor_id INT NOT NULL,
    placement_id INT NULL,              -- Referens till sponsor_placements
    page_type VARCHAR(50),
    page_id INT NULL,                   -- ID av specifik sida (event_id, series_id etc)
    action_type ENUM('impression', 'click', 'hover') NOT NULL,
    user_id INT NULL,                   -- Rider ID om inloggad
    ip_hash VARCHAR(64),                -- Hashad IP för unik räkning
    user_agent TEXT,
    referer VARCHAR(255),
    session_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE,
    FOREIGN KEY (placement_id) REFERENCES sponsor_placements(id) ON DELETE SET NULL,
    INDEX idx_sponsor (sponsor_id),
    INDEX idx_placement (placement_id),
    INDEX idx_action (action_type),
    INDEX idx_created (created_at),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- DEL 6: ADMIN-INSTÄLLNINGAR
-- ==============================================

-- Settings för sponsorsystemet
CREATE TABLE IF NOT EXISTS sponsor_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT INTO sponsor_settings (setting_key, setting_value, description) VALUES
('max_sponsors_per_page', '5', 'Max antal sponsorer per sida'),
('banner_rotation_seconds', '10', 'Rotation banner (sekunder)'),
('enable_analytics', '1', 'Aktivera sponsorstatistik'),
('require_approval_race_reports', '0', 'Kräv godkännande för race reports'),
('featured_reports_count', '3', 'Antal featured reports på startsida'),
('instagram_auto_import', '0', 'Auto-importera från Instagram'),
('public_enabled', '0', 'Visa globala sponsorer för besökare (0=endast admin, 1=alla)'),
('race_reports_public', '0', 'Visa race reports för besökare (0=endast admin, 1=alla)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
