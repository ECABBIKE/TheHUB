<?php
// Standalone debug script - doesn't use config.php to avoid dependency issues
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = ['status' => 'starting'];

try {
    $response['step'] = 'connecting to database';

    // Direct database connection
    $host = 'localhost';
    $dbname = 'u994733455_thehub';
    $username = 'u994733455_rogerthat';
    $password = 'staggerMYnagger987!';

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $response['database'] = 'connected';

    $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 234;
    $response['event_id'] = $eventId;

    // Get event info
    $stmt = $pdo->prepare("SELECT id, name, stage_names FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        $response['error'] = "Event $eventId not found";
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }

    $response['event_name'] = $event['name'];
    $response['stage_names'] = $event['stage_names'] ? json_decode($event['stage_names'], true) : null;

    // Check segments
    $response['segments'] = [];
    for ($i = 1; $i <= 15; $i++) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM results
            WHERE event_id = ? AND ss$i IS NOT NULL AND ss$i != ''
        ");
        $stmt->execute([$eventId]);
        $result = $stmt->fetch();
        $count = $result['count'];

        if ($count > 0) {
            $response['segments']["ss$i"] = $count;
        }
    }

    // Get sample data
    $stmt = $pdo->prepare("
        SELECT cyclist_id, class_id, finish_time,
               ss1, ss2, ss3, ss4, ss5, ss6, ss7, ss8, ss9, ss10, ss11, ss12, ss13, ss14, ss15
        FROM results
        WHERE event_id = ?
        LIMIT 3
    ");
    $stmt->execute([$eventId]);
    $samples = $stmt->fetchAll();

    $response['sample_results'] = [];
    foreach ($samples as $sample) {
        $segs = [];
        for ($i = 1; $i <= 15; $i++) {
            if (!empty($sample["ss$i"])) {
                $segs["ss$i"] = $sample["ss$i"];
            }
        }
        $response['sample_results'][] = [
            'cyclist_id' => $sample['cyclist_id'],
            'class_id' => $sample['class_id'],
            'finish_time' => $sample['finish_time'],
            'segments' => $segs
        ];
    }

    $response['status'] = 'success';

} catch (PDOException $e) {
    $response['error'] = 'Database error';
    $response['message'] = $e->getMessage();
} catch (Exception $e) {
    $response['error'] = 'General error';
    $response['message'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
