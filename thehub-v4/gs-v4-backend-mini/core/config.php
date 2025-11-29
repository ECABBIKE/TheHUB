<?php
// core/config.php
// Adjust these for your webhotel DB

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'u994733455_thehub'));
define('DB_USER', env('DB_USER', 'u994733455_rogerthat'));
define('DB_PASS', env('DB_PASS', 'staggerMYnagger987!'));

define('BASE_URL', '/gs-v4-backend-mini/public');

function url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/');
    $path = ltrim($path, '/');
    return $base . '/' . $path;
}
