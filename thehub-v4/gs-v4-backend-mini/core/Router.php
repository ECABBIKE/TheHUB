<?php
// core/Router.php

final class Router
{
    public function dispatch(): void
    {
        $module = $_GET['module'] ?? 'cyclists';
        $action = $_GET['action'] ?? 'index';

        switch ($module) {
            case 'cyclists':
            default:
                require_once __DIR__ . '/../modules/cyclists/CyclistController.php';
                $controller = new CyclistController();
                break;
        }

        if (!method_exists($controller, $action)) {
            http_response_code(404);
            echo 'Unknown action';
            return;
        }

        $controller->{$action}();
    }
}
