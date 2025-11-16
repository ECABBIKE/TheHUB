-- Fix gender for ALL riders based on their class names
-- Updates both classes and riders based on name patterns

START TRANSACTION;

-- Step 1: Update female classes (D=Dam, F=Flicka) to have gender = 'K'
UPDATE classes
SET gender = 'K'
WHERE (name LIKE 'D%' OR name LIKE 'F%')
  AND name NOT IN ('DH');  -- Exclude "DH" (Downhill)

-- Step 2: Update male classes (H=Herr, P=Pojke) to have gender = 'M'
UPDATE classes
SET gender = 'M'
WHERE (name LIKE 'H%' OR name LIKE 'P%')
  AND name NOT IN ('HE');  -- Exclude if needed

-- Show updated classes
SELECT 'Female Classes (K)' as 'Type',
       name,
       display_name,
       gender
FROM classes
WHERE gender = 'K'
UNION ALL
SELECT 'Male Classes (M)' as 'Type',
       name,
       display_name,
       gender
FROM classes
WHERE gender = 'M'
ORDER BY Type, name;

-- Step 3: Update riders in female classes to have gender = 'F'
UPDATE riders r
SET r.gender = 'F'
WHERE r.id IN (
    SELECT DISTINCT res.cyclist_id
    FROM results res
    JOIN classes c ON res.class_id = c.id
    WHERE c.gender = 'K'
);

-- Step 4: Update riders in male classes to have gender = 'M'
UPDATE riders r
SET r.gender = 'M'
WHERE r.id IN (
    SELECT DISTINCT res.cyclist_id
    FROM results res
    JOIN classes c ON res.class_id = c.id
    WHERE c.gender = 'M'
);

-- Show statistics
SELECT 'Statistics' as 'Type',
       'Female' as 'Gender',
       COUNT(*) as 'Count'
FROM riders
WHERE gender = 'F'
UNION ALL
SELECT 'Statistics' as 'Type',
       'Male' as 'Gender',
       COUNT(*) as 'Count'
FROM riders
WHERE gender = 'M';

COMMIT;

-- Done!
SELECT 'All classes and riders updated based on class names!' as 'Status';
