-- Migration 084: Add response email link fields to winback_campaigns
--
-- The post-survey response email needs configurable links:
-- 1. Info link (e.g. TheHUB event page)
-- 2. Registration link (e.g. external registration platform)

ALTER TABLE winback_campaigns
    ADD COLUMN response_email_info_url VARCHAR(500) DEFAULT NULL AFTER email_body,
    ADD COLUMN response_email_info_text VARCHAR(255) DEFAULT NULL AFTER response_email_info_url,
    ADD COLUMN response_email_reg_url VARCHAR(500) DEFAULT NULL AFTER response_email_info_text,
    ADD COLUMN response_email_reg_text VARCHAR(255) DEFAULT NULL AFTER response_email_reg_url;
