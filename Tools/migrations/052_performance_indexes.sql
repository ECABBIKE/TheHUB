-- Performance indexes for commonly queried columns
-- Fas 2 av prestandaoptimering

-- event_registrations: used in signup counts, payment tracking, event pages
CREATE INDEX IF NOT EXISTS idx_er_event_status ON event_registrations(event_id, status);
CREATE INDEX IF NOT EXISTS idx_er_event_payment ON event_registrations(event_id, payment_status);
CREATE INDEX IF NOT EXISTS idx_er_rider ON event_registrations(rider_id);

-- results: used in rider profiles, search with_results filter, series standings
CREATE INDEX IF NOT EXISTS idx_results_cyclist ON results(cyclist_id);
CREATE INDEX IF NOT EXISTS idx_results_event_class ON results(event_id, class_id);

-- orders: used in promotor economics, payment reports
CREATE INDEX IF NOT EXISTS idx_orders_payment_status ON orders(payment_status);
CREATE INDEX IF NOT EXISTS idx_orders_series_payment ON orders(series_id, payment_status);
CREATE INDEX IF NOT EXISTS idx_orders_event_payment ON orders(event_id, payment_status);
CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);

-- events: used in calendar, series listings, search
CREATE INDEX IF NOT EXISTS idx_events_date_active ON events(date, active);
CREATE INDEX IF NOT EXISTS idx_events_series ON events(series_id);

-- riders: used in search (firstname/lastname), login (email)
CREATE INDEX IF NOT EXISTS idx_riders_name ON riders(lastname, firstname);
CREATE INDEX IF NOT EXISTS idx_riders_email ON riders(email);
CREATE INDEX IF NOT EXISTS idx_riders_club ON riders(club_id);
