<?php
// core/config.php
// Adjust these for your webhotel DB
define('DB_HOST', 'localhost');
define('DB_NAME', 'gs_v4_test');
define('DB_USER', 'db_user');
define('DB_PASS', 'db_password');
define('DB_CHARSET', 'utf8mb4');

// Base URL relative to document root, e.g. '/gs-v4-backend-mini/public'
define('BASE_URL', '/gs-v4-backend-mini/public');

function url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/');
    $path = ltrim($path, '/');
    return $base . '/' . $path;
}
