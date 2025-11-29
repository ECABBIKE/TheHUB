<?php
// core/Router.php

final class Router
{
    public function dispatch(): void
    {
        $module = $_GET['module'] ?? 'riders';
        $action = $_GET['action'] ?? 'index';

        switch ($module) {

            // Riders module
            case 'riders':
                require_once __DIR__ . '/../modules/riders/RiderController.php';
                $controller = new RiderController();
                break;

            // Cyclists placeholder module
            case 'cyclists':
                require_once __DIR__ . '/../modules/cyclists/CyclistController.php';
                $controller = new CyclistController();
                break;

            // Events module (STEG 2)
            case 'events':
                require_once __DIR__ . '/../modules/events/EventController.php';
                $controller = new EventController();
                break;

            // API Explorer module (STEG 4)
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
