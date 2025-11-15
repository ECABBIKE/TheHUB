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
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$series_id = isset($_GET['series']) ? (int)$_GET['series'] : null;

// Get all available years
$years = $db->getAll("SELECT DISTINCT YEAR(date) as year FROM events ORDER BY year DESC");

// Get series info if filtering by series
$series_info = null;
if ($series_id) {
    $series_info = $db->getRow("SELECT * FROM series WHERE id = ?", [$series_id]);
}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = EVENTS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// Build WHERE clause
$where_clauses = ["YEAR(e.date) = ?"];
$params = [$year];

if ($series_id) {
    $where_clauses[] = "e.series_id = ?";
    $params[] = $series_id;
}

$where_sql = implode(' AND ', $where_clauses);

// Get total count
$totalCount = $db->getRow(
    "SELECT COUNT(*) as count FROM events e WHERE $where_sql",
    $params
)['count'] ?? 0;

$pagination = paginate($totalCount, $perPage, $page);

// Get events
$params_with_limit = array_merge($params, [$perPage, $offset]);
$events = $db->getAll(
    "SELECT e.id, e.name, e.advent_id, e.date as event_date, e.location, e.type as event_type, e.status,
            s.name as series_name, s.id as series_id,
            COUNT(r.id) as participant_count,
            COUNT(DISTINCT res.category_id) as category_count
     FROM events e
     LEFT JOIN results r ON e.id = r.event_id
     LEFT JOIN results res ON e.id = res.event_id
     LEFT JOIN series s ON e.series_id = s.id
     WHERE $where_sql
     GROUP BY e.id
     ORDER BY e.date DESC
     LIMIT ? OFFSET ?",
    $params_with_limit
);

$pageTitle = 'Tävlingar';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main class="gs-main-content">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-justify-between gs-items-center gs-mb-xl">
                <div>
                    <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                        <i data-lucide="calendar"></i>
                        <?php if ($series_info): ?>
                            <?= h($series_info['name']) ?> - Tävlingar
                        <?php else: ?>
                            Tävlingskalender
                        <?php endif; ?>
                    </h1>
                    <p class="gs-text-secondary">
                        <?= $totalCount ?> tävlingar under <?= $year ?>
                        <?php if ($series_info): ?>
                            <a href="/events.php?year=<?= $year ?>" class="gs-btn gs-btn-sm gs-btn-outline gs-ml-sm">
                                <i data-lucide="x"></i>
                                Visa alla
                            </a>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Year Filter -->
                <?php if (!empty($years)): ?>
                    <div class="gs-flex gs-gap-sm">
                        <?php foreach ($years as $y): ?>
                            <a href="?year=<?= $y['year'] ?><?= $series_id ? '&series=' . $series_id : '' ?>"
                               class="gs-btn <?= $y['year'] == $year ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                                <?= $y['year'] ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Events Grid -->
            <?php if (empty($events)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="calendar-x" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga tävlingar hittades</h3>
                    <p class="gs-text-secondary">
                        Det finns inga registrerade tävlingar för <?= $year ?>.
                    </p>
                </div>
            <?php else: ?>
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-xl-grid-cols-4 gs-gap-lg">
                    <?php foreach ($events as $event): ?>
                        <a href="/event.php?id=<?= $event['id'] ?>" style="text-decoration: none; color: inherit;">
                            <div class="gs-card gs-card-hover" style="height: 100%; transition: transform 0.2s, box-shadow 0.2s;">
                                <div class="gs-card-header">
                                    <div class="gs-flex gs-justify-between gs-items-start gs-mb-sm">
                                        <div class="gs-event-date-badge">
                                            <div class="gs-event-date-day"><?= date('d', strtotime($event['event_date'])) ?></div>
                                            <div class="gs-event-date-month"><?= date('M', strtotime($event['event_date'])) ?></div>
                                        </div>
                                        <?php
                                        $status_class = 'gs-badge-secondary';
                                        $status_text = $event['status'];
                                        if ($event['status'] == 'upcoming' || strtotime($event['event_date']) > time()) {
                                            $status_class = 'gs-badge-warning';
                                            $status_text = 'Kommande';
                                        } elseif ($event['status'] == 'completed' || strtotime($event['event_date']) < time()) {
                                            $status_class = 'gs-badge-success';
                                            $status_text = 'Avklarad';
                                        }
                                        ?>
                                        <span class="gs-badge <?= $status_class ?>">
                                            <i data-lucide="<?= $status_text == 'Kommande' ? 'clock' : 'check-circle' ?>"></i>
                                            <?= h($status_text) ?>
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

                                    <?php if ($event['advent_id']): ?>
                                        <p class="gs-text-xs gs-text-secondary gs-mt-xs">
                                            ID: <?= h($event['advent_id']) ?>
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

                                    <div class="gs-flex gs-justify-between gs-items-center">
                                        <?php if ($event['participant_count'] > 0): ?>
                                            <span class="gs-text-sm gs-text-primary" style="font-weight: 600;">
                                                <i data-lucide="trophy" style="width: 14px; height: 14px;"></i>
                                                Visa resultat →
                                            </span>
                                        <?php else: ?>
                                            <span class="gs-text-sm gs-text-secondary">
                                                <i data-lucide="info" style="width: 14px; height: 14px;"></i>
                                                Inga resultat ännu
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="gs-flex gs-justify-center gs-gap-sm gs-mt-xl">
                        <?php
                        $base_url = "?year=$year" . ($series_id ? "&series=$series_id" : "");
                        ?>
                        <?php if ($pagination['has_prev']): ?>
                            <a href="<?= $base_url ?>&page=<?= $pagination['prev_page'] ?>"
                               class="gs-btn gs-btn-outline">
                                <i data-lucide="chevron-left"></i>
                                Föregående
                            </a>
                        <?php endif; ?>

                        <span class="gs-flex gs-items-center gs-px-md gs-text-secondary">
                            Sida <?= $pagination['current_page'] ?> av <?= $pagination['total_pages'] ?>
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="<?= $base_url ?>&page=<?= $pagination['next_page'] ?>"
                               class="gs-btn gs-btn-outline">
                                Nästa
                                <i data-lucide="chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
