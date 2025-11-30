<?php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/Controller.php';
require_once __DIR__ . '/EventModel.php';

class EventController extends Controller
{
    public function index(): void
    {
        $events = EventModel::all(200);
        $this->json(['ok' => true, 'data' => $events]);
    }

    public function show(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            $this->json(['ok' => false, 'error' => 'Missing id'], 400);
            return;
        }

        $event = EventModel::find($id);
        if (!$event) {
            $this->json(['ok' => false, 'error' => 'Not found'], 404);
            return;
        }

        $this->json(['ok' => true, 'data' => $event]);
    }
}
