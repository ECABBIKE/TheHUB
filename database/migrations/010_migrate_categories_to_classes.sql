-- Migration 010: Migrate from categories to classes
-- This migration converts all category_id references to class_id
-- and marks the categories table as deprecated

-- Step 1: Create mapping between old categories and new classes
-- This is based on age ranges and gender

-- First, let's migrate existing results with category_id to class_id
-- We'll use the rider's birth_year and event date to determine the correct class

UPDATE results r
INNER JOIN riders rider ON r.cyclist_id = rider.id
INNER JOIN events e ON r.event_id = e.id
INNER JOIN categories old_cat ON r.category_id = old_cat.id
SET r.class_id = (
    SELECT c.id
    FROM classes c
    WHERE c.active = 1
      AND (
          c.gender = rider.gender
          OR c.gender = 'ALL'
          OR (rider.gender IN ('F', 'K') AND c.gender IN ('F', 'K'))
      )
      AND (
          c.discipline IS NULL
          OR c.discipline = ''
          OR c.discipline = COALESCE(e.discipline, 'ROAD')
          OR c.discipline LIKE CONCAT('%', COALESCE(e.discipline, 'ROAD'), '%')
      )
      AND (c.min_age IS NULL OR c.min_age <= YEAR(e.date) - rider.birth_year)
      AND (c.max_age IS NULL OR c.max_age >= YEAR(e.date) - rider.birth_year)
    ORDER BY
        CASE WHEN c.discipline = COALESCE(e.discipline, 'ROAD') THEN 0 ELSE 1 END,
        CASE WHEN c.gender = rider.gender THEN 0 ELSE 1 END,
        c.sort_order ASC
    LIMIT 1
)
WHERE r.category_id IS NOT NULL
  AND r.class_id IS NULL
  AND rider.birth_year IS NOT NULL
  AND rider.gender IS NOT NULL;

-- Step 2: For results that couldn't be auto-mapped (missing birth_year/gender),
-- create generic fallback classes if they don't exist

-- Generic Men's class
INSERT IGNORE INTO classes (name, display_name, gender, min_age, max_age, discipline, sort_order, active)
VALUES ('M_GENERIC', 'Herrar (Allmän)', 'M', NULL, NULL, '', 900, 1);

-- Generic Women's class
INSERT IGNORE INTO classes (name, display_name, gender, min_age, max_age, discipline, sort_order, active)
VALUES ('K_GENERIC', 'Damer (Allmän)', 'K', NULL, NULL, '', 901, 1);

-- Generic Open class
INSERT IGNORE INTO classes (name, display_name, gender, min_age, max_age, discipline, sort_order, active)
VALUES ('OPEN_GENERIC', 'Öppen (Allmän)', 'ALL', NULL, NULL, '', 902, 1);

-- Step 3: Map remaining results without class_id to generic classes
UPDATE results r
INNER JOIN riders rider ON r.cyclist_id = rider.id
INNER JOIN categories old_cat ON r.category_id = old_cat.id
SET r.class_id = (
    CASE
        WHEN rider.gender = 'M' THEN (SELECT id FROM classes WHERE name = 'M_GENERIC' LIMIT 1)
        WHEN rider.gender IN ('F', 'K') THEN (SELECT id FROM classes WHERE name = 'K_GENERIC' LIMIT 1)
        ELSE (SELECT id FROM classes WHERE name = 'OPEN_GENERIC' LIMIT 1)
    END
)
WHERE r.category_id IS NOT NULL
  AND r.class_id IS NULL;

-- Step 4: Recalculate class positions for all events
-- This is done via PHP, but we'll log which events need recalculation
CREATE TEMPORARY TABLE IF NOT EXISTS events_to_recalculate AS
SELECT DISTINCT event_id
FROM results
WHERE class_id IS NOT NULL;

-- Step 5: Add deprecation comment to categories table
ALTER TABLE categories
COMMENT = 'DEPRECATED: Use classes table instead. Kept for historical reference only.';

-- Step 6: Create a backup of the old category mappings for reference
CREATE TABLE IF NOT EXISTS category_class_migration_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    result_id INT NOT NULL,
    old_category_id INT,
    old_category_name VARCHAR(100),
    new_class_id INT,
    new_class_name VARCHAR(100),
    rider_id INT,
    rider_age INT,
    event_id INT,
    migrated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_result (result_id),
    INDEX idx_old_category (old_category_id),
    INDEX idx_new_class (new_class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log all migrations for audit trail
INSERT INTO category_class_migration_log
    (result_id, old_category_id, old_category_name, new_class_id, new_class_name, rider_id, rider_age, event_id)
SELECT
    r.id,
    r.category_id,
    cat.name,
    r.class_id,
    cls.name,
    r.cyclist_id,
    YEAR(e.date) - rider.birth_year as rider_age,
    r.event_id
FROM results r
LEFT JOIN categories cat ON r.category_id = cat.id
LEFT JOIN classes cls ON r.class_id = cls.id
LEFT JOIN riders rider ON r.cyclist_id = rider.id
LEFT JOIN events e ON r.event_id = e.id
WHERE r.category_id IS NOT NULL
  AND r.class_id IS NOT NULL;

-- Step 7: Summary statistics
SELECT
    'Migration Summary' as info,
    COUNT(*) as total_results_migrated,
    COUNT(DISTINCT r.event_id) as events_affected,
    COUNT(DISTINCT r.cyclist_id) as riders_affected
FROM results r
WHERE r.category_id IS NOT NULL AND r.class_id IS NOT NULL;

SELECT
    'Results by Class' as info,
    cls.name as class_name,
    cls.display_name,
    COUNT(*) as result_count
FROM results r
INNER JOIN classes cls ON r.class_id = cls.id
WHERE r.category_id IS NOT NULL
GROUP BY cls.id, cls.name, cls.display_name
ORDER BY result_count DESC;

-- Note: After this migration, you should:
-- 1. Run PHP script to recalculate class positions: includes/class-calculations.php
-- 2. Update all PHP files to use class_id instead of category_id
-- 3. Test thoroughly before removing category_id column
