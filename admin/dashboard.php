<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;

// Get statistics
$stats = [];

try {
    $stats['riders'] = $pdo->query("SELECT COUNT(*) FROM riders")->fetchColumn();
    $stats['events'] = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $stats['clubs'] = $pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn();
    $stats['series'] = $pdo->query("SELECT COUNT(*) FROM series")->fetchColumn();
} catch (Exception $e) {
    $stats = ['riders' => 0, 'events' => 0, 'clubs' => 0, 'series' => 0];
}

$pageTitle = 'Dashboard';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <h1 class="gs-h2 gs-mb-lg">
            <i data-lucide="layout-dashboard"></i>
            Dashboard
        </h1>

        <!-- Stats Grid -->
        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-md gs-mb-lg">
            
            <div class="gs-stat-card">
                <i data-lucide="users" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                <div class="gs-stat-number"><?= number_format($stats['riders']) ?></div>
                <div class="gs-stat-label">Deltagare</div>
                <a href="/admin/riders.php" class="gs-btn gs-btn-sm gs-btn-outline gs-mt-md">
                    Visa alla →
                </a>
            </div>

            <div class="gs-stat-card">
                <i data-lucide="calendar" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                <div class="gs-stat-number"><?= number_format($stats['events']) ?></div>
                <div class="gs-stat-label">Events</div>
                <a href="/admin/events.php" class="gs-btn gs-btn-sm gs-btn-outline gs-mt-md">
                    Visa alla →
                </a>
            </div>

            <div class="gs-stat-card">
                <i data-lucide="building" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                <div class="gs-stat-number"><?= number_format($stats['clubs']) ?></div>
                <div class="gs-stat-label">Klubbar</div>
                <a href="/admin/clubs.php" class="gs-btn gs-btn-sm gs-btn-outline gs-mt-md">
                    Visa alla →
                </a>
            </div>

            <div class="gs-stat-card">
                <i data-lucide="trophy" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                <div class="gs-stat-number"><?= number_format($stats['series']) ?></div>
                <div class="gs-stat-label">Serier</div>
                <a href="/admin/series.php" class="gs-btn gs-btn-sm gs-btn-outline gs-mt-md">
                    Visa alla →
                </a>
            </div>

        </div>

        <!-- Quick Actions -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">Snabbåtgärder</h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-flex gs-gap-md gs-flex-wrap">
                    <a href="/admin/import-uci.php" class="gs-btn gs-btn-primary">
                        <i data-lucide="upload"></i>
                        Importera cyklister
                    </a>
                    <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="calendar-plus"></i>
                        Nytt event
                    </a>
                    <a href="/admin/import-history.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="history"></i>
                        Import-historik
                    </a>
                </div>
            </div>
        </div>

    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
