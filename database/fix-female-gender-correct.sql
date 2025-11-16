-- Fix female gender for classes and riders
-- Classes use 'K' (Kvinnor) while riders can use 'F' or 'K'

START TRANSACTION;

-- Step 1: Update female classes to have gender = 'K' (Swedish for women)
UPDATE classes
SET gender = 'K'
WHERE name IN ('DE', 'DJ', 'D35', 'D19', 'F15-16', 'F13-14')
   OR display_name LIKE '%Dam%'
   OR display_name LIKE '%Flick%';

-- Show which classes were updated
SELECT 'Updated Classes' as 'Step 1',
       name,
       display_name,
       gender
FROM classes
WHERE gender = 'K';

-- Step 2: Update riders in female classes to have gender = 'F'
UPDATE riders r
SET r.gender = 'F'
WHERE r.id IN (
    SELECT DISTINCT res.cyclist_id
    FROM results res
    JOIN classes c ON res.class_id = c.id
    WHERE c.gender = 'K'
);

-- Show how many riders were updated
SELECT 'Updated Riders' as 'Step 2',
       COUNT(*) as 'Female Riders'
FROM riders
WHERE gender = 'F';

-- Verify: Show some female riders in female classes
SELECT 'Verification' as 'Step 3',
       c.name as 'Class',
       c.gender as 'Class Gender',
       r.firstname,
       r.lastname,
       r.gender as 'Rider Gender'
FROM riders r
JOIN results res ON r.id = res.cyclist_id
JOIN classes c ON res.class_id = c.id
WHERE c.gender = 'K'
LIMIT 10;

COMMIT;

-- Done!
SELECT 'All female classes and riders updated!' as 'Status';
