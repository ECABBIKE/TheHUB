<?php

class Router
{
    public function dispatch(): void
    {
        $module = $_GET['module'] ?? 'riders';
        $action = $_GET['action'] ?? 'index';

        $controllerMap = [
            'riders' => 'RiderController.php',
            'events' => 'EventController.php',
        ];

        if (!isset($controllerMap[$module])) {
            http_response_code(404);
            echo "Module not found";
            return;
        }

        $modulePath = __DIR__ . '/../modules/' . $module . '/' . $controllerMap[$module];

        if (!file_exists($modulePath)) {
            http_response_code(404);
            echo "Module controller missing";
            return;
        }

        require_once $modulePath;

        $className = pathinfo($controllerMap[$module], PATHINFO_FILENAME);
        $controller = new $className();

        if (!method_exists($controller, $action)) {
            http_response_code(404);
            echo "Action not found";
            return;
        }

        $controller->{$action}();
    }
}
