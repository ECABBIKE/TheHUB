<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Demo mode check
$is_demo = ($db->getConnection() === null);

if ($is_demo) {
    // Demo series data
    $series = [
        [
            'id' => 1,
            'name' => 'GravitySeries 2025',
            'type' => 'XC',
            'events_count' => 6,
            'status' => 'active',
            'start_date' => '2025-05-01',
            'end_date' => '2025-09-30'
        ],
        [
            'id' => 2,
            'name' => 'Svenska Cupen MTB',
            'type' => 'XC',
            'events_count' => 8,
            'status' => 'active',
            'start_date' => '2025-04-15',
            'end_date' => '2025-10-15'
        ],
        [
            'id' => 3,
            'name' => 'Vasaloppet Cycling',
            'type' => 'Landsväg',
            'events_count' => 4,
            'status' => 'active',
            'start_date' => '2025-06-01',
            'end_date' => '2025-08-31'
        ],
    ];
} else {
    // Get series from database
    $series = $db->getAll("SELECT id, name, type, status, start_date, end_date,
                          (SELECT COUNT(*) FROM events WHERE series_id = series.id) as events_count
                          FROM series
                          ORDER BY start_date DESC");
}

$pageTitle = 'Serier';
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
    <!-- Mobile Menu Toggle -->
    <button id="mobile-menu-toggle" class="gs-mobile-menu-toggle">
        <i data-lucide="menu"></i>
        <span>Meny</span>
    </button>

    <!-- Mobile Overlay -->
    <div id="mobile-overlay" class="gs-mobile-overlay"></div>

    <?php include __DIR__ . '/../includes/navigation.php'; ?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="trophy"></i>
                    Serier
                </h1>
            </div>

            <!-- Info Alert -->
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <div>
                    <strong>Demo-läge</strong><br>
                    Denna sida visar demo-data. Anslut databasen för att hantera riktiga serier.
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-4 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="trophy" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($series) ?></div>
                    <div class="gs-stat-label">Totalt serier</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($series, fn($s) => $s['status'] === 'active')) ?>
                    </div>
                    <div class="gs-stat-label">Aktiva</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($series, 'events_count')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt events</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                    <div class="gs-stat-number">~1,200</div>
                    <div class="gs-stat-label">Deltagare</div>
                </div>
            </div>

            <!-- Series Table -->
            <div class="gs-card">
                <div class="gs-table-responsive">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>
                                    <i data-lucide="trophy"></i>
                                    Namn
                                </th>
                                <th>Typ</th>
                                <th>Startdatum</th>
                                <th>Slutdatum</th>
                                <th>
                                    <i data-lucide="calendar"></i>
                                    Events
                                </th>
                                <th>Status</th>
                                <th style="width: 150px; text-align: right;">Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($series as $serie): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($serie['name']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="gs-badge gs-badge-primary">
                                            <i data-lucide="flag"></i>
                                            <?= h($serie['type']) ?>
                                        </span>
                                    </td>
                                    <td class="gs-text-secondary" style="font-family: monospace;">
                                        <?= date('d M Y', strtotime($serie['start_date'])) ?>
                                    </td>
                                    <td class="gs-text-secondary" style="font-family: monospace;">
                                        <?= date('d M Y', strtotime($serie['end_date'])) ?>
                                    </td>
                                    <td class="gs-text-center">
                                        <strong class="gs-text-primary"><?= $serie['events_count'] ?></strong>
                                    </td>
                                    <td>
                                        <span class="gs-badge gs-badge-success">
                                            <i data-lucide="check-circle"></i>
                                            <?= ucfirst(h($serie['status'])) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <span class="gs-badge gs-badge-secondary">Demo</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- TheHUB JavaScript -->
    <script src="/assets/thehub.js"></script>
</body>
</html>
