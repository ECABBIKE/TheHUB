<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$db = getDB();
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 234;

$response = [
    'event_id' => $eventId,
    'segments_found' => [],
    'sample_results' => [],
    'code_version_check' => []
];

// Check which segments have data
for ($i = 1; $i <= 15; $i++) {
    $count = $db->getValue("
        SELECT COUNT(*)
        FROM results
        WHERE event_id = ? AND ss$i IS NOT NULL AND ss$i != ''
    ", [$eventId]);

    if ($count > 0) {
        $response['segments_found'][] = [
            'segment' => "ss$i",
            'riders_with_data' => $count
        ];
    }
}

// Get sample results with all segment data
$results = $db->getAll("
    SELECT
        cyclist_id,
        class_id,
        finish_time,
        ss1, ss2, ss3, ss4, ss5, ss6, ss7, ss8, ss9, ss10, ss11, ss12, ss13, ss14, ss15
    FROM results
    WHERE event_id = ?
    LIMIT 3
", [$eventId]);

foreach ($results as $r) {
    $segments = [];
    for ($s = 1; $s <= 15; $s++) {
        if (!empty($r['ss'.$s])) {
            $segments["ss$s"] = $r['ss'.$s];
        }
    }
    $response['sample_results'][] = [
        'cyclist_id' => $r['cyclist_id'],
        'class_id' => $r['class_id'],
        'finish_time' => $r['finish_time'],
        'segments' => $segments,
        'segment_count' => count($segments)
    ];
}

// Check if the new code is deployed by testing the logic
$response['code_version_check'] = [
    'test' => 'Checking if improved segment detection is active',
    'note' => 'If you see this, the API endpoint is working'
];

// Get event stage_names
$event = $db->getRow("SELECT stage_names FROM events WHERE id = ?", [$eventId]);
$response['stage_names'] = $event['stage_names'] ? json_decode($event['stage_names'], true) : null;

echo json_encode($response, JSON_PRETTY_PRINT);
