-- Migration 074: Add inherit_series_sponsors to events + display_size to series_sponsors
-- 2026-03-05

-- Events: flag to explicitly inherit sponsors from series (default 0 = don't inherit)
ALTER TABLE events ADD COLUMN inherit_series_sponsors TINYINT(1) NOT NULL DEFAULT 0 AFTER series_id;

-- Series sponsors: display size for partner logos (large = 3/row, small = 5/row)
ALTER TABLE series_sponsors ADD COLUMN display_size ENUM('large', 'small') NOT NULL DEFAULT 'small' AFTER display_order;
