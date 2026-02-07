-- Add platform fee percent per payment recipient
-- Allows different platform fees for different recipients

ALTER TABLE payment_recipients
ADD COLUMN platform_fee_percent DECIMAL(5,2) DEFAULT 2.00 COMMENT 'Platform fee percentage (e.g., 2.00 = 2%)';

-- Update existing recipients to default 2%
UPDATE payment_recipients
SET platform_fee_percent = 2.00
WHERE platform_fee_percent IS NULL;
