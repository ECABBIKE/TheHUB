<?php
// Bootstrap backend
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Controller.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/config.php';

$router = new Router();

try {
    $router->dispatch();
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Backend error</h1>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
