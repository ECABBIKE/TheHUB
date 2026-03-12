-- Migration 097: Championship surcharge on events
-- Adds a flat surcharge amount for SM/championship events
-- This amount is added to ALL price periods (early bird, normal, late fee)
-- and is NEVER discounted in series registrations.
-- At settlement, the surcharge always goes to the SM event's payment recipient.

ALTER TABLE events
    ADD COLUMN championship_surcharge DECIMAL(10,2) NULL DEFAULT NULL
    AFTER is_championship;
