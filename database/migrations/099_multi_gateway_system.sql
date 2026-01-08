-- Migration 099: Multi-Gateway Payment System
-- Date: 2026-01-08
-- Description: Adds support for multiple payment gateways (Swish Handel, Stripe Connect, etc)

-- ============================================================================
-- 1. ADD GATEWAY FIELDS TO PAYMENT_RECIPIENTS
-- ============================================================================

ALTER TABLE payment_recipients
    ADD COLUMN IF NOT EXISTS gateway_type ENUM('manual', 'swish_handel', 'stripe') DEFAULT 'manual' AFTER active,
    ADD COLUMN IF NOT EXISTS gateway_config JSON NULL COMMENT 'Gateway-specific config (credentials, certificates)' AFTER gateway_type,
    ADD COLUMN IF NOT EXISTS gateway_enabled TINYINT(1) DEFAULT 0 AFTER gateway_config;

-- Add indexes if they don't exist
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'payment_recipients' AND index_name = 'idx_gateway_type');
SET @sql := IF(@exist = 0, 'ALTER TABLE payment_recipients ADD INDEX idx_gateway_type (gateway_type)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'payment_recipients' AND index_name = 'idx_gateway_enabled');
SET @sql := IF(@exist = 0, 'ALTER TABLE payment_recipients ADD INDEX idx_gateway_enabled (gateway_enabled)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 2. UPDATE ORDERS TABLE FOR MULTI-GATEWAY
-- ============================================================================

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS gateway_code VARCHAR(20) NULL AFTER payment_method COMMENT 'swish_handel, stripe, manual',
    ADD COLUMN IF NOT EXISTS gateway_transaction_id VARCHAR(100) NULL AFTER payment_reference COMMENT 'Gateway-specific transaction ID',
    ADD COLUMN IF NOT EXISTS gateway_metadata JSON NULL COMMENT 'Gateway-specific data',
    ADD COLUMN IF NOT EXISTS callback_received_at DATETIME NULL COMMENT 'When webhook/callback was received';

-- Add indexes if they don't exist
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'idx_gateway_code');
SET @sql := IF(@exist = 0, 'ALTER TABLE orders ADD INDEX idx_gateway_code (gateway_code)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'idx_gateway_transaction');
SET @sql := IF(@exist = 0, 'ALTER TABLE orders ADD INDEX idx_gateway_transaction (gateway_transaction_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 3. CREATE PAYMENT_TRANSACTIONS TABLE
-- ============================================================================
-- Detailed log of all gateway interactions

CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,

    -- Gateway info
    gateway_code VARCHAR(20) NOT NULL,
    transaction_type ENUM('payment', 'refund', 'cancel', 'status_check') DEFAULT 'payment',

    -- Request/Response
    request_data JSON NULL,
    response_data JSON NULL,

    -- Status
    status ENUM('pending', 'success', 'failed', 'cancelled') DEFAULT 'pending',
    error_code VARCHAR(50) NULL,
    error_message TEXT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,

    -- Foreign keys
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_order (order_id),
    INDEX idx_gateway (gateway_code),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. CREATE GATEWAY_CERTIFICATES TABLE
-- ============================================================================
-- Store Swish certificates per payment recipient

CREATE TABLE IF NOT EXISTS gateway_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_recipient_id INT NOT NULL,

    -- Certificate info
    cert_type ENUM('swish_test', 'swish_production') NOT NULL,
    cert_data MEDIUMBLOB NOT NULL COMMENT 'Certificate file content',
    cert_password VARCHAR(255) NULL COMMENT 'Encrypted certificate password',

    -- Metadata
    uploaded_by INT NULL COMMENT 'admin_users.id',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATE NULL,

    -- Status
    active TINYINT(1) DEFAULT 1,

    -- Foreign keys
    FOREIGN KEY (payment_recipient_id) REFERENCES payment_recipients(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES admin_users(id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_payment_recipient (payment_recipient_id),
    INDEX idx_cert_type (cert_type),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. CREATE WEBHOOK_LOGS TABLE
-- ============================================================================
-- Log all incoming webhooks for debugging

CREATE TABLE IF NOT EXISTS webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Webhook info
    gateway_code VARCHAR(20) NOT NULL,
    webhook_type VARCHAR(50) NULL COMMENT 'e.g. payment.succeeded, payment.failed',

    -- Request data
    payload JSON NOT NULL,
    headers JSON NULL,
    signature VARCHAR(500) NULL,

    -- Processing
    processed TINYINT(1) DEFAULT 0,
    order_id INT NULL,
    error_message TEXT NULL,

    -- Timestamps
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,

    -- Indexes
    INDEX idx_gateway (gateway_code),
    INDEX idx_processed (processed),
    INDEX idx_order (order_id),
    INDEX idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. ADD STRIPE FIELDS TO PAYMENT_RECIPIENTS
-- ============================================================================

ALTER TABLE payment_recipients
    ADD COLUMN IF NOT EXISTS stripe_account_id VARCHAR(100) NULL COMMENT 'Connected Account ID' AFTER gateway_config,
    ADD COLUMN IF NOT EXISTS stripe_account_status ENUM('pending', 'active', 'disabled') NULL AFTER stripe_account_id;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'payment_recipients' AND index_name = 'idx_stripe_account');
SET @sql := IF(@exist = 0, 'ALTER TABLE payment_recipients ADD INDEX idx_stripe_account (stripe_account_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 7. MIGRATION COMPLETE
-- ============================================================================
-- Run this migration with: mysql -u username -p database_name < 099_multi_gateway_system.sql
