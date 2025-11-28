<?php
$pdo = hub_db();

// Get stats
$stats = [];
try {
    $stats['events'] = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $stats['riders'] = $pdo->query("SELECT COUNT(*) FROM riders")->fetchColumn();
    $stats['clubs'] = $pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn();
    $stats['series'] = $pdo->query("SELECT COUNT(*) FROM series WHERE status = 'active'")->fetchColumn();
} catch (Exception $e) {
    $stats = ['events' => 0, 'riders' => 0, 'clubs' => 0, 'series' => 0];
}

// Recent registrations
$recentRegistrations = [];
try {
    $stmt = $pdo->query("
        SELECT er.*,
               CONCAT(r.firstname, ' ', r.lastname) as rider_name,
               e.name as event_name,
               e.date as event_date
        FROM event_registrations er
        JOIN riders r ON er.rider_id = r.id
        JOIN events e ON er.event_id = e.id
        ORDER BY er.created_at DESC
        LIMIT 10
    ");
    $recentRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist or different structure
}

// Upcoming events
$upcomingEvents = [];
try {
    $stmt = $pdo->query("
        SELECT e.*, COUNT(er.id) as reg_count
        FROM events e
        LEFT JOIN event_registrations er ON e.id = er.event_id
        WHERE e.date >= CURDATE()
        GROUP BY e.id
        ORDER BY e.date ASC
        LIMIT 5
    ");
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback
}
?>

<div class="admin-dashboard">

    <!-- Stats -->
    <div class="admin-stats-grid">
        <div class="admin-stat-card">
            <span class="admin-stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
            </span>
            <div class="admin-stat-content">
                <span class="admin-stat-value"><?= number_format($stats['events']) ?></span>
                <span class="admin-stat-label">Tavlingar</span>
            </div>
        </div>

        <div class="admin-stat-card">
            <span class="admin-stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </span>
            <div class="admin-stat-content">
                <span class="admin-stat-value"><?= number_format($stats['riders']) ?></span>
                <span class="admin-stat-label">Deltagare</span>
            </div>
        </div>

        <div class="admin-stat-card">
            <span class="admin-stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                    <path d="M3 21h18"/>
                    <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                </svg>
            </span>
            <div class="admin-stat-content">
                <span class="admin-stat-value"><?= number_format($stats['clubs']) ?></span>
                <span class="admin-stat-label">Klubbar</span>
            </div>
        </div>

        <div class="admin-stat-card">
            <span class="admin-stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                    <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>
                    <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>
                    <path d="M4 22h16"/>
                    <path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>
                </svg>
            </span>
            <div class="admin-stat-content">
                <span class="admin-stat-value"><?= number_format($stats['series']) ?></span>
                <span class="admin-stat-label">Aktiva serier</span>
            </div>
        </div>
    </div>

    <div class="admin-grid">

        <!-- Upcoming Events -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h2>Kommande tavlingar</h2>
                <a href="<?= admin_url('events') ?>" class="btn btn-ghost btn-sm">Visa alla</a>
            </div>

            <?php if (empty($upcomingEvents)): ?>
                <p class="text-muted">Inga kommande tavlingar.</p>
            <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($upcomingEvents as $event): ?>
                    <a href="<?= admin_url('events/' . $event['id']) ?>" class="admin-list-item">
                        <div class="admin-list-date">
                            <span class="day"><?= date('j', strtotime($event['date'])) ?></span>
                            <span class="month"><?= hub_month_short($event['date']) ?></span>
                        </div>
                        <div class="admin-list-content">
                            <strong><?= htmlspecialchars($event['name']) ?></strong>
                            <span class="text-secondary"><?= htmlspecialchars($event['location'] ?? '') ?></span>
                        </div>
                        <span class="badge"><?= $event['reg_count'] ?? 0 ?> anm.</span>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Registrations -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h2>Senaste anmalningar</h2>
            </div>

            <?php if (empty($recentRegistrations)): ?>
                <p class="text-muted">Inga anmalningar annu.</p>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Deltagare</th>
                                <th>Tavling</th>
                                <th>Datum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRegistrations as $reg): ?>
                            <tr>
                                <td><?= htmlspecialchars($reg['rider_name']) ?></td>
                                <td><?= htmlspecialchars($reg['event_name']) ?></td>
                                <td class="text-secondary"><?= date('Y-m-d', strtotime($reg['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Quick Actions -->
    <div class="admin-card">
        <h2 class="admin-card-title">Snabbatgarder</h2>
        <div class="admin-quick-actions">
            <a href="<?= admin_url('events/create') ?>" class="btn btn-primary">
                + Ny tavling
            </a>
            <a href="<?= admin_url('series/create') ?>" class="btn btn-secondary">
                + Ny serie
            </a>
            <a href="<?= admin_url('import') ?>" class="btn btn-secondary">
                Importera resultat
            </a>
        </div>
    </div>

</div>
