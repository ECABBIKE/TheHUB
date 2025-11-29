<?php
// core/Router.php

final class Router
{
    public function dispatch(): void
    {
        $module = $_GET['module'] ?? 'riders';
        $action = $_GET['action'] ?? 'index';

        switch ($module) {
            case 'riders':
                require_once __DIR__ . '/../modules/riders/RiderController.php';
                $controller = new RiderController();
                break;
            case 'cyclists':
                require_once __DIR__ . '/../modules/cyclists/CyclistController.php';
                $controller = new CyclistController();
                break;
            default:
                http_response_code(404);
                echo 'Unknown module';
                return;
        }

        if (!method_exists($controller, $action)) {
            http_response_code(404);
            echo 'Unknown action';
            return;
        }

        $controller->{$action}();
    }
}
