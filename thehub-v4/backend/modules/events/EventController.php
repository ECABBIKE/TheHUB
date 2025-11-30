<?php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/EventModel.php';
require_once __DIR__ . '/../../core/Controller.php';

class EventController extends Controller
{
    public function index(): void
    {
        $events = EventModel::all(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'data' => $events,
        ]);
    }
}
