-- Migration 049: Add actual Stripe fee storage to orders
-- Stores the real fee amount from Stripe's balance_transaction
-- instead of relying on estimated calculations

ALTER TABLE orders
ADD COLUMN stripe_fee DECIMAL(10,2) NULL DEFAULT NULL
COMMENT 'Actual Stripe fee in SEK from balance_transaction';

ALTER TABLE orders
ADD COLUMN stripe_balance_transaction_id VARCHAR(100) NULL DEFAULT NULL
COMMENT 'Stripe balance_transaction ID for fee lookup';
