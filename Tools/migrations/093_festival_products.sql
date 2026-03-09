-- Migration 093: Festival products (merchandise, food, etc.)
-- Products can be sold separately or included in festival passes
-- Supports sizes (S/M/L/XL or "Ej aktuellt") and configurable VAT rates (6%, 12%, 25%)

CREATE TABLE IF NOT EXISTS festival_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    product_type ENUM('merch', 'food', 'other') NOT NULL DEFAULT 'merch',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 25.00,
    has_sizes TINYINT(1) NOT NULL DEFAULT 0,
    max_quantity INT NULL DEFAULT NULL,
    included_in_pass TINYINT(1) NOT NULL DEFAULT 0,
    pass_included_count INT NOT NULL DEFAULT 0,
    image_url VARCHAR(500) NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE,
    INDEX idx_festival_products_festival (festival_id),
    INDEX idx_festival_products_active (festival_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS festival_product_sizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    size_label VARCHAR(20) NOT NULL,
    stock INT NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (product_id) REFERENCES festival_products(id) ON DELETE CASCADE,
    INDEX idx_product_sizes_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS festival_product_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    size_id INT NULL DEFAULT NULL,
    rider_id INT NULL DEFAULT NULL,
    order_id INT NULL DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 25.00,
    pass_discount TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
    payment_status ENUM('pending', 'paid', 'refunded') NOT NULL DEFAULT 'pending',
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    email VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES festival_products(id) ON DELETE CASCADE,
    INDEX idx_product_orders_product (product_id),
    INDEX idx_product_orders_order (order_id),
    INDEX idx_product_orders_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add product-related column to order_items
ALTER TABLE order_items ADD COLUMN product_order_id INT NULL DEFAULT NULL AFTER festival_pass_id;
