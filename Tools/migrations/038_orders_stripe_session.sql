-- Add stripe_session_id column to orders table for Stripe Checkout tracking
ALTER TABLE orders ADD COLUMN IF NOT EXISTS stripe_session_id VARCHAR(255) NULL DEFAULT NULL AFTER stripe_payment_intent_id;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS stripe_payment_intent_id VARCHAR(255) NULL DEFAULT NULL AFTER payment_reference;
