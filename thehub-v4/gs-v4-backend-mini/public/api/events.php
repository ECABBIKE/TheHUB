<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../modules/events/EventModel.php';

$model = new EventModel();

try {
    $events = $model->all();
    api_ok($events);
} catch (Throwable $e) {
    api_error(500, 'Internal error fetching events');
}
