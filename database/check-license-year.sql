-- Check if license_year is being imported correctly

-- 1. Check license_year distribution
SELECT
    license_year,
    COUNT(*) as rider_count
FROM riders
WHERE license_year IS NOT NULL
GROUP BY license_year
ORDER BY license_year DESC;

-- 2. Check riders with missing license_year
SELECT
    COUNT(*) as missing_license_year_count
FROM riders
WHERE license_year IS NULL OR license_year = 0;

-- 3. Sample riders with license data
SELECT
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
