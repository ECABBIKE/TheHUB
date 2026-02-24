-- Migration 054: Premium membership plans and rider-specific sponsors
-- Adds correct pricing plans and rider_sponsors table for premium members

-- Clear old sample plans (they have wrong pricing)
DELETE FROM membership_plans WHERE stripe_product_id IS NULL AND stripe_price_id IS NULL;

-- Insert correct premium plans
INSERT INTO membership_plans (name, description, price_amount, currency, billing_interval, billing_interval_count, benefits, discount_percent, sort_order, active) VALUES
('Månadsmedlemskap', 'Premium-medlemskap med månatlig betalning', 2500, 'SEK', 'month', 1, '["Premium-badge på profilen", "Visa dina sponsorer på profilen", "Exklusiva delningsbadges", "Stöd Gravity Series"]', 0, 1, 1),
('Årsmedlemskap', 'Premium-medlemskap - spara 101 kr per år', 19900, 'SEK', 'year', 1, '["Premium-badge på profilen", "Visa dina sponsorer på profilen", "Exklusiva delningsbadges", "Stöd Gravity Series", "Bästa pris - spara 101 kr"]', 0, 2, 1);

-- Rider sponsors table - personal sponsors shown on premium rider profiles
CREATE TABLE IF NOT EXISTS rider_sponsors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    name VARCHAR(150) NOT NULL COMMENT 'Sponsor name',
    logo_url VARCHAR(500) NULL COMMENT 'Logo image URL (uploaded or external)',
    website_url VARCHAR(500) NULL COMMENT 'Sponsor website link',
    sort_order INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_rider (rider_id),
    INDEX idx_active_rider (rider_id, active),
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
