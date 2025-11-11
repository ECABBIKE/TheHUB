<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if (!$eventId) {
    die("Event ID required");
}

// Get event info
$event = $db->getRow(
    "SELECT * FROM events WHERE id = ?",
    [$eventId]
);

if (!$event) {
    die("Event not found");
}

// Get results
$results = $db->getAll(
    "SELECT
        r.position,
        r.bib_number,
        r.finish_time,
        r.status,
        CONCAT(c.firstname, ' ', c.lastname) as cyclist_name,
        c.id as cyclist_id,
        c.birth_year,
        cl.name as club_name,
        cat.name as category_name
     FROM results r
     JOIN cyclists c ON r.cyclist_id = c.id
     LEFT JOIN clubs cl ON c.club_id = cl.id
     LEFT JOIN categories cat ON r.category_id = cat.id
     WHERE r.event_id = ?
     ORDER BY r.position ASC, r.finish_time ASC",
    [$eventId]
);

$pageTitle = $event['name'] . ' - Resultat';
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
                <a href="/public/events.php">Tävlingar</a>
                <a href="/public/results.php" class="active">Resultat</a>
                <a href="/admin/login.php">Admin</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="event-header-detail">
            <h1><?= h($event['name']) ?></h1>
            <div class="event-meta-detail">
                <span class="meta-item">
                    <strong>Datum:</strong> <?= formatDate($event['event_date'], 'd M Y') ?>
                </span>
                <span class="meta-item">
                    <strong>Plats:</strong> <?= h($event['location']) ?>
                </span>
                <?php if ($event['distance']): ?>
                    <span class="meta-item">
                        <strong>Distans:</strong> <?= $event['distance'] ?> km
                    </span>
                <?php endif; ?>
                <span class="meta-item">
                    <strong>Typ:</strong> <?= h(str_replace('_', ' ', $event['event_type'])) ?>
                </span>
            </div>
        </div>

        <h2>Resultat (<?= count($results) ?> deltagare)</h2>

        <?php if (empty($results)): ?>
            <p class="no-data">Inga resultat ännu</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th class="col-position">Plac</th>
                            <th class="col-bib">#</th>
                            <th class="col-name">Namn</th>
                            <th class="col-club">Klubb</th>
                            <th class="col-category">Kategori</th>
                            <th class="col-time">Tid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr class="<?= $result['position'] <= 3 ? 'podium-' . $result['position'] : '' ?>">
                                <td class="col-position">
                                    <?php if ($result['position']): ?>
                                        <?= $result['position'] ?>
                                        <?php if ($result['position'] <= 3): ?>
                                            <span class="medal">🏆</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="col-bib"><?= h($result['bib_number']) ?></td>
                                <td class="col-name">
                                    <a href="/public/cyclist.php?id=<?= $result['cyclist_id'] ?>">
                                        <?= h($result['cyclist_name']) ?>
                                    </a>
                                    <?php if ($result['birth_year']): ?>
                                        <span class="year">(<?= $result['birth_year'] ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-club"><?= h($result['club_name']) ?></td>
                                <td class="col-category"><?= h($result['category_name']) ?></td>
                                <td class="col-time"><?= formatTime($result['finish_time']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> TheHUB</p>
        </div>
    </footer>
</body>
</html>
