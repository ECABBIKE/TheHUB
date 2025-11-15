-- Add password and authentication fields to riders table
ALTER TABLE riders
ADD COLUMN password VARCHAR(255) DEFAULT NULL AFTER email,
ADD COLUMN password_reset_token VARCHAR(255) DEFAULT NULL AFTER password,
ADD COLUMN password_reset_expires DATETIME DEFAULT NULL AFTER password_reset_token,
ADD COLUMN last_login DATETIME DEFAULT NULL AFTER password_reset_expires,
ADD INDEX idx_email (email),
ADD INDEX idx_reset_token (password_reset_token);

-- Make email unique for riders who have an account
-- Note: We don't enforce UNIQUE on email itself since not all riders will have accounts
