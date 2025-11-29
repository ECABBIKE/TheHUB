<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../modules/events/EventModel.php';

$model = new EventModel();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    api_error(400, 'Missing or invalid id');
}

try {
    $event = $model->find($id);
    if (!$event) {
        api_error(404, 'Event not found');
    }
    api_ok($event);
} catch (Throwable $e) {
    api_error(500, 'Internal error fetching event');
}
