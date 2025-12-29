-- Elimination Class Mapping
-- Maps Dual Slalom classes to series point classes
-- Example: "Ungdom Pojkar" in DS -> "Herr Junior" for series points
-- Created: 2025-12-29

CREATE TABLE IF NOT EXISTS elimination_class_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    ds_class_id INT NOT NULL,           -- The class used in Dual Slalom
    series_class_id INT NOT NULL,       -- The class to award points to in the series

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (ds_class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (series_class_id) REFERENCES classes(id) ON DELETE CASCADE,

    UNIQUE KEY unique_event_ds_class (event_id, ds_class_id),
    INDEX idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
