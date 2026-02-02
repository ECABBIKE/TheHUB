-- Add 'one_timer' to audience_type ENUM in winback_campaigns
-- This allows targeting participants who only competed once in target year

ALTER TABLE winback_campaigns
MODIFY COLUMN audience_type ENUM('churned', 'active', 'one_timer') DEFAULT 'churned';
