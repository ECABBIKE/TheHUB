-- Migration 090: Festival pass included count per activity
-- Adds count column (0 = not included, 1+ = number of times included in pass)
-- Also adds pass_discount flag on registrations to track which used the pass discount

ALTER TABLE festival_activities
    ADD COLUMN pass_included_count INT NOT NULL DEFAULT 0 AFTER included_in_pass;

-- Backfill: copy existing boolean to count
UPDATE festival_activities SET pass_included_count = 1 WHERE included_in_pass = 1;
UPDATE festival_activities SET pass_included_count = 0 WHERE included_in_pass = 0;

-- Track which registrations used the pass discount (for count validation)
ALTER TABLE festival_activity_registrations
    ADD COLUMN pass_discount TINYINT(1) NOT NULL DEFAULT 0;
