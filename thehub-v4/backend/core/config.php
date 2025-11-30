<?php
// backend/core/config.php

// DB credentials – your existing TheHUB DB
define('DB_HOST', 'localhost');
define('DB_NAME', 'u994733455_thehub');
define('DB_USER', 'u994733455_rogerthat');
define('DB_PASS', 'staggerMYnagger987!');
define('DB_CHARSET', 'utf8mb4');

// Base URL for backend, relative to domain root
// This assumes app ligger på /thehub-v4/
define('BACKEND_BASE_URL', '/thehub-v4/backend');

function url(string $path = ''): string
{
    $base = rtrim(BACKEND_BASE_URL, '/');
    $path = ltrim($path, '/');
    return $base . '/' . $path;
}
