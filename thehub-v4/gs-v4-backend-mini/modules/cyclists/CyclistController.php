<?php
// modules/cyclists/CyclistController.php
require_once __DIR__ . '/../../core/Controller.php';
require_once __DIR__ . '/CyclistModel.php';

final class CyclistController extends Controller
{
    private CyclistModel $model;

    public function __construct()
    {
        $this->model = new CyclistModel();
    }

    public function index(): void
    {
        $cyclists = $this->model->all();
        $this->render(__DIR__ . '/views/list.php', compact('cyclists'));
    }

    public function create(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'first_name' => trim($_POST['first_name'] ?? ''),
                'last_name'  => trim($_POST['last_name'] ?? ''),
                'uci_id'     => trim($_POST['uci_id'] ?? ''),
                'club'       => trim($_POST['club'] ?? ''),
            ];
            $this->model->create($data);
            header('Location: ?module=cyclists');
            exit;
        }

        $cyclist = null;
        $this->render(__DIR__ . '/views/form.php', compact('cyclist'));
    }

    public function edit(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $cyclist = $this->model->find($id);
        if (!$cyclist) {
            http_response_code(404);
            echo 'Cyclist not found';
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'first_name' => trim($_POST['first_name'] ?? ''),
                'last_name'  => trim($_POST['last_name'] ?? ''),
                'uci_id'     => trim($_POST['uci_id'] ?? ''),
                'club'       => trim($_POST['club'] ?? ''),
            ];
            $this->model->update($id, $data);
            header('Location: ?module=cyclists');
            exit;
        }

        $this->render(__DIR__ . '/views/form.php', compact('cyclist'));
    }

    public function delete(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $this->model->delete($id);
        }
        header('Location: ?module=cyclists');
        exit;
    }
}
