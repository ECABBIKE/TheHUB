-- Add class_id and split times to results table
-- Split times are important for Enduro/DH races

ALTER TABLE results
ADD COLUMN class_id INT NULL AFTER category_id,
ADD COLUMN ss1 TIME NULL COMMENT 'Split time 1',
ADD COLUMN ss2 TIME NULL COMMENT 'Split time 2',
ADD COLUMN ss3 TIME NULL COMMENT 'Split time 3',
ADD COLUMN ss4 TIME NULL COMMENT 'Split time 4',
ADD COLUMN ss5 TIME NULL COMMENT 'Split time 5',
ADD COLUMN ss6 TIME NULL COMMENT 'Split time 6',
ADD COLUMN ss7 TIME NULL COMMENT 'Split time 7',
ADD COLUMN ss8 TIME NULL COMMENT 'Split time 8',
ADD COLUMN ss9 TIME NULL COMMENT 'Split time 9',
ADD COLUMN ss10 TIME NULL COMMENT 'Split time 10',
ADD COLUMN ss11 TIME NULL COMMENT 'Split time 11',
ADD COLUMN ss12 TIME NULL COMMENT 'Split time 12',
ADD COLUMN ss13 TIME NULL COMMENT 'Split time 13',
ADD COLUMN ss14 TIME NULL COMMENT 'Split time 14',
ADD COLUMN ss15 TIME NULL COMMENT 'Split time 15',
ADD FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
ADD INDEX idx_class (class_id);
