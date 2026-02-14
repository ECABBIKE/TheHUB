-- ============================================================
-- SCF Match Candidates: Add 'not_found' status + UNIQUE KEY
-- Migration 046
--
-- Fixes:
-- - Adds 'not_found' status so searched-but-not-matched riders
--   are tracked and not re-searched every time
-- - Deduplicates existing entries and adds UNIQUE KEY on rider_id
--   so ON DUPLICATE KEY UPDATE actually works
-- ============================================================

-- Step 1: Add 'not_found' to the status enum
ALTER TABLE scf_match_candidates
    MODIFY COLUMN status ENUM('pending', 'confirmed', 'rejected', 'auto_confirmed', 'not_found') DEFAULT 'pending';

-- Step 2: Remove duplicate entries per rider (keep the one with highest ID = most recent)
DELETE mc1 FROM scf_match_candidates mc1
INNER JOIN scf_match_candidates mc2
    ON mc1.rider_id = mc2.rider_id AND mc1.id < mc2.id;

-- Step 3: Add UNIQUE KEY on rider_id so ON DUPLICATE KEY UPDATE works correctly
ALTER TABLE scf_match_candidates
    ADD UNIQUE KEY uk_rider_id (rider_id);
