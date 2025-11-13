<?php
require_once __DIR__ . '/config.php';

$db = getDB();

// Get event ID from URL
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$eventId) {
    header('Location: /events.php');
    exit;
}

// Fetch event details
$event = $db->getRow("
    SELECT
        e.*,
        s.name as series_name,
        s.logo as series_logo,
        v.name as venue_name,
        v.city as venue_city,
        v.address as venue_address
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN venues v ON e.venue_id = v.id
    WHERE e.id = ?
", [$eventId]);

if (!$event) {
    header('Location: /events.php');
    exit;
}

// Fetch all results for this event with rider and category info
$results = $db->getAll("
    SELECT
        res.*,
        r.firstname,
        r.lastname,
        r.gender,
        r.birth_year,
        r.license_number,
        c.name as club_name,
        cat.name as category_name,
        cat.short_name as category_short
    FROM results res
    INNER JOIN riders r ON res.cyclist_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    LEFT JOIN categories cat ON res.category_id = cat.id
    WHERE res.event_id = ?
    ORDER BY
        COALESCE(cat.name, 'Okategoriserad'),
        CASE WHEN res.status = 'finished' THEN res.position ELSE 999 END,
        res.finish_time
", [$eventId]);

// Group results by category
$resultsByCategory = [];
$totalParticipants = count($results);
$totalFinished = 0;

foreach ($results as $result) {
    $categoryName = $result['category_name'] ?? 'Okategoriserad';

    if (!isset($resultsByCategory[$categoryName])) {
        $resultsByCategory[$categoryName] = [
            'short_name' => $result['category_short'] ?? 'N/A',
            'results' => []
        ];
    }

    $resultsByCategory[$categoryName]['results'][] = $result;

    if ($result['status'] === 'finished') {
        $totalFinished++;
    }
}

// Calculate time behind leader for each category
foreach ($resultsByCategory as $categoryName => &$categoryData) {
    $winnerTime = null;

    // Find winner's time
    foreach ($categoryData['results'] as $result) {
        if ($result['position'] == 1 && !empty($result['finish_time']) && $result['status'] === 'finished') {
            $winnerTime = $result['finish_time'];
            break;
        }
    }

    // Calculate time behind for each result
    foreach ($categoryData['results'] as &$result) {
        if ($winnerTime && !empty($result['finish_time']) && $result['status'] === 'finished' && $result['position'] > 1) {
            $winnerSeconds = strtotime("1970-01-01 $winnerTime UTC");
            $riderSeconds = strtotime("1970-01-01 {$result['finish_time']} UTC");
            $diffSeconds = $riderSeconds - $winnerSeconds;

            $hours = floor($diffSeconds / 3600);
            $minutes = floor(($diffSeconds % 3600) / 60);
            $seconds = $diffSeconds % 60;

            if ($hours > 0) {
                $result['time_behind_formatted'] = sprintf('+%d:%02d:%02d', $hours, $minutes, $seconds);
            } else {
                $result['time_behind_formatted'] = sprintf('+%d:%02d', $minutes, $seconds);
            }
        } else {
            $result['time_behind_formatted'] = null;
        }
    }
}
unset($categoryData); // Break reference

$pageTitle = $event['name'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main class="gs-main-content">
        <div class="gs-container">

            <!-- Event Header -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-content" style="padding: var(--gs-space-xl);">
                    <!-- Back Button -->
                    <div class="gs-mb-lg">
                        <a href="/events.php" class="gs-btn gs-btn-outline gs-btn-sm">
                            <i data-lucide="arrow-left"></i>
                            Tillbaka till tävlingar
                        </a>
                    </div>

                    <div class="gs-flex gs-items-start gs-gap-lg">
                        <?php if ($event['series_logo']): ?>
                            <div style="flex-shrink: 0;">
                                <img src="<?= h($event['series_logo']) ?>"
                                     alt="<?= h($event['series_name'] ?? 'Serie') ?>"
                                     style="width: 120px; height: auto; border-radius: var(--gs-border-radius);">
                            </div>
                        <?php endif; ?>

                        <div class="gs-flex-1">
                            <h1 class="gs-h1 gs-text-primary gs-mb-sm">
                                <?= h($event['name']) ?>
                            </h1>

                            <div class="gs-flex gs-gap-md gs-flex-wrap gs-mb-md">
                                <div class="gs-flex gs-items-center gs-gap-xs">
                                    <i data-lucide="calendar" style="width: 18px; height: 18px;"></i>
                                    <span class="gs-text-secondary">
                                        <?= date('l j F Y', strtotime($event['date'])) ?>
                                    </span>
                                </div>

                                <?php if ($event['venue_name']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="map-pin" style="width: 18px; height: 18px;"></i>
                                        <span class="gs-text-secondary">
                                            <?= h($event['venue_name']) ?>
                                            <?php if ($event['venue_city']): ?>
                                                , <?= h($event['venue_city']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php elseif ($event['location']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="map-pin" style="width: 18px; height: 18px;"></i>
                                        <span class="gs-text-secondary"><?= h($event['location']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($event['series_name']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="award" style="width: 18px; height: 18px;"></i>
                                        <span class="gs-badge gs-badge-primary">
                                            <?= h($event['series_name']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Event Stats -->
                            <div class="gs-flex gs-gap-md">
                                <div style="padding: var(--gs-space-sm) var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                    <span class="gs-text-sm gs-text-secondary">Deltagare: </span>
                                    <strong class="gs-text-primary"><?= $totalParticipants ?></strong>
                                </div>
                                <div style="padding: var(--gs-space-sm) var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                    <span class="gs-text-sm gs-text-secondary">Slutförda: </span>
                                    <strong class="gs-text-success"><?= $totalFinished ?></strong>
                                </div>
                                <div style="padding: var(--gs-space-sm) var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                    <span class="gs-text-sm gs-text-secondary">Kategorier: </span>
                                    <strong class="gs-text-primary"><?= count($resultsByCategory) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($results)): ?>
                <!-- No Results -->
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="trophy" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga resultat ännu</h3>
                    <p class="gs-text-secondary">
                        Resultat har inte laddats upp för denna tävling.
                    </p>
                </div>
            <?php else: ?>
                <!-- Results by Category -->
                <?php foreach ($resultsByCategory as $categoryName => $categoryData): ?>
                    <div class="gs-card gs-mb-xl category-section" data-category="<?= h($categoryName) ?>">
                        <div class="gs-card-header">
                            <h2 class="gs-h4 gs-text-primary">
                                <i data-lucide="users"></i>
                                <?= h($categoryName) ?>
                                <span class="gs-badge gs-badge-secondary gs-ml-sm">
                                    <?= count($categoryData['results']) ?> deltagare
                                </span>
                            </h2>
                        </div>
                        <div class="gs-card-content" style="padding: 0; overflow-x: auto;">
                            <table class="gs-table results-table">
                                <thead>
                                    <tr>
                                        <th style="width: 60px; text-align: center;" data-sort="position">
                                            <span style="cursor: pointer;">Plac. <i data-lucide="arrow-up-down" style="width: 14px; height: 14px;"></i></span>
                                        </th>
                                        <th data-sort="name">
                                            <span style="cursor: pointer;">Namn <i data-lucide="arrow-up-down" style="width: 14px; height: 14px;"></i></span>
                                        </th>
                                        <th data-sort="club">
                                            <span style="cursor: pointer;">Klubb <i data-lucide="arrow-up-down" style="width: 14px; height: 14px;"></i></span>
                                        </th>
                                        <th style="width: 100px; text-align: center;">Startnr</th>
                                        <th style="width: 120px; text-align: center;" data-sort="time">
                                            <span style="cursor: pointer;">Tid <i data-lucide="arrow-up-down" style="width: 14px; height: 14px;"></i></span>
                                        </th>
                                        <th style="width: 100px; text-align: center;">+Tid</th>
                                        <th style="width: 80px; text-align: center;" data-sort="points">
                                            <span style="cursor: pointer;">Poäng <i data-lucide="arrow-up-down" style="width: 14px; height: 14px;"></i></span>
                                        </th>
                                        <th style="width: 100px; text-align: center;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categoryData['results'] as $result): ?>
                                        <tr class="result-row"
                                            data-position="<?= $result['position'] ?? 999 ?>"
                                            data-name="<?= h($result['lastname'] . ' ' . $result['firstname']) ?>"
                                            data-club="<?= h($result['club_name'] ?? '') ?>"
                                            data-time="<?= $result['finish_time'] ?? '' ?>"
                                            data-points="<?= $result['points'] ?? 0 ?>">

                                            <!-- Position -->
                                            <td style="text-align: center; font-weight: bold;">
                                                <?php if ($result['status'] === 'finished' && $result['position']): ?>
                                                    <?php if ($result['position'] == 1): ?>
                                                        <span style="font-size: 1.2em;">🥇</span>
                                                    <?php elseif ($result['position'] == 2): ?>
                                                        <span style="font-size: 1.2em;">🥈</span>
                                                    <?php elseif ($result['position'] == 3): ?>
                                                        <span style="font-size: 1.2em;">🥉</span>
                                                    <?php else: ?>
                                                        <?= $result['position'] ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Name -->
                                            <td>
                                                <a href="/rider.php?id=<?= $result['cyclist_id'] ?>"
                                                   style="text-decoration: none; color: inherit; font-weight: 600;">
                                                    <?= h($result['firstname']) ?> <?= h($result['lastname']) ?>
                                                </a>
                                                <div style="font-size: 0.75rem; color: var(--gs-text-secondary); margin-top: 2px;">
                                                    <?php if ($result['birth_year']): ?>
                                                        <?= calculateAge($result['birth_year']) ?> år
                                                    <?php endif; ?>
                                                    <?php if ($result['gender']): ?>
                                                        • <?= $result['gender'] == 'M' ? 'Herr' : ($result['gender'] == 'F' ? 'Dam' : '') ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Club -->
                                            <td>
                                                <?php if ($result['club_name']): ?>
                                                    <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                        <?= h($result['club_name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Bib Number -->
                                            <td style="text-align: center;">
                                                <?= $result['bib_number'] ? h($result['bib_number']) : '<span class="gs-text-secondary">-</span>' ?>
                                            </td>

                                            <!-- Finish Time -->
                                            <td style="text-align: center; font-family: monospace; font-weight: 600;">
                                                <?php if ($result['finish_time'] && $result['status'] === 'finished'): ?>
                                                    <?= h($result['finish_time']) ?>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Time Behind -->
                                            <td style="text-align: center; font-family: monospace; color: var(--gs-text-secondary);">
                                                <?= $result['time_behind_formatted'] ?? '<span class="gs-text-secondary">-</span>' ?>
                                            </td>

                                            <!-- Points -->
                                            <td style="text-align: center; font-weight: 600;">
                                                <?= $result['points'] ?? 0 ?>
                                            </td>

                                            <!-- Status -->
                                            <td style="text-align: center;">
                                                <?php
                                                $statusBadge = 'gs-badge-success';
                                                $statusText = 'OK';
                                                if ($result['status'] === 'dnf') {
                                                    $statusBadge = 'gs-badge-danger';
                                                    $statusText = 'DNF';
                                                } elseif ($result['status'] === 'dns') {
                                                    $statusBadge = 'gs-badge-secondary';
                                                    $statusText = 'DNS';
                                                } elseif ($result['status'] === 'dq') {
                                                    $statusBadge = 'gs-badge-danger';
                                                    $statusText = 'DQ';
                                                }
                                                ?>
                                                <span class="gs-badge <?= $statusBadge ?> gs-badge-sm">
                                                    <?= $statusText ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

<?php
$additionalScripts = <<<'SCRIPT'
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Table sorting functionality
    const tables = document.querySelectorAll('.results-table');

    tables.forEach(table => {
        const headers = table.querySelectorAll('th[data-sort]');

        headers.forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', function() {
                const sortKey = this.getAttribute('data-sort');
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));

                // Determine sort direction
                const currentDir = this.getAttribute('data-sort-dir') || 'asc';
                const newDir = currentDir === 'asc' ? 'desc' : 'asc';

                // Reset all headers
                headers.forEach(h => {
                    h.removeAttribute('data-sort-dir');
                    const icon = h.querySelector('i[data-lucide]');
                    if (icon) icon.setAttribute('data-lucide', 'arrow-up-down');
                });

                // Set current header
                this.setAttribute('data-sort-dir', newDir);
                const icon = this.querySelector('i[data-lucide]');
                if (icon) icon.setAttribute('data-lucide', newDir === 'asc' ? 'arrow-up' : 'arrow-down');

                // Sort rows
                rows.sort((a, b) => {
                    let aVal, bVal;

                    if (sortKey === 'position') {
                        aVal = parseInt(a.getAttribute('data-position')) || 999;
                        bVal = parseInt(b.getAttribute('data-position')) || 999;
                    } else if (sortKey === 'points') {
                        aVal = parseInt(a.getAttribute('data-points')) || 0;
                        bVal = parseInt(b.getAttribute('data-points')) || 0;
                    } else if (sortKey === 'time') {
                        aVal = a.getAttribute('data-time') || 'ZZZ';
                        bVal = b.getAttribute('data-time') || 'ZZZ';
                    } else {
                        aVal = a.getAttribute('data-' + sortKey).toLowerCase();
                        bVal = b.getAttribute('data-' + sortKey).toLowerCase();
                    }

                    if (aVal < bVal) return newDir === 'asc' ? -1 : 1;
                    if (aVal > bVal) return newDir === 'asc' ? 1 : -1;
                    return 0;
                });

                // Reorder DOM
                rows.forEach(row => tbody.appendChild(row));

                // Reinitialize Lucide icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
        });
    });
});
</script>
SCRIPT;

include __DIR__ . '/includes/layout-footer.php';
?>
