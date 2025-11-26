<?php
/**
 * API: Search Riders
 * Returns matching riders for registration form and admin user linking
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');
$limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 10;

if (strlen($query) < 2) {
    echo json_encode(['riders' => []]);
    exit;
}

$db = getDB();

// Search by name, UCI ID, or email
$searchTerm = '%' . $query . '%';

$riders = $db->getAll("
    SELECT
        r.id,
        r.firstname,
        r.lastname,
        r.license_number,
        r.license_number as uci_id,
        r.email,
        r.gravity_id,
        r.license_type,
        r.birth_year,
        r.gender,
        c.name as club_name
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.firstname LIKE ?
       OR r.lastname LIKE ?
       OR CONCAT(r.firstname, ' ', r.lastname) LIKE ?
       OR r.license_number LIKE ?
       OR r.email LIKE ?
    ORDER BY r.lastname, r.firstname
    LIMIT ?
", [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);

echo json_encode(['riders' => $riders]);
