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
 * Skapa en multi-rider order
 *
 * @param array $buyerData Köparens info: name, email, phone, user_id
 * @param array $items Lista av registreringar: [{type, rider_id, event_id/series_id, class_id}, ...]
 * @param string|null $discountCode Rabattkod (valfritt)
 * @return array ['success' => bool, 'order' => array|null, 'error' => string|null]
 */
function createMultiRiderOrder(array $buyerData, array $items, ?string $discountCode = null): array {
    $pdo = hub_db();

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

        // Hämta payment config från första event (för Swish-nummer)
        $firstEventId = null;
        foreach ($items as $item) {
            if (($item['type'] ?? 'event') === 'event' && !empty($item['event_id'])) {
                $firstEventId = intval($item['event_id']);
                break;
            }
        }

        $swishNumber = null;
        $swishMessage = $orderReference;

        if ($firstEventId && function_exists('getPaymentConfig')) {
            $paymentConfig = getPaymentConfig($firstEventId);
            if ($paymentConfig && !empty($paymentConfig['swish_number'])) {
                $swishNumber = $paymentConfig['swish_number'];
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

        $orderStmt = $pdo->prepare("
            INSERT INTO orders (
                order_number, rider_id, customer_email, customer_name,
                event_id, subtotal, discount, total_amount, currency,
                payment_method, payment_status,
                swish_number, swish_message,
                expires_at, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, 0, 0, 0, 'SEK',
                'swish', 'pending',
                ?, ?,
                DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW()
            )
        ");
        $orderStmt->execute([
            $orderReference,
            $validRiderId,  // Only set if valid rider ID
            $buyerData['email'],
            $buyerData['name'],
            $firstEventId,
            $swishNumber,
            $swishMessage
        ]);

        $orderId = $pdo->lastInsertId();

        $subtotal = 0;
        $registrations = [];
        $orderItems = [];

        // Processa varje item
        foreach ($items as $item) {
            $itemType = $item['type'] ?? 'event';
            $riderId = intval($item['rider_id']);
            $classId = intval($item['class_id']);

            // Hämta rider-info
            $riderStmt = $pdo->prepare("
                SELECT firstname, lastname, email, birth_year, gender, club_id
                FROM riders WHERE id = ?
            ");
            $riderStmt->execute([$riderId]);
            $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);

            if (!$rider) {
                throw new Exception("Rider med ID {$riderId} hittades inte");
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

                // Kolla om redan anmäld
                $checkRegStmt = $pdo->prepare("
                    SELECT id FROM event_registrations
                    WHERE event_id = ? AND rider_id = ? AND status != 'cancelled'
                ");
                $checkRegStmt->execute([$eventId, $riderId]);
                if ($checkRegStmt->fetch()) {
                    throw new Exception("{$rider['firstname']} {$rider['lastname']} är redan anmäld till detta event");
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

                // Skapa order_item
                $description = "{$rider['firstname']} {$rider['lastname']} - {$eventInfo['event_name']} - {$className}";
                $itemStmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, item_type, registration_id,
                        description, unit_price, quantity, total_price
                    ) VALUES (?, 'registration', ?, ?, ?, 1, ?)
                ");
                $itemStmt->execute([$orderId, $registrationId, $description, $finalPrice, $finalPrice]);

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

                // Kolla om redan anmäld till serien
                $checkSeriesStmt = $pdo->prepare("
                    SELECT id FROM series_registrations
                    WHERE series_id = ? AND rider_id = ? AND status != 'cancelled'
                ");
                $checkSeriesStmt->execute([$seriesId, $riderId]);
                if ($checkSeriesStmt->fetch()) {
                    throw new Exception("{$rider['firstname']} {$rider['lastname']} har redan ett serie-pass för denna serie");
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

                // Skapa order_item
                $description = "{$rider['firstname']} {$rider['lastname']} - {$seriesInfo['series_name']} (Serie-pass) - {$className}";
                $itemStmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, item_type, series_registration_id,
                        description, unit_price, quantity, total_price
                    ) VALUES (?, 'series_registration', ?, ?, ?, 1, ?)
                ");
                $itemStmt->execute([$orderId, $seriesRegId, $description, $finalPrice, $finalPrice]);

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

        // Check for Gravity ID discount for each registration
        if (function_exists('checkGravityIdDiscount')) {
            // Loop through each registration and check for Gravity ID discount
            foreach ($registrations as $reg) {
                // Only check for event registrations (not series)
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
function getEligibleClassesForEvent(int $eventId, int $riderId): array {
    $pdo = hub_db();

    // Hämta rider-info inkl licensinformation
    $riderStmt = $pdo->prepare("
        SELECT birth_year, gender, license_type, license_valid_until, license_year
        FROM riders WHERE id = ?
    ");
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

    // If license is expired or missing, check SCF API for current license
    if ($licenseStatus !== 'valid' && !empty($rider['license_number'])) {
        try {
            require_once __DIR__ . '/SCFLicenseService.php';
            $scfApiKey = env('SCF_API_KEY', '');

            if (!empty($scfApiKey)) {
                $scfService = new SCFLicenseService($scfApiKey, getDB());
                $eventYear = date('Y', $eventDate);

                // Check current year license from SCF API using UCI ID
                $uciId = $scfService->normalizeUciId($rider['license_number']);
                $scfResults = $scfService->lookupByUciIds([$uciId], $eventYear);

                if (!empty($scfResults) && isset($scfResults[$uciId])) {
                    $scfLicense = $scfResults[$uciId];

                    // Check if license is valid for the event year
                    if (!empty($scfLicense['license_year']) && $scfLicense['license_year'] >= $eventYear) {
                        // License is valid according to SCF - update local database
                        $licenseStatus = 'valid';

                        // Update rider using SCFLicenseService method
                        $scfService->updateRiderLicense($riderId, $scfLicense, $eventYear);

                        error_log("SCF API: Updated license for rider $riderId (UCI: $uciId) - valid for $eventYear");
                    }
                }
            }
        } catch (Exception $e) {
            // Silently fail - don't block registration if SCF API is down
            error_log("SCF API check failed for rider $riderId: " . $e->getMessage());
        }
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

        // Kolla kön
        if ($eligible && $class['gender'] && $class['gender'] !== $riderGender) {
            $eligible = false;
            $reason = $class['gender'] === 'M' ? 'Endast herrar' : 'Endast damer';
            error_log("    BLOCKED: Gender mismatch (need='{$class['gender']}', have='$riderGender')");
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

        $classData = [
            'class_id' => $class['class_id'],
            'name' => $class['display_name'] ?: $class['name'],
            'base_price' => $basePrice,
            'early_bird_price' => $earlyBirdPrice,
            'late_fee' => $lateFeePrice,
            'current_price' => $currentPrice,
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

    // Hämta rider-info
    $riderStmt = $pdo->prepare("
        SELECT birth_year, gender, license_type FROM riders WHERE id = ?
    ");
    $riderStmt->execute([$riderId]);
    $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        return [];
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

        // Kolla kön
        if ($class['gender'] && $class['gender'] !== $riderGender) {
            $eligible = false;
            $reason = $class['gender'] === 'M' ? 'Endast herrar' : 'Endast damer';
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
            'name' => $class['display_name'] ?: $class['name'],
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
        // Validera obligatoriska fält
        if (empty($data['firstname']) || empty($data['lastname']) || empty($data['email'])) {
            throw new Exception('Förnamn, efternamn och e-post krävs');
        }

        // Kolla om email redan finns
        $checkStmt = $pdo->prepare("SELECT id FROM riders WHERE email = ?");
        $checkStmt->execute([$data['email']]);
        if ($checkStmt->fetch()) {
            throw new Exception('E-postadressen är redan registrerad');
        }

        // Beräkna födelseår från födelsedatum
        $birthYear = null;
        if (!empty($data['birth_date'])) {
            $birthYear = date('Y', strtotime($data['birth_date']));
        } elseif (!empty($data['birth_year'])) {
            $birthYear = intval($data['birth_year']);
        }

        // Skapa rider
        $insertStmt = $pdo->prepare("
            INSERT INTO riders (
                firstname, lastname, email, birth_year, gender,
                license_type, license_number, club_id, active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $insertStmt->execute([
            $data['firstname'],
            $data['lastname'],
            $data['email'],
            $birthYear,
            $data['gender'] ?? null,
            $data['license_type'] ?? null,
            $data['license_number'] ?? null,
            $data['club_id'] ?? null
        ]);

        $riderId = $pdo->lastInsertId();

        // Skapa family_member-koppling om det finns en förälder
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
                'gender' => $data['gender'] ?? null
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
 * Beräkna savings för multi-rider order
 */
function calculateMultiRiderSavings(int $riderCount): array {
    $swishFeePerTransaction = 1.00; // kr

    $individualCost = $riderCount * $swishFeePerTransaction;
    $multiRiderCost = $swishFeePerTransaction; // Bara en avgift
    $savings = $individualCost - $multiRiderCost;

    return [
        'individual_fees' => $individualCost,
        'multi_rider_fee' => $multiRiderCost,
        'savings' => $savings,
        'savings_percent' => $riderCount > 1 ? round((1 - 1/$riderCount) * 100) : 0
    ];
}
