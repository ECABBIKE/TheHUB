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
    "SELECT e.id, e.name, e.advent_id, e.date as event_date, e.location, e.organizer, e.type as event_type, e.status,
            s.name as series_name, s.id as series_id, s.logo as series_logo,
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
    "SELECT e.id, e.name, e.advent_id, e.date as event_date, e.location, e.organizer, e.type as event_type, e.status,
            s.name as series_name, s.id as series_id, s.logo as series_logo,
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
                <form method="GET" action="" id="filterForm" class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                    <!-- Series Filter -->
                    <div>
                        <label class="gs-label">Serie</label>
                        <select name="series" class="gs-input" onchange="document.getElementById('filterForm').submit()">
                            <option value="">Alla serier</option>
                            <?php foreach ($allSeries as $series): ?>
                                <option value="<?= $series['id'] ?>" <?= $series_id == $series['id'] ? 'selected' : '' ?>>
                                    <?= h($series['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Event Type Filter -->
                    <div>
                        <label class="gs-label">Tävlingsformat</label>
                        <select name="format" class="gs-input" onchange="document.getElementById('filterForm').submit()">
                            <option value="">Alla format</option>
                            <?php foreach ($allEventTypes as $type): ?>
                                <option value="<?= h($type['type']) ?>" <?= $event_type == $type['type'] ? 'selected' : '' ?>>
                                    <?= h(str_replace('_', ' ', $type['type'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Reset Button -->
                    <div>
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
                    .event-card-horizontal {
                        display: grid;
                        grid-template-columns: 120px 1fr;
                        gap: 1rem;
                        padding: 1rem;
                        min-height: 100px;
                    }
                    .event-logo-container {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        background: #f8f9fa;
                        border-radius: 6px;
                        padding: 0.5rem;
                    }
                    .event-logo-container img {
                        max-width: 100%;
                        max-height: 70px;
                        object-fit: contain;
                    }
                    .event-info-right {
                        display: flex;
                        flex-direction: column;
                        gap: 0.5rem;
                    }
                    .event-date-box {
                        display: inline-flex;
                        align-items: center;
                        gap: 0.5rem;
                        padding: 0.25rem 0.5rem;
                        background: #667eea;
                        color: white;
                        border-radius: 4px;
                        font-size: 0.875rem;
                        font-weight: 600;
                        width: fit-content;
                    }
                    .event-date-box.completed {
                        background: #48bb78;
                    }
                    .event-title {
                        font-size: 1.125rem;
                        font-weight: 700;
                        color: #1a202c;
                        line-height: 1.3;
                    }
                    .event-meta {
                        display: flex;
                        flex-direction: column;
                        gap: 0.25rem;
                        font-size: 0.875rem;
                        color: #718096;
                    }
                    @media (max-width: 640px) {
                        .event-card-horizontal {
                            grid-template-columns: 80px 1fr;
                            gap: 0.75rem;
                            padding: 0.75rem;
                        }
                        .event-logo-container img {
                            max-height: 50px;
                        }
                        .event-title {
                            font-size: 1rem;
                        }
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
                                        <div class="gs-card gs-card-hover event-card-horizontal" style="transition: transform 0.2s, box-shadow 0.2s;">
                                            <!-- Logo Left -->
                                            <div class="event-logo-container">
                                                <?php if ($event['series_logo']): ?>
                                                    <img src="<?= h($event['series_logo']) ?>"
                                                         alt="<?= h($event['series_name']) ?>">
                                                <?php else: ?>
                                                    <div style="font-size: 0.75rem; color: #9ca3af; text-align: center;">
                                                        <?= h($event['series_name'] ?? 'Event') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Info Right -->
                                            <div class="event-info-right">
                                                <div class="event-date-box">
                                                    <i data-lucide="calendar" style="width: 14px; height: 14px;"></i>
                                                    <?= date('d M Y', strtotime($event['event_date'])) ?>
                                                </div>

                                                <div class="event-title">
                                                    <?= h($event['name']) ?>
                                                </div>

                                                <div class="event-meta">
                                                    <?php if ($event['location']): ?>
                                                        <div>
                                                            <i data-lucide="map-pin" style="width: 14px; height: 14px;"></i>
                                                            <?= h($event['location']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($event['organizer']): ?>
                                                        <div>
                                                            <i data-lucide="user" style="width: 14px; height: 14px;"></i>
                                                            <?= h($event['organizer']) ?>
                                                        </div>
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
                                        <div class="gs-card gs-card-hover event-card-horizontal" style="transition: transform 0.2s, box-shadow 0.2s;">
                                            <!-- Logo Left -->
                                            <div class="event-logo-container">
                                                <?php if ($event['series_logo']): ?>
                                                    <img src="<?= h($event['series_logo']) ?>"
                                                         alt="<?= h($event['series_name']) ?>">
                                                <?php else: ?>
                                                    <div style="font-size: 0.75rem; color: #9ca3af; text-align: center;">
                                                        <?= h($event['series_name'] ?? 'Event') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Info Right -->
                                            <div class="event-info-right">
                                                <div class="event-date-box completed">
                                                    <i data-lucide="check-circle" style="width: 14px; height: 14px;"></i>
                                                    <?= date('d M Y', strtotime($event['event_date'])) ?>
                                                </div>

                                                <div class="event-title">
                                                    <?= h($event['name']) ?>
                                                </div>

                                                <div class="event-meta">
                                                    <?php if ($event['location']): ?>
                                                        <div>
                                                            <i data-lucide="map-pin" style="width: 14px; height: 14px;"></i>
                                                            <?= h($event['location']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($event['organizer']): ?>
                                                        <div>
                                                            <i data-lucide="user" style="width: 14px; height: 14px;"></i>
                                                            <?= h($event['organizer']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($event['participant_count'] > 0): ?>
                                                        <div style="font-weight: 600; color: #667eea;">
                                                            <i data-lucide="trophy" style="width: 14px; height: 14px;"></i>
                                                            <?= $event['participant_count'] ?> deltagare •
                                                            <?= $event['category_count'] ?> <?= $event['category_count'] == 1 ? 'klass' : 'klasser' ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
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
