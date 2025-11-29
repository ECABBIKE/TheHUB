<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../modules/riders/RiderModel.php';

$model = new RiderModel();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    api_error(400, 'Missing or invalid id');
}

try {
    $rider = $model->find($id);
    if (!$rider) {
        api_error(404, 'Rider not found');
    }
    api_ok($rider);
} catch (Throwable $e) {
    api_error(500, 'Internal error fetching rider');
}
