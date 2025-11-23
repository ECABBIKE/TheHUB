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
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
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
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
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

            <!-- Series List -->
            <?php if (empty($series)): ?>
                <div class="gs-card gs-text-center gs-empty-state-container">
                    <i data-lucide="inbox" class="gs-empty-state-icon-lg"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga serier ännu</h3>
                    <p class="gs-text-secondary">
                        Det finns inga tävlingsserier registrerade ännu.
                    </p>
                </div>
            <?php else: ?>
                <div class="gs-series-list">
                    <?php foreach ($series as $s): ?>
                        <a href="/series-standings.php?id=<?= $s['id'] ?>" class="gs-link-inherit">
                            <div class="gs-card gs-card-hover gs-series-card gs-transition-card">
                                <!-- Logo Left -->
                                <div class="gs-series-logo">
                                    <?php if ($s['logo']): ?>
                                        <img src="<?= h($s['logo']) ?>"
                                             alt="<?= h($s['name']) ?>">
                                    <?php else: ?>
                                        <div class="gs-placeholder-icon">
                                            <i data-lucide="award" class="gs-icon-48"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Info Right -->
                                <div class="gs-series-info">
                                    <div class="gs-series-header">
                                        <div class="gs-series-title">
                                            <?= h($s['name']) ?>
                                        </div>
                                        <?php if ($s['year']): ?>
                                            <span class="gs-series-year">
                                                <?= $s['year'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($s['description']): ?>
                                        <div class="gs-series-description">
                                            <?= h($s['description']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="gs-series-meta">
                                        <i data-lucide="calendar" class="gs-icon-14"></i>
                                        <span class="gs-font-semibold gs-text-purple">
                                            <?= $s['event_count'] ?> <?= $s['event_count'] == 1 ? 'tävling' : 'tävlingar' ?>
                                        </span>
                                        <?php if ($s['participant_count'] > 0): ?>
                                            <span class="gs-ml-1">•</span>
                                            <i data-lucide="users" class="gs-icon-14"></i>
                                            <span class="gs-font-semibold gs-text-purple">
                                                <?= number_format($s['participant_count'], 0, ',', ' ') ?> deltagare
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($s['start_date'] && $s['end_date']): ?>
                                            <span class="gs-ml-1">•</span>
                                            <span>
                                                <?= date('M Y', strtotime($s['start_date'])) ?> - <?= date('M Y', strtotime($s['end_date'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
<?php include __DIR__ . '/includes/layout-footer.php'; ?>
