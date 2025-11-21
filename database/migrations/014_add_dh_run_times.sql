-- Migration 014: Add DH run times to results table
-- Downhill races have two runs, best time wins

ALTER TABLE results
ADD COLUMN run_1_time VARCHAR(20) NULL COMMENT 'DH Run 1 time' AFTER finish_time,
ADD COLUMN run_2_time VARCHAR(20) NULL COMMENT 'DH Run 2 time' AFTER run_1_time;

-- Note: Split times for DH runs can use existing ss1-ss8 columns:
-- Run 1 splits: ss1, ss2, ss3, ss4
-- Run 2 splits: ss5, ss6, ss7, ss8
