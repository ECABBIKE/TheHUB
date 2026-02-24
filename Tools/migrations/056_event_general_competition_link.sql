-- Migration 053: Add link fields to general competition info section
-- Allows adding a clickable link with custom display text under "Generell tävlingsinformation"

ALTER TABLE events ADD COLUMN general_competition_link_url VARCHAR(500) NULL AFTER general_competition_hidden;
ALTER TABLE events ADD COLUMN general_competition_link_text VARCHAR(255) NULL AFTER general_competition_link_url;
