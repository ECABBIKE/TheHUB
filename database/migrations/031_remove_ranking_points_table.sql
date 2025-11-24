-- Migration: Remove ranking_points table
-- Description: Drops the ranking_points table as the lightweight ranking system calculates on-the-fly instead
-- Date: 2025-11-24

-- Drop the ranking_points table (no longer needed with lightweight on-the-fly calculation)
DROP TABLE IF EXISTS ranking_points;
