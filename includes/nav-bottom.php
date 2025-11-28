<?php
/**
 * Bottom Navigation - Visas på mobil (ersätter sidebar)
 */

$currentPage = basename($_SERVER['PHP_SELF']);
$currentPath = $_SERVER['PHP_SELF'];

$navItems = [
    ['id' => 'index', 'label' => 'Hem', 'url' => '/', 'icon' => 'home'],
    ['id' => 'events', 'label' => 'Kalender', 'url' => '/events.php', 'icon' => 'calendar'],
    ['id' => 'results', 'label' => 'Resultat', 'url' => '/results.php', 'icon' => 'trophy'],
    ['id' => 'series', 'label' => 'Serier', 'url' => '/series.php', 'icon' => 'award'],
    ['id' => 'ranking', 'label' => 'Ranking', 'url' => '/ranking/', 'icon' => 'trending-up'],
];

// SVG ikoner
$icons = [
    'home' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>',
    'trophy' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
    'award' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
    'trending-up' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
];
?>

<nav class="nav-bottom" role="navigation" aria-label="Huvudnavigering">
    <?php foreach ($navItems as $item): ?>
        <?php
        // Determine if this item is active
        $isActive = false;
        if ($item['id'] === 'index' && ($currentPage === 'index.php' || $currentPath === '/')) {
            $isActive = true;
        } elseif ($item['id'] === 'ranking' && strpos($currentPath, '/ranking/') !== false) {
            $isActive = true;
        } elseif ($item['id'] !== 'index' && $item['id'] !== 'ranking') {
            $isActive = ($currentPage === $item['id'] . '.php');
        }
        ?>
        <a href="<?= $item['url'] ?>"
           class="nav-bottom-item <?= $isActive ? 'is-active' : '' ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="nav-bottom-icon"><?= $icons[$item['icon']] ?></span>
            <span class="nav-bottom-label"><?= $item['label'] ?></span>
        </a>
    <?php endforeach; ?>
</nav>
