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
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="inbox" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga serier ännu</h3>
                    <p class="gs-text-secondary">
                        Det finns inga tävlingsserier registrerade ännu.
                    </p>
                </div>
            <?php else: ?>
                <style>
                    .series-list {
                        display: flex;
                        flex-direction: column;
                        gap: 1rem;
                        max-width: 900px;
                        margin: 0 auto;
                    }
                    .series-card-horizontal {
                        display: grid;
                        grid-template-columns: 140px 1fr;
                        gap: 1rem;
                        padding: 1rem;
                        min-height: auto;
                    }
                    .series-logo-container {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        background: #f8f9fa;
                        border-radius: 8px;
                        padding: 0.75rem;
                    }
                    .series-logo-container img {
                        max-width: 100%;
                        max-height: 90px;
                        object-fit: contain;
                    }
                    .series-info-right {
                        display: flex;
                        flex-direction: column;
                        gap: 0.5rem;
                    }
                    .series-header-row {
                        display: flex;
                        align-items: center;
                        gap: 0.75rem;
                        flex-wrap: wrap;
                    }
                    .series-title {
                        font-size: 1.375rem;
                        font-weight: 700;
                        color: #1a202c;
                        line-height: 1.3;
                    }
                    .series-year-badge {
                        display: inline-flex;
                        align-items: center;
                        padding: 0.25rem 0.75rem;
                        background: #667eea;
                        color: white;
                        border-radius: 4px;
                        font-size: 0.875rem;
                        font-weight: 600;
                    }
                    .series-description {
                        color: #4a5568;
                        font-size: 0.9375rem;
                        line-height: 1.5;
                        margin: 0.25rem 0;
                    }
                    .series-meta {
                        display: flex;
                        align-items: center;
                        gap: 0.5rem;
                        font-size: 0.875rem;
                        color: #718096;
                        margin-top: 0.25rem;
                        flex-wrap: wrap;
                    }
                    @media (max-width: 640px) {
                        .series-card-horizontal {
                            grid-template-columns: 80px 1fr;
                            gap: 0.75rem;
                            padding: 0.75rem;
                        }
                        .series-logo-container {
                            padding: 0.5rem;
                        }
                        .series-logo-container img {
                            max-height: 55px;
                        }
                        .series-title {
                            font-size: 1rem;
                        }
                        .series-description {
                            font-size: 0.8125rem;
                            line-height: 1.4;
                        }
                        .series-year-badge {
                            font-size: 0.75rem;
                            padding: 0.1875rem 0.5rem;
                        }
                        .series-meta {
                            font-size: 0.75rem;
                        }
                    }
                </style>

                <div class="series-list">
                    <?php foreach ($series as $s): ?>
                        <a href="/series-standings.php?id=<?= $s['id'] ?>" style="text-decoration: none; color: inherit;">
                            <div class="gs-card gs-card-hover series-card-horizontal" style="transition: transform 0.2s, box-shadow 0.2s;">
                                <!-- Logo Left -->
                                <div class="series-logo-container">
                                    <?php if ($s['logo']): ?>
                                        <img src="<?= h($s['logo']) ?>"
                                             alt="<?= h($s['name']) ?>">
                                    <?php else: ?>
                                        <div style="font-size: 2rem; color: #cbd5e0;">
                                            <i data-lucide="award" style="width: 48px; height: 48px;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Info Right -->
                                <div class="series-info-right">
                                    <div class="series-header-row">
                                        <div class="series-title">
                                            <?= h($s['name']) ?>
                                        </div>
                                        <?php if ($s['year']): ?>
                                            <span class="series-year-badge">
                                                <?= $s['year'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($s['description']): ?>
                                        <div class="series-description">
                                            <?= h($s['description']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="series-meta">
                                        <i data-lucide="calendar" style="width: 14px; height: 14px;"></i>
                                        <span style="font-weight: 600; color: #667eea;">
                                            <?= $s['event_count'] ?> <?= $s['event_count'] == 1 ? 'tävling' : 'tävlingar' ?>
                                        </span>
                                        <?php if ($s['participant_count'] > 0): ?>
                                            <span style="margin-left: 0.25rem;">•</span>
                                            <i data-lucide="users" style="width: 14px; height: 14px;"></i>
                                            <span style="font-weight: 600; color: #667eea;">
                                                <?= number_format($s['participant_count'], 0, ',', ' ') ?> deltagare
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($s['start_date'] && $s['end_date']): ?>
                                            <span style="margin-left: 0.25rem;">•</span>
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
