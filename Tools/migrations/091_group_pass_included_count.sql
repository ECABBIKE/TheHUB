-- Migration 091: Add pass_included_count to festival_activity_groups
-- Allows configuring how many activities from a group are included in a festival pass
-- e.g. group "Fox Clinics" has 5 activities, pass includes 2 of them (rider picks which 2)

ALTER TABLE festival_activity_groups
    ADD COLUMN pass_included_count INT NOT NULL DEFAULT 0 AFTER active;

-- When a group has pass_included_count > 0, individual activities in the group
-- should NOT have their own pass_included_count (group overrides).
-- The booking page will let riders choose N activities from the group.
