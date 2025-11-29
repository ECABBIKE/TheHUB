<?php
// gs-v4-backend-mini · core/config.php
// Using your real database (u994733455_thehub)

define('DB_HOST', 'localhost');
define('DB_NAME', 'u994733455_thehub');
define('DB_USER', 'u994733455_rogerthat');
define('DB_PASS', 'staggerMYnagger987!');
define('DB_CHARSET', 'utf8mb4');

// Base URL for where project is installed
// Adjust ONLY if you change folder location
define('BASE_URL', '/thehub/thehub-v4/gs-v4-backend-mini/public');

function url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/');
    $path = ltrim($path, '/');
    return $base . '/' . $path;
}
