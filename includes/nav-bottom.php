<?php
/**
 * Bottom Navigation - Visas på mobil
 */

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentSection = $_GET['section'] ?? $currentPage;

$navItems = [
    ['id' => 'calendar', 'label' => 'Kalender', 'url' => '/calendar.php', 'icon' => 'calendar'],
    ['id' => 'results', 'label' => 'Resultat', 'url' => '/results.php', 'icon' => 'flag'],
    ['id' => 'series', 'label' => 'Serier', 'url' => '/series.php', 'icon' => 'trophy'],
    ['id' => 'database', 'label' => 'Databas', 'url' => '/database.php', 'icon' => 'search'],
    ['id' => 'ranking', 'label' => 'Ranking', 'url' => '/ranking.php', 'icon' => 'trending-up'],
];

// SVG ikoner
$icons = [
    'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>',
    'flag' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>',
    'trophy' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
    'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
    'trending-up' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
];
?>

<nav class="nav-bottom" role="navigation" aria-label="Huvudnavigering">
    <?php foreach ($navItems as $item): ?>
        <?php $isActive = ($currentSection === $item['id'] || strpos($currentPage, $item['id']) !== false); ?>
        <a href="<?= $item['url'] ?>"
           class="nav-bottom-item <?= $isActive ? 'is-active' : '' ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="nav-bottom-icon"><?= $icons[$item['icon']] ?></span>
            <span class="nav-bottom-label"><?= $item['label'] ?></span>
        </a>
    <?php endforeach; ?>
</nav>
