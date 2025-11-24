-- Migration 027: Registration Rules System
-- Flexible registration rules supporting national series and sport/motion events
-- Rules can be set at series level and optionally overridden per event

-- Registration rule type templates (Nationell vs Sport/Motion)
CREATE TABLE IF NOT EXISTS registration_rule_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    description TEXT NULL,
    default_requires_license TINYINT(1) DEFAULT 1,
    default_strict_gender TINYINT(1) DEFAULT 1,
    default_strict_age TINYINT(1) DEFAULT 1,
    default_strict_license_type TINYINT(1) DEFAULT 1,
    is_system TINYINT(1) DEFAULT 0 COMMENT 'System templates cannot be deleted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Insert default rule types
INSERT IGNORE INTO registration_rule_types (name, code, description, default_requires_license, default_strict_gender, default_strict_age, default_strict_license_type, is_system) VALUES
('Nationell', 'national', 'Strikta regler för nationella tävlingar. Kräver korrekt licenstyp, köns- och åldersbegränsningar.', 1, 1, 1, 1, 1),
('Sport/Motion', 'sport_motion', 'Milda regler för motionslopp. Framför allt könsbegränsning, ingen licenskontroll.', 0, 1, 0, 0, 1),
('Öppen', 'open', 'Öppna tävlingar utan begränsningar. Alla kan anmäla sig till alla klasser.', 0, 0, 0, 0, 1);

-- Series rule type setting
ALTER TABLE series
ADD COLUMN IF NOT EXISTS registration_rule_type_id INT NULL AFTER status,
ADD CONSTRAINT fk_series_rule_type
    FOREIGN KEY (registration_rule_type_id)
    REFERENCES registration_rule_types(id)
    ON DELETE SET NULL;

-- Event rule type override (optional)
ALTER TABLE events
ADD COLUMN IF NOT EXISTS registration_rule_type_id INT NULL AFTER status,
ADD COLUMN IF NOT EXISTS use_series_rules TINYINT(1) DEFAULT 1 COMMENT '1=use series rules, 0=use event-specific rules',
ADD CONSTRAINT fk_event_rule_type
    FOREIGN KEY (registration_rule_type_id)
    REFERENCES registration_rule_types(id)
    ON DELETE SET NULL;

-- Event-specific class rules (overrides series_class_rules when use_series_rules=0)
CREATE TABLE IF NOT EXISTS event_class_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    class_id INT NOT NULL,
    allowed_license_types JSON NULL COMMENT 'Array of allowed license types',
    min_birth_year INT NULL COMMENT 'Minimum birth year (oldest allowed)',
    max_birth_year INT NULL COMMENT 'Maximum birth year (youngest allowed)',
    allowed_genders JSON NULL COMMENT 'Array of allowed genders ["M", "K", "ALL"]',
    requires_license TINYINT(1) DEFAULT 1,
    requires_club_membership TINYINT(1) DEFAULT 0 COMMENT 'Must be member of a club',
    min_age INT NULL COMMENT 'Minimum age on event date',
    max_age INT NULL COMMENT 'Maximum age on event date',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_class_rule (event_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- License type definitions for reference
CREATE TABLE IF NOT EXISTS license_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    priority INT DEFAULT 0 COMMENT 'Higher = more privileged license',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Insert common Swedish cycling license types
INSERT IGNORE INTO license_types (code, name, description, priority) VALUES
('elite', 'Elite', 'Elitlicens för professionella tävlingar', 100),
('senior', 'Senior', 'Seniorlicens för tävlande', 80),
('junior', 'Junior', 'Juniorlicens för unga tävlande', 70),
('youth', 'Ungdom', 'Ungdomslicens', 60),
('hobby', 'Hobby', 'Hobbylicens för motionslopp', 40),
('motion', 'Motion', 'Motionslicens utan tävlingsfokus', 30),
('day', 'Daglicens', 'Tillfällig licens för enstaka event', 20),
('none', 'Ingen licens', 'Ingen licens krävs', 0);

-- Class eligibility mapping (which license types can compete in which classes)
CREATE TABLE IF NOT EXISTS class_license_eligibility (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    license_type_code VARCHAR(50) NOT NULL,
    is_allowed TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_class_license (class_id, license_type_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Add extended fields to series_class_rules
ALTER TABLE series_class_rules
ADD COLUMN IF NOT EXISTS requires_club_membership TINYINT(1) DEFAULT 0 AFTER requires_license,
ADD COLUMN IF NOT EXISTS min_age INT NULL AFTER max_birth_year,
ADD COLUMN IF NOT EXISTS max_age INT NULL AFTER min_age;

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_event_class_rules_event ON event_class_rules(event_id);
CREATE INDEX IF NOT EXISTS idx_license_types_code ON license_types(code);
CREATE INDEX IF NOT EXISTS idx_class_license_eligibility_class ON class_license_eligibility(class_id);

-- View to get effective rules for an event (combining series and event rules)
CREATE OR REPLACE VIEW v_event_effective_rules AS
SELECT
    e.id AS event_id,
    e.name AS event_name,
    e.series_id,
    s.name AS series_name,
    COALESCE(e.registration_rule_type_id, s.registration_rule_type_id) AS effective_rule_type_id,
    rt.name AS rule_type_name,
    rt.code AS rule_type_code,
    e.use_series_rules,
    CASE
        WHEN e.use_series_rules = 1 THEN 'series'
        ELSE 'event'
    END AS rules_source
FROM events e
LEFT JOIN series s ON e.series_id = s.id
LEFT JOIN registration_rule_types rt ON COALESCE(e.registration_rule_type_id, s.registration_rule_type_id) = rt.id;
