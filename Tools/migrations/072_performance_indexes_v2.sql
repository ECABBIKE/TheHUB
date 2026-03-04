-- Migration 072: Additional performance indexes
-- Addresses slow queries in riders.php, results.php, event.php

-- results(cyclist_id) - critical for riders.php aggregation and event.php JOIN
CREATE INDEX IF NOT EXISTS idx_results_cyclist_id ON results(cyclist_id);

-- rider_club_seasons(rider_id, season_year) - eliminates correlated subquery in riders.php
CREATE INDEX IF NOT EXISTS idx_rcs_rider_season ON rider_club_seasons(rider_id, season_year);

-- events(date, active) - calendar and results page filtering
CREATE INDEX IF NOT EXISTS idx_events_date_active ON events(date, active);

-- events(series_id, active) - series event lookups
CREATE INDEX IF NOT EXISTS idx_events_series_active ON events(series_id, active);

-- event_info_links(event_id) - event page info links lookup
CREATE INDEX IF NOT EXISTS idx_eil_event_id ON event_info_links(event_id);

-- event_albums(event_id, is_published) - gallery photo queries
CREATE INDEX IF NOT EXISTS idx_albums_event_published ON event_albums(event_id, is_published);

-- event_photos(album_id) - photo listing within albums
CREATE INDEX IF NOT EXISTS idx_photos_album_id ON event_photos(album_id);

-- series_sponsors(series_id) - sponsor loading
CREATE INDEX IF NOT EXISTS idx_ss_series_id ON series_sponsors(series_id);

-- event_sponsors(event_id) - sponsor loading
CREATE INDEX IF NOT EXISTS idx_es_event_id ON event_sponsors(event_id);
