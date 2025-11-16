-- Normalize all gender values in riders table
-- Converts various female/male representations to standard F/M

START TRANSACTION;

-- Show current gender values
SELECT 'Before Normalization' as Stage,
       gender,
       COUNT(*) as Count
FROM riders
GROUP BY gender;

-- Normalize female gender values (Women, K, Female, etc.) to 'F'
UPDATE riders
SET gender = 'F'
WHERE LOWER(gender) IN ('woman', 'women', 'female', 'kvinna', 'dam', 'f', 'k');

-- Normalize male gender values (Men, Herr, Male, etc.) to 'M'
UPDATE riders
SET gender = 'M'
WHERE LOWER(gender) IN ('man', 'men', 'male', 'herr', 'm');

-- Show gender values after normalization
SELECT 'After Normalization' as Stage,
       gender,
       COUNT(*) as Count
FROM riders
GROUP BY gender;

-- Update class gender values
-- Female classes to 'K' (for database ENUM compatibility)
UPDATE classes
SET gender = 'K'
WHERE name IN (
    'DE',      -- Damer Elit
    'DJ',      -- Damer Junior
    'D19',     -- Damer 19
    'D35',     -- Master Damer 35+
    'F13-14',  -- Flickor 13-14
    'F15-16'   -- Flickor 15-16
)
OR display_name LIKE '%Dam%'
OR display_name LIKE '%Flick%';

-- Male classes to 'M'
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
)
OR display_name LIKE '%Herr%'
OR display_name LIKE '%Pojk%';

-- Show class gender after update
SELECT 'Class Gender' as Type,
       gender,
       COUNT(*) as Count,
       GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') as Classes
FROM classes
GROUP BY gender;

COMMIT;

SELECT 'All gender values normalized!' as Status;
