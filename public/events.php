<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

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
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="gs-nav">
        <div class="gs-container">
            <ul class="gs-nav-list">
                <li><a href="/public/index.php" class="gs-nav-link">Hem</a></li>
                <li><a href="/public/events.php" class="gs-nav-link active">Tävlingar</a></li>
                <li><a href="/public/results.php" class="gs-nav-link">Resultat</a></li>
                <li style="margin-left: auto;"><a href="/admin/login.php" class="gs-btn gs-btn-sm gs-btn-primary">Admin</a></li>
            </ul>
        </div>
    </nav>

    <main class="gs-container gs-py-xl">
        <h1 class="gs-h1 gs-text-primary gs-mb-lg">Tävlingar</h1>

        <!-- Filters -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <div class="gs-flex gs-items-center gs-gap-md">
                    <label for="year" class="gs-label" style="margin-bottom: 0;">År:</label>
                    <select id="year" class="gs-input" style="max-width: 200px;" onchange="window.location.href='?year=' + this.value">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= $y['year'] ?>" <?= $y['year'] == $year ? 'selected' : '' ?>>
                                <?= $y['year'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="gs-text-secondary gs-text-sm">Totalt: <?= $totalCount ?> tävlingar</span>
                </div>
            </div>
        </div>

        <?php if (empty($events)): ?>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center gs-py-xl">
                    <p class="gs-text-secondary">Inga tävlingar hittades för <?= $year ?></p>
                </div>
            </div>
        <?php else: ?>
            <!-- Events Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-lg gs-mb-lg">
                <?php foreach ($events as $event): ?>
                    <div class="gs-event-card">
                        <div class="gs-event-header">
                            <div class="gs-event-date" style="background-color: <?= $event['status'] === 'completed' ? 'var(--gs-success)' : 'var(--gs-primary)' ?>;">
                                <div class="gs-event-date-day"><?= formatDate($event['event_date'], 'd') ?></div>
                                <div class="gs-event-date-month"><?= formatDate($event['event_date'], 'M Y') ?></div>
                            </div>
                            <span class="gs-badge gs-badge-<?= $event['status'] === 'completed' ? 'success' : ($event['status'] === 'upcoming' ? 'warning' : 'primary') ?>">
                                <?= h($event['status']) ?>
                            </span>
                        </div>
                        <div class="gs-event-content">
                            <h3 class="gs-event-title">
                                <a href="/public/event.php?id=<?= $event['id'] ?>"><?= h($event['name']) ?></a>
                            </h3>
                            <p class="gs-event-meta"><?= h($event['location']) ?></p>
                            <p class="gs-event-meta gs-text-xs"><?= h(str_replace('_', ' ', $event['event_type'])) ?></p>
                            <?php if ($event['participant_count'] > 0): ?>
                                <p class="gs-event-meta gs-text-xs gs-text-primary" style="margin-top: var(--gs-space-sm);">
                                    <?= $event['participant_count'] ?> deltagare
                                </p>
                            <?php endif; ?>
                            <a href="/public/results.php?event_id=<?= $event['id'] ?>" class="gs-btn gs-btn-sm gs-btn-primary gs-mt-lg">
                                Visa resultat
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="gs-flex gs-items-center gs-justify-between gs-gap-md">
                    <?php if ($pagination['has_prev']): ?>
                        <a href="?year=<?= $year ?>&page=<?= $page - 1 ?>" class="gs-btn gs-btn-outline">« Föregående</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>

                    <span class="gs-text-secondary">Sida <?= $page ?> av <?= $pagination['total_pages'] ?></span>

                    <?php if ($pagination['has_next']): ?>
                        <a href="?year=<?= $year ?>&page=<?= $page + 1 ?>" class="gs-btn gs-btn-outline">Nästa »</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="gs-bg-dark gs-text-white gs-py-xl gs-text-center">
        <div class="gs-container">
            <p>&copy; <?= date('Y') ?> TheHUB</p>
        </div>
    </footer>
</body>
</html>
