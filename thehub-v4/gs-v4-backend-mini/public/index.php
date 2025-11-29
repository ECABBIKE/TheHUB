<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../core/Router.php';

$router = new Router();
try {
    $router->dispatch();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Application error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}
