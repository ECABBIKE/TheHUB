<?php
// core/config.php
// Adjust these for your webhotel DB

define('DB_HOST', 'localhost'); // ändra vid behov
define('DB_NAME', 'u994733455_thehub'); 
define('DB_USER', 'u994733455_rogerthat'); 
define('DB_PASS', 'staggerMYnagger987!');

define('DB_CHARSET', 'utf8mb4');

define('BASE_URL', '/thehub/thehub-v4/gs-v4-backend-mini/public');

function url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/');
    $path = ltrim($path, '/');
    return $base . '/' . $path;
}
