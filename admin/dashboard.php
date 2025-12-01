<?php
/**
 * Admin Dashboard - Unified V3 Design System
 */
require_once __DIR__ . '/../config.php';

global $pdo;

// Get statistics
$stats = [];
try {
    $stats['riders'] = $pdo->query("SELECT COUNT(*) FROM riders")->fetchColumn();
    $stats['events'] = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $stats['clubs'] = $pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn();
    $stats['series'] = $pdo->query("SELECT COUNT(*) FROM series")->fetchColumn();
    $stats['upcoming'] = $pdo->query("SELECT COUNT(*) FROM events WHERE date >= CURDATE()")->fetchColumn();
    $stats['results'] = $pdo->query("SELECT COUNT(*) FROM results")->fetchColumn();
} catch (Exception $e) {
    $stats = ['riders' => 0, 'events' => 0, 'clubs' => 0, 'series' => 0, 'upcoming' => 0, 'results' => 0];
}

// Get recent events
$recentEvents = [];
try {
    $recentEvents = $pdo->query("
        SELECT e.id, e.name, e.date, e.location, s.name as series_name
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        ORDER BY e.date DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentEvents = [];
}

// Page config
$page_title = 'Dashboard';
$breadcrumbs = [
    ['label' => 'Dashboard']
];

// Include unified layout (uses same layout as public site)
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Stats Grid -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="stat-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="stat-value"><?= number_format($stats['riders']) ?></div>
        <div class="stat-label">Deltagare</div>
    </div>

    <div class="admin-stat-card">
        <div class="stat-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
        </div>
        <div class="stat-value"><?= number_format($stats['events']) ?></div>
        <div class="stat-label">Events</div>
    </div>

    <div class="admin-stat-card">
        <div class="stat-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20v-6M6 20V10M18 20V4"/></svg>
        </div>
        <div class="stat-value"><?= number_format($stats['upcoming']) ?></div>
        <div class="stat-label">Kommande events</div>
    </div>

    <div class="admin-stat-card">
        <div class="stat-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
        </div>
        <div class="stat-value"><?= number_format($stats['series']) ?></div>
        <div class="stat-label">Serier</div>
    </div>

    <div class="admin-stat-card">
        <div class="stat-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
        </div>
        <div class="stat-value"><?= number_format($stats['clubs']) ?></div>
        <div class="stat-label">Klubbar</div>
    </div>

    <div class="admin-stat-card">
        <div class="stat-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="stat-value"><?= number_format($stats['results']) ?></div>
        <div class="stat-label">Resultat</div>
    </div>
</div>

<!-- Quick Actions & Recent Events -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: var(--space-lg);">

    <!-- Quick Actions -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Snabbåtgärder</h2>
        </div>
        <div class="admin-card-body">
            <div style="display: flex; flex-wrap: wrap; gap: var(--space-md);">
                <a href="/admin/event-create.php" class="btn-admin btn-admin-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    Skapa event
                </a>
                <a href="/admin/import-uci.php" class="btn-admin btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                    Importera deltagare
                </a>
                <a href="/admin/import-results.php" class="btn-admin btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" x2="12" y1="18" y2="12"/><line x1="9" x2="15" y1="15" y2="15"/></svg>
                    Importera resultat
                </a>
                <a href="/admin/find-duplicates.php" class="btn-admin btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    Hitta dubbletter
                </a>
                <a href="/admin/import-history.php" class="btn-admin btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
                    Import-historik
                </a>
            </div>
        </div>
    </div>

    <!-- Recent Events -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Senaste events</h2>
            <a href="/admin/events.php" class="btn-admin btn-admin-sm btn-admin-secondary">Visa alla</a>
        </div>
        <div class="admin-card-body" style="padding: 0;">
            <?php if (empty($recentEvents)): ?>
                <div class="admin-empty-state" style="padding: var(--space-xl);">
                    <p>Inga events ännu</p>
                    <a href="/admin/event-create.php" class="btn-admin btn-admin-primary">Skapa första eventet</a>
                </div>
            <?php else: ?>
                <div class="admin-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Datum</th>
                                <th>Plats</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentEvents as $event): ?>
                                <tr>
                                    <td>
                                        <a href="/admin/event-edit.php?id=<?= $event['id'] ?>" style="color: var(--color-accent); text-decoration: none; font-weight: 500;">
                                            <?= htmlspecialchars($event['name']) ?>
                                        </a>
                                        <?php if ($event['series_name']): ?>
                                            <div style="font-size: var(--text-xs); color: var(--color-text-secondary);">
                                                <?= htmlspecialchars($event['series_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('Y-m-d', strtotime($event['date'])) ?></td>
                                    <td><?= htmlspecialchars($event['location'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
