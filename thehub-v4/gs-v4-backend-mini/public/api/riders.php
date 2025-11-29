<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../modules/riders/RiderModel.php';

$model = new RiderModel();

$search     = $_GET['search']     ?? null;
$discipline = $_GET['discipline'] ?? null;
$active     = $_GET['active']     ?? null;
$club       = $_GET['club']       ?? null;

try {
    $riders = $model->all($search, $discipline, $active, $club);
    api_ok($riders);
} catch (Throwable $e) {
    api_error(500, 'Internal error fetching riders');
}
