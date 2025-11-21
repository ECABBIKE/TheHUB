-- Migration 016: Change finish_time from TIME to VARCHAR to preserve decimals
-- TIME type truncates milliseconds/hundredths on display

ALTER TABLE results
MODIFY COLUMN finish_time VARCHAR(20) NULL COMMENT 'Finish time with decimals support';

-- Also update time_behind to VARCHAR for consistency
ALTER TABLE results
MODIFY COLUMN time_behind VARCHAR(20) NULL COMMENT 'Time behind winner';
