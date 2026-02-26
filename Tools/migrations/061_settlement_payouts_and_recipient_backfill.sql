-- Migration 061: Settlement payouts table + backfill payment_recipient_id via promotor chain
-- Skapar tabell för att spåra faktiska utbetalningar till betalningsmottagare (avräkningar)
-- Backfillar payment_recipient_id på events och series baserat på promotor-kopplingar

-- ============================================================
-- 1. Settlement payouts - spåra faktiska utbetalningar
-- ============================================================
CREATE TABLE IF NOT EXISTS settlement_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    reference VARCHAR(100) NULL COMMENT 'Betalningsreferens (t.ex. OCR, Swish-ref)',
    payment_method VARCHAR(20) DEFAULT 'bank' COMMENT 'bank, swish, stripe',
    notes TEXT NULL,
    status ENUM('pending','completed','cancelled') DEFAULT 'completed',
    created_by INT NULL COMMENT 'Admin user som skapade utbetalningen',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient_id),
    INDEX idx_period (period_start, period_end),
    INDEX idx_status (status)
);

-- ============================================================
-- 2. Backfill: Sätt payment_recipient_id på events via promotor-kedjan
-- Kedja: payment_recipients.admin_user_id → promotor_events.user_id → events
-- ============================================================
UPDATE events e
JOIN promotor_events pe ON pe.event_id = e.id
JOIN payment_recipients pr ON pr.admin_user_id = pe.user_id
SET e.payment_recipient_id = pr.id
WHERE e.payment_recipient_id IS NULL
AND pr.active = 1;

-- ============================================================
-- 3. Backfill: Sätt payment_recipient_id på series via promotor-kedjan
-- Kedja: payment_recipients.admin_user_id → promotor_series.user_id → series
-- ============================================================
UPDATE series s
JOIN promotor_series ps ON ps.series_id = s.id
JOIN payment_recipients pr ON pr.admin_user_id = ps.user_id
SET s.payment_recipient_id = pr.id
WHERE s.payment_recipient_id IS NULL
AND pr.active = 1;

-- ============================================================
-- 4. Backfill: Sätt payment_recipient_id på events via serie-koppling
-- Om serien har recipient men eventet inte har det
-- ============================================================
UPDATE events e
JOIN series_events se ON se.event_id = e.id
JOIN series s ON se.series_id = s.id
SET e.payment_recipient_id = s.payment_recipient_id
WHERE e.payment_recipient_id IS NULL
AND s.payment_recipient_id IS NOT NULL;

-- Fallback: events.series_id (legacy)
UPDATE events e
JOIN series s ON e.series_id = s.id
SET e.payment_recipient_id = s.payment_recipient_id
WHERE e.payment_recipient_id IS NULL
AND s.payment_recipient_id IS NOT NULL;
