-- Migration 083: Backfill orders.series_id from order_items → series_registrations
--
-- Bug: order-manager.php checked item.type === 'series' but series items have type 'event'
-- with is_series_registration=true. So orders.series_id was never set.
-- All series order revenue was attributed to the first event instead of being split.
--
-- This migration backfills series_id for existing orders that have series_registration items
-- but NULL series_id.

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
