-- Migration 044: Add general competition info field to events
-- Adds a new text field for general competition information,
-- displayed below the invitation text on the Inbjudan tab.

ALTER TABLE events ADD COLUMN general_competition_info TEXT NULL AFTER invitation;
ALTER TABLE events ADD COLUMN general_competition_use_global TINYINT(1) DEFAULT 0 AFTER general_competition_info;
ALTER TABLE events ADD COLUMN general_competition_hidden TINYINT(1) DEFAULT 0 AFTER general_competition_use_global;
