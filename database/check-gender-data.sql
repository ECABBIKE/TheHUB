-- Diagnostic: Check gender values in database

-- 1. Show what gender values exist in classes
SELECT 'Class Gender Values' as 'Check',
       gender,
       COUNT(*) as 'Count',
       GROUP_CONCAT(name SEPARATOR ', ') as 'Class Names'
FROM classes
GROUP BY gender;

-- 2. Show what gender values exist in riders
SELECT 'Rider Gender Values' as 'Check',
       gender,
       COUNT(*) as 'Count'
FROM riders
GROUP BY gender;

-- 3. Show riders in female classes and their current gender
SELECT 'Riders in Female Classes' as 'Check',
       c.name as 'Class Name',
       c.gender as 'Class Gender',
       r.firstname,
       r.lastname,
       r.gender as 'Rider Gender'
FROM riders r
JOIN results res ON r.id = res.cyclist_id
JOIN classes c ON res.class_id = c.id
WHERE c.gender IN ('F', 'K')
   OR c.display_name LIKE '%Dam%'
   OR c.display_name LIKE '%Flick%'
GROUP BY r.id, c.id
LIMIT 20;
