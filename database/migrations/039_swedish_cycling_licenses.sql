-- Migration 039: Swedish Cycling Federation License Types
-- Complete license system based on SCF (Svenska Cykelförbundet) regulations
-- This migration is IDEMPOTENT - safe to run multiple times

-- Delete existing data in correct order (respecting FK constraints)
DELETE FROM license_class_category_access WHERE 1=1;
DELETE FROM license_age_requirements WHERE 1=1;
DELETE FROM class_categories WHERE 1=1;
DELETE FROM license_types WHERE 1=1;

-- Insert all Swedish cycling license types
INSERT INTO license_types (code, name, description, priority, is_active) VALUES
-- Day/Motion licenses (lower priority)
('engangslicens', 'Engångslicens', 'Giltig i Sport/Motion-klasser. 65 kr (11+), 0 kr (0-10 år)', 10, 1),
('motionslicens', 'Motionslicens', '15 år+. Motion, Sportmotion, E-bike, E-cycling. 300 kr', 20, 1),
('ecycling', 'E-cyclinglicens', 'E-cycling klassen. 260 kr', 15, 1),

-- Youth licenses
('under11', 'Under 11 Men/Women', 'Pojkar/Flickor 5-10 år. P/F 5-10, Ungdom Sport, E-cycling. UCI ID. 0 kr', 30, 1),
('youth', 'Youth Men/Women', 'Pojkar/Flickor 11-16 år. P/F 10-16, Ungdom Sport, E-bike, E-cycling. UCI ID. 260 kr', 40, 1),

-- Competition licenses (higher priority)
('junior', 'Junior Men/Women', '17-18 år. U21, Junior, Senior, Sport, E-bike, E-cycling. UCI ID. 660 kr', 60, 1),
('u23', 'Under 23 Men/Women', '19-22 år. U21, U23, Elit, Senior, Sport, E-bike, E-cycling. UCI ID. 960 kr', 70, 1),
('elite_women', 'Elite Women', '23+ år. Dam Elit, Seniorer, Tävling, Sport, E-bike, E-cycling. UCI ID. 960 kr', 90, 1),
('elite_men', 'Elite Men', '23+ år. Herr Elit, Seniorer, Tävling, Sport, E-bike, E-cycling. UCI ID. 960 kr', 90, 1),
('master', 'Master Men/Women', '30+ år. H30-H75, D30-D60, Master, Seniorer, Sport, E-bike, E-cycling. UCI ID. 960 kr', 80, 1),

-- Special licenses
('paracyclist', 'Para-cyclist Men/Women', '16+ år. Samtliga Paracykelklasser, E-cycling. UCI ID. 960 kr', 85, 1),
('pilot', 'Pilotlicens Men/Women', '16+ år. Pilot i tandemklasser. UCI ID. 210 kr', 50, 1),
('baslicens', 'Baslicens Men/Women', '15+ år. H/D Sport, Sportmotion, E-bike, E-cycling, BMX nationellt. UCI ID. 420 kr', 55, 1);

-- Create license age requirements table
CREATE TABLE IF NOT EXISTS license_age_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_type_code VARCHAR(50) NOT NULL,
    min_age INT NULL COMMENT 'Minimum age allowed',
    max_age INT NULL COMMENT 'Maximum age allowed',
    gender ENUM('M', 'K', 'ALL') DEFAULT 'ALL',
    UNIQUE KEY unique_license_age (license_type_code, gender),
    FOREIGN KEY (license_type_code) REFERENCES license_types(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Insert age requirements
INSERT INTO license_age_requirements (license_type_code, min_age, max_age, gender) VALUES
('engangslicens', NULL, NULL, 'ALL'),  -- All ages
('motionslicens', 15, NULL, 'ALL'),
('ecycling', NULL, NULL, 'ALL'),
('under11', 5, 10, 'ALL'),
('youth', 11, 16, 'ALL'),
('junior', 17, 18, 'ALL'),
('u23', 19, 22, 'ALL'),
('elite_women', 23, NULL, 'K'),
('elite_men', 23, NULL, 'M'),
('master', 30, NULL, 'ALL'),
('paracyclist', 16, NULL, 'ALL'),
('pilot', 16, NULL, 'ALL'),
('baslicens', 15, NULL, 'ALL');

-- Create class categories for easier mapping
CREATE TABLE IF NOT EXISTS class_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

INSERT INTO class_categories (code, name, description, sort_order) VALUES
('youth_p', 'Pojkar Ungdom', 'P5-6, P7-8, P9-10, P10-12, P11-12, P13-14, P15-16', 10),
('youth_f', 'Flickor Ungdom', 'F5-6, F7-8, F9-10, F10-12, F11-12, F13-14, F15-16', 20),
('junior', 'Junior', 'Junior Herrar/Damer, U21', 30),
('u23', 'Under 23', 'U23 Herrar/Damer', 40),
('elite', 'Elit', 'Herr Elit, Dam Elit', 50),
('senior', 'Senior', 'Herrar Seniorer, Damer Seniorer', 60),
('master_m', 'Master Herrar', 'H30-H75', 70),
('master_k', 'Master Damer', 'D30-D60', 80),
('sport', 'Sport', 'Herrar Sport, Damer Sport, Ungdom Sport', 90),
('motion', 'Motion', 'Motion, Sportmotion', 100),
('ebike', 'E-bike/E-cycling', 'E-bike, E-cycling', 110),
('para', 'Paracykel', 'Samtliga paracykelklasser', 120);

-- License to class category mapping (which licenses can compete in which category)
CREATE TABLE IF NOT EXISTS license_class_category_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_type_code VARCHAR(50) NOT NULL,
    class_category_code VARCHAR(50) NOT NULL,
    is_allowed TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    UNIQUE KEY unique_license_category (license_type_code, class_category_code),
    FOREIGN KEY (license_type_code) REFERENCES license_types(code) ON DELETE CASCADE,
    FOREIGN KEY (class_category_code) REFERENCES class_categories(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Map licenses to class categories based on SCF rules
-- Engångslicens
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('engangslicens', 'youth_p'),
('engangslicens', 'youth_f'),
('engangslicens', 'sport'),
('engangslicens', 'motion'),
('engangslicens', 'para'),
('engangslicens', 'ebike');

-- Motionslicens
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('motionslicens', 'motion'),
('motionslicens', 'ebike');

-- E-cyclinglicens
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('ecycling', 'ebike');

-- Under 11
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('under11', 'youth_p'),
('under11', 'youth_f'),
('under11', 'sport'),
('under11', 'ebike');

-- Youth
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('youth', 'youth_p'),
('youth', 'youth_f'),
('youth', 'sport'),
('youth', 'ebike');

-- Junior
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('junior', 'junior'),
('junior', 'senior'),
('junior', 'sport'),
('junior', 'motion'),
('junior', 'ebike');

-- U23
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('u23', 'junior'),
('u23', 'u23'),
('u23', 'elite'),
('u23', 'senior'),
('u23', 'sport'),
('u23', 'motion'),
('u23', 'ebike');

-- Elite Women
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('elite_women', 'elite'),
('elite_women', 'senior'),
('elite_women', 'sport'),
('elite_women', 'motion'),
('elite_women', 'ebike');

-- Elite Men
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('elite_men', 'elite'),
('elite_men', 'senior'),
('elite_men', 'sport'),
('elite_men', 'motion'),
('elite_men', 'ebike');

-- Master
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('master', 'master_m'),
('master', 'master_k'),
('master', 'senior'),
('master', 'elite'),
('master', 'sport'),
('master', 'motion'),
('master', 'ebike');

-- Paracyclist
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('paracyclist', 'para'),
('paracyclist', 'ebike');

-- Baslicens
INSERT INTO license_class_category_access (license_type_code, class_category_code) VALUES
('baslicens', 'sport'),
('baslicens', 'motion'),
('baslicens', 'ebike');

-- Add class_category_id to classes table for easier filtering
ALTER TABLE classes
ADD COLUMN IF NOT EXISTS class_category_code VARCHAR(50) NULL AFTER discipline,
ADD INDEX IF NOT EXISTS idx_class_category (class_category_code);

-- Verify
SELECT 'License types created:' as status;
SELECT code, name, priority FROM license_types ORDER BY priority DESC;

SELECT 'Class categories created:' as status;
SELECT code, name FROM class_categories ORDER BY sort_order;
