-- Migration 067: Add TikTok URL to photographers
-- Date: 2026-02-27

ALTER TABLE photographers ADD COLUMN tiktok_url VARCHAR(255) DEFAULT NULL AFTER instagram_url;
