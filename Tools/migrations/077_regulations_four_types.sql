-- Migration 077: Expand regulations global types from 2 to 4
-- Old: sportmotion, competition
-- New: sportmotion_edr, sportmotion_dh, national_edr, national_dh

-- Seed new global texts (keep old ones for backwards compatibility)
INSERT IGNORE INTO global_texts (field_key, field_name, field_category, content, sort_order, is_active)
VALUES
    ('regulations_sportmotion_edr', 'Regelverk - sportMotion EDR', 'rules', '', 4, 1),
    ('regulations_sportmotion_dh', 'Regelverk - sportMotion DH', 'rules', '', 5, 1),
    ('regulations_national_edr', 'Regelverk - Nationell EDR', 'rules', '', 6, 1),
    ('regulations_national_dh', 'Regelverk - Nationell DH', 'rules', '', 7, 1);

-- Copy content from old sportmotion → sportmotion_edr (if any content exists)
UPDATE global_texts dst
JOIN global_texts src ON src.field_key = 'regulations_sportmotion'
SET dst.content = src.content
WHERE dst.field_key = 'regulations_sportmotion_edr' AND dst.content = '' AND src.content != '';

-- Copy content from old competition → national_edr (closest match)
UPDATE global_texts dst
JOIN global_texts src ON src.field_key = 'regulations_competition'
SET dst.content = src.content
WHERE dst.field_key = 'regulations_national_edr' AND dst.content = '' AND src.content != '';

-- Copy links from old keys to new keys
INSERT IGNORE INTO global_text_links (field_key, link_url, link_text, sort_order)
SELECT 'regulations_sportmotion_edr', link_url, link_text, sort_order
FROM global_text_links WHERE field_key = 'regulations_sportmotion';

INSERT IGNORE INTO global_text_links (field_key, link_url, link_text, sort_order)
SELECT 'regulations_national_edr', link_url, link_text, sort_order
FROM global_text_links WHERE field_key = 'regulations_competition';

-- Migrate existing events using old types to new types
UPDATE events SET regulations_global_type = 'sportmotion_edr' WHERE regulations_global_type = 'sportmotion';
UPDATE events SET regulations_global_type = 'national_edr' WHERE regulations_global_type = 'competition';

-- Deactivate old global texts (keep data, just hide from admin)
UPDATE global_texts SET is_active = 0 WHERE field_key IN ('regulations_sportmotion', 'regulations_competition');
