<?php
/**
 * API: Skapa platsregistrering
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Kräv inloggning
if (!isLoggedIn() || !hasRole('promotor')) {
    http_response_code(401);
    echo json_encode(['error' => 'Ej inloggad']);
    exit;
}

// Läs JSON-body
$input = json_decode(file_get_contents('php://input'), true);

// Validera
$eventId = (int)($input['event_id'] ?? 0);
$firstName = trim($input['first_name'] ?? '');
$lastName = trim($input['last_name'] ?? '');
$className = trim($input['class_name'] ?? '');

if (!$eventId || !$firstName || !$lastName || !$className) {
    http_response_code(400);
    echo json_encode(['error' => 'Saknade obligatoriska fält']);
    exit;
}

// Kontrollera eventtillgång
if (!canAccessEvent($eventId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Ingen tillgång till detta event']);
    exit;
}

try {
    global $pdo;

    // Generera startnummer
    $bibNumber = getNextBibNumber($eventId);
    if (ONSITE_BIB_PREFIX) {
        $bibNumber = ONSITE_BIB_PREFIX . $bibNumber;
    }

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
            'confirmed', ?,
            'onsite', ?,
            NOW()
        )
    ");

    $stmt->execute([
        $eventId,
        $input['rider_id'] ?: null,
        $firstName,
        $lastName,
        $input['email'] ?: null,
        $input['phone'] ?: null,
        $input['birth_year'] ?: null,
        $input['gender'] ?: null,
        $input['club_name'] ?: null,
        $input['license_number'] ?: null,
        $className,
        $bibNumber,
        $input['payment_status'] ?? 'unpaid',
        $_SESSION['admin_id']
    ]);

    $registrationId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'registration_id' => $registrationId,
        'bib_number' => $bibNumber
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Kunde inte skapa registrering: ' . $e->getMessage()]);
}
