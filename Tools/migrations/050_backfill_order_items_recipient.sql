-- Migration 050: Backfill payment_recipient_id on order_items
-- Many order_items have NULL payment_recipient_id because createMultiRiderOrder()
-- didn't set it. This updates them using the event → payment_recipient chain.

-- Strategy 1: Via orders.event_id → events.payment_recipient_id (direct)
UPDATE order_items oi
JOIN orders o ON oi.order_id = o.id
JOIN events e ON o.event_id = e.id
SET oi.payment_recipient_id = e.payment_recipient_id
WHERE oi.payment_recipient_id IS NULL
AND e.payment_recipient_id IS NOT NULL;

-- Strategy 2: Via orders.event_id → events.series_id → series.payment_recipient_id
UPDATE order_items oi
JOIN orders o ON oi.order_id = o.id
JOIN events e ON o.event_id = e.id
JOIN series s ON e.series_id = s.id
SET oi.payment_recipient_id = s.payment_recipient_id
WHERE oi.payment_recipient_id IS NULL
AND e.payment_recipient_id IS NULL
AND s.payment_recipient_id IS NOT NULL;

-- Strategy 3: Via event_registrations → events (for items linked via registration_id)
UPDATE order_items oi
JOIN event_registrations er ON oi.registration_id = er.id
JOIN events e ON er.event_id = e.id
SET oi.payment_recipient_id = e.payment_recipient_id
WHERE oi.payment_recipient_id IS NULL
AND e.payment_recipient_id IS NOT NULL;

-- Strategy 4: Via event_registrations → events → series
UPDATE order_items oi
JOIN event_registrations er ON oi.registration_id = er.id
JOIN events e ON er.event_id = e.id
JOIN series s ON e.series_id = s.id
SET oi.payment_recipient_id = s.payment_recipient_id
WHERE oi.payment_recipient_id IS NULL
AND e.payment_recipient_id IS NULL
AND s.payment_recipient_id IS NOT NULL;
