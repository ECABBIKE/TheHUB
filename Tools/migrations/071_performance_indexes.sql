-- Migration 071: Performance indexes for slow pages
-- Dashboard, Calendar, Event page optimization

-- event_registrations: used by calendar COUNT, dashboard stats, capacity checks
CREATE INDEX IF NOT EXISTS idx_er_event_id ON event_registrations(event_id);
CREATE INDEX IF NOT EXISTS idx_er_created_at ON event_registrations(created_at);

-- photo_rider_tags: used by event gallery LEFT JOIN
CREATE INDEX IF NOT EXISTS idx_prt_photo_id ON photo_rider_tags(photo_id);

-- race_reports: used by dashboard pending count + event page count
CREATE INDEX IF NOT EXISTS idx_race_reports_status ON race_reports(status);
CREATE INDEX IF NOT EXISTS idx_race_reports_event_status ON race_reports(event_id, status);

-- rider_claims: used by dashboard pending count
CREATE INDEX IF NOT EXISTS idx_rider_claims_status ON rider_claims(status);

-- bug_reports: used by dashboard pending count
CREATE INDEX IF NOT EXISTS idx_bug_reports_status ON bug_reports(status);

-- results: event page fetches all results by event_id
CREATE INDEX IF NOT EXISTS idx_results_event_id ON results(event_id);

-- orders: dashboard payment stats
CREATE INDEX IF NOT EXISTS idx_orders_payment_status ON orders(payment_status);
