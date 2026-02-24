-- Migration 053: GravityTiming API
-- 2026-02-22
--
-- Skapar tabeller och kolumner för GravityTiming tidtagnings-API:
-- 1. api_keys - API-nycklar för extern autentisering
-- 2. api_request_log - Logg för API-anrop (debug/rate limiting)
-- 3. events.timing_live - Flagga för live-tidtagning

-- API-nycklar för extern autentisering
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    api_secret_hash VARCHAR(255) NOT NULL,
    scope ENUM('timing', 'readonly', 'admin') DEFAULT 'timing',
    event_ids TEXT NULL,
    series_ids TEXT NULL,
    created_by INT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_key (api_key),
    INDEX idx_active (active)
);

-- Live-timing flagga på events
ALTER TABLE events ADD COLUMN timing_live TINYINT(1) DEFAULT 0 AFTER active;

-- Logg för API-anrop (debug och rate limiting)
CREATE TABLE IF NOT EXISTS api_request_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    endpoint VARCHAR(200) NOT NULL,
    method VARCHAR(10) NOT NULL,
    event_id INT NULL,
    response_code INT NOT NULL,
    request_body_size INT DEFAULT 0,
    response_time_ms INT DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_key_id (api_key_id),
    INDEX idx_created (created_at)
);
