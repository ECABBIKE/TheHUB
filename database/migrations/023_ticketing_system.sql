-- Migration 023: Ticketing System
-- Adds ticketing columns to events and creates ticketing tables

-- Add ticketing columns to events table
ALTER TABLE events
ADD COLUMN ticketing_enabled TINYINT(1) DEFAULT 0 AFTER status,
ADD COLUMN woo_product_id INT UNSIGNED NULL AFTER ticketing_enabled,
ADD COLUMN ticket_deadline_days INT DEFAULT 7 AFTER woo_product_id;

-- Create event_pricing_rules table
CREATE TABLE IF NOT EXISTS event_pricing_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    class_id INT UNSIGNED NOT NULL,
    base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    early_bird_discount_percent DECIMAL(5,2) DEFAULT 0,
    early_bird_end_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_class (event_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Create event_tickets table
CREATE TABLE IF NOT EXISTS event_tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    class_id INT UNSIGNED NOT NULL,
    ticket_code VARCHAR(32) NOT NULL UNIQUE,
    status ENUM('available', 'reserved', 'sold', 'cancelled') DEFAULT 'available',
    rider_id INT UNSIGNED NULL,
    paid_price DECIMAL(10,2) NULL,
    order_id VARCHAR(64) NULL,
    purchased_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_event_status (event_id, status),
    INDEX idx_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Create event_refund_requests table
CREATE TABLE IF NOT EXISTS event_refund_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    rider_id INT UNSIGNED NOT NULL,
    reason TEXT,
    refund_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'approved', 'denied') DEFAULT 'pending',
    admin_notes TEXT NULL,
    processed_by VARCHAR(100) NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES event_tickets(id) ON DELETE CASCADE,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
