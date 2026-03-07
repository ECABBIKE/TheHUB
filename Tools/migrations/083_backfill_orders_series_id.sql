-- Migration 083: Backfill orders.series_id for series orders
--
-- Bug: order-manager.php checked item.type === 'series' but series items have type 'event'
-- with is_series_registration=true. So orders.series_id was never set.
-- All series order revenue was attributed to the first event instead of being split.
--
-- Serie-registrations go through the EVENT path in createMultiRiderOrder(), so they create:
--   - Multiple event_registrations (one per event in the series)
--   - order_items with item_type='registration' (NOT 'series_registration')
--   - NO series_registrations records
--
-- Strategy: Find orders that have registrations for 2+ events in the same series.
-- These are series orders that need series_id set.

-- Path 1: Via order_items → event_registrations → series_events (current series membership)
UPDATE orders o
SET o.series_id = (
    SELECT se.series_id
    FROM order_items oi
    JOIN event_registrations er ON er.id = oi.registration_id
    JOIN series_events se ON se.event_id = er.event_id
    WHERE oi.order_id = o.id AND oi.item_type = 'registration'
    GROUP BY se.series_id
    HAVING COUNT(DISTINCT er.event_id) >= 2
    LIMIT 1
)
WHERE o.series_id IS NULL
  AND EXISTS (
    SELECT 1
    FROM order_items oi2
    JOIN event_registrations er2 ON er2.id = oi2.registration_id
    JOIN series_events se2 ON se2.event_id = er2.event_id
    WHERE oi2.order_id = o.id AND oi2.item_type = 'registration'
    GROUP BY se2.series_id
    HAVING COUNT(DISTINCT er2.event_id) >= 2
  );

-- Path 2: Via order_items → series_registrations (if any orders went through the series path)
UPDATE orders o
SET o.series_id = (
    SELECT sr.series_id
    FROM order_items oi
    JOIN series_registrations sr ON sr.id = oi.series_registration_id
    WHERE oi.order_id = o.id
      AND oi.item_type = 'series_registration'
    LIMIT 1
)
WHERE o.series_id IS NULL
  AND EXISTS (
    SELECT 1 FROM order_items oi2
    JOIN series_registrations sr2 ON sr2.id = oi2.series_registration_id
    WHERE oi2.order_id = o.id AND oi2.item_type = 'series_registration'
  );
