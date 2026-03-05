-- Migration 075: Change inherit_series_sponsors from TINYINT to VARCHAR
-- Needed because the column stores comma-separated placement names (e.g. 'header,content,partner')
-- 2026-03-05

ALTER TABLE events MODIFY COLUMN inherit_series_sponsors VARCHAR(100) NOT NULL DEFAULT '';
