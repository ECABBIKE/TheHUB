-- Migration 085: Festival system
-- Skapar tabeller för festivaler, festival-event-kopplingar, aktiviteter, pass och sponsorer

-- Huvudtabell: Festivaler
CREATE TABLE IF NOT EXISTS festivals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NULL,
    description TEXT,
    short_description VARCHAR(500),
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    location VARCHAR(255),
    venue_id INT NULL,
    logo_media_id INT NULL,
    header_banner_media_id INT NULL,
    website VARCHAR(255),
    contact_email VARCHAR(255),
    contact_phone VARCHAR(50),
    venue_coordinates VARCHAR(100),
    venue_map_url VARCHAR(255),

    -- Festivalpass
    pass_enabled TINYINT(1) DEFAULT 0,
    pass_name VARCHAR(100) DEFAULT 'Festivalpass',
    pass_description TEXT,
    pass_price DECIMAL(10,2) NULL,
    pass_max_quantity INT NULL,

    -- Ekonomi
    payment_recipient_id INT NULL,

    -- Status
    status ENUM('draft','published','completed','cancelled') DEFAULT 'draft',
    active TINYINT(1) DEFAULT 1,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_slug (slug),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Junction: Festival -> befintliga tävlingsevent
CREATE TABLE IF NOT EXISTS festival_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    event_id INT NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_festival_event (festival_id, event_id),
    INDEX idx_festival (festival_id),
    INDEX idx_event (event_id),
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Festival-aktiviteter (clinics, grouprides, föreläsningar etc.)
CREATE TABLE IF NOT EXISTS festival_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    activity_type ENUM('clinic','lecture','groupride','workshop','social','other') DEFAULT 'other',

    date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,

    location_detail VARCHAR(255),
    instructor_name VARCHAR(255),
    instructor_info TEXT,

    price DECIMAL(10,2) DEFAULT 0.00,
    max_participants INT NULL,
    registration_opens DATETIME NULL,
    registration_deadline DATETIME NULL,
    included_in_pass TINYINT(1) DEFAULT 1,

    sort_order INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_festival (festival_id),
    INDEX idx_date (date),
    INDEX idx_type (activity_type),
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Anmälningar till aktiviteter
CREATE TABLE IF NOT EXISTS festival_activity_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    rider_id INT NULL,
    order_id INT NULL,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),

    status ENUM('pending','confirmed','cancelled') DEFAULT 'pending',
    payment_status ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_activity (activity_id),
    INDEX idx_rider (rider_id),
    INDEX idx_order (order_id),
    FOREIGN KEY (activity_id) REFERENCES festival_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Festivalpass (sålda)
CREATE TABLE IF NOT EXISTS festival_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    rider_id INT NULL,
    order_id INT NULL,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),

    pass_code VARCHAR(20) NULL,
    status ENUM('active','cancelled','used') DEFAULT 'active',
    payment_status ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_pass_code (pass_code),
    INDEX idx_festival (festival_id),
    INDEX idx_rider (rider_id),
    INDEX idx_order (order_id),
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sponsorer per festival
CREATE TABLE IF NOT EXISTS festival_sponsors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    sponsor_id INT NOT NULL,
    placement ENUM('header','content','sidebar','partner') DEFAULT 'content',
    display_order INT DEFAULT 0,
    display_size ENUM('large','small') DEFAULT 'large',

    INDEX idx_festival (festival_id),
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Convenience-kolumn på events (samma mönster som series_id)
ALTER TABLE events ADD COLUMN festival_id INT NULL AFTER series_id;
ALTER TABLE events ADD INDEX idx_festival_id (festival_id);

-- Festival-koppling på orders
ALTER TABLE orders ADD COLUMN festival_id INT NULL AFTER series_id;
ALTER TABLE orders ADD INDEX idx_orders_festival_id (festival_id);
