-- Migration 070: Bug reports / feedback system
-- Allows users to report profile issues, result errors, or general feedback

CREATE TABLE IF NOT EXISTS bug_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT DEFAULT NULL,
    category ENUM('profile', 'results', 'other') NOT NULL DEFAULT 'other',
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    page_url VARCHAR(500) DEFAULT NULL,
    browser_info VARCHAR(500) DEFAULT NULL,
    related_rider_ids VARCHAR(255) DEFAULT NULL,
    related_event_id INT DEFAULT NULL,
    status ENUM('new', 'in_progress', 'resolved', 'wontfix') NOT NULL DEFAULT 'new',
    admin_notes TEXT DEFAULT NULL,
    resolved_by INT DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_rider_id (rider_id),
    INDEX idx_created_at (created_at),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
