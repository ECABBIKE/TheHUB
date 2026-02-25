-- Migration 059: Add admin_user_id to payment_recipients
-- Links a payment recipient to a promotor/admin user

ALTER TABLE payment_recipients ADD COLUMN admin_user_id INT NULL AFTER org_number;

ALTER TABLE payment_recipients ADD INDEX idx_admin_user_id (admin_user_id);

ALTER TABLE payment_recipients ADD CONSTRAINT fk_pr_admin_user
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL;
