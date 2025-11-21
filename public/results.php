<?php
require_once __DIR__ . '/../config.php';

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

// Helper function to convert time string to seconds
function timeToSeconds($timeStr) {
    if (empty($timeStr)) return 0;

    // Handle formats like "0:14:16.42" or "14:16.42" or "1:42.33"
    $parts = explode(':', $timeStr);
    $seconds = 0;

    if (count($parts) === 3) {
        // h:mm:ss.cc
        $seconds = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (float)$parts[2];
    } elseif (count($parts) === 2) {
        // mm:ss.cc
        $seconds = (int)$parts[0] * 60 + (float)$parts[1];
    } else {
        $seconds = (float)$timeStr;
    }

    return $seconds;
}

// Helper function to format seconds back to time
function formatSecondsAsTime($seconds) {
    if ($seconds <= 0) return '';

    $minutes = floor($seconds / 60);
    $secs = $seconds - ($minutes * 60);

    if ($minutes >= 60) {
        $hours = floor($minutes / 60);
        $minutes = $minutes % 60;
        return sprintf('%d:%02d:%05.2f', $hours, $minutes, $secs);
    }

    return sprintf('%d:%05.2f', $minutes, $secs);
}

// Get results with split times and class info (including class settings)
$results = $db->getAll(
    "SELECT
        r.position,
        r.bib_number,
        r.finish_time,
        r.status,
        r.points,
        r.time_behind,
        r.ss1, r.ss2, r.ss3, r.ss4, r.ss5, r.ss6, r.ss7, r.ss8, r.ss9, r.ss10,
        r.run_1_time, r.run_2_time,
        CONCAT(c.firstname, ' ', c.lastname) as cyclist_name,
        c.id as cyclist_id,
        c.birth_year,
        cl.name as club_name,
        COALESCE(cls.display_name, cat.name, 'Okänd') as class_name,
        COALESCE(cls.awards_points, 1) as awards_points,
        COALESCE(cls.ranking_type, 'time') as ranking_type,
        COALESCE(cls.series_eligible, 1) as series_eligible,
        COALESCE(cls.sort_order, 999) as class_sort_order
     FROM results r
     JOIN riders c ON r.cyclist_id = c.id
     LEFT JOIN clubs cl ON c.club_id = cl.id
     LEFT JOIN classes cls ON r.class_id = cls.id
     LEFT JOIN categories cat ON r.category_id = cat.id
     WHERE r.event_id = ?
     ORDER BY
        COALESCE(cls.sort_order, 999) ASC,
        COALESCE(cls.display_name, cat.name, 'ZZZ') ASC,
        CASE WHEN r.status = 'finished' THEN 0 ELSE 1 END ASC,
        r.position ASC,
        r.finish_time ASC",
    [$eventId]
);

// Calculate gap times and group by class
$resultsByClass = [];
$classTimes = [];
$classSettings = [];

foreach ($results as &$result) {
    $className = $result['class_name'] ?? 'Okänd klass';

    // Initialize class array and settings
    if (!isset($resultsByClass[$className])) {
        $resultsByClass[$className] = [];
        $classSettings[$className] = [
            'awards_points' => $result['awards_points'],
            'ranking_type' => $result['ranking_type'],
            'series_eligible' => $result['series_eligible']
        ];
    }

    // Track winner time per class and calculate gap
    if ($result['status'] === 'finished' && $result['finish_time']) {
        if (!isset($classTimes[$className])) {
            $classTimes[$className] = $result['finish_time'];
            $result['calculated_gap'] = '';
        } else {
            // Calculate gap
            $winnerTime = timeToSeconds($classTimes[$className]);
            $currentTime = timeToSeconds($result['finish_time']);
            $gap = $currentTime - $winnerTime;
            $result['calculated_gap'] = $gap > 0 ? '+' . formatSecondsAsTime($gap) : '';
        }
    } else {
        $result['calculated_gap'] = '';
    }

    $resultsByClass[$className][] = $result;
}
unset($result);

// Sort each class according to its ranking_type
foreach ($resultsByClass as $className => &$classResults) {
    $rankingType = $classSettings[$className]['ranking_type'] ?? 'time';

    if ($rankingType === 'name') {
        // Sort by name alphabetically
        usort($classResults, function($a, $b) {
            return strcasecmp($a['cyclist_name'], $b['cyclist_name']);
        });
    } elseif ($rankingType === 'bib') {
        // Sort by bib number
        usort($classResults, function($a, $b) {
            return ((int)$a['bib_number'] ?: 9999) - ((int)$b['bib_number'] ?: 9999);
        });
    }
    // 'time' is already sorted by the SQL query
}
unset($classResults);

// Check if we have split times to show
$hasSplitTimes = false;
$hasRunTimes = false;
$maxSplits = 0;
foreach ($results as $result) {
    for ($i = 1; $i <= 10; $i++) {
        if (!empty($result['ss' . $i])) {
            $hasSplitTimes = true;
            $maxSplits = max($maxSplits, $i);
        }
    }
    if (!empty($result['run_1_time']) || !empty($result['run_2_time'])) {
        $hasRunTimes = true;
    }
}

$pageTitle = $event['name'] . ' - Resultat';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
    <style>
        .gs-gap-time { color: var(--gs-text-secondary); font-size: 0.85em; }
        .gs-split-times { font-size: 0.85em; color: var(--gs-text-secondary); }
        .gs-class-header { background: var(--gs-primary); color: white; padding: var(--gs-space-md); margin-top: var(--gs-space-lg); }
        .gs-class-header:first-child { margin-top: 0; }
    </style>
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
                        <?= date('d M Y', strtotime($event['date'])) ?>
                    </div>
                    <div>
                        <i data-lucide="map-pin"></i>
                        <span class="gs-text-primary" style="font-weight: 600;">Plats:</span>
                        <?= h($event['location']) ?>
                    </div>
                    <?php if (!empty($event['distance'])): ?>
                        <div>
                            <i data-lucide="route"></i>
                            <span class="gs-text-primary" style="font-weight: 600;">Distans:</span>
                            <?= $event['distance'] ?> km
                        </div>
                    <?php endif; ?>
                    <div>
                        <i data-lucide="flag"></i>
                        <span class="gs-text-primary" style="font-weight: 600;">Typ:</span>
                        <?= h(str_replace('_', ' ', $event['type'] ?? '')) ?>
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
            <!-- Results by Class -->
            <?php foreach ($resultsByClass as $className => $classResults): ?>
                <div class="gs-card gs-mb-lg">
                    <div class="gs-class-header">
                        <h3 class="gs-h4 gs-m-0"><?= h($className) ?> (<?= count($classResults) ?>)</h3>
                    </div>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">Plac</th>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th style="width: 100px;">Tid</th>
                                    <th style="width: 100px;">+Tid</th>
                                    <?php if ($hasSplitTimes): ?>
                                        <?php for ($i = 1; $i <= $maxSplits; $i++): ?>
                                            <th style="width: 80px;">SS<?= $i ?></th>
                                        <?php endfor; ?>
                                    <?php endif; ?>
                                    <?php if ($hasRunTimes): ?>
                                        <th style="width: 80px;">Run 1</th>
                                        <th style="width: 80px;">Run 2</th>
                                    <?php endif; ?>
                                    <th style="width: 60px;">Poäng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classResults as $result): ?>
                                    <tr class="<?= $result['position'] >= 1 && $result['position'] <= 3 ? 'gs-podium-' . $result['position'] : '' ?>">
                                        <td style="font-weight: 700; color: var(--gs-primary);">
                                            <?php if ($result['status'] === 'finished' && $result['position']): ?>
                                                <?= $result['position'] ?>
                                            <?php elseif ($result['status'] === 'dnf'): ?>
                                                DNF
                                            <?php elseif ($result['status'] === 'dns'): ?>
                                                DNS
                                            <?php elseif ($result['status'] === 'dq'): ?>
                                                DQ
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
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
                                        <td style="font-family: monospace;">
                                            <?= $result['finish_time'] ? h($result['finish_time']) : '-' ?>
                                        </td>
                                        <td class="gs-gap-time" style="font-family: monospace;">
                                            <?= h($result['calculated_gap']) ?>
                                        </td>
                                        <?php if ($hasSplitTimes): ?>
                                            <?php for ($i = 1; $i <= $maxSplits; $i++): ?>
                                                <td class="gs-split-times" style="font-family: monospace;">
                                                    <?= !empty($result['ss' . $i]) ? h($result['ss' . $i]) : '-' ?>
                                                </td>
                                            <?php endfor; ?>
                                        <?php endif; ?>
                                        <?php if ($hasRunTimes): ?>
                                            <td class="gs-split-times" style="font-family: monospace;">
                                                <?= !empty($result['run_1_time']) ? h($result['run_1_time']) : '-' ?>
                                            </td>
                                            <td class="gs-split-times" style="font-family: monospace;">
                                                <?= !empty($result['run_2_time']) ? h($result['run_2_time']) : '-' ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="gs-text-secondary">
                                            <?= (int)$result['points'] ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
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
