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
 * 5. Tag each split row with the event's payment_recipient_id (for multi-recipient filtering)
 *
 * @param array $orders Order rows to process
 * @param mixed $db Database instance (supports getAll method)
 * @return array Processed rows with series orders split into per-event rows
 */
function explodeSeriesOrdersToEvents(array $orders, $db): array {
    $result = [];
    $seriesEventsCache = [];

    foreach ($orders as $order) {
        $hasSeriesId = !empty($order['series_id']);

        // If series_id is set, ALWAYS split (even if event_id is also set).
        // Old orders (pre-migration 051) have both event_id AND series_id;
        // event_id pointed to the first event as a bug.
        if (!$hasSeriesId) {
            $result[] = $order;
            continue;
        }

        $seriesId = (int)$order['series_id'];
        $orderId = (int)$order['id'];
        $orderAmount = (float)$order['total_amount'];

        // Get series events (cached) - includes payment_recipient_id per event
        if (!isset($seriesEventsCache[$seriesId])) {
            try {
                $seriesEventsCache[$seriesId] = $db->getAll("
                    SELECT DISTINCT e.id, e.name, e.date, e.payment_recipient_id,
                           e.is_championship, e.championship_surcharge,
                           pt.championship_fee as template_championship_fee
                    FROM (
                        SELECT event_id as eid FROM series_events WHERE series_id = ?
                        UNION
                        SELECT id as eid FROM events WHERE series_id = ?
                    ) combined
                    JOIN events e ON e.id = combined.eid
                    LEFT JOIN pricing_templates pt ON e.pricing_template_id = pt.id
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

        // Get series registrations for this order (series path orders)
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

        // If no series_registrations found, try event-path registrations
        // (series registrations created as individual event_registrations)
        $eventPathRegs = [];
        if (empty($seriesRegs)) {
            try {
                $eventPathRegs = $db->getAll("
                    SELECT oi.unit_price, er.event_id, er.category as class_name
                    FROM order_items oi
                    JOIN event_registrations er ON er.id = oi.registration_id
                    WHERE oi.order_id = ? AND oi.item_type = 'registration'
                    AND er.event_id IN (
                        SELECT event_id FROM series_events WHERE series_id = ?
                        UNION
                        SELECT id FROM events WHERE series_id = ?
                    )
                ", [$orderId, $seriesId, $seriesId]);
            } catch (Exception $e) {}
        }

        if (empty($seriesRegs) && empty($eventPathRegs)) {
            // Final fallback: equal distribution
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
                $eventRow['_event_recipient_id'] = $evt['payment_recipient_id'] ?? null;
                $result[] = $eventRow;
            }
            continue;
        }

        // Calculate per-event shares
        $eventShares = [];
        $totalRegAmount = 0;

        if (!empty($eventPathRegs)) {
            // Event-path: use actual per-event prices from order_items
            $regEventIds = [];
            foreach ($eventPathRegs as $reg) {
                $evId = (int)$reg['event_id'];
                $price = (float)$reg['unit_price'];
                $eventShares[$evId] = ($eventShares[$evId] ?? 0) + $price;
                $totalRegAmount += $price;
                $regEventIds[$evId] = true;
            }

            // For events in the series that DON'T have a registration in this order,
            // distribute proportionally from events that do
            foreach ($seriesEvents as $evt) {
                $evId = (int)$evt['id'];
                if (!isset($regEventIds[$evId])) {
                    $eventShares[$evId] = 0;
                }
            }
        } else {
            // Series-path: use series_registrations pricing rules
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
            } // end foreach ($seriesRegs)
        } // end else (series-path)

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

        // Build event ID → recipient lookup + championship surcharge map
        $eventRecipientMap = [];
        $eventSurchargeMap = [];
        $totalSurcharge = 0;
        foreach ($seriesEvents as $evt) {
            $evId = (int)$evt['id'];
            $eventRecipientMap[$evId] = $evt['payment_recipient_id'] ?? null;
            $surcharge = 0;
            if (!empty($evt['is_championship'])) {
                // Pricing template fee takes priority over event-level surcharge
                if (!empty($evt['template_championship_fee']) && floatval($evt['template_championship_fee']) > 0) {
                    $surcharge = floatval($evt['template_championship_fee']);
                } elseif (!empty($evt['championship_surcharge'])) {
                    $surcharge = floatval($evt['championship_surcharge']);
                }
            }
            $eventSurchargeMap[$evId] = $surcharge;
            $totalSurcharge += $surcharge;
        }

        // If there are SM surcharges, extract them from the order amount before proportional split
        // The surcharge goes undiluted to the SM event's payment recipient
        $amountForProportionalSplit = $orderAmount;
        if ($totalSurcharge > 0) {
            $amountForProportionalSplit = $orderAmount - $totalSurcharge;
            // Re-normalize shares to match the reduced amount
            if ($shareTotal > 0) {
                $scale = $amountForProportionalSplit / ($shareTotal - $totalSurcharge);
                if ($scale > 0 && is_finite($scale)) {
                    foreach ($eventShares as $evId => &$share) {
                        // Subtract the surcharge from SM event shares before scaling
                        $adjShare = $share - $eventSurchargeMap[$evId];
                        $share = round($adjShare * $scale, 2);
                    }
                    unset($share);
                }
                // Re-balance rounding errors
                $newTotal = array_sum($eventShares);
                $diff = round($amountForProportionalSplit - $newTotal, 2);
                if ($diff != 0 && !empty($eventShares)) {
                    $firstKey = array_key_first($eventShares);
                    $eventShares[$firstKey] += $diff;
                }
            }
        }

        // Create per-event rows
        $seriesName = $order['event_name'] ?? ($order['source_name'] ?? '');
        foreach ($seriesEvents as $evt) {
            $evId = (int)$evt['id'];
            $evAmount = $eventShares[$evId] ?? round($amountForProportionalSplit / $eventCount, 2);

            // Add championship surcharge undiluted to SM event
            $evSurcharge = $eventSurchargeMap[$evId] ?? 0;
            $evAmount += $evSurcharge;

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
            $eventRow['_split_event_count'] = $eventCount;
            $eventRow['_event_recipient_id'] = $eventRecipientMap[$evId] ?? null;
            $eventRow['_championship_surcharge'] = $evSurcharge > 0 ? $evSurcharge : null;
            if (isset($order['stripe_fee']) && $order['stripe_fee'] !== null) {
                $eventRow['stripe_fee'] = round((float)$order['stripe_fee'] * $fraction, 2);
            }
            $result[] = $eventRow;
        }
    }

    return $result;
}

/**
 * Get all event IDs that belong to a specific payment recipient.
 * Uses both direct payment_recipient_id AND promotor chain.
 *
 * @param mixed $db Database instance
 * @param int $recipientId Payment recipient ID
 * @return array Event IDs belonging to this recipient
 */
function getRecipientEventIds($db, int $recipientId): array {
    $eventIds = [];

    // Path 1: Direct events.payment_recipient_id
    try {
        $rows = $db->getAll("SELECT id FROM events WHERE payment_recipient_id = ?", [$recipientId]);
        foreach ($rows as $r) $eventIds[] = (int)$r['id'];
    } catch (Exception $e) {}

    // Path 2: Via promotor chain
    try {
        $rows = $db->getAll("
            SELECT pe.event_id as id FROM promotor_events pe
            JOIN payment_recipients pr ON pr.admin_user_id = pe.user_id
            WHERE pr.id = ?
        ", [$recipientId]);
        foreach ($rows as $r) {
            if (!in_array((int)$r['id'], $eventIds)) $eventIds[] = (int)$r['id'];
        }
    } catch (Exception $e) {}

    // Path 3: Events in series owned by this recipient's promotor
    try {
        $rows = $db->getAll("
            SELECT DISTINCT se.event_id as id FROM series_events se
            JOIN promotor_series ps ON ps.series_id = se.series_id
            JOIN payment_recipients pr ON pr.admin_user_id = ps.user_id
            WHERE pr.id = ?
        ", [$recipientId]);
        foreach ($rows as $r) {
            if (!in_array((int)$r['id'], $eventIds)) $eventIds[] = (int)$r['id'];
        }
    } catch (Exception $e) {}

    return $eventIds;
}

/**
 * Filter split rows to only show events belonging to a specific recipient.
 * Non-split rows are kept as-is. Split rows are filtered by _event_recipient_id
 * or by checking if the event_id is in the recipient's event list.
 *
 * @param array $rows Order rows (possibly with split rows)
 * @param int $recipientId Payment recipient ID to filter by
 * @param array $recipientEventIds Pre-computed list of event IDs for this recipient
 * @return array Filtered rows
 */
function filterSplitRowsByRecipient(array $rows, int $recipientId, array $recipientEventIds): array {
    return array_values(array_filter($rows, function($row) use ($recipientId, $recipientEventIds) {
        if (empty($row['is_series_split'])) {
            return true; // Keep non-split rows (already filtered by SQL)
        }

        // Check if split row's event belongs to this recipient
        $eventRecipientId = $row['_event_recipient_id'] ?? null;
        if ($eventRecipientId !== null && (int)$eventRecipientId === $recipientId) {
            return true;
        }

        // Fallback: check if event_id is in recipient's event list
        $eventId = (int)($row['event_id'] ?? 0);
        if ($eventId > 0 && in_array($eventId, $recipientEventIds)) {
            return true;
        }

        return false;
    }));
}
