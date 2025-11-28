<?php
require_once __DIR__ . '/config.php';

function hub_get_current_page(): array {
    $raw = trim($_GET['page'] ?? '', '/');

    if ($raw === '' || $raw === 'index.php') {
        return ['page' => 'dashboard', 'params' => [], 'file' => HUB_V3_ROOT . '/pages/dashboard.php'];
    }

    $segments = explode('/', $raw);
    $page = $segments[0];

    // Single-item pages (rider/123, event/456, club/789, series/123)
    $singlePages = ['rider', 'event', 'club'];
    if (in_array($page, $singlePages) && isset($segments[1])) {
        return [
            'page' => $page,
            'params' => ['id' => $segments[1]],
            'file' => HUB_V3_ROOT . '/pages/' . $page . '.php'
        ];
    }

    // Series single page (series/123 -> series-single.php)
    if ($page === 'series' && isset($segments[1]) && is_numeric($segments[1])) {
        return [
            'page' => 'series-single',
            'params' => ['id' => $segments[1]],
            'file' => HUB_V3_ROOT . '/pages/series-single.php'
        ];
    }

    if (in_array($page, HUB_VALID_PAGES)) {
        return ['page' => $page, 'params' => [], 'file' => HUB_V3_ROOT . '/pages/' . $page . '.php'];
    }

    return ['page' => '404', 'params' => ['requested' => $raw], 'file' => HUB_V3_ROOT . '/pages/404.php'];
}

function hub_is_ajax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function hub_is_nav_active(string $navId, string $currentPage): bool {
    if ($navId === 'dashboard' && $currentPage === 'dashboard') return true;
    if ($navId === 'series' && in_array($currentPage, ['series', 'series-single'])) return true;
    if ($navId === 'results' && in_array($currentPage, ['results', 'event'])) return true;
    if ($navId === 'riders' && in_array($currentPage, ['riders', 'rider'])) return true;
    if ($navId === 'clubs' && in_array($currentPage, ['clubs', 'club'])) return true;
    if ($navId === 'ranking' && $currentPage === 'ranking') return true;
    return $navId === $currentPage;
}
