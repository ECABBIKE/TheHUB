<?php
/**
 * Admin Router
 */

function admin_parse_route(): array {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = trim($path, '/');

    // Remove v3/admin prefix
    $path = preg_replace('#^v3/admin/?#', '', $path);

    if ($path === '' || $path === 'v3/admin') {
        return [
            'section' => 'dashboard',
            'action' => 'index',
            'id' => null,
            'file' => __DIR__ . '/pages/dashboard.php'
        ];
    }

    $segments = explode('/', $path);
    $section = $segments[0] ?? 'dashboard';
    $action = $segments[1] ?? 'index';
    $id = $segments[2] ?? null;
    $params = [];

    // Special handling for users section (users/123/assignments)
    if ($section === 'users' && isset($segments[1]) && is_numeric($segments[1])) {
        $params['id'] = (int) $segments[1];
        $action = $segments[2] ?? 'edit';
        $id = $segments[3] ?? null;
    }
    // If second segment is numeric, it's an ID for edit
    elseif (isset($segments[1]) && is_numeric($segments[1])) {
        $id = $segments[1];
        $action = 'edit';
    }

    // Map sections to files
    $validSections = ['dashboard', 'events', 'series', 'riders', 'clubs', 'config', 'import', 'system', 'users', 'images'];

    if (!in_array($section, $validSections)) {
        $section = 'dashboard';
        $action = 'index';
    }

    if ($section === 'dashboard') {
        $file = __DIR__ . '/pages/dashboard.php';
    } else {
        $file = __DIR__ . '/pages/' . $section . '/' . $action . '.php';
        if (!file_exists($file)) {
            $file = __DIR__ . '/pages/' . $section . '/index.php';
        }
    }

    return compact('section', 'action', 'id', 'file', 'params');
}

function admin_is_active(string $section): bool {
    global $route;
    return ($route['section'] ?? '') === $section;
}

function admin_url(string $path = ''): string {
    return HUB_V3_URL . '/admin' . ($path ? '/' . ltrim($path, '/') : '');
}
