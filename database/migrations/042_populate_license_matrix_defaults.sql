-- Migration 042: Populate license matrix with sensible defaults
-- Uses class age ranges (min_age, max_age) to determine which licenses are allowed
-- This is more reliable than pattern matching on class names

-- First, clear any existing data to start fresh
DELETE FROM class_license_eligibility;

-- NATIONAL RULES (based on age ranges from classes table)

-- Under 11 classes (max_age <= 10 or max_age = 11)
-- Allowed: under11, youth (can move up), junior, u23, elite, master, engångslicens
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'national', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND c.max_age IS NOT NULL
  AND c.max_age <= 11
  AND lt.code IN ('under11', 'youth', 'junior', 'u23', 'elite_men', 'elite_women', 'master', 'engangslicens')
  AND lt.is_active = 1;

-- Youth classes (ages 11-16, typically min_age >= 11 AND max_age <= 16)
-- Allowed: youth, junior, u23, elite, master, engångslicens, motionslicens
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'national', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND c.min_age IS NOT NULL AND c.max_age IS NOT NULL
  AND c.min_age >= 11 AND c.max_age <= 16
  AND lt.code IN ('youth', 'junior', 'u23', 'elite_men', 'elite_women', 'master', 'engangslicens', 'motionslicens')
  AND lt.is_active = 1;

-- Junior classes (ages 17-18)
-- Allowed: junior, u23, elite, master, engångslicens, motionslicens
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'national', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND c.min_age IS NOT NULL AND c.max_age IS NOT NULL
  AND c.min_age >= 17 AND c.max_age <= 18
  AND lt.code IN ('junior', 'u23', 'elite_men', 'elite_women', 'master', 'engangslicens', 'motionslicens')
  AND lt.is_active = 1;

-- U23 classes (ages 19-22)
-- Allowed: u23, elite, master, engångslicens, motionslicens
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'national', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND c.min_age IS NOT NULL AND c.max_age IS NOT NULL
  AND c.min_age >= 19 AND c.max_age <= 22
  AND lt.code IN ('u23', 'elite_men', 'elite_women', 'master', 'engangslicens', 'motionslicens')
  AND lt.is_active = 1;

-- Elite/Senior classes (ages 23-34, roughly)
-- Allowed: elite, master, u23 (can stay), engångslicens, motionslicens
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'national', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND c.min_age IS NOT NULL
  AND c.min_age >= 23 AND (c.max_age IS NULL OR c.max_age < 35 OR c.max_age >= 999)
  AND lt.code IN ('elite_men', 'elite_women', 'u23', 'master', 'engangslicens', 'motionslicens')
  AND lt.is_active = 1;

-- Master classes (ages 35+)
-- Allowed: master, elite, engångslicens, motionslicens
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'national', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND c.min_age IS NOT NULL
  AND c.min_age >= 35
  AND lt.code IN ('master', 'elite_men', 'elite_women', 'engangslicens', 'motionslicens')
  AND lt.is_active = 1;

-- Classes without age restrictions (open/motion/sport) - allow all licenses
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'national', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND (c.min_age IS NULL AND c.max_age IS NULL)
  AND lt.is_active = 1;

-- For any class still without rules, add sensible defaults based on gender='ALL' classes
INSERT IGNORE INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'national', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND lt.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM class_license_eligibility cle
      WHERE cle.class_id = c.id AND cle.event_license_class = 'national'
  );

-- SPORTMOTION RULES (copy national and add baslicens/sweid)
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'sportmotion', class_id, license_type_code, is_allowed
FROM class_license_eligibility
WHERE event_license_class = 'national';

-- Add baslicens and sweid to sportmotion for all classes
INSERT IGNORE INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'sportmotion', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND lt.is_active = 1
  AND lt.code IN ('baslicens', 'sweid');

-- MOTION RULES (most relaxed - allow all licenses for all classes)
INSERT INTO class_license_eligibility (event_license_class, class_id, license_type_code, is_allowed)
SELECT 'motion', c.id, lt.code, 1
FROM classes c
CROSS JOIN license_types lt
WHERE c.active = 1
  AND lt.is_active = 1;

-- Verify the results
SELECT 'License matrix populated:' as status;
SELECT event_license_class, COUNT(*) as rules_count
FROM class_license_eligibility
GROUP BY event_license_class;

SELECT 'Sample rules for youth-age classes (max_age <= 16):' as info;
SELECT c.name as class_name, c.min_age, c.max_age, cle.event_license_class,
       GROUP_CONCAT(cle.license_type_code ORDER BY cle.license_type_code) as allowed_licenses
FROM class_license_eligibility cle
JOIN classes c ON cle.class_id = c.id
WHERE cle.event_license_class = 'national'
  AND c.max_age IS NOT NULL AND c.max_age <= 16
GROUP BY c.id, cle.event_license_class
LIMIT 10;
