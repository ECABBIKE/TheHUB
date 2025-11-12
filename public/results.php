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
     JOIN riders c ON r.cyclist_id = c.id
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
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="gs-nav">
        <div class="gs-container">
            <ul class="gs-nav-list">
                <li><a href="/public/index.php" class="gs-nav-link">
                    <i data-lucide="home"></i> Hem
                </a></li>
                <li><a href="/public/events.php" class="gs-nav-link">
                    <i data-lucide="calendar"></i> Tävlingar
                </a></li>
                <li><a href="/public/results.php" class="gs-nav-link active">
                    <i data-lucide="trophy"></i> Resultat
                </a></li>
                <li style="margin-left: auto;"><a href="/admin/login.php" class="gs-btn gs-btn-sm gs-btn-primary">
                    <i data-lucide="log-in"></i> Admin
                </a></li>
            </ul>
        </div>
    </nav>

    <main class="gs-container gs-py-xl">
        <!-- Event Header -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <h1 class="gs-h2 gs-text-primary gs-mb-md">
                    <i data-lucide="trophy"></i>
                    <?= h($event['name']) ?>
                </h1>
                <div class="gs-flex gs-flex-col gs-gap-sm gs-text-secondary gs-text-sm">
                    <div>
                        <i data-lucide="calendar"></i>
                        <span class="gs-text-primary" style="font-weight: 600;">Datum:</span>
                        <?= formatDate($event['event_date'], 'd M Y') ?>
                    </div>
                    <div>
                        <i data-lucide="map-pin"></i>
                        <span class="gs-text-primary" style="font-weight: 600;">Plats:</span>
                        <?= h($event['location']) ?>
                    </div>
                    <?php if ($event['distance']): ?>
                        <div>
                            <i data-lucide="route"></i>
                            <span class="gs-text-primary" style="font-weight: 600;">Distans:</span>
                            <?= $event['distance'] ?> km
                        </div>
                    <?php endif; ?>
                    <div>
                        <i data-lucide="flag"></i>
                        <span class="gs-text-primary" style="font-weight: 600;">Typ:</span>
                        <?= h(str_replace('_', ' ', $event['event_type'])) ?>
                    </div>
                </div>
            </div>
        </div>

        <h2 class="gs-h3 gs-text-primary gs-mb-lg">
            <i data-lucide="list"></i>
            Resultat (<?= count($results) ?> deltagare)
        </h2>

        <?php if (empty($results)): ?>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center gs-py-xl">
                    <p class="gs-text-secondary">Inga resultat ännu</p>
                </div>
            </div>
        <?php else: ?>
            <!-- Results Table -->
            <div class="gs-card">
                <div class="gs-table-responsive">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Plac</th>
                                <th style="width: 60px;">#</th>
                                <th>Namn</th>
                                <th>Klubb</th>
                                <th>Kategori</th>
                                <th style="width: 100px;">Tid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <tr class="<?= $result['position'] >= 1 && $result['position'] <= 3 ? 'gs-podium-' . $result['position'] : '' ?>">
                                    <td style="font-weight: 700; color: var(--gs-primary);">
                                        <?php if ($result['position']): ?>
                                            <?= $result['position'] ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="gs-text-secondary"><?= h($result['bib_number']) ?></td>
                                    <td>
                                        <a href="/public/cyclist.php?id=<?= $result['cyclist_id'] ?>" style="color: var(--gs-text-primary); text-decoration: none; font-weight: 500;">
                                            <?= h($result['cyclist_name']) ?>
                                        </a>
                                        <?php if ($result['birth_year']): ?>
                                            <span class="gs-text-secondary gs-text-xs" style="margin-left: var(--gs-space-xs);">
                                                (<?= $result['birth_year'] ?>)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="gs-text-secondary"><?= h($result['club_name']) ?></td>
                                    <td>
                                        <span class="gs-badge gs-badge-primary gs-text-xs">
                                            <?= h($result['category_name']) ?>
                                        </span>
                                    </td>
                                    <td class="gs-text-secondary" style="font-family: monospace;">
                                        <?= formatTime($result['finish_time']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Back Button -->
        <div class="gs-mt-lg">
            <a href="/public/events.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka till tävlingar
            </a>
        </div>
    </main>

    <!-- Footer -->
    <footer class="gs-bg-dark gs-text-white gs-py-xl gs-text-center">
        <div class="gs-container">
            <p>&copy; <?= date('Y') ?> TheHUB - Sveriges plattform för cykeltävlingar</p>
            <p class="gs-text-sm gs-text-secondary" style="margin-top: var(--gs-space-sm);">
                <i data-lucide="palette"></i>
                GravitySeries Design System + Lucide Icons
            </p>
        </div>
    </footer>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
        });
    </script>
</body>
</html>
