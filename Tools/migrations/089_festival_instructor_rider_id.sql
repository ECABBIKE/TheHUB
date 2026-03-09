-- Migration 089: Koppla instruktör/guide till rider-profil
-- Gör det möjligt att länka instruktören till en befintlig deltagarprofil

ALTER TABLE festival_activities
    ADD COLUMN instructor_rider_id INT NULL AFTER instructor_info;

ALTER TABLE festival_activities
    ADD CONSTRAINT fk_activity_instructor_rider
    FOREIGN KEY (instructor_rider_id) REFERENCES riders(id)
    ON DELETE SET NULL;

ALTER TABLE festival_activity_groups
    ADD COLUMN instructor_rider_id INT NULL AFTER instructor_info;

ALTER TABLE festival_activity_groups
    ADD CONSTRAINT fk_group_instructor_rider
    FOREIGN KEY (instructor_rider_id) REFERENCES riders(id)
    ON DELETE SET NULL;
