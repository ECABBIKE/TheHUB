-- Migration 080: Bug report conversation messages
-- Adds a messages table for threaded conversations on bug reports
-- Replaces email-based replies with in-app chat

CREATE TABLE IF NOT EXISTS bug_report_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bug_report_id INT NOT NULL,
    sender_type ENUM('admin', 'user') NOT NULL,
    sender_id INT DEFAULT NULL,
    sender_name VARCHAR(255) DEFAULT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bug_report_id (bug_report_id),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_brm_bug_report FOREIGN KEY (bug_report_id) REFERENCES bug_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add a unique token column to bug_reports for secure public access
ALTER TABLE bug_reports ADD COLUMN view_token VARCHAR(64) DEFAULT NULL AFTER admin_notes;
ALTER TABLE bug_reports ADD INDEX idx_view_token (view_token);

-- Backfill tokens for existing reports that have an email
UPDATE bug_reports SET view_token = MD5(CONCAT(id, '-', created_at, '-', RAND())) WHERE email IS NOT NULL AND view_token IS NULL;
