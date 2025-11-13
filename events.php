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

// Get year filter
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Get all available years
$years = $db->getAll("SELECT DISTINCT YEAR(event_date) as year FROM events ORDER BY year DESC");

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = EVENTS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// Get total count
$totalCount = $db->getRow(
    "SELECT COUNT(*) as count FROM events WHERE YEAR(event_date) = ?",
    [$year]
)['count'] ?? 0;

$pagination = paginate($totalCount, $perPage, $page);

// Get events
$events = $db->getAll(
    "SELECT e.id, e.name, e.event_date, e.location, e.event_type, e.status,
            COUNT(r.id) as participant_count
     FROM events e
     LEFT JOIN results r ON e.id = r.event_id
     WHERE YEAR(e.event_date) = ?
     GROUP BY e.id
     ORDER BY e.event_date DESC
     LIMIT ? OFFSET ?",
    [$year, $perPage, $offset]
);

$pageTitle = 'Tävlingar';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main style="padding: 6rem 2rem 2rem;">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-justify-between gs-items-center gs-mb-xl">
                <div>
                    <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                        <i data-lucide="calendar"></i>
                        Tävlingskalender
                    </h1>
                    <p class="gs-text-secondary">
                        <?= $totalCount ?> tävlingar under <?= $year ?>
                    </p>
                </div>

                <!-- Year Filter -->
                <?php if (!empty($years)): ?>
                    <div class="gs-flex gs-gap-sm">
                        <?php foreach ($years as $y): ?>
                            <a href="?year=<?= $y['year'] ?>"
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
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-lg">
                    <?php foreach ($events as $event): ?>
                        <div class="gs-card gs-card-hover">
                            <div class="gs-card-header">
                                <div class="gs-flex gs-justify-between gs-items-start gs-mb-sm">
                                    <div class="gs-event-date-badge">
                                        <div class="gs-event-date-day"><?= formatDate($event['event_date'], 'd') ?></div>
                                        <div class="gs-event-date-month"><?= formatDate($event['event_date'], 'M') ?></div>
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
                                <?php if ($event['location']): ?>
                                    <p class="gs-text-sm gs-text-secondary">
                                        <i data-lucide="map-pin" style="width: 14px; height: 14px;"></i>
                                        <?= h($event['location']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="gs-card-content">
                                <?php if ($event['event_type']): ?>
                                    <p class="gs-mb-sm">
                                        <strong>Typ:</strong>
                                        <span class="gs-badge gs-badge-primary gs-text-xs">
                                            <?= h(str_replace('_', ' ', $event['event_type'])) ?>
                                        </span>
                                    </p>
                                <?php endif; ?>

                                <?php if ($event['participant_count'] > 0): ?>
                                    <p class="gs-text-sm gs-text-secondary gs-mb-md">
                                        <i data-lucide="users" style="width: 14px; height: 14px;"></i>
                                        <?= $event['participant_count'] ?> deltagare
                                    </p>

                                    <a href="/results.php?event_id=<?= $event['id'] ?>"
                                       class="gs-btn gs-btn-primary gs-btn-sm gs-w-full">
                                        <i data-lucide="trophy"></i>
                                        Visa resultat
                                    </a>
                                <?php else: ?>
                                    <p class="gs-text-sm gs-text-secondary">
                                        <i data-lucide="info" style="width: 14px; height: 14px;"></i>
                                        Inga resultat registrerade ännu
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="gs-flex gs-justify-center gs-gap-sm gs-mt-xl">
                        <?php if ($pagination['has_prev']): ?>
                            <a href="?year=<?= $year ?>&page=<?= $pagination['prev_page'] ?>"
                               class="gs-btn gs-btn-outline">
                                <i data-lucide="chevron-left"></i>
                                Föregående
                            </a>
                        <?php endif; ?>

                        <span class="gs-flex gs-items-center gs-px-md gs-text-secondary">
                            Sida <?= $pagination['current_page'] ?> av <?= $pagination['total_pages'] ?>
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="?year=<?= $year ?>&page=<?= $pagination['next_page'] ?>"
                               class="gs-btn gs-btn-outline">
                                Nästa
                                <i data-lucide="chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
<?php include __DIR__ . '/includes/layout-footer.php'; ?>
