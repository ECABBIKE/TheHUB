<?php
/**
 * API: Promotion audience preview
 * Returns count of riders matching filters
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Require admin login
if (!isLoggedIn() || !hasRole('admin')) {
    echo json_encode(['error' => 'Unauthorized', 'count' => 0]);
    exit;
}

global $pdo;

$currentYear = (int)date('Y');
$where = ["r.active = 1", "r.email IS NOT NULL", "r.email != ''"];

// Gender filter
$gender = $_GET['gender'] ?? '';
if ($gender === 'M') {
    $where[] = "r.gender = 'M'";
} elseif ($gender === 'F') {
    $where[] = "(r.gender = 'F' OR r.gender = 'K')";
}

// Age filter
$ageMin = !empty($_GET['age_min']) ? (int)$_GET['age_min'] : 0;
$ageMax = !empty($_GET['age_max']) ? (int)$_GET['age_max'] : 0;
if ($ageMin > 0) {
    $maxBirthYear = $currentYear - $ageMin;
    $where[] = "r.birth_year <= $maxBirthYear";
}
if ($ageMax > 0) {
    $minBirthYear = $currentYear - $ageMax;
    $where[] = "r.birth_year >= $minBirthYear";
}

// Region filter
$regions = !empty($_GET['regions']) ? $_GET['regions'] : '';
if ($regions) {
    $regionList = implode(',', array_map(function($r) {
        return "'" . addslashes(trim($r)) . "'";
    }, explode(',', $regions)));
    $where[] = "c.region IN ($regionList)";
}

// District filter
$districts = !empty($_GET['districts']) ? $_GET['districts'] : '';
if ($districts) {
    $districtList = implode(',', array_map(function($d) {
        return "'" . addslashes(trim($d)) . "'";
    }, explode(',', $districts)));
    $where[] = "r.district IN ($districtList)";
}

// Only active participants
$where[] = "EXISTS (SELECT 1 FROM results res WHERE res.cyclist_id = r.id)";

$whereStr = implode(' AND ', $where);

try {
    $sql = "SELECT COUNT(*) FROM riders r LEFT JOIN clubs c ON r.club_id = c.id WHERE $whereStr";
    $count = (int)$pdo->query($sql)->fetchColumn();
    echo json_encode(['count' => $count]);
} catch (Exception $e) {
    echo json_encode(['count' => 0, 'error' => $e->getMessage()]);
}
