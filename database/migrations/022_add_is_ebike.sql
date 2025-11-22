-- Migration 022: Add is_ebike column to results
-- E-Bike riders are flagged but don't receive points

ALTER TABLE results ADD COLUMN is_ebike TINYINT(1) DEFAULT 0 AFTER status;

-- Add index for filtering
ALTER TABLE results ADD INDEX idx_is_ebike (is_ebike);
