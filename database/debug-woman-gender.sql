-- Check what's actually in the database for WOMAN riders

SELECT
    'Riders with WOMAN gender' as Check,
    id,
    firstname,
    lastname,
    gender,
    birth_year,
    license_year
FROM riders
WHERE UPPER(gender) = 'WOMAN'
LIMIT 20;

-- Check what classes these riders are in
SELECT
    'Classes for WOMAN riders' as Check,
    r.firstname,
    r.lastname,
    r.gender as RiderGender,
    c.name as ClassName,
    c.display_name as ClassDisplay,
    c.gender as ClassGender
FROM riders r
JOIN results res ON r.id = res.cyclist_id
JOIN classes c ON res.class_id = c.id
WHERE UPPER(r.gender) = 'WOMAN'
LIMIT 20;
