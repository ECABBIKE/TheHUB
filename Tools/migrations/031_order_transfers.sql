-- Migration 030: Order Transfers för Multi-Seller Payments
-- Datum: 2026-02-01
-- Beskrivning: Stöd för automatiska överföringar till flera säljare per order
--   - Alla betalningar går till plattformen
--   - Automatiska transfers till säljare efter lyckad betalning
--   - Tracking av alla transfers för rapportering

-- ============================================================
-- 1. ORDER TRANSFERS (Spåra överföringar till säljare)
-- ============================================================

CREATE TABLE IF NOT EXISTS order_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Koppling till order
    order_id INT NOT NULL,
    order_item_id INT DEFAULT NULL,

    -- Mottagare
    payment_recipient_id INT NOT NULL,
    stripe_account_id VARCHAR(50) NOT NULL,

    -- Belopp
    amount DECIMAL(10,2) NOT NULL,
    platform_fee DECIMAL(10,2) DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'SEK',

    -- Stripe-koppling
    stripe_transfer_id VARCHAR(100) DEFAULT NULL,
    stripe_charge_id VARCHAR(100) DEFAULT NULL,
    transfer_group VARCHAR(100) DEFAULT NULL,

    -- Status
    status ENUM('pending', 'completed', 'failed', 'reversed') DEFAULT 'pending',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    transferred_at DATETIME DEFAULT NULL,
    failed_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,

    -- Metadata
    metadata JSON DEFAULT NULL,

    INDEX idx_order (order_id),
    INDEX idx_recipient (payment_recipient_id),
    INDEX idx_stripe_transfer (stripe_transfer_id),
    INDEX idx_transfer_group (transfer_group),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
);

-- ============================================================
-- 2. UPPDATERA ORDER_ITEMS MED RECIPIENT
-- ============================================================

-- Lägg till payment_recipient_id på order_items
ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS payment_recipient_id INT DEFAULT NULL AFTER product_type_id,
    ADD COLUMN IF NOT EXISTS seller_amount DECIMAL(10,2) DEFAULT NULL AFTER payment_recipient_id,
    ADD INDEX IF NOT EXISTS idx_recipient (payment_recipient_id);

-- ============================================================
-- 3. UPPDATERA ORDERS MED TRANSFER-METADATA
-- ============================================================

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS transfer_group VARCHAR(100) DEFAULT NULL AFTER gateway_metadata,
    ADD COLUMN IF NOT EXISTS transfers_status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT NULL AFTER transfer_group,
    ADD COLUMN IF NOT EXISTS transfers_completed_at DATETIME DEFAULT NULL AFTER transfers_status,
    ADD INDEX IF NOT EXISTS idx_transfer_group (transfer_group);

-- ============================================================
-- 4. SELLER WEEKLY REPORTS
-- ============================================================

CREATE TABLE IF NOT EXISTS seller_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Säljare
    payment_recipient_id INT NOT NULL,

    -- Period
    report_type ENUM('weekly', 'monthly', 'custom') DEFAULT 'weekly',
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,

    -- Sammanfattning
    total_sales DECIMAL(12,2) DEFAULT 0.00,
    total_items INT DEFAULT 0,
    total_orders INT DEFAULT 0,
    platform_fees DECIMAL(10,2) DEFAULT 0.00,
    net_amount DECIMAL(12,2) DEFAULT 0.00,

    -- Betalningsstatus
    transfers_amount DECIMAL(12,2) DEFAULT 0.00,
    pending_amount DECIMAL(12,2) DEFAULT 0.00,

    -- PDF/Export
    pdf_path VARCHAR(500) DEFAULT NULL,
    csv_path VARCHAR(500) DEFAULT NULL,

    -- Status
    status ENUM('draft', 'sent', 'viewed') DEFAULT 'draft',
    sent_at DATETIME DEFAULT NULL,
    viewed_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_recipient (payment_recipient_id),
    INDEX idx_period (period_start, period_end),
    INDEX idx_type (report_type),
    UNIQUE KEY idx_unique_report (payment_recipient_id, report_type, period_start, period_end)
);

-- Rapportrader (detaljerad lista över försäljning)
CREATE TABLE IF NOT EXISTS seller_report_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,

    -- Order-info
    order_id INT NOT NULL,
    order_number VARCHAR(50),
    order_date DATETIME,

    -- Produkt-info
    item_description VARCHAR(500),
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2),
    total_price DECIMAL(10,2),

    -- Kund-info
    customer_name VARCHAR(255),
    customer_email VARCHAR(255),

    -- Event-info (om relevant)
    event_name VARCHAR(255),
    event_date DATE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_report (report_id),
    INDEX idx_order (order_id),
    FOREIGN KEY (report_id) REFERENCES seller_reports(id) ON DELETE CASCADE
);

-- ============================================================
-- 5. SYSTEM SETTINGS FÖR TRANSFERS
-- ============================================================

INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
    ('auto_transfer_enabled', '1', 'boolean', 'Automatiska överföringar till säljare efter betalning'),
    ('auto_transfer_delay_hours', '0', 'number', 'Fördröjning innan överföring (timmar, 0 = direkt)'),
    ('weekly_seller_reports_enabled', '1', 'boolean', 'Skicka veckorapporter till säljare'),
    ('weekly_seller_reports_day', '1', 'number', 'Veckodag för rapporter (1=Måndag, 7=Söndag)'),
    ('platform_fee_type', 'fixed', 'string', 'Typ av plattformsavgift: fixed, percent, tiered'),
    ('platform_fee_fixed', '0', 'number', 'Fast plattformsavgift i SEK (om fixed)'),
    ('platform_fee_percent', '0', 'number', 'Procentuell avgift (om percent)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
