<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config first
if (!file_exists(__DIR__ . '/config.php')) {
    die('ERROR: config.php not found! Current directory: ' . __DIR__);
}
require_once __DIR__ . '/config.php';

$db = getDB();

// Check if series_events table exists
$seriesEventsTableExists = false;
try {
    $tables = $db->getAll("SHOW TABLES LIKE 'series_events'");
    $seriesEventsTableExists = !empty($tables);
} catch (Exception $e) {
    // Table doesn't exist, that's ok
}

// Get all series with event and participant counts
if ($seriesEventsTableExists) {
    // Use series_events table (many-to-many)
    $series = $db->getAll("
        SELECT s.id, s.name, s.description, s.year, s.status,
               COUNT(DISTINCT se.event_id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN series_events se2 ON r.event_id = se2.event_id
                WHERE se2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN series_events se ON s.id = se.series_id
        WHERE s.status = 'active'
        GROUP BY s.id
        ORDER BY s.year DESC, s.name ASC
    ");
} else {
    // Fallback to old series_id column in events
    $series = $db->getAll("
        SELECT s.id, s.name, s.description, s.year, s.status,
               COUNT(DISTINCT e.id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN events e2 ON r.event_id = e2.id
                WHERE e2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN events e ON s.id = e.series_id
        WHERE s.status = 'active'
        GROUP BY s.id
        ORDER BY s.year DESC, s.name ASC
    ");
}

$pageTitle = 'Serier';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main class="gs-main-content">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-mb-xl">
                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="trophy"></i>
                    Tävlingsserier
                </h1>
                <p class="gs-text-secondary">
                    Alla GravitySeries och andra tävlingsserier
                </p>
            </div>

            <!-- Series Grid -->
            <?php if (empty($series)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="inbox" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga serier ännu</h3>
                    <p class="gs-text-secondary">
                        Det finns inga tävlingsserier registrerade ännu.
                    </p>
                </div>
            <?php else: ?>
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-xl-grid-cols-4 gs-gap-lg">
                    <?php foreach ($series as $s): ?>
                        <a href="/series-standings.php?id=<?= $s['id'] ?>" class="gs-card gs-card-hover" style="text-decoration: none; color: inherit; display: block;">
                            <div class="gs-card-header">
                                <h3 class="gs-h4">
                                    <i data-lucide="award"></i>
                                    <?= h($s['name']) ?>
                                </h3>
                                <?php if ($s['year']): ?>
                                    <span class="gs-badge gs-badge-primary gs-text-xs">
                                        <?= $s['year'] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="gs-card-content">
                                <?php if ($s['description']): ?>
                                    <p class="gs-text-secondary gs-mb-md">
                                        <?= h($s['description']) ?>
                                    </p>
                                <?php endif; ?>
                                <div class="gs-flex gs-flex-col gs-gap-xs gs-text-sm gs-text-secondary gs-mb-md">
                                    <div class="gs-flex gs-items-center gs-gap-sm">
                                        <i data-lucide="calendar" style="width: 16px; height: 16px;"></i>
                                        <span><?= $s['event_count'] ?> tävlingar</span>
                                    </div>
                                    <?php if ($s['participant_count'] > 0): ?>
                                    <div class="gs-flex gs-items-center gs-gap-sm">
                                        <i data-lucide="users" style="width: 16px; height: 16px;"></i>
                                        <span><?= number_format($s['participant_count'], 0, ',', ' ') ?> unika deltagare</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($s['event_count'] > 0): ?>
                                    <div class="gs-btn gs-btn-primary gs-btn-sm" style="width: 100%; text-align: center;">
                                        <i data-lucide="info"></i>
                                        INFO
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
<?php include __DIR__ . '/includes/layout-footer.php'; ?>
