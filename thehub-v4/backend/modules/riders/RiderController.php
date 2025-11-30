<?php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/Controller.php';
require_once __DIR__ . '/RiderModel.php';

class RiderController extends Controller
{
    public function index(): void
    {
        $riders = RiderModel::all(250);
        $this->json(['ok' => true, 'data' => $riders]);
    }

    public function show(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            $this->json(['ok' => false, 'error' => 'Missing id'], 400);
            return;
        }

        $rider = RiderModel::find($id);
        if (!$rider) {
            $this->json(['ok' => false, 'error' => 'Not found'], 404);
            return;
        }

        $this->json(['ok' => true, 'data' => $rider]);
    }
}
