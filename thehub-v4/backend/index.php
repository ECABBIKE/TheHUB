<?php
// TheHUB V4 backend entrypoint

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Controller.php';
require_once __DIR__ . '/core/Router.php';

if (!class_exists('Router')) {
    http_response_code(500);
    echo "<h1>Backend error</h1><pre>Router class missing</pre>";
    exit;
}

$router = new Router();

try {
    $router->dispatch();
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Backend error</h1>";
    echo "<pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
}
