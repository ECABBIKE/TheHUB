-- Update rider gender based on their class gender
-- Updates all riders in female classes (gender = 'F') to have gender 'F'

START TRANSACTION;

-- Show counts before update
SELECT
    'Before Update' as Stage,
    (SELECT COUNT(DISTINCT r.id)
     FROM riders r
     JOIN results res ON r.id = res.cyclist_id
     JOIN classes c ON res.class_id = c.id
     WHERE c.gender = 'F' AND r.gender != 'F') as 'Riders to Update';

-- Update riders in female classes to have gender 'F'
UPDATE riders r
JOIN results res ON r.id = res.cyclist_id
JOIN classes c ON res.class_id = c.id
SET r.gender = 'F'
WHERE c.gender = 'F'
  AND r.gender != 'F';

-- Show counts after update
SELECT
    'After Update' as Stage,
    (SELECT COUNT(DISTINCT r.id)
     FROM riders r
     JOIN results res ON r.id = res.cyclist_id
     JOIN classes c ON res.class_id = c.id
     WHERE c.gender = 'F' AND r.gender = 'F') as 'Female Riders in Female Classes';

COMMIT;

-- Done!
SELECT 'All riders in female classes updated to gender F!' as 'Status';
