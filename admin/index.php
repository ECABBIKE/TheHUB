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
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body>
    <div class="admin-container">
        <nav class="admin-nav">
            <div class="nav-header">
                <h1>TheHUB</h1>
                <p class="nav-user">Inloggad: <?= h($currentAdmin['name']) ?></p>
            </div>
            <ul>
                <li><a href="/admin/index.php" class="active">Dashboard</a></li>
                <li><a href="/admin/cyclists.php">Cyklister</a></li>
                <li><a href="/admin/events.php">Tävlingar</a></li>
                <li><a href="/admin/results.php">Resultat</a></li>
                <li><a href="/admin/import.php">Import</a></li>
                <li><a href="/admin/logout.php">Logga ut</a></li>
            </ul>
            <div class="nav-footer">
                <a href="/public/index.php" target="_blank">Visa publik sida</a>
            </div>
        </nav>

        <main class="admin-content">
            <h1>Dashboard</h1>

            <?php $flash = getFlash(); if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?>">
                    <?= h($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($stats['cyclists']) ?></div>
                    <div class="stat-label">Cyklister</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($stats['clubs']) ?></div>
                    <div class="stat-label">Klubbar</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($stats['events']) ?></div>
                    <div class="stat-label">Tävlingar</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($stats['results']) ?></div>
                    <div class="stat-label">Resultat</div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="dashboard-section">
                    <h2>Senaste tävlingarna</h2>
                    <?php if (empty($recentEvents)): ?>
                        <p class="no-data">Inga tävlingar ännu</p>
                    <?php else: ?>
                        <table class="data-table">
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
                                        <td><a href="/admin/events.php?id=<?= $event['id'] ?>"><?= h($event['name']) ?></a></td>
                                        <td><?= formatDate($event['event_date'], 'd M Y') ?></td>
                                        <td><?= h($event['location']) ?></td>
                                        <td><span class="badge badge-<?= $event['status'] ?>"><?= h($event['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="dashboard-section">
                    <h2>Senaste importer</h2>
                    <?php if (empty($recentImports)): ?>
                        <p class="no-data">Inga importer ännu</p>
                    <?php else: ?>
                        <table class="data-table">
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
                                        <td><?= h($import['import_type']) ?></td>
                                        <td><?= h($import['filename']) ?></td>
                                        <td>
                                            <span class="text-success"><?= $import['records_success'] ?></span> /
                                            <span class="text-danger"><?= $import['records_failed'] ?></span> av
                                            <?= $import['records_total'] ?>
                                        </td>
                                        <td><?= formatDate($import['created_at'], 'd M H:i') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
