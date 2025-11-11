<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$db = getDB();

// Get statistics
$stats = [
    'cyclists' => $db->getRow("SELECT COUNT(*) as count FROM cyclists WHERE active = 1")['count'] ?? 0,
    'clubs' => $db->getRow("SELECT COUNT(*) as count FROM clubs WHERE active = 1")['count'] ?? 0,
    'events' => $db->getRow("SELECT COUNT(*) as count FROM events")['count'] ?? 0,
    'results' => $db->getRow("SELECT COUNT(*) as count FROM results")['count'] ?? 0,
    'upcoming_events' => $db->getRow("SELECT COUNT(*) as count FROM events WHERE status = 'upcoming'")['count'] ?? 0
];

// Recent events
$recentEvents = $db->getAll(
    "SELECT id, name, event_date, location, status
     FROM events
     ORDER BY event_date DESC
     LIMIT 5"
);

// Recent imports
$recentImports = $db->getAll(
    "SELECT import_type, filename, records_total, records_success, records_failed, created_at
     FROM import_logs
     ORDER BY created_at DESC
     LIMIT 5"
);

$pageTitle = 'Dashboard';
$currentAdmin = getCurrentAdmin();
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB Admin</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <div class="gs-admin-layout">
        <!-- Sidebar -->
        <aside class="gs-admin-sidebar">
            <div class="gs-admin-sidebar-header">
                <h1 class="gs-admin-sidebar-title">TheHUB</h1>
                <p class="gs-text-secondary gs-text-sm">Inloggad: <?= h($currentAdmin['name']) ?></p>
            </div>
            <nav>
                <ul class="gs-admin-sidebar-nav">
                    <li><a href="/admin/index.php" class="gs-admin-sidebar-link active">Dashboard</a></li>
                    <li><a href="/admin/cyclists.php" class="gs-admin-sidebar-link">Cyklister</a></li>
                    <li><a href="/admin/events.php" class="gs-admin-sidebar-link">Tävlingar</a></li>
                    <li><a href="/admin/results.php" class="gs-admin-sidebar-link">Resultat</a></li>
                    <li><a href="/admin/import.php" class="gs-admin-sidebar-link">Import</a></li>
                    <li><a href="/admin/logout.php" class="gs-admin-sidebar-link">Logga ut</a></li>
                </ul>
            </nav>
            <div style="padding: var(--gs-space-lg); margin-top: auto; border-top: 1px solid var(--gs-gray);">
                <a href="/public/index.php" target="_blank" class="gs-text-secondary gs-text-sm" style="text-decoration: none;">
                    Visa publik sida →
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="gs-admin-content">
            <h1 class="gs-h1 gs-text-primary gs-mb-lg">Dashboard</h1>

            <?php $flash = getFlash(); if ($flash): ?>
                <div class="gs-alert gs-alert-<?= h($flash['type']) ?> gs-mb-lg">
                    <?= h($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-4 gs-gap-lg gs-mb-xl">
                <div class="gs-stat-card">
                    <div class="gs-stat-number"><?= number_format($stats['cyclists']) ?></div>
                    <div class="gs-stat-label">Cyklister</div>
                </div>
                <div class="gs-stat-card">
                    <div class="gs-stat-number"><?= number_format($stats['clubs']) ?></div>
                    <div class="gs-stat-label">Klubbar</div>
                </div>
                <div class="gs-stat-card">
                    <div class="gs-stat-number"><?= number_format($stats['events']) ?></div>
                    <div class="gs-stat-label">Tävlingar</div>
                </div>
                <div class="gs-stat-card">
                    <div class="gs-stat-number"><?= number_format($stats['results']) ?></div>
                    <div class="gs-stat-label">Resultat</div>
                </div>
            </div>

            <!-- Dashboard Sections -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                <!-- Recent Events -->
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">Senaste tävlingarna</h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (empty($recentEvents)): ?>
                            <p class="gs-text-secondary gs-text-center gs-py-lg">Inga tävlingar ännu</p>
                        <?php else: ?>
                            <table class="gs-table">
                                <thead>
                                    <tr>
                                        <th>Namn</th>
                                        <th>Datum</th>
                                        <th>Plats</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentEvents as $event): ?>
                                        <tr>
                                            <td>
                                                <a href="/admin/events.php?id=<?= $event['id'] ?>" style="color: var(--gs-primary); text-decoration: none;">
                                                    <?= h($event['name']) ?>
                                                </a>
                                            </td>
                                            <td class="gs-text-secondary gs-text-sm"><?= formatDate($event['event_date'], 'd M Y') ?></td>
                                            <td class="gs-text-secondary gs-text-sm"><?= h($event['location']) ?></td>
                                            <td>
                                                <span class="gs-badge gs-badge-<?= $event['status'] === 'completed' ? 'success' : ($event['status'] === 'upcoming' ? 'warning' : 'primary') ?>">
                                                    <?= h($event['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Imports -->
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">Senaste importer</h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (empty($recentImports)): ?>
                            <p class="gs-text-secondary gs-text-center gs-py-lg">Inga importer ännu</p>
                        <?php else: ?>
                            <table class="gs-table">
                                <thead>
                                    <tr>
                                        <th>Typ</th>
                                        <th>Fil</th>
                                        <th>Resultat</th>
                                        <th>Datum</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentImports as $import): ?>
                                        <tr>
                                            <td class="gs-text-sm"><?= h($import['import_type']) ?></td>
                                            <td class="gs-text-secondary gs-text-sm"><?= h($import['filename']) ?></td>
                                            <td class="gs-text-sm">
                                                <span style="color: var(--gs-success);"><?= $import['records_success'] ?></span> /
                                                <span style="color: #dc2626;"><?= $import['records_failed'] ?></span> av
                                                <?= $import['records_total'] ?>
                                            </td>
                                            <td class="gs-text-secondary gs-text-sm"><?= formatDate($import['created_at'], 'd M H:i') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
