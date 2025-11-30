<?php
// backend/modules/events/EventController.php
require_once __DIR__ . '/../../core/Controller.php';
require_once __DIR__ . '/EventModel.php';

final class EventController extends Controller
{
    private EventModel $model;

    public function __construct()
    {
        $this->model = new EventModel();
    }

    public function index(): void
    {
        $events = $this->model->all();
        $this->render(__DIR__ . '/views/list.php', compact('events'));
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $event = $this->model->find($id);

        if (!$event) {
            http_response_code(404);
            echo "Event not found";
            return;
        }

        $this->render(__DIR__ . '/views/view.php', compact('event'));
    }
}
