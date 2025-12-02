-- Migration 045: Set awards_points = 0 for motion/sportmotion classes
-- These classes should not count for wins, podiums, or series standings
-- Motion kids, Motion kort, Motion mellan, Sportmotion lång

-- Update classes to not award points (and thus no podiums/wins)
UPDATE classes SET awards_points = 0, series_eligible = 0
WHERE LOWER(name) LIKE '%motion%'
   OR LOWER(display_name) LIKE '%motion%'
   OR LOWER(name) LIKE 'sport%'
   OR LOWER(display_name) LIKE 'sport%';

-- Specifically target the known motion classes
UPDATE classes SET awards_points = 0, series_eligible = 0
WHERE LOWER(display_name) IN (
    'motion kids',
    'motion kort',
    'motion mellan',
    'sportmotion lång',
    'sportmotion kort',
    'sportmotion'
);
