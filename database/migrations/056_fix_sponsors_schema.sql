-- Migration 056: Fix sponsors table schema
-- Adds missing columns that the admin interface expects

-- Add contact fields
ALTER TABLE sponsors ADD COLUMN IF NOT EXISTS contact_name VARCHAR(255) NULL AFTER description;
ALTER TABLE sponsors ADD COLUMN IF NOT EXISTS contact_email VARCHAR(255) NULL AFTER contact_name;
ALTER TABLE sponsors ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(50) NULL AFTER contact_email;

-- Add display order for sorting
ALTER TABLE sponsors ADD COLUMN IF NOT EXISTS display_order INT DEFAULT 0 AFTER contact_phone;

-- Add logo_media_id for media library integration (optional, keeps logo field as fallback)
ALTER TABLE sponsors ADD COLUMN IF NOT EXISTS logo_media_id INT NULL AFTER logo_dark;

-- Add foreign key if media table exists
-- ALTER TABLE sponsors ADD CONSTRAINT fk_sponsor_logo_media FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL;

-- Add index for sorting
CREATE INDEX IF NOT EXISTS idx_display_order ON sponsors(display_order);
