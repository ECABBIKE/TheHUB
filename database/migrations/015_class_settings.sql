-- Migration 015: Add class settings for points, ranking, and series eligibility
-- Controls how classes are handled in results and series calculations

ALTER TABLE classes
ADD COLUMN awards_points BOOLEAN DEFAULT 1 COMMENT 'Whether this class awards series points',
ADD COLUMN ranking_type ENUM('time', 'name', 'bib') DEFAULT 'time' COMMENT 'How to rank participants: time, name, or bib number',
ADD COLUMN series_eligible BOOLEAN DEFAULT 1 COMMENT 'Whether this class counts in series standings';

-- Update common non-competitive classes
UPDATE classes SET awards_points = 0, ranking_type = 'name', series_eligible = 0
WHERE LOWER(display_name) LIKE '%kids%'
   OR LOWER(display_name) LIKE '%barn%'
   OR LOWER(display_name) LIKE '%nybörjare%'
   OR LOWER(name) LIKE '%kids%'
   OR LOWER(name) LIKE '%barn%';

-- Update motion/sport classes that don't count for series
UPDATE classes SET series_eligible = 0
WHERE LOWER(display_name) LIKE '%motion%'
   OR LOWER(display_name) LIKE '%sport%'
   OR LOWER(name) LIKE '%motion%'
   OR LOWER(name) LIKE '%sport%';
