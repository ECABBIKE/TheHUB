<?php

class Router
{
    public function dispatch(): void
    {
        $module = $_GET['module'] ?? 'riders';
        $action = $_GET['action'] ?? 'index';

        $modulePath = __DIR__ . '/../modules/' . $module . '/' . ucfirst($module) . 'Controller.php';

        if (!file_exists($modulePath)) {
            http_response_code(404);
            echo "Module not found";
            return;
        }

        require_once $modulePath;

        $controllerClass = ucfirst($module) . 'Controller';
        $controller = new $controllerClass();

        if (!method_exists($controller, $action)) {
            http_response_code(404);
            echo "Action not found";
            return;
        }

        $controller->{$action}();
    }
}
