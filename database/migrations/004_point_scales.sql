-- Point Scale System Migration
-- Created: 2025-11-14
-- Purpose: Add support for configurable point scales per event

-- ============================================================================
-- POINT SCALES TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS `point_scales` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT,
  `discipline` ENUM('ENDURO','DH','XCO','CX','ALL') DEFAULT 'ALL',
  `active` TINYINT(1) DEFAULT 1,
  `is_default` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_discipline` (`discipline`),
  INDEX `idx_active` (`active`),
  INDEX `idx_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- POINT SCALE VALUES TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS `point_scale_values` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `scale_id` INT NOT NULL,
  `position` INT NOT NULL,
  `points` DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (`scale_id`) REFERENCES `point_scales`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_scale_position` (`scale_id`, `position`),
  INDEX `idx_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ADD POINT SCALE TO EVENTS
-- ============================================================================
ALTER TABLE `events`
ADD COLUMN `point_scale_id` INT DEFAULT NULL AFTER `series_id`,
ADD FOREIGN KEY (`point_scale_id`) REFERENCES `point_scales`(`id`) ON DELETE SET NULL,
ADD INDEX `idx_point_scale` (`point_scale_id`);

-- ============================================================================
-- SEED DEFAULT SCALES
-- ============================================================================

-- SweCup Standard Scale (UCI-based)
INSERT INTO `point_scales` (`name`, `description`, `discipline`, `active`, `is_default`) VALUES
('SweCup Standard', 'Standard SweCup poängtabell baserad på UCI-systemet', 'ALL', 1, 1);

SET @swecup_id = LAST_INSERT_ID();

INSERT INTO `point_scale_values` (`scale_id`, `position`, `points`) VALUES
(@swecup_id, 1, 100), (@swecup_id, 2, 95), (@swecup_id, 3, 90),
(@swecup_id, 4, 87), (@swecup_id, 5, 84), (@swecup_id, 6, 82),
(@swecup_id, 7, 80), (@swecup_id, 8, 78), (@swecup_id, 9, 76),
(@swecup_id, 10, 74), (@swecup_id, 11, 72), (@swecup_id, 12, 70),
(@swecup_id, 13, 68), (@swecup_id, 14, 66), (@swecup_id, 15, 64),
(@swecup_id, 16, 62), (@swecup_id, 17, 60), (@swecup_id, 18, 58),
(@swecup_id, 19, 56), (@swecup_id, 20, 54), (@swecup_id, 21, 52),
(@swecup_id, 22, 50), (@swecup_id, 23, 48), (@swecup_id, 24, 46),
(@swecup_id, 25, 44), (@swecup_id, 26, 42), (@swecup_id, 27, 40),
(@swecup_id, 28, 38), (@swecup_id, 29, 36), (@swecup_id, 30, 34),
(@swecup_id, 31, 32), (@swecup_id, 32, 30), (@swecup_id, 33, 28),
(@swecup_id, 34, 26), (@swecup_id, 35, 24), (@swecup_id, 36, 22),
(@swecup_id, 37, 20), (@swecup_id, 38, 18), (@swecup_id, 39, 16),
(@swecup_id, 40, 14), (@swecup_id, 41, 12), (@swecup_id, 42, 10),
(@swecup_id, 43, 8), (@swecup_id, 44, 6), (@swecup_id, 45, 4),
(@swecup_id, 46, 2), (@swecup_id, 47, 1), (@swecup_id, 48, 1),
(@swecup_id, 49, 1), (@swecup_id, 50, 1);

-- Gravity Series Pro Scale (Higher points for prestige events)
INSERT INTO `point_scales` (`name`, `description`, `discipline`, `active`, `is_default`) VALUES
('Gravity Series Pro', 'Högpoängtabell för Gravity Series tävlingar', 'ENDURO', 1, 0);

SET @gravity_id = LAST_INSERT_ID();

INSERT INTO `point_scale_values` (`scale_id`, `position`, `points`) VALUES
(@gravity_id, 1, 150), (@gravity_id, 2, 140), (@gravity_id, 3, 130),
(@gravity_id, 4, 125), (@gravity_id, 5, 120), (@gravity_id, 6, 115),
(@gravity_id, 7, 110), (@gravity_id, 8, 105), (@gravity_id, 9, 100),
(@gravity_id, 10, 95), (@gravity_id, 11, 90), (@gravity_id, 12, 85),
(@gravity_id, 13, 80), (@gravity_id, 14, 75), (@gravity_id, 15, 70),
(@gravity_id, 16, 65), (@gravity_id, 17, 60), (@gravity_id, 18, 55),
(@gravity_id, 19, 50), (@gravity_id, 20, 45), (@gravity_id, 21, 40),
(@gravity_id, 22, 36), (@gravity_id, 23, 32), (@gravity_id, 24, 28),
(@gravity_id, 25, 24), (@gravity_id, 26, 20), (@gravity_id, 27, 18),
(@gravity_id, 28, 16), (@gravity_id, 29, 14), (@gravity_id, 30, 12),
(@gravity_id, 31, 10), (@gravity_id, 32, 8), (@gravity_id, 33, 6),
(@gravity_id, 34, 4), (@gravity_id, 35, 2), (@gravity_id, 36, 1),
(@gravity_id, 37, 1), (@gravity_id, 38, 1), (@gravity_id, 39, 1),
(@gravity_id, 40, 1);

-- Simple Scale (1 point per position)
INSERT INTO `point_scales` (`name`, `description`, `discipline`, `active`, `is_default`) VALUES
('Enkel Skala', 'Enkel poängskala: 1 poäng per placering', 'ALL', 1, 0);

SET @simple_id = LAST_INSERT_ID();

INSERT INTO `point_scale_values` (`scale_id`, `position`, `points`) VALUES
(@simple_id, 1, 1), (@simple_id, 2, 2), (@simple_id, 3, 3),
(@simple_id, 4, 4), (@simple_id, 5, 5), (@simple_id, 6, 6),
(@simple_id, 7, 7), (@simple_id, 8, 8), (@simple_id, 9, 9),
(@simple_id, 10, 10), (@simple_id, 11, 11), (@simple_id, 12, 12),
(@simple_id, 13, 13), (@simple_id, 14, 14), (@simple_id, 15, 15),
(@simple_id, 16, 16), (@simple_id, 17, 17), (@simple_id, 18, 18),
(@simple_id, 19, 19), (@simple_id, 20, 20), (@simple_id, 21, 21),
(@simple_id, 22, 22), (@simple_id, 23, 23), (@simple_id, 24, 24),
(@simple_id, 25, 25), (@simple_id, 26, 26), (@simple_id, 27, 27),
(@simple_id, 28, 28), (@simple_id, 29, 29), (@simple_id, 30, 30);

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- Verify tables created
SELECT 'Tables created:' as status;
SHOW TABLES LIKE '%point%';

-- Verify scales created
SELECT 'Point scales created:' as status;
SELECT id, name, discipline, is_default FROM point_scales;

-- Verify values created
SELECT 'Scale values count:' as status;
SELECT
    ps.name,
    COUNT(psv.id) as value_count,
    MAX(psv.position) as max_position,
    MAX(psv.points) as max_points
FROM point_scales ps
LEFT JOIN point_scale_values psv ON ps.id = psv.scale_id
GROUP BY ps.id, ps.name;
