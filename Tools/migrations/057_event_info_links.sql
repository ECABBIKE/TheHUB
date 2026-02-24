-- Migration 057: Event info links table
-- Replaces single link columns (general_competition_link_url/text) with a multi-link table
-- Allows unlimited links per event in the "Generell tävlingsinformation" section

CREATE TABLE IF NOT EXISTS event_info_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    link_url VARCHAR(500) NOT NULL,
    link_text VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_eil_event (event_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing single-link data to new table
INSERT INTO event_info_links (event_id, link_url, link_text, sort_order)
SELECT id, general_competition_link_url, general_competition_link_text, 0
FROM events
WHERE general_competition_link_url IS NOT NULL AND general_competition_link_url != '';
