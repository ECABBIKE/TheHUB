<?php
/**
 * Organizer Registration App - Configuration
 *
 * Fristående app för platsregistreringar på tävlingsdagen.
 * 100% iPad-optimerad för snabb hantering.
 */

// Ladda huvudkonfigurationen
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';

// =============================================================================
// APP-KONFIGURATION
// =============================================================================

// URL-konfiguration (ändra här om appen flyttas till subdomän)
define('ORGANIZER_BASE_URL', SITE_URL . '/organizer');
define('ORGANIZER_APP_NAME', 'Platsregistrering');

// Startnummer-konfiguration
define('ONSITE_BIB_PREFIX', ''); // Prefix för platsen-startnummer (t.ex. 'P' ger P001, P002...)
define('ONSITE_BIB_START', 200); // Startnummer börjar från detta nummer om inget annat finns

// Session-timeout för organizer (i sekunder) - längre än admin pga tävlingsdag
define('ORGANIZER_SESSION_TIMEOUT', 28800); // 8 timmar

// =============================================================================
// HJÄLPFUNKTIONER
// =============================================================================

/**
 * Hämta event med alla klasser och priser
 */
function getEventWithClasses(int $eventId): ?array {
    global $pdo;

    // Hämta event
    $stmt = $pdo->prepare("
        SELECT e.*,
               s.name as series_name,
               v.name as venue_name,
               c.name as organizer_club_name
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN venues v ON e.venue_id = v.id
        LEFT JOIN clubs c ON e.organizer_club_id = c.id
        WHERE e.id = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        return null;
    }

    // Hämta klasser med priser för detta event
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.display_name, c.gender, c.min_age, c.max_age, c.sort_order,
               COALESCE(epr.base_price, epr.onsite_price, 0) as price,
               COALESCE(epr.onsite_price, epr.base_price, 0) as onsite_price,
               epr.id as pricing_rule_id
        FROM classes c
        JOIN event_classes ec ON ec.class_id = c.id AND ec.event_id = ?
        LEFT JOIN event_pricing_rules epr ON epr.class_id = c.id AND epr.event_id = ?
        WHERE c.active = 1
        ORDER BY c.sort_order, c.name
    ");
    $stmt->execute([$eventId, $eventId]);
    $event['classes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Om inga klasser via event_classes, försök hämta alla aktiva klasser
    if (empty($event['classes'])) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.display_name, c.gender, c.min_age, c.max_age, c.sort_order,
                   COALESCE(epr.base_price, 0) as price,
                   COALESCE(epr.onsite_price, epr.base_price, 0) as onsite_price,
                   epr.id as pricing_rule_id
            FROM classes c
            LEFT JOIN event_pricing_rules epr ON epr.class_id = c.id AND epr.event_id = ?
            WHERE c.active = 1
            ORDER BY c.sort_order, c.name
        ");
        $stmt->execute([$eventId]);
        $event['classes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Hämta betalningskonfiguration
    $event['payment_config'] = getPaymentConfig($eventId);

    return $event;
}

/**
 * Hämta nästa lediga startnummer för event
 */
function getNextBibNumber(int $eventId): int {
    global $pdo;

    // Hitta högsta befintliga startnummer
    $stmt = $pdo->prepare("
        SELECT MAX(CAST(bib_number AS UNSIGNED)) as max_bib
        FROM event_registrations
        WHERE event_id = ? AND bib_number IS NOT NULL AND bib_number != ''
    ");
    $stmt->execute([$eventId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $maxBib = (int)($result['max_bib'] ?? 0);

    // Om inget startnummer finns, börja från konfigurerat värde
    if ($maxBib < ONSITE_BIB_START) {
        return ONSITE_BIB_START;
    }

    return $maxBib + 1;
}

/**
 * Sök efter befintlig åkare
 */
function searchRiders(string $query, int $limit = 20): array {
    global $pdo;

    $searchTerm = '%' . $query . '%';

    $stmt = $pdo->prepare("
        SELECT r.id, r.firstname, r.lastname, r.birth_year, r.gender,
               r.license_number, r.license_type,
               c.name as club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.active = 1
          AND (
              r.firstname LIKE ?
              OR r.lastname LIKE ?
              OR CONCAT(r.firstname, ' ', r.lastname) LIKE ?
              OR r.license_number LIKE ?
          )
        ORDER BY r.lastname, r.firstname
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Skapa platsregistrering
 */
function createOnsiteRegistration(array $data, int $eventId, int $registeredByUserId): array {
    global $pdo;

    $pdo->beginTransaction();

    try {
        // Generera startnummer
        $bibNumber = ONSITE_BIB_PREFIX . getNextBibNumber($eventId);

        // Skapa registrering
        $stmt = $pdo->prepare("
            INSERT INTO event_registrations (
                event_id, rider_id,
                first_name, last_name, email, phone,
                birth_year, gender, club_name, license_number,
                category, bib_number,
                status, payment_status,
                registration_source, registered_by_user_id,
                registration_date
            ) VALUES (
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                'pending', 'unpaid',
                'onsite', ?,
                NOW()
            )
        ");

        $stmt->execute([
            $eventId,
            $data['rider_id'] ?? null,
            $data['first_name'],
            $data['last_name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['birth_year'] ?? null,
            $data['gender'] ?? null,
            $data['club_name'] ?? null,
            $data['license_number'] ?? null,
            $data['class_name'],
            $bibNumber,
            $registeredByUserId
        ]);

        $registrationId = $pdo->lastInsertId();

        $pdo->commit();

        return [
            'success' => true,
            'registration_id' => $registrationId,
            'bib_number' => $bibNumber
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
 * Hämta platsregistreringar för event
 */
function getOnsiteRegistrations(int $eventId, ?string $status = null): array {
    global $pdo;

    $sql = "
        SELECT er.*,
               r.firstname as rider_firstname, r.lastname as rider_lastname
        FROM event_registrations er
        LEFT JOIN riders r ON er.rider_id = r.id
        WHERE er.event_id = ? AND er.registration_source = 'onsite'
    ";

    $params = [$eventId];

    if ($status) {
        $sql .= " AND er.payment_status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY er.registration_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Räkna registreringar för event
 * Hanterar fallet där registration_source-kolumnen inte finns ännu
 */
function countEventRegistrations(int $eventId): array {
    global $pdo;

    try {
        // Försök med full query inkl. registration_source
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN registration_source = 'onsite' THEN 1 ELSE 0 END) as onsite,
                SUM(CASE WHEN registration_source = 'online' OR registration_source IS NULL THEN 1 ELSE 0 END) as online,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN payment_status != 'paid' OR payment_status IS NULL THEN 1 ELSE 0 END) as unpaid
            FROM event_registrations
            WHERE event_id = ? AND status != 'cancelled'
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback om registration_source-kolumnen inte finns
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total,
                0 as onsite,
                COUNT(*) as online,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN payment_status != 'paid' OR payment_status IS NULL THEN 1 ELSE 0 END) as unpaid
            FROM event_registrations
            WHERE event_id = ? AND status != 'cancelled'
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

/**
 * Kräv organizer-inloggning (promotor eller högre)
 */
function requireOrganizer() {
    requireLogin();

    if (!hasRole('promotor')) {
        http_response_code(403);
        die('Endast arrangörer har tillgång till denna sida.');
    }
}

/**
 * Kräv tillgång till specifikt event
 */
function requireEventAccess(int $eventId) {
    requireOrganizer();

    if (!canAccessEvent($eventId)) {
        http_response_code(403);
        die('Du har inte tillgång till detta event.');
    }
}

/**
 * Hämta events som användaren har tillgång till
 */
function getAccessibleEvents(): array {
    global $pdo;
    $userId = $_SESSION['admin_id'] ?? null;

    // Admin/super_admin ser alla aktiva events
    if (hasRole('admin')) {
        $stmt = $pdo->prepare("
            SELECT e.*,
                   s.name as series_name,
                   (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id AND er.status != 'cancelled') as registration_count
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            WHERE e.active = 1 AND e.date >= CURDATE() - INTERVAL 7 DAY
            ORDER BY e.date ASC
        ");
        $stmt->execute();
    } else {
        // Promotor ser endast tilldelade events
        $stmt = $pdo->prepare("
            SELECT e.*,
                   s.name as series_name,
                   pe.can_manage_registrations,
                   (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id AND er.status != 'cancelled') as registration_count
            FROM events e
            JOIN promotor_events pe ON pe.event_id = e.id
            LEFT JOIN series s ON e.series_id = s.id
            WHERE pe.user_id = ? AND e.active = 1 AND e.date >= CURDATE() - INTERVAL 7 DAY
            ORDER BY e.date ASC
        ");
        $stmt->execute([$userId]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
