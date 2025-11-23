-- Migration 024: Series Pricing and Class Rules
-- Prices and license restrictions tied to series instead of events

-- Series pricing rules (replaces event_pricing_rules concept)
CREATE TABLE IF NOT EXISTS series_pricing_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    series_id INT NOT NULL,
    class_id INT NOT NULL,
    base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    early_bird_discount_percent DECIMAL(5,2) DEFAULT 0,
    early_bird_days_before INT DEFAULT 20,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    UNIQUE KEY unique_series_class (series_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Series class rules (license restrictions per class)
CREATE TABLE IF NOT EXISTS series_class_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    series_id INT NOT NULL,
    class_id INT NOT NULL,
    allowed_license_types JSON NULL COMMENT 'Array of allowed license types, e.g. ["Elite", "Junior"]',
    min_birth_year INT NULL COMMENT 'Minimum birth year (oldest allowed)',
    max_birth_year INT NULL COMMENT 'Maximum birth year (youngest allowed)',
    allowed_genders JSON NULL COMMENT 'Array of allowed genders, e.g. ["M", "F"]',
    requires_license TINYINT(1) DEFAULT 1 COMMENT 'Whether a license is required',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    UNIQUE KEY unique_series_class_rule (series_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Add index for faster lookups
CREATE INDEX idx_series_pricing_series ON series_pricing_rules(series_id);
CREATE INDEX idx_series_class_rules_series ON series_class_rules(series_id);
