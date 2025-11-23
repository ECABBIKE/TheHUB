-- Migration 025: Pricing Templates
-- Replaces series-based pricing with reusable templates that can be applied to events/series

-- Pricing templates (master table)
CREATE TABLE IF NOT EXISTS pricing_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_default TINYINT(1) DEFAULT 0 COMMENT 'Default template for new events',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Pricing rules per class in each template
CREATE TABLE IF NOT EXISTS pricing_template_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    class_id INT NOT NULL,
    base_price DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Ordinarie pris',
    early_bird_percent DECIMAL(5,2) DEFAULT 0 COMMENT 'Early bird rabatt i procent',
    early_bird_days_before INT DEFAULT 21 COMMENT 'Dagar före event för early bird',
    late_fee_percent DECIMAL(5,2) DEFAULT 0 COMMENT 'Efteranmälan tillägg i procent',
    late_fee_days_before INT DEFAULT 3 COMMENT 'Dagar före event för efteranmälan',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES pricing_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_template_class (template_id, class_id),
    INDEX idx_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Add pricing template reference to events
ALTER TABLE events
ADD COLUMN pricing_template_id INT NULL COMMENT 'Prismall för detta event' AFTER ticketing_enabled,
ADD FOREIGN KEY (pricing_template_id) REFERENCES pricing_templates(id) ON DELETE SET NULL;

-- Add default pricing template to series
ALTER TABLE series
ADD COLUMN default_pricing_template_id INT NULL COMMENT 'Standardprismall för events i serien' AFTER organizer,
ADD FOREIGN KEY (default_pricing_template_id) REFERENCES pricing_templates(id) ON DELETE SET NULL;

-- Create index for faster lookups
CREATE INDEX idx_events_pricing_template ON events(pricing_template_id);
CREATE INDEX idx_series_pricing_template ON series(default_pricing_template_id);
