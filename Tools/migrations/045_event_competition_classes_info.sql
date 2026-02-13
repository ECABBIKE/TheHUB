-- Migration 045: Add competition classes info field to events
-- Adds a text field for competition classes information,
-- displayed below the general competition info on the Inbjudan tab.

ALTER TABLE events ADD COLUMN competition_classes_info TEXT NULL AFTER general_competition_hidden;
ALTER TABLE events ADD COLUMN competition_classes_use_global TINYINT(1) DEFAULT 0 AFTER competition_classes_info;
ALTER TABLE events ADD COLUMN competition_classes_hidden TINYINT(1) DEFAULT 0 AFTER competition_classes_use_global;
