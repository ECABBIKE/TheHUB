<?php
require_once __DIR__ . '/config.php';

$db = getDB();

// Get rider ID from URL
$riderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$riderId) {
    header('Location: /riders.php');
    exit;
}

// Fetch rider details
$rider = $db->getRow("
    SELECT
        r.*,
        c.name as club_name,
        c.city as club_city
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.id = ?
", [$riderId]);

if (!$rider) {
    header('Location: /riders.php');
    exit;
}

// Fetch rider's results with event details
$results = $db->getAll("
    SELECT
        res.*,
        e.name as event_name,
        e.date as event_date,
        e.location as event_location,
        s.name as series_name,
        v.name as venue_name,
        v.city as venue_city
    FROM results res
    INNER JOIN events e ON res.event_id = e.id
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN venues v ON e.venue_id = v.id
    WHERE res.cyclist_id = ?
    ORDER BY e.date DESC
", [$riderId]);

// Calculate statistics
$totalRaces = count($results);
$podiums = 0;
$wins = 0;
$bestPosition = null;
$totalPoints = 0;
$dnfCount = 0;

foreach ($results as $result) {
    if ($result['position']) {
        if ($result['position'] == 1) $wins++;
        if ($result['position'] <= 3) $podiums++;
        if ($bestPosition === null || $result['position'] < $bestPosition) {
            $bestPosition = $result['position'];
        }
    }
    $totalPoints += $result['points'] ?? 0;
    if ($result['status'] === 'dnf') $dnfCount++;
}

// Get recent results (last 5)
$recentResults = array_slice($results, 0, 5);

// Get results by year
$resultsByYear = [];
foreach ($results as $result) {
    $year = date('Y', strtotime($result['event_date']));
    if (!isset($resultsByYear[$year])) {
        $resultsByYear[$year] = [];
    }
    $resultsByYear[$year][] = $result;
}
krsort($resultsByYear); // Sort by year descending

// Check license status
$licenseCheck = checkLicense($rider);

$pageTitle = $rider['firstname'] . ' ' . $rider['lastname'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main class="gs-main-content">
        <div class="gs-container">

            <!-- Back Button -->
            <div class="gs-mb-lg">
                <a href="/riders.php" class="gs-btn gs-btn-outline gs-btn-sm">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka till deltagare
                </a>
            </div>

            <!-- Profile Header -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-content" style="padding: var(--gs-space-xl);">
                    <div class="gs-flex gs-items-start gs-gap-lg">
                        <!-- Avatar -->
                        <div class="gs-avatar gs-avatar-xl gs-bg-primary" style="width: 120px; height: 120px; flex-shrink: 0;">
                            <i data-lucide="user" class="gs-text-white" style="width: 60px; height: 60px;"></i>
                        </div>

                        <!-- Info -->
                        <div class="gs-flex-1">
                            <h1 class="gs-h1 gs-text-primary gs-mb-sm">
                                <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                            </h1>

                            <!-- Badges -->
                            <div class="gs-flex gs-gap-sm gs-flex-wrap gs-mb-md">
                                <span class="gs-badge gs-badge-secondary">
                                    <?= $rider['gender'] == 'M' ? '👨 Herr' : ($rider['gender'] == 'F' ? '👩 Dam' : '👤 Okänt') ?>
                                </span>
                                <?php if ($rider['birth_year']): ?>
                                    <span class="gs-badge gs-badge-secondary">
                                        <?= calculateAge($rider['birth_year']) ?> år
                                    </span>
                                <?php endif; ?>
                                <?php if ($rider['club_name']): ?>
                                    <span class="gs-badge gs-badge-primary">
                                        <i data-lucide="building"></i>
                                        <?= h($rider['club_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- License Info -->
                            <div class="gs-mb-lg">
                                <h3 class="gs-h5 gs-text-primary gs-mb-sm">Licens</h3>
                                <div class="gs-flex gs-gap-sm gs-flex-wrap">
                                    <?php if ($rider['license_number']): ?>
                                        <span class="gs-badge <?= strpos($rider['license_number'], 'SWE') === 0 ? 'gs-badge-warning' : 'gs-badge-primary' ?>">
                                            <?= strpos($rider['license_number'], 'SWE') === 0 ? 'SWE-ID' : 'UCI' ?>: <?= h($rider['license_number']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($rider['license_type']): ?>
                                        <span class="gs-badge gs-badge-secondary">
                                            Typ: <?= h($rider['license_type']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php
                                    // Show license status
                                    if (!empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') === 0): ?>
                                        <span class="gs-badge gs-badge-danger">
                                            ✗ Ej aktiv licens
                                        </span>
                                    <?php elseif (!empty($rider['license_type']) && $rider['license_type'] !== 'None'):
                                        if ($licenseCheck['valid']): ?>
                                            <span class="gs-badge gs-badge-success">
                                                ✓ <?= h($licenseCheck['message']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="gs-badge gs-badge-danger">
                                                ✗ <?= h($licenseCheck['message']) ?>
                                            </span>
                                        <?php endif;
                                    endif; ?>
                                </div>
                            </div>

                            <!-- Quick Stats -->
                            <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-5 gs-gap-md">
                                <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius); text-align: center;">
                                    <div class="gs-h2 gs-text-primary"><?= $totalRaces ?></div>
                                    <div class="gs-text-sm gs-text-secondary">Lopp</div>
                                </div>
                                <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius); text-align: center;">
                                    <div class="gs-h2" style="color: var(--gs-success);"><?= $wins ?></div>
                                    <div class="gs-text-sm gs-text-secondary">Segrar</div>
                                </div>
                                <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius); text-align: center;">
                                    <div class="gs-h2" style="color: var(--gs-accent);"><?= $podiums ?></div>
                                    <div class="gs-text-sm gs-text-secondary">Pallplatser</div>
                                </div>
                                <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius); text-align: center;">
                                    <div class="gs-h2 gs-text-primary"><?= $totalPoints ?></div>
                                    <div class="gs-text-sm gs-text-secondary">Poäng</div>
                                </div>
                                <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius); text-align: center;">
                                    <div class="gs-h2" style="color: var(--gs-warning);"><?= $bestPosition ?? '-' ?></div>
                                    <div class="gs-text-sm gs-text-secondary">Bästa placering</div>
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
                        Denna deltagare har inte några tävlingsresultat uppladdat.
                    </p>
                </div>
            <?php else: ?>
                <!-- Results by Year -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="trophy"></i>
                            Tävlingsresultat (<?= $totalRaces ?>)
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php foreach ($resultsByYear as $year => $yearResults): ?>
                            <div class="gs-mb-xl">
                                <h3 class="gs-h5 gs-text-primary gs-mb-md">
                                    <i data-lucide="calendar"></i>
                                    <?= $year ?> (<?= count($yearResults) ?> lopp)
                                </h3>

                                <div class="gs-table-responsive">
                                    <table class="gs-table">
                                        <thead>
                                            <tr>
                                                <th>Datum</th>
                                                <th>Tävling</th>
                                                <th>Plats</th>
                                                <th>Serie</th>
                                                <th style="text-align: center;">Placering</th>
                                                <th style="text-align: center;">Tid</th>
                                                <th style="text-align: center;">Poäng</th>
                                                <th style="text-align: center;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($yearResults as $result): ?>
                                                <tr>
                                                    <td><?= date('Y-m-d', strtotime($result['event_date'])) ?></td>
                                                    <td>
                                                        <strong><?= h($result['event_name']) ?></strong>
                                                        <?php if ($result['venue_name']): ?>
                                                            <br><span class="gs-text-xs gs-text-secondary">
                                                                <i data-lucide="map-pin"></i>
                                                                <?= h($result['venue_name']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($result['event_location']): ?>
                                                            <?= h($result['event_location']) ?>
                                                        <?php elseif ($result['venue_city']): ?>
                                                            <?= h($result['venue_city']) ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($result['series_name']): ?>
                                                            <span class="gs-badge gs-badge-primary gs-badge-sm">
                                                                <?= h($result['series_name']) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?php if ($result['position']): ?>
                                                            <?php if ($result['position'] == 1): ?>
                                                                <span class="gs-badge gs-badge-success" style="font-weight: bold;">🥇 1</span>
                                                            <?php elseif ($result['position'] == 2): ?>
                                                                <span class="gs-badge gs-badge-secondary" style="font-weight: bold;">🥈 2</span>
                                                            <?php elseif ($result['position'] == 3): ?>
                                                                <span class="gs-badge gs-badge-warning" style="font-weight: bold;">🥉 3</span>
                                                            <?php else: ?>
                                                                <span><?= $result['position'] ?></span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?= $result['finish_time'] ? h($result['finish_time']) : '-' ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?= $result['points'] ?? 0 ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?php
                                                        $statusBadge = 'gs-badge-success';
                                                        $statusText = 'Slutförd';
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
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
