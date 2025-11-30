<?php
// backend/modules/api/ApiController.php
require_once __DIR__ . '/../../core/Controller.php';

final class ApiController extends Controller
{
    public function index(): void
    {
        $this->render(__DIR__ . '/views/index.php', []);
    }
}
