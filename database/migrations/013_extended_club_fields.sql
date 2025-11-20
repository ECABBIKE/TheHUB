-- Migration 013: Extended Club Fields
-- Adds contact information, logo, and additional details for clubs

-- Logo
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS logo VARCHAR(500) AFTER website;

-- Contact Information
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS email VARCHAR(255) AFTER logo;
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS phone VARCHAR(50) AFTER email;
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS contact_person VARCHAR(255) AFTER phone;

-- Address Details
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS address VARCHAR(255) AFTER contact_person;
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS postal_code VARCHAR(20) AFTER address;

-- Description
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS description TEXT AFTER postal_code;

-- Social Media
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS facebook VARCHAR(255) AFTER description;
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS instagram VARCHAR(255) AFTER facebook;

-- Organization Number (for Swedish clubs)
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS org_number VARCHAR(20) AFTER instagram;

-- SCF Club ID (Swedish Cycling Federation)
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS scf_id VARCHAR(50) AFTER org_number;

-- Add indexes for common lookups
CREATE INDEX IF NOT EXISTS idx_clubs_email ON clubs(email);
CREATE INDEX IF NOT EXISTS idx_clubs_scf_id ON clubs(scf_id);

-- Summary
SELECT 'Migration 013 completed - Extended club fields added' as status;
