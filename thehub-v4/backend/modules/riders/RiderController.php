<?php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/RiderModel.php';
require_once __DIR__ . '/../../core/Controller.php';

class RiderController extends Controller
{
    public function index(): void
    {
        $riders = RiderModel::all(100);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'data' => $riders,
        ]);
    }
}
