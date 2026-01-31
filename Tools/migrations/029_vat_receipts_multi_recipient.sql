-- Migration 028: VAT/Moms, Kvitton och Multi-Recipient Support
-- Datum: 2026-01-29
-- Beskrivning: Lägger till stöd för:
--   1. Moms per produkttyp (6% tävling, 25% merch, 12% mat)
--   2. Kvitton/receipts lagrade på användarprofiler
--   3. Multi-recipient ordrar (en varukorg, flera mottagare)
--   4. Serie-anmälningsinställningar

-- ============================================================
-- 1. MOMS/VAT KONFIGURATION
-- ============================================================

-- Produkttyper med momssatser
CREATE TABLE IF NOT EXISTS product_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 25.00,
    stripe_tax_code VARCHAR(50) DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Standardprodukttyper med svenska momssatser
INSERT INTO product_types (code, name, vat_rate, stripe_tax_code) VALUES
    ('registration', 'Tävlingsanmälan', 6.00, 'txcd_20060098'),
    ('series_registration', 'Serieanmälan', 6.00, 'txcd_20060098'),
    ('merchandise', 'Merchandise/Produkter', 25.00, 'txcd_99999999'),
    ('food_drink', 'Mat & Dryck', 12.00, 'txcd_40060003'),
    ('camping', 'Camping/Boende', 12.00, 'txcd_30060000'),
    ('service', 'Tjänst', 25.00, 'txcd_10000000'),
    ('license', 'Licens/Medlemskap', 0.00, 'txcd_00000000')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Lägg till product_type_id på order_items
ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS product_type_id INT DEFAULT NULL AFTER item_type,
    ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) DEFAULT NULL AFTER total_price,
    ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(10,2) DEFAULT NULL AFTER vat_rate,
    ADD COLUMN IF NOT EXISTS price_includes_vat TINYINT(1) DEFAULT 1 AFTER vat_amount;

-- Lägg till VAT-summering på orders
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(10,2) DEFAULT 0.00 AFTER total_amount,
    ADD COLUMN IF NOT EXISTS price_includes_vat TINYINT(1) DEFAULT 1 AFTER vat_amount;

-- ============================================================
-- 2. KVITTON/RECEIPTS
-- ============================================================

CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(30) NOT NULL UNIQUE,
    order_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    rider_id INT DEFAULT NULL,

    -- Koppling till betalningsmottagare
    payment_recipient_id INT DEFAULT NULL,

    -- Belopp
    subtotal DECIMAL(10,2) NOT NULL,
    vat_amount DECIMAL(10,2) DEFAULT 0.00,
    discount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'SEK',

    -- Kund/köparinfo
    customer_name VARCHAR(255),
    customer_email VARCHAR(255),
    customer_address TEXT,
    customer_org_number VARCHAR(20),

    -- Säljare/mottagarinfo (snapshot vid köptillfället)
    seller_name VARCHAR(255),
    seller_org_number VARCHAR(20),
    seller_address TEXT,
    seller_vat_number VARCHAR(30),

    -- Stripe-koppling
    stripe_receipt_url VARCHAR(500),
    stripe_invoice_id VARCHAR(100),
    stripe_payment_intent_id VARCHAR(100),

    -- PDF-lagring
    pdf_path VARCHAR(500),
    pdf_generated_at DATETIME,

    -- Status
    status ENUM('draft', 'issued', 'void', 'refunded') DEFAULT 'issued',
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    voided_at DATETIME,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_order (order_id),
    INDEX idx_user (user_id),
    INDEX idx_rider (rider_id),
    INDEX idx_recipient (payment_recipient_id),
    INDEX idx_receipt_number (receipt_number),
    INDEX idx_issued_at (issued_at)
);

-- Kvittorader (kopia av order_items vid köptillfället)
CREATE TABLE IF NOT EXISTS receipt_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    vat_rate DECIMAL(5,2) DEFAULT 25.00,
    vat_amount DECIMAL(10,2) DEFAULT 0.00,
    total_price DECIMAL(10,2) NOT NULL,

    -- Referens till ursprunglig order_item
    order_item_id INT,
    product_type_code VARCHAR(50),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_receipt (receipt_id),
    FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE
);

-- ============================================================
-- 3. MULTI-RECIPIENT ORDRAR
-- ============================================================

-- En "cart" kan resultera i flera ordrar (en per mottagare)
-- Vi lägger till cart_session_id för att gruppera relaterade ordrar
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS cart_session_id VARCHAR(100) DEFAULT NULL AFTER session_id,
    ADD COLUMN IF NOT EXISTS parent_order_id INT DEFAULT NULL AFTER cart_session_id,
    ADD COLUMN IF NOT EXISTS is_split_order TINYINT(1) DEFAULT 0 AFTER parent_order_id;

-- Index för att hitta relaterade ordrar
ALTER TABLE orders
    ADD INDEX IF NOT EXISTS idx_cart_session (cart_session_id),
    ADD INDEX IF NOT EXISTS idx_parent_order (parent_order_id);

-- ============================================================
-- 4. SERIE-ANMÄLNINGSINSTÄLLNINGAR (utökade)
-- ============================================================

-- Lägg till fler inställningar på series
ALTER TABLE series
    ADD COLUMN IF NOT EXISTS series_registration_deadline_days INT DEFAULT 6 AFTER allow_series_registration,
    ADD COLUMN IF NOT EXISTS series_registration_deadline_type ENUM('days_before_first', 'fixed_date') DEFAULT 'days_before_first' AFTER series_registration_deadline_days,
    ADD COLUMN IF NOT EXISTS series_early_bird_deadline_days INT DEFAULT 30 AFTER series_registration_deadline_type,
    ADD COLUMN IF NOT EXISTS series_early_bird_discount_percent DECIMAL(5,2) DEFAULT 10.00 AFTER series_early_bird_deadline_days,
    ADD COLUMN IF NOT EXISTS show_series_option_on_events TINYINT(1) DEFAULT 1 AFTER series_early_bird_discount_percent;

-- ============================================================
-- 5. STRIPE TAX INSTÄLLNINGAR
-- ============================================================

-- Global inställning för Stripe Tax
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
    ('stripe_tax_enabled', '0', 'boolean', 'Aktivera Stripe Tax för automatisk momsberäkning'),
    ('stripe_tax_behavior', 'inclusive', 'string', 'Moms inkluderad (inclusive) eller exkluderad (exclusive)'),
    ('default_vat_rate', '6', 'number', 'Standard momssats för tävlingsanmälningar (%)'),
    ('vat_registration_number', '', 'string', 'Plattformens momsregistreringsnummer')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================================
-- 6. RECEIPT NUMBER SEQUENCE
-- ============================================================

-- Funktion för att generera kvittonummer
-- Format: REC-YYYY-NNNNNN (t.ex. REC-2026-000001)
CREATE TABLE IF NOT EXISTS receipt_sequences (
    year INT PRIMARY KEY,
    last_number INT DEFAULT 0
);

-- Initiera för aktuellt år
INSERT INTO receipt_sequences (year, last_number) VALUES (YEAR(CURDATE()), 0)
ON DUPLICATE KEY UPDATE year = year;
