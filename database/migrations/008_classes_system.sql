-- Migration 008: Classes/Categories System
-- Implements age and gender-based class system (M17, K40, etc.)

-- Create classes table
CREATE TABLE IF NOT EXISTS `classes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(20) NOT NULL COMMENT 'Short name like M17, K40',
  `display_name` VARCHAR(100) NOT NULL COMMENT 'Full name like "Män 17-18 år"',
  `gender` ENUM('M', 'K', 'ALL') NOT NULL DEFAULT 'ALL' COMMENT 'M=Men, K=Women, ALL=Mixed',
  `min_age` INT DEFAULT NULL COMMENT 'Minimum age for this class',
  `max_age` INT DEFAULT NULL COMMENT 'Maximum age for this class',
  `discipline` ENUM('ROAD', 'MTB', 'ALL') NOT NULL DEFAULT 'ALL',
  `point_scale_id` INT DEFAULT NULL COMMENT 'Point scale for this class',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0 COMMENT 'Display order',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_class_name` (`name`, `discipline`),
  FOREIGN KEY (`point_scale_id`) REFERENCES `point_scales`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add class_id to results table
ALTER TABLE `results`
ADD COLUMN `class_id` INT DEFAULT NULL COMMENT 'Class assignment (M17, K40, etc.)' AFTER `category_id`,
ADD INDEX `idx_class_id` (`class_id`),
ADD FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE SET NULL;

-- Add class-based position tracking
ALTER TABLE `results`
ADD COLUMN `class_position` INT DEFAULT NULL COMMENT 'Position within class' AFTER `position`,
ADD COLUMN `class_points` DECIMAL(10,2) DEFAULT NULL COMMENT 'Points earned in class' AFTER `points`;

-- Add class support to events
ALTER TABLE `events`
ADD COLUMN `enable_classes` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enable class-based results' AFTER `point_scale_id`;

-- Add class support to series
ALTER TABLE `series`
ADD COLUMN `enable_classes` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enable class-based standings' AFTER `point_scale_id`;

-- Insert default Swedish cycling classes for Road
INSERT INTO `classes` (`name`, `display_name`, `gender`, `min_age`, `max_age`, `discipline`, `sort_order`, `active`) VALUES
('M17', 'Män 17-18 år', 'M', 17, 18, 'ROAD', 10, 1),
('M19', 'Män 19-22 år (U23)', 'M', 19, 22, 'ROAD', 20, 1),
('M23', 'Män 23-29 år', 'M', 23, 29, 'ROAD', 30, 1),
('M30', 'Män 30-39 år', 'M', 30, 39, 'ROAD', 40, 1),
('M40', 'Män 40-49 år', 'M', 40, 49, 'ROAD', 50, 1),
('M50', 'Män 50-59 år', 'M', 50, 59, 'ROAD', 60, 1),
('M60', 'Män 60-69 år', 'M', 60, 69, 'ROAD', 70, 1),
('M70', 'Män 70+ år', 'M', 70, 999, 'ROAD', 80, 1),
('K17', 'Kvinnor 17-18 år', 'K', 17, 18, 'ROAD', 110, 1),
('K19', 'Kvinnor 19-22 år (U23)', 'K', 19, 22, 'ROAD', 120, 1),
('K23', 'Kvinnor 23-29 år', 'K', 23, 29, 'ROAD', 130, 1),
('K30', 'Kvinnor 30-39 år', 'K', 30, 39, 'ROAD', 140, 1),
('K40', 'Kvinnor 40-49 år', 'K', 40, 49, 'ROAD', 150, 1),
('K50', 'Kvinnor 50-59 år', 'K', 50, 59, 'ROAD', 160, 1),
('K60', 'Kvinnor 60+ år', 'K', 60, 999, 'ROAD', 170, 1);

-- Insert default Swedish cycling classes for MTB
INSERT INTO `classes` (`name`, `display_name`, `gender`, `min_age`, `max_age`, `discipline`, `sort_order`, `active`) VALUES
('MTB-M17', 'MTB Män 17-18 år', 'M', 17, 18, 'MTB', 210, 1),
('MTB-M19', 'MTB Män 19-22 år', 'M', 19, 22, 'MTB', 220, 1),
('MTB-M23', 'MTB Män 23-39 år', 'M', 23, 39, 'MTB', 230, 1),
('MTB-M40', 'MTB Män 40-49 år', 'M', 40, 49, 'MTB', 240, 1),
('MTB-M50', 'MTB Män 50+ år', 'M', 50, 999, 'MTB', 250, 1),
('MTB-K17', 'MTB Kvinnor 17-18 år', 'K', 17, 18, 'MTB', 310, 1),
('MTB-K19', 'MTB Kvinnor 19-22 år', 'K', 19, 22, 'MTB', 320, 1),
('MTB-K23', 'MTB Kvinnor 23+ år', 'K', 23, 999, 'MTB', 330, 1);
