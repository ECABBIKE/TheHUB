-- Fix female gender for classes and riders
-- Step 1: Update classes to have gender = 'F'
-- Step 2: Update riders in those classes to have gender = 'F'

START TRANSACTION;

-- Step 1: Update female classes to have gender = 'F'
UPDATE classes
SET gender = 'F'
WHERE name IN ('DE', 'DJ', 'D35', 'D19', 'F15-16', 'F13-14')
   OR display_name LIKE '%Dam%'
   OR display_name LIKE '%Flick%';

-- Show which classes were updated
SELECT 'Updated Classes' as 'Step 1',
       name,
       display_name,
       gender
FROM classes
WHERE gender = 'F';

-- Step 2: Update riders in female classes to have gender = 'F'
UPDATE riders r
SET r.gender = 'F'
WHERE r.id IN (
    SELECT DISTINCT res.cyclist_id
    FROM results res
    JOIN classes c ON res.class_id = c.id
    WHERE c.gender = 'F'
);

-- Show how many riders were updated
SELECT 'Updated Riders' as 'Step 2',
       COUNT(*) as 'Female Riders'
FROM riders
WHERE gender = 'F';

COMMIT;

-- Done!
SELECT 'All female classes and riders updated!' as 'Status';
