<?php
/**
 * API: Create New Rider
 * Creates a new rider with auto-generated SWE-ID for engångslicens registrations
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Required fields
$firstname = trim($input['firstname'] ?? '');
$lastname = trim($input['lastname'] ?? '');
$birthYear = intval($input['birth_year'] ?? 0);
$gender = trim($input['gender'] ?? 'M');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');

// Validate required fields
$errors = [];

if (empty($firstname)) {
    $errors[] = 'Förnamn krävs';
}
if (empty($lastname)) {
    $errors[] = 'Efternamn krävs';
}
if ($birthYear < 1900 || $birthYear > date('Y')) {
    $errors[] = 'Ogiltigt födelseår';
}
if (!in_array($gender, ['M', 'F'])) {
    $errors[] = 'Välj kön';
}
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Ogiltig e-postadress';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode(', ', $errors)]);
    exit;
}

$db = getDB();

// Check if rider already exists with same name and birth year
$existing = $db->getRow("
    SELECT id, license_number FROM riders
    WHERE firstname = ? AND lastname = ? AND birth_year = ?
", [$firstname, $lastname, $birthYear]);

if ($existing) {
    echo json_encode([
        'success' => true,
        'existing' => true,
        'rider' => [
            'id' => $existing['id'],
            'name' => "$firstname $lastname",
            'message' => 'Deltagare finns redan i registret'
        ]
    ]);
    exit;
}

// Generate SWE-ID (engångslicens format: SWE-YYYY-NNNNN)
$year = date('Y');

// Get highest SWE-ID number for this year
$lastSweId = $db->getValue("
    SELECT MAX(CAST(SUBSTRING(license_number, 10) AS UNSIGNED))
    FROM riders
    WHERE license_number LIKE ?
", ["SWE-$year-%"]);

$nextNumber = ($lastSweId ? $lastSweId + 1 : 1);
$sweId = sprintf("SWE-%d-%05d", $year, $nextNumber);

// Insert new rider
$riderId = $db->insert('riders', [
    'firstname' => $firstname,
    'lastname' => $lastname,
    'birth_year' => $birthYear,
    'gender' => $gender,
    'email' => $email ?: null,
    'phone' => $phone ?: null,
    'license_number' => $sweId,
    'license_type' => 'Engångslicens',
    'license_year' => $year,
    'active' => 1,
    'notes' => 'Skapad via online-registrering med engångslicens'
]);

echo json_encode([
    'success' => true,
    'rider' => [
        'id' => $riderId,
        'name' => "$firstname $lastname",
        'sweId' => $sweId,
        'licenseType' => 'Engångslicens',
        'birthYear' => $birthYear,
        'gender' => $gender,
        'hasGravityId' => 0
    ]
]);
