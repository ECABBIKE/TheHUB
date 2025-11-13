-- ============================================================================
-- IMPORT HISTORY & ROLLBACK SYSTEM
-- Migration: 003_import_history.sql
-- Created: 2025-01-13
-- ============================================================================

-- Import History Table
-- Tracks all import operations with metadata and statistics
CREATE TABLE IF NOT EXISTS import_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_type ENUM('riders', 'results', 'events', 'clubs', 'uci', 'other') NOT NULL,
    filename VARCHAR(255),
    file_size INT,
    status ENUM('completed', 'failed', 'rolled_back') DEFAULT 'completed',
    total_records INT DEFAULT 0,
    success_count INT DEFAULT 0,
    updated_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    skipped_count INT DEFAULT 0,
    error_summary TEXT,
    imported_by VARCHAR(100),
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    rolled_back_at TIMESTAMP NULL,
    rolled_back_by VARCHAR(100) NULL,
    notes TEXT,
    INDEX idx_type (import_type),
    INDEX idx_status (status),
    INDEX idx_date (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import Records Table
-- Tracks individual records created/updated during each import for rollback capability
CREATE TABLE IF NOT EXISTS import_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT NOT NULL,
    record_type ENUM('rider', 'result', 'event', 'club') NOT NULL,
    record_id INT NOT NULL,
    action ENUM('created', 'updated') NOT NULL,
    old_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (import_id) REFERENCES import_history(id) ON DELETE CASCADE,
    INDEX idx_import (import_id),
    INDEX idx_record (record_type, record_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comment for documentation
ALTER TABLE import_history COMMENT = 'Tracks all data import operations with rollback capability';
ALTER TABLE import_records COMMENT = 'Individual records affected by imports for granular rollback';
