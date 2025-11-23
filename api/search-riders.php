<?php
/**
 * API: Search Riders
 * Returns matching riders for registration form
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode([]);
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
        r.uci_id,
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
       OR r.uci_id LIKE ?
       OR r.email LIKE ?
    ORDER BY r.lastname, r.firstname
    LIMIT 10
", [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);

echo json_encode($riders);
