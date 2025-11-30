<?php
// backend/core/Router.php

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
            case 'events':
                require_once __DIR__ . '/../modules/events/EventController.php';
                $controller = new EventController();
                break;
            case 'api':
                require_once __DIR__ . '/../modules/api/ApiController.php';
                $controller = new ApiController();
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
