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

// Get filters
$series_id = isset($_GET['series']) ? (int)$_GET['series'] : null;
$event_type = isset($_GET['format']) ? trim($_GET['format']) : null;

// Get all series for filter dropdown
$allSeries = $db->getAll("SELECT id, name FROM series ORDER BY name");

// Get all event types for filter dropdown
$allEventTypes = $db->getAll("SELECT DISTINCT type FROM events WHERE type IS NOT NULL AND type != '' ORDER BY type");

// Build WHERE clause for both queries
$where_clauses = [];
$params = [];

if ($series_id) {
    $where_clauses[] = "e.series_id = ?";
    $params[] = $series_id;
}

if ($event_type) {
    $where_clauses[] = "e.type = ?";
    $params[] = $event_type;
}

$where_sql = !empty($where_clauses) ? 'AND ' . implode(' AND ', $where_clauses) : '';

// Get UPCOMING events (date >= today, nearest first)
$upcomingEvents = $db->getAll(
    "SELECT e.id, e.name, e.advent_id, e.date as event_date, e.location, e.type as event_type, e.status,
            s.name as series_name, s.id as series_id,
            COUNT(r.id) as participant_count,
            COUNT(DISTINCT res.category_id) as category_count
     FROM events e
     LEFT JOIN results r ON e.id = r.event_id
     LEFT JOIN results res ON e.id = res.event_id
     LEFT JOIN series s ON e.series_id = s.id
     WHERE e.date >= CURDATE() $where_sql
     GROUP BY e.id
     ORDER BY e.date ASC",
    $params
);

// Get COMPLETED events (date < today, newest first)
$completedEvents = $db->getAll(
    "SELECT e.id, e.name, e.advent_id, e.date as event_date, e.location, e.type as event_type, e.status,
            s.name as series_name, s.id as series_id,
            COUNT(r.id) as participant_count,
            COUNT(DISTINCT res.category_id) as category_count
     FROM events e
     LEFT JOIN results r ON e.id = r.event_id
     LEFT JOIN results res ON e.id = res.event_id
     LEFT JOIN series s ON e.series_id = s.id
     WHERE e.date < CURDATE() $where_sql
     GROUP BY e.id
     ORDER BY e.date DESC",
    $params
);

$totalCount = count($upcomingEvents) + count($completedEvents);

$pageTitle = 'Tävlingar';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main class="gs-main-content">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-mb-xl">
                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="calendar"></i>
                    Tävlingskalender
                </h1>
                <p class="gs-text-secondary">
                    <?= $totalCount ?> tävlingar (<?= count($upcomingEvents) ?> kommande, <?= count($completedEvents) ?> avklarade)
                </p>
            </div>

            <!-- Filter Controls -->
            <div class="gs-card gs-mb-lg" style="padding: 1rem;">
                <form method="GET" action="" class="gs-flex gs-gap-md gs-flex-wrap gs-items-end">
                    <!-- Series Filter -->
                    <div style="flex: 1; min-width: 200px;">
                        <label class="gs-label">Serie</label>
                        <select name="series" class="gs-input">
                            <option value="">Alla serier</option>
                            <?php foreach ($allSeries as $series): ?>
                                <option value="<?= $series['id'] ?>" <?= $series_id == $series['id'] ? 'selected' : '' ?>>
                                    <?= h($series['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Event Type Filter -->
                    <div style="flex: 1; min-width: 200px;">
                        <label class="gs-label">Tävlingsformat</label>
                        <select name="format" class="gs-input">
                            <option value="">Alla format</option>
                            <?php foreach ($allEventTypes as $type): ?>
                                <option value="<?= h($type['type']) ?>" <?= $event_type == $type['type'] ? 'selected' : '' ?>>
                                    <?= h(str_replace('_', ' ', $type['type'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Apply and Reset Buttons -->
                    <div class="gs-flex gs-gap-sm">
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="filter"></i>
                            Filtrera
                        </button>
                        <a href="/events.php" class="gs-btn gs-btn-outline">
                            <i data-lucide="x"></i>
                            Rensa filter
                        </a>
                    </div>
                </form>
            </div>

            <!-- 2-Column Layout: Upcoming (Left) and Completed (Right) -->
            <?php if (empty($upcomingEvents) && empty($completedEvents)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="calendar-x" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga tävlingar hittades</h3>
                    <p class="gs-text-secondary">
                        Inga tävlingar matchade dina filter.
                    </p>
                </div>
            <?php else: ?>
                <style>
                    .events-two-column {
                        display: grid;
                        grid-template-columns: 1fr;
                        gap: 2rem;
                    }
                    @media (min-width: 768px) {
                        .events-two-column {
                            grid-template-columns: 1fr 1fr;
                        }
                    }
                    .event-column-header {
                        font-size: 1.25rem;
                        font-weight: 700;
                        color: #1a202c;
                        margin-bottom: 1rem;
                        padding-bottom: 0.5rem;
                        border-bottom: 2px solid #e2e8f0;
                    }
                    .event-list {
                        display: flex;
                        flex-direction: column;
                        gap: 1rem;
                    }
                </style>

                <div class="events-two-column">
                    <!-- LEFT COLUMN: Upcoming Events -->
                    <div>
                        <div class="event-column-header">
                            <i data-lucide="clock"></i>
                            Kommande tävlingar (<?= count($upcomingEvents) ?>)
                        </div>

                        <?php if (empty($upcomingEvents)): ?>
                            <div class="gs-card gs-text-center" style="padding: 2rem;">
                                <i data-lucide="calendar-x" style="width: 48px; height: 48px; margin: 0 auto 0.5rem; opacity: 0.3;"></i>
                                <p class="gs-text-secondary">Inga kommande tävlingar</p>
                            </div>
                        <?php else: ?>
                            <div class="event-list">
                                <?php foreach ($upcomingEvents as $event): ?>
                                    <a href="/event.php?id=<?= $event['id'] ?>" style="text-decoration: none; color: inherit;">
                                        <div class="gs-card gs-card-hover" style="transition: transform 0.2s, box-shadow 0.2s;">
                                            <div class="gs-card-header">
                                                <div class="gs-flex gs-justify-between gs-items-start gs-mb-sm">
                                                    <div class="gs-event-date-badge">
                                                        <div class="gs-event-date-day"><?= date('d', strtotime($event['event_date'])) ?></div>
                                                        <div class="gs-event-date-month"><?= date('M', strtotime($event['event_date'])) ?></div>
                                                    </div>
                                                    <span class="gs-badge gs-badge-warning">
                                                        <i data-lucide="clock"></i>
                                                        Kommande
                                                    </span>
                                                </div>
                                                <h3 class="gs-h4 gs-mb-xs"><?= h($event['name']) ?></h3>

                                                <?php if ($event['series_name']): ?>
                                                    <p class="gs-text-sm gs-text-secondary gs-mb-xs">
                                                        <i data-lucide="award" style="width: 14px; height: 14px;"></i>
                                                        <?= h($event['series_name']) ?>
                                                    </p>
                                                <?php endif; ?>

                                                <?php if ($event['location']): ?>
                                                    <p class="gs-text-sm gs-text-secondary">
                                                        <i data-lucide="map-pin" style="width: 14px; height: 14px;"></i>
                                                        <?= h($event['location']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="gs-card-content">
                                                <div class="gs-flex gs-gap-sm gs-flex-wrap">
                                                    <?php if ($event['event_type']): ?>
                                                        <span class="gs-badge gs-badge-primary gs-text-xs">
                                                            <?= h(str_replace('_', ' ', $event['event_type'])) ?>
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

                    <!-- RIGHT COLUMN: Completed Events -->
                    <div>
                        <div class="event-column-header">
                            <i data-lucide="check-circle"></i>
                            Avklarade tävlingar (<?= count($completedEvents) ?>)
                        </div>

                        <?php if (empty($completedEvents)): ?>
                            <div class="gs-card gs-text-center" style="padding: 2rem;">
                                <i data-lucide="calendar-x" style="width: 48px; height: 48px; margin: 0 auto 0.5rem; opacity: 0.3;"></i>
                                <p class="gs-text-secondary">Inga avklarade tävlingar</p>
                            </div>
                        <?php else: ?>
                            <div class="event-list">
                                <?php foreach ($completedEvents as $event): ?>
                                    <a href="/event.php?id=<?= $event['id'] ?>" style="text-decoration: none; color: inherit;">
                                        <div class="gs-card gs-card-hover" style="transition: transform 0.2s, box-shadow 0.2s;">
                                            <div class="gs-card-header">
                                                <div class="gs-flex gs-justify-between gs-items-start gs-mb-sm">
                                                    <div class="gs-event-date-badge">
                                                        <div class="gs-event-date-day"><?= date('d', strtotime($event['event_date'])) ?></div>
                                                        <div class="gs-event-date-month"><?= date('M', strtotime($event['event_date'])) ?></div>
                                                    </div>
                                                    <span class="gs-badge gs-badge-success">
                                                        <i data-lucide="check-circle"></i>
                                                        Avklarad
                                                    </span>
                                                </div>
                                                <h3 class="gs-h4 gs-mb-xs"><?= h($event['name']) ?></h3>

                                                <?php if ($event['series_name']): ?>
                                                    <p class="gs-text-sm gs-text-secondary gs-mb-xs">
                                                        <i data-lucide="award" style="width: 14px; height: 14px;"></i>
                                                        <?= h($event['series_name']) ?>
                                                    </p>
                                                <?php endif; ?>

                                                <?php if ($event['location']): ?>
                                                    <p class="gs-text-sm gs-text-secondary">
                                                        <i data-lucide="map-pin" style="width: 14px; height: 14px;"></i>
                                                        <?= h($event['location']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="gs-card-content">
                                                <div class="gs-flex gs-gap-sm gs-mb-md gs-flex-wrap">
                                                    <?php if ($event['event_type']): ?>
                                                        <span class="gs-badge gs-badge-primary gs-text-xs">
                                                            <?= h(str_replace('_', ' ', $event['event_type'])) ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if ($event['participant_count'] > 0): ?>
                                                        <span class="gs-badge gs-badge-secondary gs-text-xs">
                                                            <i data-lucide="users" style="width: 12px; height: 12px;"></i>
                                                            <?= $event['participant_count'] ?> deltagare
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if ($event['category_count'] > 0): ?>
                                                        <span class="gs-badge gs-badge-secondary gs-text-xs">
                                                            <i data-lucide="layers" style="width: 12px; height: 12px;"></i>
                                                            <?= $event['category_count'] ?> <?= $event['category_count'] == 1 ? 'klass' : 'klasser' ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($event['participant_count'] > 0): ?>
                                                    <div class="gs-flex gs-justify-between gs-items-center">
                                                        <span class="gs-text-sm gs-text-primary" style="font-weight: 600;">
                                                            <i data-lucide="trophy" style="width: 14px; height: 14px;"></i>
                                                            Visa resultat →
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
