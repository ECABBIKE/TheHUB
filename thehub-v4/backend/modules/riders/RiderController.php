<?php
// backend/modules/riders/RiderController.php
require_once __DIR__ . '/../../core/Controller.php';
require_once __DIR__ . '/RiderModel.php';

final class RiderController extends Controller
{
    private RiderModel $model;

    public function __construct()
    {
        $this->model = new RiderModel();
    }

    public function index(): void
    {
        $search     = $_GET['search']     ?? null;
        $discipline = $_GET['discipline'] ?? null;
        $active     = $_GET['active']     ?? null;
        $club       = $_GET['club']       ?? null;

        $riders = $this->model->all($search, $discipline, $active, $club);
        $clubs  = $this->model->clubs();

        $this->render(__DIR__ . '/views/list.php', compact('riders', 'clubs', 'search', 'discipline', 'active', 'club'));
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $rider = $this->model->find($id);

        if (!$rider) {
            http_response_code(404);
            echo "Rider not found";
            return;
        }

        $this->render(__DIR__ . '/views/view.php', compact('rider'));
    }
}
