<?php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/Controller.php';
require_once __DIR__ . '/ResultsModel.php';

class ResultsController extends Controller
{
    public function event(): void
    {
        $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        if ($eventId <= 0) {
            $this->json(['ok' => false, 'error' => 'Missing event_id'], 400);
            return;
        }

        $rows = ResultsModel::forEvent($eventId);
        $this->json(['ok' => true, 'data' => $rows]);
    }
}
