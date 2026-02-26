<?php
/**
 * Economy view helpers - shared between promotor.php and settlements.php
 */

/**
 * Split series orders into per-event rows with proportional pricing.
 *
 * For each series order (event_id IS NULL, series_id IS NOT NULL):
 * 1. Find series_registrations for the order → class_id, discount_percent, final_price
 * 2. Find all events in the series (series_events + events.series_id fallback)
 * 3. Look up per-event base_price via event_pricing_rules for each class
 * 4. Distribute the order amount proportionally across events
 *
 * @param array $orders Order rows to process
 * @param mixed $db Database instance (supports getAll method)
 * @return array Processed rows with series orders split into per-event rows
 */
function explodeSeriesOrdersToEvents(array $orders, $db): array {
    $result = [];
    $seriesEventsCache = [];

    foreach ($orders as $order) {
        $hasEventId = !empty($order['event_id']);
        $hasSeriesId = !empty($order['series_id']);

        if ($hasEventId || !$hasSeriesId) {
            $result[] = $order;
            continue;
        }

        $seriesId = (int)$order['series_id'];
        $orderId = (int)$order['id'];
        $orderAmount = (float)$order['total_amount'];

        // Get series events (cached)
        if (!isset($seriesEventsCache[$seriesId])) {
            try {
                $seriesEventsCache[$seriesId] = $db->getAll("
                    SELECT DISTINCT e.id, e.name, e.date
                    FROM (
                        SELECT event_id as eid FROM series_events WHERE series_id = ?
                        UNION
                        SELECT id as eid FROM events WHERE series_id = ?
                    ) combined
                    JOIN events e ON e.id = combined.eid
                    ORDER BY e.date ASC
                ", [$seriesId, $seriesId]);
            } catch (Exception $e) {
                $seriesEventsCache[$seriesId] = [];
            }
        }

        $seriesEvents = $seriesEventsCache[$seriesId];
        $eventCount = count($seriesEvents);

        if ($eventCount === 0) {
            $result[] = $order;
            continue;
        }

        // Get series registrations for this order
        $seriesRegs = [];
        try {
            $seriesRegs = $db->getAll("
                SELECT sr.id, sr.class_id, sr.base_price, sr.discount_percent, sr.final_price,
                       sr.rider_id
                FROM series_registrations sr
                JOIN order_items oi ON oi.series_registration_id = sr.id
                WHERE oi.order_id = ? AND oi.item_type = 'series_registration'
            ", [$orderId]);
        } catch (Exception $e) {}

        if (empty($seriesRegs)) {
            // Fallback: equal distribution
            $perEventAmount = round($orderAmount / $eventCount, 2);
            $remainder = round($orderAmount - ($perEventAmount * $eventCount), 2);

            foreach ($seriesEvents as $idx => $evt) {
                $eventRow = $order;
                $eventRow['event_id'] = $evt['id'];
                $eventRow['event_name'] = $evt['name'];
                $eventRow['source_name'] = $evt['name'] ?? ($order['source_name'] ?? '');
                $eventRow['is_series_split'] = true;
                $eventRow['series_name'] = $order['event_name'] ?? ($order['source_name'] ?? '');
                $eventRow['total_amount'] = ($idx === 0) ? $perEventAmount + $remainder : $perEventAmount;
                $eventRow['_split_fraction'] = 1 / $eventCount;
                $result[] = $eventRow;
            }
            continue;
        }

        // For each series registration, get per-event pricing
        $eventShares = [];
        $totalRegAmount = 0;

        foreach ($seriesRegs as $reg) {
            $classId = (int)$reg['class_id'];
            $regFinalPrice = (float)$reg['final_price'];
            $discountPct = (float)$reg['discount_percent'];
            $totalRegAmount += $regFinalPrice;

            $eventPrices = [];
            try {
                $eventPrices = $db->getAll("
                    SELECT epr.event_id, epr.base_price
                    FROM event_pricing_rules epr
                    WHERE epr.class_id = ? AND epr.event_id IN (
                        SELECT e.id FROM (
                            SELECT event_id as eid FROM series_events WHERE series_id = ?
                            UNION
                            SELECT id as eid FROM events WHERE series_id = ?
                        ) combined
                        JOIN events e ON e.id = combined.eid
                    )
                ", [$classId, $seriesId, $seriesId]);
            } catch (Exception $e) {}

            if (!empty($eventPrices)) {
                foreach ($eventPrices as $ep) {
                    $evId = (int)$ep['event_id'];
                    $evBase = (float)$ep['base_price'];
                    $evShare = round($evBase * (1 - $discountPct / 100), 2);
                    $eventShares[$evId] = ($eventShares[$evId] ?? 0) + $evShare;
                }
            } else {
                $perEvent = round($regFinalPrice / $eventCount, 2);
                foreach ($seriesEvents as $evt) {
                    $evId = (int)$evt['id'];
                    $eventShares[$evId] = ($eventShares[$evId] ?? 0) + $perEvent;
                }
            }
        }

        // Normalize to match actual order amount
        $shareTotal = array_sum($eventShares);
        if ($shareTotal > 0 && abs($shareTotal - $orderAmount) > 0.01) {
            $scale = $orderAmount / $shareTotal;
            foreach ($eventShares as &$share) {
                $share = round($share * $scale, 2);
            }
            unset($share);
            $newTotal = array_sum($eventShares);
            $diff = round($orderAmount - $newTotal, 2);
            if ($diff != 0 && !empty($eventShares)) {
                $firstKey = array_key_first($eventShares);
                $eventShares[$firstKey] += $diff;
            }
        }

        // Create per-event rows
        $seriesName = $order['event_name'] ?? ($order['source_name'] ?? '');
        foreach ($seriesEvents as $evt) {
            $evId = (int)$evt['id'];
            $evAmount = $eventShares[$evId] ?? round($orderAmount / $eventCount, 2);
            $fraction = ($orderAmount > 0) ? $evAmount / $orderAmount : (1 / $eventCount);

            $eventRow = $order;
            $eventRow['event_id'] = $evId;
            $eventRow['event_name'] = $evt['name'];
            $eventRow['source_name'] = $evt['name'];
            $eventRow['source_type'] = 'serie_split';
            $eventRow['is_series_split'] = true;
            $eventRow['series_name'] = $seriesName;
            $eventRow['total_amount'] = $evAmount;
            $eventRow['_split_fraction'] = $fraction;
            if (isset($order['stripe_fee']) && $order['stripe_fee'] !== null) {
                $eventRow['stripe_fee'] = round((float)$order['stripe_fee'] * $fraction, 2);
            }
            $result[] = $eventRow;
        }
    }

    return $result;
}
