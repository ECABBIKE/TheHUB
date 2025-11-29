<?php
/**
 * TheHUB V3.5 - Search API
 * Live search endpoint for riders and clubs
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$query = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all';
$limit = min(intval($_GET['limit'] ?? 10), 20);

if (strlen($query) < 2) {
    echo json_encode(['results' => [], 'query' => $query]);
    exit;
}

$pdo = hub_db();
$results = [];

// Search riders
if ($type === 'all' || $type === 'riders') {
    $stmt = $pdo->prepare("
        SELECT r.id, r.firstname, r.lastname, c.name as club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE CONCAT(r.firstname, ' ', r.lastname) LIKE ?
           OR r.firstname LIKE ?
           OR r.lastname LIKE ?
        ORDER BY
            CASE
                WHEN CONCAT(r.firstname, ' ', r.lastname) LIKE ? THEN 1
                WHEN r.firstname LIKE ? THEN 2
                ELSE 3
            END,
            r.lastname, r.firstname
        LIMIT ?
    ");

    $searchPattern = "%{$query}%";
    $startPattern = "{$query}%";
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $startPattern, $startPattern, $limit]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'id' => $row['id'],
            'type' => 'rider',
            'name' => $row['firstname'] . ' ' . $row['lastname'],
            'meta' => $row['club_name'] ?? '',
            'initials' => strtoupper(substr($row['firstname'], 0, 1))
        ];
    }
}

// Search clubs
if ($type === 'all' || $type === 'clubs') {
    $remainingLimit = $limit - count($results);
    if ($remainingLimit > 0) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, COUNT(r.id) as member_count
            FROM clubs c
            LEFT JOIN riders r ON c.id = r.club_id
            WHERE c.name LIKE ?
            GROUP BY c.id
            ORDER BY
                CASE WHEN c.name LIKE ? THEN 1 ELSE 2 END,
                c.name
            LIMIT ?
        ");

        $searchPattern = "%{$query}%";
        $startPattern = "{$query}%";
        $stmt->execute([$searchPattern, $startPattern, $remainingLimit]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'id' => $row['id'],
                'type' => 'club',
                'name' => $row['name'],
                'meta' => $row['member_count'] . ' medlemmar',
                'initials' => strtoupper(substr($row['name'], 0, 2))
            ];
        }
    }
}

echo json_encode([
    'results' => $results,
    'query' => $query,
    'type' => $type,
    'count' => count($results)
]);
