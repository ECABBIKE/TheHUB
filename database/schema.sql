-- TheHUB Database Schema
-- MySQL/MariaDB database for cycling competition platform
-- Updated: 2025-11-13 (Force deployment sync)

-- Set charset
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================================
-- CLUBS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS clubs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    short_name VARCHAR(50),
    region VARCHAR(100),
    city VARCHAR(100),
    country VARCHAR(100) DEFAULT 'Sverige',
    website VARCHAR(255),
    active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_city (city),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RIDERS TABLE (was CYCLISTS)
-- ============================================================================
CREATE TABLE IF NOT EXISTS riders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    birth_year INT,
    gender ENUM('M', 'F', 'Other') DEFAULT 'M',
    club_id INT,
    license_number VARCHAR(50),
    license_type VARCHAR(50),
    license_category VARCHAR(100),
    discipline VARCHAR(100),
    license_valid_until DATE,
    email VARCHAR(255),
    phone VARCHAR(20),
    city VARCHAR(100),
    active BOOLEAN DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
    INDEX idx_name (lastname, firstname),
    INDEX idx_club (club_id),
    INDEX idx_license (license_number),
    INDEX idx_active (active),
    UNIQUE KEY unique_license (license_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CATEGORIES TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    short_name VARCHAR(20),
    age_min INT,
    age_max INT,
    gender ENUM('M', 'F', 'All') DEFAULT 'All',
    description TEXT,
    active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SERIES TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS series (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(100),
    discipline VARCHAR(100),
    year INT,
    status ENUM('planning', 'active', 'completed', 'cancelled') DEFAULT 'planning',
    start_date DATE,
    end_date DATE,
    description TEXT,
    website VARCHAR(255),
    logo VARCHAR(255),
    organizer VARCHAR(255),
    active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_type (type),
    INDEX idx_discipline (discipline),
    INDEX idx_year (year),
    INDEX idx_active (active),
    INDEX idx_start_date (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- VENUES TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS venues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(100),
    region VARCHAR(100),
    country VARCHAR(100) DEFAULT 'Sverige',
    address TEXT,
    coordinates VARCHAR(100),
    description TEXT,
    website VARCHAR(255),
    active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_city (city),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- EVENTS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    location VARCHAR(255),
    venue_id INT,
    type VARCHAR(100),
    discipline VARCHAR(100),
    series_id INT,
    distance DECIMAL(6,2), -- in kilometers
    elevation_gain INT, -- in meters
    status ENUM('upcoming', 'ongoing', 'completed', 'cancelled') DEFAULT 'upcoming',
    description TEXT,
    organizer VARCHAR(255),
    website VARCHAR(255),
    registration_url VARCHAR(255),
    registration_deadline DATE,
    max_participants INT,
    entry_fee DECIMAL(10,2),
    active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE SET NULL,
    INDEX idx_date (date),
    INDEX idx_status (status),
    INDEX idx_type (type),
    INDEX idx_discipline (discipline),
    INDEX idx_series (series_id),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RESULTS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    cyclist_id INT NOT NULL,
    category_id INT,
    position INT,
    finish_time TIME,
    points INT DEFAULT 0,
    bib_number VARCHAR(20),
    status ENUM('finished', 'dnf', 'dns', 'dq') DEFAULT 'finished',
    time_behind TIME, -- time behind winner
    average_speed DECIMAL(5,2), -- km/h
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (cyclist_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_event (event_id),
    INDEX idx_cyclist (cyclist_id),
    INDEX idx_category (category_id),
    INDEX idx_position (position),
    UNIQUE KEY unique_event_cyclist (event_id, cyclist_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ADMIN USERS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('super_admin', 'admin', 'editor') DEFAULT 'editor',
    active BOOLEAN DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- IMPORT LOGS TABLE (for tracking imports)
-- ============================================================================
CREATE TABLE IF NOT EXISTS import_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_type ENUM('riders', 'results', 'events', 'clubs', 'cyclists') NOT NULL,
    filename VARCHAR(255),
    records_total INT DEFAULT 0,
    records_success INT DEFAULT 0,
    records_failed INT DEFAULT 0,
    errors TEXT,
    imported_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (import_type),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DEFAULT DATA - Categories
-- ============================================================================
INSERT INTO categories (name, short_name, age_min, age_max, gender) VALUES
('Herr Elite', 'HE', 19, 34, 'M'),
('Dam Elite', 'DE', 19, 34, 'F'),
('Herr Junior', 'HJ', 17, 18, 'M'),
('Dam Junior', 'DJ', 17, 18, 'F'),
('Herr Veteran 35-44', 'HV35', 35, 44, 'M'),
('Herr Veteran 45-54', 'HV45', 45, 54, 'M'),
('Herr Veteran 55+', 'HV55', 55, 99, 'M'),
('Dam Veteran 35+', 'DV35', 35, 99, 'F'),
('Herr Motion', 'HM', 19, 99, 'M'),
('Dam Motion', 'DM', 19, 99, 'F');

-- ============================================================================
-- DEFAULT ADMIN USER (username: admin, password: changeme123)
-- ============================================================================
INSERT INTO admin_users (username, password_hash, email, full_name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@thehub.se', 'System Administrator', 'super_admin');

-- ============================================================================
-- VIEWS FOR COMMON QUERIES
-- ============================================================================

-- View for complete results with rider and event info
CREATE OR REPLACE VIEW results_complete AS
SELECT
    r.id,
    r.event_id,
    e.name AS event_name,
    e.date AS event_date,
    e.location,
    r.cyclist_id,
    CONCAT(c.firstname, ' ', c.lastname) AS cyclist_name,
    c.birth_year,
    c.gender,
    cl.name AS club_name,
    cat.name AS category_name,
    r.position,
    r.finish_time,
    r.points,
    r.bib_number,
    r.status,
    r.average_speed
FROM results r
JOIN riders c ON r.cyclist_id = c.id
JOIN events e ON r.event_id = e.id
LEFT JOIN clubs cl ON c.club_id = cl.id
LEFT JOIN categories cat ON r.category_id = cat.id;

-- View for rider statistics
CREATE OR REPLACE VIEW cyclist_stats AS
SELECT
    c.id AS cyclist_id,
    CONCAT(c.firstname, ' ', c.lastname) AS cyclist_name,
    cl.name AS club_name,
    COUNT(r.id) AS total_races,
    COUNT(CASE WHEN r.position = 1 THEN 1 END) AS wins,
    COUNT(CASE WHEN r.position <= 3 THEN 1 END) AS podiums,
    COUNT(CASE WHEN r.position <= 10 THEN 1 END) AS top_10,
    SUM(r.points) AS total_points,
    MIN(r.position) AS best_position
FROM riders c
LEFT JOIN results r ON c.id = r.cyclist_id
LEFT JOIN clubs cl ON c.club_id = cl.id
GROUP BY c.id, cyclist_name, cl.name;
