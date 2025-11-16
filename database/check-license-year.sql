-- Check if license_year is being imported correctly

-- Check license_year distribution
SELECT
    'License Year Distribution' as Check,
    license_year,
    COUNT(*) as Count
FROM riders
WHERE license_year IS NOT NULL
GROUP BY license_year
ORDER BY license_year DESC;

-- Check riders with missing license_year
SELECT
    'Missing License Year' as Check,
    COUNT(*) as Count
FROM riders
WHERE license_year IS NULL OR license_year = 0;

-- Sample riders with license data
SELECT
    'Sample Riders with License Data' as Check,
    id,
    firstname,
    lastname,
    license_number,
    license_year,
    license_type,
    gender,
    birth_year
FROM riders
LIMIT 10;
