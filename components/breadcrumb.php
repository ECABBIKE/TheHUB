<?php
/**
 * TheHUB Breadcrumb Component
 * Shows current location in the app
 */

$section = $pageInfo['section'] ?? $pageSection ?? 'home';
$subpage = $pageInfo['subpage'] ?? null;
$title = $pageInfo['title'] ?? $pageTitle ?? 'TheHUB';

// Section labels
$sectionLabels = [
    'home' => 'Hem',
    'calendar' => 'Kalender',
    'events' => 'Kalender',
    'results' => 'Resultat',
    'series' => 'Serier',
    'database' => 'Databas',
    'riders' => 'Deltagare',
    'clubs' => 'Klubbar',
    'ranking' => 'Ranking',
    'profile' => 'Min Profil'
];

$sectionLabel = $sectionLabels[$section] ?? ucfirst($section);
$showBreadcrumb = $section !== 'home';
?>
<?php if ($showBreadcrumb): ?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <ol class="breadcrumb-list">
        <li class="breadcrumb-item">
            <a href="/" class="breadcrumb-link">
                <i data-lucide="home" class="breadcrumb-icon"></i>
                <span class="sr-only">Hem</span>
            </a>
        </li>
        <?php if ($subpage && $subpage !== 'index'): ?>
        <li class="breadcrumb-item">
            <a href="/<?= $section ?>" class="breadcrumb-link"><?= $sectionLabel ?></a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
            <?= h($title) ?>
        </li>
        <?php else: ?>
        <li class="breadcrumb-item active" aria-current="page">
            <?= $sectionLabel ?>
        </li>
        <?php endif; ?>
    </ol>
</nav>
<?php endif; ?>
