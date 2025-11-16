-- Update gender for specific classes by exact name
-- More reliable than pattern matching

START TRANSACTION;

-- Update FEMALE classes to gender = 'K'
UPDATE classes
SET gender = 'K'
WHERE name IN (
    'DE',      -- Damer Elit
    'DJ',      -- Damer Junior
    'D19',     -- Damer 19
    'D35',     -- Master Damer 35+
    'F13-14',  -- Flickor 13-14
    'F15-16'   -- Flickor 15-16
);

-- Update MALE classes to gender = 'M'
UPDATE classes
SET gender = 'M'
WHERE name IN (
    'HE',      -- Herrar Elit
    'HJ',      -- Herrar Junior
    'H19',     -- Herrar 19
    'H35',     -- Master Herrar 35+
    'H45',     -- Master Herrar 45+
    'P13-14',  -- Pojkar 13-14
    'P15-16'   -- Pojkar 15-16
);

-- Show ALL classes after update
SELECT name,
       display_name,
       gender,
       min_age,
       max_age
FROM classes
ORDER BY sort_order;

-- Update riders in FEMALE classes to gender = 'F'
UPDATE riders r
SET r.gender = 'F'
WHERE r.id IN (
    SELECT DISTINCT res.cyclist_id
    FROM results res
    JOIN classes c ON res.class_id = c.id
    WHERE c.name IN ('DE', 'DJ', 'D19', 'D35', 'F13-14', 'F15-16')
);

-- Update riders in MALE classes to gender = 'M'
UPDATE riders r
SET r.gender = 'M'
WHERE r.id IN (
    SELECT DISTINCT res.cyclist_id
    FROM results res
    JOIN classes c ON res.class_id = c.id
    WHERE c.name IN ('HE', 'HJ', 'H19', 'H35', 'H45', 'P13-14', 'P15-16')
);

-- Show rider statistics
SELECT
    'Female Riders' as Category,
    COUNT(*) as Count
FROM riders
WHERE gender = 'F'
UNION ALL
SELECT
    'Male Riders' as Category,
    COUNT(*) as Count
FROM riders
WHERE gender = 'M';

COMMIT;

SELECT 'Gender updated for all classes and riders!' as Status;
