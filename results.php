<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

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
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="gs-public-page">
    <!-- Hamburger -->
    <button class="gs-mobile-menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
        <i data-lucide="menu"></i>
    </button>

    <!-- Sidebar -->
    <?php include __DIR__ . '/includes/navigation.php'; ?>

    <!-- Overlay -->
    <div class="gs-sidebar-overlay" onclick="closeMenu()"></div>

    <!-- Main Content -->
    <main style="padding: 6rem 2rem 2rem;">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-mb-xl">
                <a href="/events.php" class="gs-btn gs-btn-sm gs-btn-outline gs-mb-md">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka till kalender
                </a>

                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="trophy"></i>
                    <?= h($event['name']) ?>
                </h1>
                <div class="gs-flex gs-gap-md gs-flex-wrap">
                    <p class="gs-text-secondary">
                        <i data-lucide="calendar" style="width: 16px; height: 16px;"></i>
                        <?= formatDate($event['event_date'], 'd M Y') ?>
                    </p>
                    <?php if ($event['location']): ?>
                        <p class="gs-text-secondary">
                            <i data-lucide="map-pin" style="width: 16px; height: 16px;"></i>
                            <?= h($event['location']) ?>
                        </p>
                    <?php endif; ?>
                    <p class="gs-text-secondary">
                        <i data-lucide="users" style="width: 16px; height: 16px;"></i>
                        <?= count($results) ?> deltagare
                    </p>
                </div>
            </div>

            <!-- Results Table -->
            <?php if (empty($results)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="trophy" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga resultat ännu</h3>
                    <p class="gs-text-secondary">
                        Resultat har inte registrerats för denna tävling.
                    </p>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h3 class="gs-h4">
                            <i data-lucide="list"></i>
                            Resultat
                        </h3>
                    </div>
                    <div class="gs-card-content" style="padding: 0; overflow-x: auto;">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Placering</th>
                                    <th>Startnr</th>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th>Kategori</th>
                                    <th>Tid</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr>
                                        <td>
                                            <?php if ($result['position'] == 1): ?>
                                                <span class="gs-badge" style="background-color: gold; color: #000;">
                                                    🥇 <?= $result['position'] ?>
                                                </span>
                                            <?php elseif ($result['position'] == 2): ?>
                                                <span class="gs-badge" style="background-color: silver; color: #000;">
                                                    🥈 <?= $result['position'] ?>
                                                </span>
                                            <?php elseif ($result['position'] == 3): ?>
                                                <span class="gs-badge" style="background-color: #CD7F32; color: #fff;">
                                                    🥉 <?= $result['position'] ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-font-bold"><?= $result['position'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-secondary"><?= h($result['bib_number']) ?></td>
                                        <td>
                                            <strong><?= h($result['cyclist_name']) ?></strong>
                                            <?php if ($result['birth_year']): ?>
                                                <span class="gs-text-sm gs-text-secondary">
                                                    (<?= $result['birth_year'] ?>)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-secondary">
                                            <?= h($result['club_name'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <?php if ($result['category_name']): ?>
                                                <span class="gs-badge gs-badge-secondary gs-text-xs">
                                                    <?= h($result['category_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-font-mono">
                                            <?= h($result['finish_time'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = 'gs-badge-success';
                                            $status_text = $result['status'] ?? 'Finished';
                                            if ($status_text == 'DNF') {
                                                $status_class = 'gs-badge-danger';
                                            } elseif ($status_text == 'DNS') {
                                                $status_class = 'gs-badge-secondary';
                                            }
                                            ?>
                                            <span class="gs-badge <?= $status_class ?> gs-text-xs">
                                                <?= h($status_text) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        lucide.createIcons();

        function toggleMenu() {
            const sidebar = document.querySelector('.gs-sidebar');
            const overlay = document.querySelector('.gs-sidebar-overlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        function closeMenu() {
            const sidebar = document.querySelector('.gs-sidebar');
            const overlay = document.querySelector('.gs-sidebar-overlay');
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        }

        // Close on link click
        document.querySelectorAll('.gs-sidebar a').forEach(link => {
            link.addEventListener('click', closeMenu);
        });
    </script>
</body>
</html>
