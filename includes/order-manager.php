<?php
/**
 * TheHUB Order Manager - Multi-Rider Support
 *
 * Hanterar ordrar med flera deltagare i samma betalning.
 * En förälder kan anmäla flera barn, en klubb kan anmäla ett helt lag.
 *
 * @since 2026-01-12
 */

require_once __DIR__ . '/../hub-config.php';
require_once __DIR__ . '/payment.php';

/**
 * Cachad kolumnkontroll - undviker SHOW COLUMNS per anrop
 * Returnerar true om en kolumn finns i angiven tabell
 */
function _hub_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = "{$table}.{$column}";
    if (!isset($cache[$key])) {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
            $stmt->execute([$table, $column]);
            $cache[$key] = ($stmt->fetch() !== false);
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/**
 * Cachad tabell-kolumnlista - undviker SHOW COLUMNS FROM riders per anrop
 * Returnerar array med kolumnnamn för angiven tabell
 */
function _hub_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
            $stmt->execute([$table]);
            $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            $cache[$table] = [];
        }
    }
    return $cache[$table];
}

/**
 * Generera unik order-referens (ex: "A5F2J0112")
 */
function generateOrderReference(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $ref = '';
    for ($i = 0; $i < 5; $i++) {
        $ref .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $ref .= date('md'); // Lägg till månad och dag
    return $ref;
}

/**
 * Cancel expired pending orders and their registrations.
 * Called automatically when creating new orders and loading checkout.
 */
function cleanupExpiredOrders(): void {
    try {
        $pdo = hub_db();
        // Find pending orders past their expires_at
        $stmt = $pdo->query("
            SELECT id FROM orders
            WHERE payment_status = 'pending'
            AND expires_at IS NOT NULL
            AND expires_at < NOW()
            LIMIT 50
        ");
        $expiredIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expiredIds as $expiredId) {
            $pdo->prepare("UPDATE event_registrations SET status = 'cancelled', payment_status = 'cancelled' WHERE order_id = ? AND status = 'pending'")->execute([$expiredId]);
            $pdo->prepare("UPDATE orders SET payment_status = 'expired', cancelled_at = NOW() WHERE id = ? AND payment_status = 'pending'")->execute([$expiredId]);
        }
    } catch (\Throwable $e) {
        error_log("cleanupExpiredOrders error: " . $e->getMessage());
    }
}

/**
 * Skapa en multi-rider order
 *
 * @param array $buyerData Köparens info: name, email, phone, user_id
 * @param array $items Lista av registreringar: [{type, rider_id, event_id/series_id, class_id}, ...]
 * @param string|null $discountCode Rabattkod (valfritt)
 * @return array ['success' => bool, 'order' => array|null, 'error' => string|null]
 */
function createMultiRiderOrder(array $buyerData, array $items, ?string $discountCode = null): array {
    $pdo = hub_db();

    // Auto-cleanup expired pending orders
    cleanupExpiredOrders();

    if (empty($items)) {
        return ['success' => false, 'error' => 'Inga deltagare valda'];
    }

    $pdo->beginTransaction();

    try {
        // Generera unik referens
        $orderReference = generateOrderReference();

        // Kolla att referensen är unik
        $checkStmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = ?");
        $checkStmt->execute([$orderReference]);
        while ($checkStmt->fetch()) {
            $orderReference = generateOrderReference();
            $checkStmt->execute([$orderReference]);
        }

        // Hämta första event_id och series_id
        $firstEventId = null;
        $firstSeriesId = null;
        foreach ($items as $item) {
            if (($item['type'] ?? 'event') === 'event' && !empty($item['event_id'])) {
                if ($firstEventId === null) $firstEventId = intval($item['event_id']);
            }
            if (($item['type'] ?? 'event') === 'series' && !empty($item['series_id'])) {
                if ($firstSeriesId === null) $firstSeriesId = intval($item['series_id']);
            }
        }

        // Skapa order
        // Validera rider_id - måste vara ett giltigt ID från riders-tabellen
        $validRiderId = null;
        if (!empty($buyerData['user_id'])) {
            $riderCheckStmt = $pdo->prepare("SELECT id FROM riders WHERE id = ? LIMIT 1");
            $riderCheckStmt->execute([$buyerData['user_id']]);
            if ($riderCheckStmt->fetch()) {
                $validRiderId = $buyerData['user_id'];
            }
        }

        // Cancel any previous pending orders for this user (prevents duplicate registrations)
        if (!empty($buyerData['email'])) {
            $oldOrdersStmt = $pdo->prepare("
                SELECT id FROM orders
                WHERE customer_email = ? AND payment_status = 'pending'
            ");
            $oldOrdersStmt->execute([$buyerData['email']]);
            $oldOrders = $oldOrdersStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($oldOrders as $oldId) {
                $pdo->prepare("UPDATE event_registrations SET status = 'cancelled', payment_status = 'cancelled' WHERE order_id = ? AND status = 'pending'")->execute([$oldId]);
                $pdo->prepare("UPDATE orders SET payment_status = 'cancelled', cancelled_at = NOW() WHERE id = ?")->execute([$oldId]);
            }
        }

        // Check if series_id column exists (migration 051)
        $hasSeriesIdCol = _hub_column_exists($pdo, 'orders', 'series_id');

        if ($hasSeriesIdCol) {
            $orderStmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, rider_id, customer_email, customer_name,
                    event_id, series_id, subtotal, discount, total_amount, currency,
                    payment_method, payment_status,
                    expires_at, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, 0, 0, 0, 'SEK',
                    'card', 'pending',
                    DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW()
                )
            ");
            $orderStmt->execute([
                $orderReference,
                $validRiderId,
                $buyerData['email'],
                $buyerData['name'],
                $firstEventId,
                $firstSeriesId
            ]);
        } else {
            $orderStmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, rider_id, customer_email, customer_name,
                    event_id, subtotal, discount, total_amount, currency,
                    payment_method, payment_status,
                    expires_at, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, 0, 0, 0, 'SEK',
                    'card', 'pending',
                    DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW()
                )
            ");
            $orderStmt->execute([
                $orderReference,
                $validRiderId,
                $buyerData['email'],
                $buyerData['name'],
                $firstEventId
            ]);
        }

        $orderId = $pdo->lastInsertId();

        $subtotal = 0;
        $registrations = [];
        $orderItems = [];

        // Processa varje item
        foreach ($items as $item) {
            $itemType = $item['type'] ?? 'event';
            $riderId = intval($item['rider_id']);
            $classId = intval($item['class_id']);

            // Hämta rider-info (inkl fält för profilvalidering)
            $riderCols = ['firstname', 'lastname', 'email', 'birth_year', 'gender', 'club_id'];
            $riderDbCols = _hub_table_columns($pdo, 'riders');
            foreach (['phone', 'ice_name', 'ice_phone'] as $optCol) {
                if (in_array($optCol, $riderDbCols)) {
                    $riderCols[] = $optCol;
                }
            }
            $riderStmt = $pdo->prepare("SELECT " . implode(', ', $riderCols) . " FROM riders WHERE id = ?");
            $riderStmt->execute([$riderId]);
            $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);

            if (!$rider) {
                throw new Exception("Rider med ID {$riderId} hittades inte");
            }

            // Validera att profilen är komplett innan anmälan skapas
            $orderMissing = [];
            if (empty($rider['gender'])) $orderMissing[] = 'kön';
            if (empty($rider['birth_year'])) $orderMissing[] = 'födelseår';
            if (empty($rider['email'])) $orderMissing[] = 'e-post';
            if (in_array('phone', $riderCols) && empty($rider['phone'])) $orderMissing[] = 'telefonnummer';
            if (in_array('ice_name', $riderCols) && empty($rider['ice_name'])) $orderMissing[] = 'nödkontakt (namn)';
            if (in_array('ice_phone', $riderCols) && empty($rider['ice_phone'])) $orderMissing[] = 'nödkontakt (telefon)';
            if (!empty($orderMissing)) {
                $riderName = trim($rider['firstname'] . ' ' . $rider['lastname']);
                throw new Exception("{$riderName} saknar obligatoriska uppgifter: " . implode(', ', $orderMissing) . ". Uppdatera profilen först.");
            }

            // Hämta klubbnamn
            $clubName = '';
            if ($rider['club_id']) {
                $clubStmt = $pdo->prepare("SELECT name FROM clubs WHERE id = ?");
                $clubStmt->execute([$rider['club_id']]);
                $clubName = $clubStmt->fetchColumn() ?: '';
            }

            // Hämta klassnamn
            $classStmt = $pdo->prepare("SELECT name, display_name FROM classes WHERE id = ?");
            $classStmt->execute([$classId]);
            $classRow = $classStmt->fetch(PDO::FETCH_ASSOC);
            $className = $classRow['display_name'] ?: $classRow['name'];

            if ($itemType === 'event') {
                $eventId = intval($item['event_id']);

                // Kolla om redan anmäld - blockera bara om det finns en BETALD registrering
                $checkRegStmt = $pdo->prepare("
                    SELECT id, status, payment_status FROM event_registrations
                    WHERE event_id = ? AND rider_id = ? AND status != 'cancelled'
                ");
                $checkRegStmt->execute([$eventId, $riderId]);
                $existingReg = $checkRegStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingReg) {
                    // Om betalad/bekräftad - blockera dubbelanmälan
                    if ($existingReg['payment_status'] === 'paid' || $existingReg['status'] === 'confirmed') {
                        throw new Exception("{$rider['firstname']} {$rider['lastname']} är redan anmäld till detta event");
                    }

                    // Obetald/pending - avbryt den gamla och ersätt med ny
                    $cancelStmt = $pdo->prepare("
                        UPDATE event_registrations SET status = 'cancelled', payment_status = 'cancelled'
                        WHERE event_id = ? AND rider_id = ? AND status = 'pending' AND payment_status != 'paid'
                    ");
                    $cancelStmt->execute([$eventId, $riderId]);
                }

                // Kontrollera max antal deltagare
                $capStmt = $pdo->prepare("SELECT max_participants FROM events WHERE id = ?");
                $capStmt->execute([$eventId]);
                $maxParticipants = $capStmt->fetchColumn();

                if ($maxParticipants && $maxParticipants > 0) {
                    $countStmt = $pdo->prepare("
                        SELECT COUNT(*) FROM event_registrations
                        WHERE event_id = ? AND status NOT IN ('cancelled')
                    ");
                    $countStmt->execute([$eventId]);
                    $currentCount = $countStmt->fetchColumn();

                    if ($currentCount >= $maxParticipants) {
                        throw new Exception("Eventet är fullbokat ({$maxParticipants} platser). Inga fler anmälningar kan göras.");
                    }
                }

                // Hämta event-info och pris (använd pricing template system)
                $classesData = getEligibleClassesForEvent($eventId, $riderId);
                $selectedClass = null;
                foreach ($classesData as $cls) {
                    if ($cls['class_id'] == $classId) {
                        $selectedClass = $cls;
                        break;
                    }
                }

                if (!$selectedClass) {
                    throw new Exception("Klassen är inte tillgänglig för denna deltagare");
                }

                // Get event info
                $eventStmt = $pdo->prepare("SELECT name as event_name, date as event_date FROM events WHERE id = ?");
                $eventStmt->execute([$eventId]);
                $eventInfo = $eventStmt->fetch(PDO::FETCH_ASSOC);

                if (!$eventInfo) {
                    throw new Exception("Event med ID {$eventId} hittades inte");
                }

                // Use prices from eligible classes (already calculated with early bird/late fee)
                $finalPrice = $selectedClass['current_price'] ?? $selectedClass['base_price'];
                $basePrice = $selectedClass['base_price'];
                $earlyBirdDiscount = 0;
                $lateFee = 0;

                // Dummy logic to keep old code working
                $now = time();
                if (false) { // Disabled - now handled by getEligibleClassesForEvent
                    if ($eventInfo['early_bird_price']) {
                        $earlyBirdDiscount = $basePrice - floatval($eventInfo['early_bird_price']);
                        $finalPrice = floatval($eventInfo['early_bird_price']);
                    }
                } elseif (!empty($eventInfo['late_fee_start']) && $now >= strtotime($eventInfo['late_fee_start'])) {
                    if ($eventInfo['late_fee']) {
                        $lateFee = floatval($eventInfo['late_fee']);
                        $finalPrice = $basePrice + $lateFee;
                    }
                }

                // Skapa event_registration
                $regStmt = $pdo->prepare("
                    INSERT INTO event_registrations (
                        event_id, rider_id, first_name, last_name, email,
                        birth_year, gender, club_name, category,
                        status, payment_status, registration_date, order_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid', NOW(), ?)
                ");
                $regStmt->execute([
                    $eventId,
                    $riderId,
                    $rider['firstname'],
                    $rider['lastname'],
                    $rider['email'],
                    $rider['birth_year'],
                    $rider['gender'],
                    $clubName,
                    $className,
                    $orderId
                ]);

                $registrationId = $pdo->lastInsertId();

                // Look up payment recipient for this event
                $eventRecipientId = null;
                try {
                    $recipientStmt = $pdo->prepare("
                        SELECT COALESCE(e.payment_recipient_id, s.payment_recipient_id) as recipient_id
                        FROM events e
                        LEFT JOIN series s ON e.series_id = s.id
                        WHERE e.id = ?
                    ");
                    $recipientStmt->execute([$eventId]);
                    $recipientRow = $recipientStmt->fetch(PDO::FETCH_ASSOC);
                    $eventRecipientId = $recipientRow['recipient_id'] ?? null;
                } catch (\Throwable $e) {}

                // Skapa order_item
                $description = "{$rider['firstname']} {$rider['lastname']} - {$eventInfo['event_name']} - {$className}";
                $itemStmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, item_type, registration_id,
                        description, unit_price, quantity, total_price,
                        payment_recipient_id
                    ) VALUES (?, 'registration', ?, ?, ?, 1, ?, ?)
                ");
                $itemStmt->execute([$orderId, $registrationId, $description, $finalPrice, $finalPrice, $eventRecipientId]);

                $subtotal += $finalPrice;

                $registrations[] = [
                    'type' => 'event',
                    'registration_id' => $registrationId,
                    'rider_id' => $riderId,
                    'event_id' => $eventId,
                    'rider_name' => "{$rider['firstname']} {$rider['lastname']}",
                    'event_name' => $eventInfo['event_name'],
                    'class_name' => $className,
                    'price' => $finalPrice
                ];

            } elseif ($itemType === 'series') {
                $seriesId = intval($item['series_id']);

                // Kolla om redan anmäld till serien - blockera bara betalda
                $checkSeriesStmt = $pdo->prepare("
                    SELECT id, status, payment_status FROM series_registrations
                    WHERE series_id = ? AND rider_id = ? AND status != 'cancelled'
                ");
                $checkSeriesStmt->execute([$seriesId, $riderId]);
                $existingSeriesReg = $checkSeriesStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingSeriesReg) {
                    if ($existingSeriesReg['payment_status'] === 'paid' || $existingSeriesReg['status'] === 'confirmed') {
                        throw new Exception("{$rider['firstname']} {$rider['lastname']} har redan ett serie-pass för denna serie");
                    }

                    // Obetald/pending - avbryt den gamla
                    try {
                        $cancelSeriesStmt = $pdo->prepare("
                            UPDATE series_registrations SET status = 'cancelled'
                            WHERE series_id = ? AND rider_id = ? AND status = 'pending' AND payment_status != 'paid'
                        ");
                        $cancelSeriesStmt->execute([$seriesId, $riderId]);
                    } catch (\Throwable $e) {
                        // series_registrations table may not exist
                    }
                }

                // Hämta serie-info
                $seriesStmt = $pdo->prepare("
                    SELECT s.name as series_name, s.series_discount_percent
                    FROM series s
                    WHERE s.id = ?
                ");
                $seriesStmt->execute([$seriesId]);
                $seriesInfo = $seriesStmt->fetch(PDO::FETCH_ASSOC);

                if (!$seriesInfo) {
                    throw new Exception("Serie med ID {$seriesId} hittades inte");
                }

                // Beräkna seriepris (summa av alla event-priser minus rabatt)
                $seriesPriceStmt = $pdo->prepare("
                    SELECT SUM(COALESCE(epr.base_price, 0)) as total_price
                    FROM events e
                    LEFT JOIN event_pricing_rules epr ON epr.event_id = e.id AND epr.class_id = ?
                    WHERE e.series_id = ?
                ");
                $seriesPriceStmt->execute([$classId, $seriesId]);
                $priceRow = $seriesPriceStmt->fetch(PDO::FETCH_ASSOC);
                $basePrice = floatval($priceRow['total_price'] ?? 0);

                $discountPercent = floatval($seriesInfo['series_discount_percent'] ?? 15);
                $discountAmount = round($basePrice * ($discountPercent / 100), 2);
                $finalPrice = $basePrice - $discountAmount;

                // Skapa series_registration
                $seriesRegStmt = $pdo->prepare("
                    INSERT INTO series_registrations (
                        rider_id, series_id, class_id,
                        base_price, discount_percent, discount_amount, final_price,
                        order_id, payment_status, status, registration_source, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'active', 'web', NOW())
                ");
                $seriesRegStmt->execute([
                    $riderId,
                    $seriesId,
                    $classId,
                    $basePrice,
                    $discountPercent,
                    $discountAmount,
                    $finalPrice,
                    $orderId
                ]);

                $seriesRegId = $pdo->lastInsertId();

                // Skapa event-kopplingar för alla event i serien
                $eventsStmt = $pdo->prepare("SELECT id FROM events WHERE series_id = ?");
                $eventsStmt->execute([$seriesId]);
                while ($eventRow = $eventsStmt->fetch(PDO::FETCH_ASSOC)) {
                    $linkStmt = $pdo->prepare("
                        INSERT INTO series_registration_events (series_registration_id, event_id, status)
                        VALUES (?, ?, 'registered')
                    ");
                    $linkStmt->execute([$seriesRegId, $eventRow['id']]);
                }

                // Look up payment recipient for this series
                $seriesRecipientId = null;
                try {
                    $recipientStmt = $pdo->prepare("SELECT payment_recipient_id FROM series WHERE id = ?");
                    $recipientStmt->execute([$seriesId]);
                    $seriesRecipientId = $recipientStmt->fetchColumn() ?: null;
                } catch (\Throwable $e) {}

                // Skapa order_item
                $description = "{$rider['firstname']} {$rider['lastname']} - {$seriesInfo['series_name']} (Serie-pass) - {$className}";
                $itemStmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, item_type, series_registration_id,
                        description, unit_price, quantity, total_price,
                        payment_recipient_id
                    ) VALUES (?, 'series_registration', ?, ?, ?, 1, ?, ?)
                ");
                $itemStmt->execute([$orderId, $seriesRegId, $description, $finalPrice, $finalPrice, $seriesRecipientId]);

                $subtotal += $finalPrice;

                $registrations[] = [
                    'type' => 'series',
                    'registration_id' => $seriesRegId,
                    'rider_name' => "{$rider['firstname']} {$rider['lastname']}",
                    'series_name' => $seriesInfo['series_name'],
                    'class_name' => $className,
                    'price' => $finalPrice,
                    'discount' => $discountAmount
                ];
            }
        }

        // Beräkna totalt discount
        $totalDiscount = 0;

        // Series discount: group by rider+class+series, compare regular total vs season_price
        $seriesGroups = [];
        foreach ($items as $item) {
            if (!empty($item['is_series_registration']) && !empty($item['season_price']) && floatval($item['season_price']) > 0) {
                $key = intval($item['rider_id']) . '_' . intval($item['class_id']) . '_' . intval($item['series_id'] ?? 0);
                if (!isset($seriesGroups[$key])) {
                    $seriesGroups[$key] = ['season_price' => floatval($item['season_price']), 'regular_total' => 0];
                }
                // Sum up actual backend prices for these items
                foreach ($registrations as $reg) {
                    if ($reg['type'] === 'event'
                        && intval($reg['rider_id']) == intval($item['rider_id'])
                        && intval($reg['event_id']) == intval($item['event_id'])) {
                        $seriesGroups[$key]['regular_total'] += floatval($reg['price']);
                        break;
                    }
                }
            }
        }
        foreach ($seriesGroups as $group) {
            if ($group['regular_total'] > $group['season_price']) {
                $totalDiscount += round($group['regular_total'] - $group['season_price']);
            }
        }

        // Gravity ID discount for each registration
        if (function_exists('checkGravityIdDiscount')) {
            foreach ($registrations as $reg) {
                if ($reg['type'] === 'event' && !empty($reg['event_id']) && !empty($reg['rider_id'])) {
                    $gravityIdInfo = checkGravityIdDiscount($reg['rider_id'], $reg['event_id']);
                    if ($gravityIdInfo && $gravityIdInfo['has_gravity_id'] && $gravityIdInfo['discount'] > 0) {
                        $totalDiscount += floatval($gravityIdInfo['discount']);
                    }
                }
            }
        }

        $totalAmount = $subtotal - $totalDiscount;

        // Uppdatera order med summor
        $updateStmt = $pdo->prepare("
            UPDATE orders SET
                subtotal = ?,
                discount = ?,
                total_amount = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$subtotal, $totalDiscount, $totalAmount, $orderId]);

        $pdo->commit();

        return [
            'success' => true,
            'order' => [
                'id' => $orderId,
                'order_reference' => $orderReference,
                'subtotal' => $subtotal,
                'discount' => $totalDiscount,
                'total_amount' => $totalAmount,
                'registrations' => $registrations,
                'checkout_url' => "/checkout?order={$orderId}"
            ]
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Hämta riders som användaren kan anmäla
 * Returnerar: sig själv + sina barn (family members)
 */
function getRegistrableRiders(int $userId): array {
    $pdo = hub_db();

    $riders = [];

    // Hämta användarens egen rider-profil
    $selfStmt = $pdo->prepare("
        SELECT r.id, r.firstname, r.lastname, r.birth_year, r.gender,
               r.license_number, r.license_type, c.name as club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.id = ?
    ");
    $selfStmt->execute([$userId]);
    $self = $selfStmt->fetch(PDO::FETCH_ASSOC);

    if ($self) {
        $self['relation'] = 'self';
        $riders[] = $self;
    }

    // Hämta familjemedlemmar (barn)
    try {
        $familyStmt = $pdo->prepare("
            SELECT r.id, r.firstname, r.lastname, r.birth_year, r.gender,
                   r.license_number, r.license_type, c.name as club_name
            FROM family_members fm
            JOIN riders r ON fm.child_rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE fm.parent_rider_id = ?
        ");
        $familyStmt->execute([$userId]);
        while ($family = $familyStmt->fetch(PDO::FETCH_ASSOC)) {
            $family['relation'] = 'child';
            $riders[] = $family;
        }
    } catch (PDOException $e) {
        // family_members table might not exist yet
    }

    return $riders;
}

/**
 * Hämta tillgängliga klasser för en rider i ett event
 */
function getEligibleClassesForEvent(int $eventId, int $riderId, ?array &$licenseValidationOut = null): array {
    $pdo = hub_db();

    // Hämta rider-info inkl licensinformation och kontaktuppgifter
    // Dynamically check available columns to avoid errors if ICE columns don't exist yet
    $riderColumns = ['firstname', 'lastname', 'birth_year', 'gender', 'license_number', 'license_type', 'license_valid_until', 'license_year', 'email', 'phone', 'nationality', 'scf_license_year'];
    $optionalColumns = ['ice_name', 'ice_phone'];
    $existingOptional = [];
    $dbColumns = _hub_table_columns($pdo, 'riders');
    foreach ($optionalColumns as $col) {
        if (in_array($col, $dbColumns)) {
            $existingOptional[] = $col;
        }
    }
    // scf_license_year may not exist yet
    if (!in_array('scf_license_year', $dbColumns)) {
        $riderColumns = array_diff($riderColumns, ['scf_license_year']);
    }

    $selectCols = implode(', ', array_merge($riderColumns, $existingOptional));
    $riderStmt = $pdo->prepare("SELECT {$selectCols} FROM riders WHERE id = ?");
    $riderStmt->execute([$riderId]);
    $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        return [];
    }

    // Kontrollera att kritisk profildata finns
    $missingFields = [];
    if (empty($rider['gender'])) {
        $missingFields[] = 'kön';
    }
    if (empty($rider['birth_year'])) {
        $missingFields[] = 'födelsår';
    }
    if (empty($rider['phone'])) {
        $missingFields[] = 'telefonnummer';
    }
    if (empty($rider['email'])) {
        $missingFields[] = 'e-post';
    }
    // ICE fields - only validate if columns exist
    if (in_array('ice_name', $existingOptional) && empty($rider['ice_name'])) {
        $missingFields[] = 'nödkontakt (namn)';
    }
    if (in_array('ice_phone', $existingOptional) && empty($rider['ice_phone'])) {
        $missingFields[] = 'nödkontakt (telefon)';
    }

    // Om kritisk data saknas, returnera special error
    if (!empty($missingFields)) {
        return [[
            'error' => 'incomplete_profile',
            'message' => 'Profilen saknar: ' . implode(', ', $missingFields),
            'missing_fields' => $missingFields
        ]];
    }

    // Hämta event-datum och pricing_template_id
    // Include series pricing_template_id as fallback
    $eventStmt = $pdo->prepare("
        SELECT e.date, e.pricing_template_id,
               s.pricing_template_id as series_pricing_template_id
        FROM events e
        LEFT JOIN series_events se ON e.id = se.event_id
        LEFT JOIN series s ON se.series_id = s.id
        WHERE e.id = ?
        LIMIT 1
    ");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        return [];
    }

    // Use event pricing template, or fallback to series pricing template
    $pricingTemplateId = $event['pricing_template_id'] ?? $event['series_pricing_template_id'] ?? null;

    $eventDate = strtotime($event['date']);
    $riderAge = date('Y', $eventDate) - intval($rider['birth_year']);
    $riderGender = strtoupper($rider['gender']);

    // Validera licens
    $licenseStatus = 'none'; // none, valid, expired
    if (!empty($rider['license_valid_until'])) {
        $licenseExpiry = strtotime($rider['license_valid_until']);
        $licenseStatus = ($licenseExpiry >= $eventDate) ? 'valid' : 'expired';
    } elseif (!empty($rider['license_year'])) {
        // Licenser är giltiga till 31 dec det angivna året
        $licenseExpiry = strtotime($rider['license_year'] . '-12-31');
        $licenseStatus = ($licenseExpiry >= $eventDate) ? 'valid' : 'expired';
    }

    // License validation details (returned to frontend for display)
    $licenseValidation = [
        'status' => $licenseStatus,
        'source' => 'local',
        'uci_id' => $rider['license_number'] ?? null,
        'license_type' => $rider['license_type'] ?? null,
        'license_year' => $rider['license_year'] ?? null,
        'club_name' => null,
        'uci_id_updated' => false,
        'message' => null
    ];

    $eventYear = date('Y', $eventDate);
    $licenseNumber = $rider['license_number'] ?? '';
    $hasSweId = (stripos($licenseNumber, 'SWE') === 0);
    $hasRealUciId = !empty($licenseNumber) && !$hasSweId && strlen(preg_replace('/[^0-9]/', '', $licenseNumber)) >= 9;

    // Skip SCF check if already verified valid for this year
    $alreadyVerified = ($licenseStatus === 'valid' && $hasRealUciId &&
        !empty($rider['scf_license_year']) && $rider['scf_license_year'] >= $eventYear);

    // If license is not verified valid, check SCF API
    if (!$alreadyVerified) {
        try {
            require_once __DIR__ . '/SCFLicenseService.php';
            $scfApiKey = env('SCF_API_KEY', '');

            if (!empty($scfApiKey)) {
                $scfService = new SCFLicenseService($scfApiKey, getDB());
                $scfResult = null;

                // Strategy 1: UCI ID lookup (for riders with real UCI ID)
                if ($hasRealUciId) {
                    $uciId = $scfService->normalizeUciId($licenseNumber);
                    if (strlen($uciId) >= 9) {
                        $scfResults = $scfService->lookupByUciIds([$uciId], $eventYear);
                        if (!empty($scfResults) && isset($scfResults[$uciId])) {
                            $scfResult = $scfResults[$uciId];
                        }
                    }
                }

                // Strategy 2: Name lookup (for SWE-ID riders or if UCI lookup failed)
                if (!$scfResult && !empty($rider['firstname']) && !empty($rider['lastname']) && !empty($rider['gender'])) {
                    $gender = strtoupper(substr($rider['gender'], 0, 1));
                    // First try without birthdate (more reliable - we only have birth_year, not exact date)
                    $scfResult = $scfService->lookupByName(
                        $rider['firstname'], $rider['lastname'], $gender, null, $eventYear
                    );
                    if ($scfResult) {
                        $licenseValidation['name_match'] = true;
                    }
                }

                if ($scfResult) {
                    $scfLicenseYear = $scfResult['license_year'] ?? null;
                    $isValid = !empty($scfLicenseYear) && $scfLicenseYear >= $eventYear;

                    if ($isValid) {
                        $licenseStatus = 'valid';
                    }

                    // Update rider in database
                    $scfService->updateRiderLicense($riderId, $scfResult, $eventYear);

                    // If rider had SWE-ID or no UCI ID, and we found a real one - update license_number
                    if (!$hasRealUciId && !empty($scfResult['uci_id'])) {
                        $newUciId = $scfService->normalizeUciId($scfResult['uci_id']);
                        if (strlen($newUciId) === 11) {
                            $pdo->prepare("UPDATE riders SET license_number = ?, updated_at = NOW() WHERE id = ?")->execute([$newUciId, $riderId]);
                            $licenseValidation['uci_id_updated'] = true;
                            $licenseValidation['uci_id'] = $newUciId;
                        }
                    }

                    // Update validation details for frontend
                    $licenseValidation['status'] = $isValid ? 'valid' : 'expired';
                    $licenseValidation['source'] = 'scf';
                    $licenseValidation['license_type'] = $scfResult['license_type'] ?? $rider['license_type'] ?? null;
                    $licenseValidation['license_year'] = $scfLicenseYear;
                    $licenseValidation['club_name'] = $scfResult['club_name'] ?? null;
                    $licenseValidation['discipline'] = $scfResult['discipline'] ?? null;
                    $licenseValidation['message'] = $isValid
                        ? 'Giltig licens för' . $eventYear
                        : 'Licensen är inte giltig för' . $eventYear;

                    // Cache the result
                    $scfService->cacheLicense($scfResult, $eventYear);
                } else {
                    $licenseValidation['status'] = $hasRealUciId ? 'not_found' : 'none';
                    $licenseValidation['source'] = 'scf';
                    $licenseValidation['message'] = 'Ingen licens hittades i SCFs register för' . $eventYear;
                }
            }
        } catch (Exception $e) {
            // Silently fail - don't block registration if SCF API is down
            error_log("SCF CHECK ERROR for rider $riderId: " . $e->getMessage());
            $licenseValidation['message'] = 'Kunde inte kontakta SCF';
        }
    } else {
        $licenseValidation['source'] = 'local_verified';
        $licenseValidation['message'] = 'Giltig licens för' . $eventYear;
    }

    // Try pricing template system first (new system)
    if (!empty($pricingTemplateId)) {
        // Get template settings
        $templateStmt = $pdo->prepare("SELECT * FROM pricing_templates WHERE id = ?");
        $templateStmt->execute([$pricingTemplateId]);
        $template = $templateStmt->fetch(PDO::FETCH_ASSOC);

        // Get pricing rules from template (including manual price overrides)
        $classStmt = $pdo->prepare("
            SELECT c.id as class_id, c.name, c.display_name, c.gender, c.min_age, c.max_age,
                   ptr.base_price, ptr.early_bird_price, ptr.late_fee_price, ptr.season_price
            FROM pricing_template_rules ptr
            JOIN classes c ON ptr.class_id = c.id
            WHERE ptr.template_id = ?
            ORDER BY c.sort_order, c.name
        ");
        $classStmt->execute([$pricingTemplateId]);

        $earlyBirdPercent = floatval($template['early_bird_percent'] ?? 0);
        $lateFeePercent = floatval($template['late_fee_percent'] ?? 0);
        $pricingMode = $template['pricing_mode'] ?? 'percentage';
        $earlyBirdDaysBefore = intval($template['early_bird_days_before'] ?? 21);
        $lateFeeDaysBefore = intval($template['late_fee_days_before'] ?? 3);
    } else {
        // Fallback to legacy event_pricing_rules
        $classStmt = $pdo->prepare("
            SELECT c.id as class_id, c.name, c.display_name, c.gender, c.min_age, c.max_age,
                   epr.base_price
            FROM event_pricing_rules epr
            JOIN classes c ON epr.class_id = c.id
            WHERE epr.event_id = ?
            ORDER BY c.sort_order, c.name
        ");
        $classStmt->execute([$eventId]);
        $earlyBirdPercent = null;
        $lateFeePercent = null;
        $earlyBirdDaysBefore = null;
        $lateFeeDaysBefore = null;
    }

    $eligibleClasses = [];
    $ineligibleClasses = [];

    // DEBUG: Log rider info
    error_log("DEBUG getEligibleClassesForEvent: Event=$eventId, Rider=$riderId, Age=$riderAge, Gender='$riderGender', License=$licenseStatus");

    while ($class = $classStmt->fetch(PDO::FETCH_ASSOC)) {
        $eligible = true;
        $reason = '';
        $warning = '';

        // DEBUG: Log each class check
        error_log("  Class: {$class['name']} | Gender={$class['gender']}, Age={$class['min_age']}-{$class['max_age']}");

        // Kolla licens - TILLÅT utgångna licenser med varning (license commitment)
        if ($licenseStatus === 'expired') {
            // ALLOW expired licenses - show warning to require commitment
            $warning = 'Licensen har gått ut - licensåtagande krävs';
            error_log("    WARNING: Expired license (allowed with commitment)");
        } elseif ($licenseStatus === 'none') {
            // Varna om ingen licens finns, men tillåt anmälan
            $warning = 'Ingen licens registrerad';
        }

        // Kolla kön - classes använder 'K' för dam, riders använder 'F'
        $classGender = $class['gender'];
        $normalizedClassGender = ($classGender === 'K') ? 'F' : $classGender;
        if ($eligible && $classGender && $normalizedClassGender !== $riderGender) {
            $eligible = false;
            $reason = ($classGender === 'M') ? 'Endast herrar' : 'Endast damer';
            error_log("    BLOCKED: Gender mismatch (need='{$classGender}', have='$riderGender')");
        }

        // Kolla ålder
        if ($eligible && $class['min_age'] && $riderAge < $class['min_age']) {
            $eligible = false;
            $reason = "Minst {$class['min_age']} år";
            error_log("    BLOCKED: Too young (age=$riderAge < min={$class['min_age']})");
        }
        if ($eligible && $class['max_age'] && $riderAge > $class['max_age']) {
            $eligible = false;
            $reason = "Max {$class['max_age']} år";
            error_log("    BLOCKED: Too old (age=$riderAge > max={$class['max_age']})");
        }

        if ($eligible) {
            error_log("    ELIGIBLE!");
        }

        $basePrice = round(floatval($class['base_price'])); // Always whole numbers

        // Calculate early bird and late fee prices
        $earlyBirdPrice = null;
        $lateFeePrice = null;

        if ($earlyBirdPercent !== null && $lateFeePercent !== null) {
            // Pricing template system - check for manual overrides first
            if (!empty($class['early_bird_price'])) {
                // Manual price set - use it (already whole number)
                $earlyBirdPrice = round(floatval($class['early_bird_price']));
            } else {
                // Calculate from percentage and round to whole number
                $earlyBirdPrice = round($basePrice - ($basePrice * $earlyBirdPercent / 100));
            }

            if (!empty($class['late_fee_price'])) {
                // Manual price set - use it (already whole number)
                $lateFeePrice = round(floatval($class['late_fee_price']));
            } else {
                // Calculate from percentage and round to whole number
                $lateFeePrice = round($basePrice + ($basePrice * $lateFeePercent / 100));
            }
        } else {
            // Legacy system - no early bird or late fee in old system
            $earlyBirdPrice = $basePrice;
            $lateFeePrice = $basePrice;
        }

        // Calculate current_price based on timing
        $currentPrice = $basePrice; // default
        $now = time();

        if (isset($earlyBirdDaysBefore) && isset($lateFeeDaysBefore)) {
            // Calculate early bird deadline and late fee start from event date
            $earlyBirdDeadline = $eventDate - ($earlyBirdDaysBefore * 86400); // 86400 seconds per day
            $lateFeeStart = $eventDate - ($lateFeeDaysBefore * 86400);

            if ($now <= $earlyBirdDeadline) {
                // Before early bird deadline - use early bird price
                $currentPrice = $earlyBirdPrice;
            } elseif ($now >= $lateFeeStart) {
                // After late fee start - use late fee price
                $currentPrice = $lateFeePrice;
            } else {
                // Between early bird and late fee - use base price
                $currentPrice = $basePrice;
            }
        }

        $seasonPrice = round(floatval($class['season_price'] ?? 0));

        $classData = [
            'class_id' => $class['class_id'],
            'name' => $class['display_name'] ?: $class['name'] ?: ('Klass ' . $class['class_id']),
            'base_price' => $basePrice,
            'early_bird_price' => $earlyBirdPrice,
            'late_fee' => $lateFeePrice,
            'current_price' => $currentPrice,
            'season_price' => $seasonPrice,
            'eligible' => $eligible,
            'reason' => $reason,
            'warning' => $warning
        ];

        // Separera eligible och ineligible klasser
        if ($eligible) {
            $eligibleClasses[] = $classData;
        } else {
            $ineligibleClasses[] = $classData;
        }
    }

    // Pass license validation data to caller
    $licenseValidationOut = $licenseValidation;

    // Returnera endast eligible klasser (dölj ineligible)
    // Om inga eligible klasser, returnera debug-info
    if (empty($eligibleClasses)) {
        if (!empty($ineligibleClasses)) {
            // Fanns klasser men ingen matchade
            return [[
                'error' => 'no_eligible_classes',
                'message' => 'Inga klasser matchade kriterierna',
                'debug' => [
                    'rider_age' => $riderAge,
                    'rider_gender' => $riderGender,
                    'license_status' => $licenseStatus,
                    'ineligible_classes' => array_map(function($cls) {
                        return [
                            'name' => $cls['name'],
                            'reason' => $cls['reason']
                        ];
                    }, $ineligibleClasses)
                ]
            ]];
        } else {
            // Inga klasser alls i prismallen
            return [[
                'error' => 'no_classes_configured',
                'message' => 'Eventet saknar klasser',
                'debug' => [
                    'pricing_template_id' => $pricingTemplateId ?? 'NULL',
                    'event_id' => $eventId
                ]
            ]];
        }
    }

    return $eligibleClasses;
}

/**
 * Hämta tillgängliga klasser för en rider i en serie
 */
function getEligibleClassesForSeries(int $seriesId, int $riderId): array {
    $pdo = hub_db();

    // Hämta rider-info (inkl alla fält som behövs för validering)
    // Check which optional columns exist (cached per request)
    $dbColumns = _hub_table_columns($pdo, 'riders');
    $optionalCols = [];
    foreach (['phone', 'ice_name', 'ice_phone'] as $col) {
        if (in_array($col, $dbColumns)) {
            $optionalCols[] = $col;
        }
    }
    $selectCols = array_merge(['birth_year', 'gender', 'license_type', 'email'], $optionalCols);
    $selectStr = implode(', ', $selectCols);

    $riderStmt = $pdo->prepare("SELECT {$selectStr} FROM riders WHERE id = ?");
    $riderStmt->execute([$riderId]);
    $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        return [];
    }

    // Kontrollera att kritisk profildata finns (samma som getEligibleClassesForEvent)
    $missingFields = [];
    if (empty($rider['gender'])) {
        $missingFields[] = 'kön';
    }
    if (empty($rider['birth_year'])) {
        $missingFields[] = 'födelseår';
    }
    if (empty($rider['phone'])) {
        $missingFields[] = 'telefonnummer';
    }
    if (empty($rider['email'])) {
        $missingFields[] = 'e-post';
    }
    if (in_array('ice_name', $optionalCols) && empty($rider['ice_name'])) {
        $missingFields[] = 'nödkontakt (namn)';
    }
    if (in_array('ice_phone', $optionalCols) && empty($rider['ice_phone'])) {
        $missingFields[] = 'nödkontakt (telefon)';
    }

    if (!empty($missingFields)) {
        return [[
            'error' => 'incomplete_profile',
            'message' => 'Profilen saknar: ' . implode(', ', $missingFields),
            'missing_fields' => $missingFields
        ]];
    }

    // Använd nuvarande år för ålder
    $riderAge = date('Y') - intval($rider['birth_year']);
    $riderGender = strtoupper($rider['gender']);

    // Hämta serie-rabatt
    $seriesStmt = $pdo->prepare("SELECT series_discount_percent FROM series WHERE id = ?");
    $seriesStmt->execute([$seriesId]);
    $discountPercent = floatval($seriesStmt->fetchColumn() ?: 15);

    // Hämta klasser som finns i seriens event
    $classStmt = $pdo->prepare("
        SELECT DISTINCT c.id as class_id, c.name, c.display_name, c.gender, c.min_age, c.max_age,
               (SELECT SUM(epr2.base_price)
                FROM event_pricing_rules epr2
                JOIN events e2 ON epr2.event_id = e2.id
                WHERE e2.series_id = ? AND epr2.class_id = c.id) as total_price
        FROM events e
        JOIN event_pricing_rules epr ON epr.event_id = e.id
        JOIN classes c ON epr.class_id = c.id
        WHERE e.series_id = ?
        GROUP BY c.id
        ORDER BY c.sort_order, c.name
    ");
    $classStmt->execute([$seriesId, $seriesId]);

    $classes = [];
    while ($class = $classStmt->fetch(PDO::FETCH_ASSOC)) {
        $eligible = true;
        $reason = '';

        // Kolla kön - classes använder 'K' för dam, riders använder 'F'
        $classGender = $class['gender'];
        $normalizedClassGender = ($classGender === 'K') ? 'F' : $classGender;
        if ($classGender && $normalizedClassGender !== $riderGender) {
            $eligible = false;
            $reason = ($classGender === 'M') ? 'Endast herrar' : 'Endast damer';
        }

        // Kolla ålder
        if ($eligible && $class['min_age'] && $riderAge < $class['min_age']) {
            $eligible = false;
            $reason = "Minst {$class['min_age']} år";
        }
        if ($eligible && $class['max_age'] && $riderAge > $class['max_age']) {
            $eligible = false;
            $reason = "Max {$class['max_age']} år";
        }

        $basePrice = floatval($class['total_price'] ?? 0);
        $discountAmount = round($basePrice * ($discountPercent / 100), 2);
        $finalPrice = $basePrice - $discountAmount;

        $classes[] = [
            'class_id' => $class['class_id'],
            'name' => $class['display_name'] ?: $class['name'] ?: ('Klass ' . $class['class_id']),
            'base_price' => $basePrice,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
            'eligible' => $eligible,
            'reason' => $reason
        ];
    }

    return $classes;
}

/**
 * Skapa en ny rider från anmälningsflödet
 */
function createRiderFromRegistration(array $data, int $parentUserId): array {
    $pdo = hub_db();

    $pdo->beginTransaction();

    try {
        // Validera alla obligatoriska fält (backend - matchar frontend-validering)
        if (empty($data['firstname']) || empty($data['lastname']) || empty($data['email'])) {
            throw new Exception('Förnamn, efternamn och e-post krävs');
        }
        if (empty($data['birth_year'])) {
            throw new Exception('Födelseår krävs');
        }
        if (empty($data['gender'])) {
            throw new Exception('Kön krävs');
        }
        if (empty($data['phone'])) {
            throw new Exception('Telefonnummer krävs');
        }
        if (empty($data['ice_name']) || empty($data['ice_phone'])) {
            throw new Exception('Nödkontakt (namn och telefon) krävs');
        }

        // Kolla om email redan finns - ge specifik feedback
        $checkStmt = $pdo->prepare("SELECT id, password, firstname, lastname FROM riders WHERE email = ? LIMIT 1");
        $checkStmt->execute([$data['email']]);
        $existingRider = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRider) {
            $pdo->rollBack();
            $name = trim($existingRider['firstname'] . ' ' . $existingRider['lastname']);
            if (!empty($existingRider['password'])) {
                return [
                    'success' => false,
                    'code' => 'email_exists_active',
                    'error' => "Det finns redan ett konto ({$name}) med denna e-post. Logga in för att anmäla."
                ];
            } else {
                return [
                    'success' => false,
                    'code' => 'email_exists_inactive',
                    'error' => "Det finns redan en profil ({$name}) med denna e-post som inte är aktiverad."
                ];
            }
        }

        // Kolla om namn + födelseår redan finns (fångar dubbletter med annan e-post)
        $birthYearCheck = !empty($data['birth_year']) ? intval($data['birth_year']) : null;
        if ($birthYearCheck) {
            $nameCheckStmt = $pdo->prepare("
                SELECT id, email, firstname, lastname, password
                FROM riders
                WHERE LOWER(firstname) = LOWER(?) AND LOWER(lastname) = LOWER(?) AND birth_year = ?
                LIMIT 1
            ");
            $nameCheckStmt->execute([trim($data['firstname']), trim($data['lastname']), $birthYearCheck]);
            $nameMatch = $nameCheckStmt->fetch(PDO::FETCH_ASSOC);

            if ($nameMatch) {
                $pdo->rollBack();
                $existingName = trim($nameMatch['firstname'] . ' ' . $nameMatch['lastname']);
                $maskedEmail = '';
                if (!empty($nameMatch['email'])) {
                    $parts = explode('@', $nameMatch['email']);
                    $maskedEmail = substr($parts[0], 0, 2) . '***@' . ($parts[1] ?? '');
                }
                return [
                    'success' => false,
                    'code' => 'name_duplicate',
                    'error' => "Det finns redan en profil för {$existingName} (f. {$birthYearCheck}) med e-post {$maskedEmail}. Sök på namnet istället för att skapa en ny profil.",
                    'existing_rider_id' => $nameMatch['id']
                ];
            }
        }

        // Berakna fodelsear
        $birthYear = null;
        if (!empty($data['birth_date'])) {
            $birthYear = date('Y', strtotime($data['birth_date']));
        } elseif (!empty($data['birth_year'])) {
            $birthYear = intval($data['birth_year']);
        }

        // Check which optional columns exist (cached per request)
        $optionalCols = [];
        $dbColumns = _hub_table_columns($pdo, 'riders');
        foreach (['phone', 'ice_name', 'ice_phone', 'nationality'] as $col) {
            if (in_array($col, $dbColumns)) {
                $optionalCols[] = $col;
            }
        }

        // Build dynamic insert
        $columns = ['firstname', 'lastname', 'email', 'birth_year', 'gender',
                     'license_type', 'license_number', 'club_id', 'active', 'created_at'];
        $values = [
            $data['firstname'],
            $data['lastname'],
            $data['email'],
            $birthYear,
            $data['gender'] ?? null,
            $data['license_type'] ?? null,
            $data['license_number'] ?? null,
            $data['club_id'] ?? null,
            1,
            date('Y-m-d H:i:s')
        ];

        // Add optional columns if they exist in the database
        if (in_array('phone', $optionalCols) && !empty($data['phone'])) {
            $columns[] = 'phone';
            $values[] = $data['phone'];
        }
        if (in_array('ice_name', $optionalCols) && !empty($data['ice_name'])) {
            $columns[] = 'ice_name';
            $values[] = $data['ice_name'];
        }
        if (in_array('ice_phone', $optionalCols) && !empty($data['ice_phone'])) {
            $columns[] = 'ice_phone';
            $values[] = $data['ice_phone'];
        }
        if (in_array('nationality', $optionalCols) && !empty($data['nationality'])) {
            $columns[] = 'nationality';
            $values[] = $data['nationality'];
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList = implode(', ', $columns);

        $insertStmt = $pdo->prepare("INSERT INTO riders ({$columnList}) VALUES ({$placeholders})");
        $insertStmt->execute($values);

        $riderId = $pdo->lastInsertId();

        // Skapa family_member-koppling om det finns en foralder
        if ($parentUserId) {
            try {
                $familyStmt = $pdo->prepare("
                    INSERT INTO family_members (parent_rider_id, child_rider_id, created_at)
                    VALUES (?, ?, NOW())
                ");
                $familyStmt->execute([$parentUserId, $riderId]);
            } catch (PDOException $e) {
                // family_members table might not exist - that's ok
            }
        }

        $pdo->commit();

        return [
            'success' => true,
            'rider' => [
                'id' => $riderId,
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'email' => $data['email'],
                'birth_year' => $birthYear,
                'gender' => $data['gender'] ?? null,
                'phone' => $data['phone'] ?? null,
                'ice_name' => $data['ice_name'] ?? null,
                'ice_phone' => $data['ice_phone'] ?? null
            ]
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

