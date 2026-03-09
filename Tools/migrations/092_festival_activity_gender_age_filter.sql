-- Migration 092: Add gender and age filters to festival activities and activity slots
-- Allows restricting activities/slots to specific gender and/or age groups
-- Examples: "Tjejer-clinic", "Kids 8-12", "Herrar 19+"

-- Gender filter on activities (NULL = all, 'M' = men only, 'F' = women only)
ALTER TABLE festival_activities
    ADD COLUMN gender CHAR(1) NULL DEFAULT NULL AFTER max_participants,
    ADD COLUMN min_age INT NULL DEFAULT NULL AFTER gender,
    ADD COLUMN max_age INT NULL DEFAULT NULL AFTER min_age;

-- Gender filter on individual time slots (overrides activity-level if set)
ALTER TABLE festival_activity_slots
    ADD COLUMN gender CHAR(1) NULL DEFAULT NULL AFTER max_participants,
    ADD COLUMN min_age INT NULL DEFAULT NULL AFTER gender,
    ADD COLUMN max_age INT NULL DEFAULT NULL AFTER min_age;
