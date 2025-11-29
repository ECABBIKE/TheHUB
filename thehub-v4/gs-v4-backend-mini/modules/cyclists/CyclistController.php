<?php
// placeholder cyclists module
require_once __DIR__ . '/../../core/Controller.php';

final class CyclistController extends Controller
{
    public function index(): void
    {
        $this->render(__DIR__ . '/views/list.php', []);
    }
}
