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
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <div class="header-content">
                <h1 class="site-title">TheHUB</h1>
            </div>
            <nav class="main-nav">
                <a href="/public/index.php">Hem</a>
                <a href="/public/events.php" class="active">Tävlingar</a>
                <a href="/public/results.php">Resultat</a>
                <a href="/admin/login.php">Admin</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <h1>Tävlingar</h1>

        <div class="filters">
            <div class="filter-group">
                <label for="year">År:</label>
                <select id="year" onchange="window.location.href='?year=' + this.value">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y['year'] ?>" <?= $y['year'] == $year ? 'selected' : '' ?>>
                            <?= $y['year'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (empty($events)): ?>
            <p class="no-data">Inga tävlingar hittades för <?= $year ?></p>
        <?php else: ?>
            <div class="events-grid">
                <?php foreach ($events as $event): ?>
                    <div class="event-card-large">
                        <div class="event-header">
                            <div class="event-date-large">
                                <span class="day"><?= formatDate($event['event_date'], 'd') ?></span>
                                <span class="month"><?= formatDate($event['event_date'], 'M Y') ?></span>
                            </div>
                            <span class="badge badge-<?= $event['status'] ?>"><?= h($event['status']) ?></span>
                        </div>
                        <h3><a href="/public/event.php?id=<?= $event['id'] ?>"><?= h($event['name']) ?></a></h3>
                        <p class="event-location"><?= h($event['location']) ?></p>
                        <p class="event-type"><?= h(str_replace('_', ' ', $event['event_type'])) ?></p>
                        <?php if ($event['participant_count'] > 0): ?>
                            <p class="event-participants"><?= $event['participant_count'] ?> deltagare</p>
                        <?php endif; ?>
                        <a href="/public/results.php?event_id=<?= $event['id'] ?>" class="btn btn-sm">Visa resultat</a>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="pagination">
                    <?php if ($pagination['has_prev']): ?>
                        <a href="?year=<?= $year ?>&page=<?= $page - 1 ?>" class="btn btn-sm">« Föregående</a>
                    <?php endif; ?>

                    <span class="page-info">Sida <?= $page ?> av <?= $pagination['total_pages'] ?></span>

                    <?php if ($pagination['has_next']): ?>
                        <a href="?year=<?= $year ?>&page=<?= $page + 1 ?>" class="btn btn-sm">Nästa »</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> TheHUB</p>
        </div>
    </footer>
</body>
</html>
