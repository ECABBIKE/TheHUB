-- Migration 025: Memberships and Subscriptions for Stripe v2 API
-- Adds tables for managing recurring memberships via Stripe Billing

-- Membership plans (what can be purchased)
CREATE TABLE IF NOT EXISTS membership_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,

    -- Pricing
    price_amount INT NOT NULL COMMENT 'Price in ore (SEK cents)',
    currency VARCHAR(3) DEFAULT 'SEK',
    billing_interval ENUM('day', 'week', 'month', 'year') DEFAULT 'year',
    billing_interval_count INT DEFAULT 1 COMMENT 'e.g., 1 = every month, 3 = every 3 months',

    -- Stripe integration
    stripe_product_id VARCHAR(100) COMMENT 'Stripe Product ID',
    stripe_price_id VARCHAR(100) COMMENT 'Stripe Price ID',

    -- Benefits/features
    benefits JSON COMMENT 'Array of benefit strings',
    discount_percent INT DEFAULT 0 COMMENT 'Discount on event registrations',

    -- Targeting
    club_id INT NULL COMMENT 'If set, only available for this club',
    series_id INT NULL COMMENT 'If set, only for this series',

    -- Status
    active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_active (active),
    INDEX idx_club (club_id),
    INDEX idx_series (series_id),
    INDEX idx_stripe_product (stripe_product_id),
    INDEX idx_stripe_price (stripe_price_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Member subscriptions (active memberships)
CREATE TABLE IF NOT EXISTS member_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Member info (link to rider or standalone)
    rider_id INT NULL COMMENT 'Link to riders table if applicable',
    user_id INT NULL COMMENT 'Link to users table if applicable',
    email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,

    -- Plan reference
    plan_id INT NOT NULL,

    -- Stripe subscription data
    stripe_customer_id VARCHAR(100) NOT NULL,
    stripe_subscription_id VARCHAR(100) NOT NULL,
    stripe_subscription_status VARCHAR(50) NOT NULL COMMENT 'active, past_due, canceled, etc.',

    -- Subscription period
    current_period_start DATETIME,
    current_period_end DATETIME,
    cancel_at_period_end TINYINT(1) DEFAULT 0,
    canceled_at DATETIME NULL,

    -- Trial info
    trial_start DATETIME NULL,
    trial_end DATETIME NULL,

    -- Payment info
    last_payment_at DATETIME NULL,
    last_payment_amount INT NULL,

    -- Metadata
    metadata JSON COMMENT 'Additional subscription data',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (plan_id) REFERENCES membership_plans(id),
    INDEX idx_rider (rider_id),
    INDEX idx_user (user_id),
    INDEX idx_email (email),
    INDEX idx_stripe_customer (stripe_customer_id),
    INDEX idx_stripe_subscription (stripe_subscription_id),
    INDEX idx_status (stripe_subscription_status),
    INDEX idx_period_end (current_period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription invoices (payment history)
CREATE TABLE IF NOT EXISTS subscription_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NOT NULL,

    -- Stripe invoice data
    stripe_invoice_id VARCHAR(100) NOT NULL,
    stripe_invoice_number VARCHAR(100),
    stripe_invoice_pdf VARCHAR(500),
    stripe_hosted_invoice_url VARCHAR(500),

    -- Amount and status
    amount_due INT NOT NULL,
    amount_paid INT NOT NULL,
    currency VARCHAR(3) DEFAULT 'SEK',
    status VARCHAR(50) NOT NULL COMMENT 'draft, open, paid, void, uncollectible',

    -- Dates
    period_start DATETIME,
    period_end DATETIME,
    due_date DATETIME,
    paid_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (subscription_id) REFERENCES member_subscriptions(id),
    INDEX idx_stripe_invoice (stripe_invoice_id),
    INDEX idx_status (status),
    INDEX idx_paid_at (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe customers (link users to Stripe customers)
CREATE TABLE IF NOT EXISTS stripe_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- User reference (one of these should be set)
    rider_id INT NULL,
    user_id INT NULL,
    email VARCHAR(255) NOT NULL,

    -- Stripe data
    stripe_customer_id VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255),
    phone VARCHAR(50),

    -- Default payment method
    default_payment_method_id VARCHAR(100),

    -- Metadata
    metadata JSON,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_rider (rider_id),
    INDEX idx_user (user_id),
    INDEX idx_email (email),
    UNIQUE INDEX idx_stripe_customer (stripe_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample membership plans
INSERT INTO membership_plans (name, description, price_amount, billing_interval, benefits, discount_percent, sort_order) VALUES
('Basmedlemskap', 'Grundlaggande medlemskap med rabatt pa anmalningar', 29900, 'year', '["10% rabatt pa alla anmalningar", "Tillgang till medlemsnytt", "Rostningsmojlighet pa arsmote"]', 10, 1),
('Premium', 'Premium medlemskap med extra formaner', 49900, 'year', '["20% rabatt pa alla anmalningar", "Prioriterad anmalan", "Exklusiva traningslager", "Medlemsprodukter"]', 20, 2),
('Klubbmedlemskap', 'For klubbar och foreningar', 99900, 'year', '["Obegransat antal medlemmar", "Klubb-dashboard", "Resultatstatistik", "Marknadsforingsmaterial"]', 15, 3);
