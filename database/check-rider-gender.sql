-- Check gender values in riders table

-- Show gender distribution
SELECT
    'Gender Distribution' as Check,
    gender,
    COUNT(*) as Count
FROM riders
GROUP BY gender
ORDER BY Count DESC;

-- Show sample riders with their gender
SELECT
    'Sample Riders' as Check,
    id,
    firstname,
    lastname,
    gender,
    birth_year
FROM riders
LIMIT 20;

-- Count riders with missing gender
SELECT
    'Missing Gender' as Check,
    COUNT(*) as Count
FROM riders
WHERE gender IS NULL OR gender = '';
