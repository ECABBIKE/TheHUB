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

// Calculate age and determine current class
require_once __DIR__ . '/includes/class-calculations.php';
$currentYear = date('Y');
$age = $currentYear - ($rider['birth_year'] ?? 0);
$currentClass = null;
$currentClassName = null;

if ($rider['birth_year'] && $rider['gender']) {
    $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'));
    if ($classId) {
        $class = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$classId]);
        $currentClass = $class['name'];
        $currentClassName = $class['display_name'];
    }
}

// Check license status
$licenseCheck = checkLicense($rider);

$pageTitle = $rider['firstname'] . ' ' . $rider['lastname'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<style>
    .license-card-container {
        perspective: 1000px;
        margin-bottom: 2rem;
    }

    .license-card {
        max-width: 856px;
        margin: 0 auto;
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        position: relative;
        overflow: hidden;
        transform-style: preserve-3d;
        transition: transform 0.6s;
    }

    .license-card:hover {
        transform: rotateY(2deg) rotateX(1deg);
    }

    /* UCI Stripe */
    .uci-stripe {
        height: 8px;
        background: linear-gradient(90deg,
            #E31E24 0% 20%,
            #000000 20% 40%,
            #FFD700 40% 60%,
            #0066CC 60% 80%,
            #009B3A 80% 100%
        );
    }

    /* Header */
    .license-header {
        padding: 30px 40px;
        background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
        color: white;
    }

    .license-header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .license-title {
        font-size: 28px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
    }

    .license-season {
        font-size: 20px;
        font-weight: 600;
        background: rgba(255, 255, 255, 0.2);
        padding: 8px 20px;
        border-radius: 30px;
    }

    /* Main Content */
    .license-content {
        display: grid;
        grid-template-columns: 200px 1fr;
        gap: 40px;
        padding: 40px;
    }

    /* Photo Section */
    .license-photo {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 20px;
    }

    .photo-frame {
        width: 180px;
        height: 240px;
        background: linear-gradient(135deg, #e0e0e0 0%, #f5f5f5 100%);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 4px solid #fff;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        overflow: hidden;
    }

    .photo-frame img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .photo-placeholder {
        font-size: 64px;
        color: #999;
    }

    .qr-code {
        width: 120px;
        height: 120px;
        background: white;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        color: #999;
        text-align: center;
        padding: 10px;
    }

    /* Info Section */
    .license-info {
        display: flex;
        flex-direction: column;
        gap: 25px;
    }

    .rider-name {
        font-size: 42px;
        font-weight: 800;
        color: #1a202c;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: -0.5px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .info-field {
        background: white;
        padding: 15px 20px;
        border-radius: 10px;
        border-left: 4px solid #667eea;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .info-label {
        font-size: 11px;
        color: #718096;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
    }

    .info-value {
        font-size: 20px;
        color: #1a202c;
        font-weight: 700;
    }

    /* Class Badge */
    .class-badge {
        grid-column: span 2;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px 30px;
        border-radius: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }

    .class-info {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .class-label {
        font-size: 12px;
        opacity: 0.9;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .class-name {
        font-size: 32px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .class-code {
        background: rgba(255, 255, 255, 0.25);
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 2px;
    }

    /* Footer */
    .license-footer {
        padding: 15px 40px;
        background: rgba(0, 0, 0, 0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 11px;
        color: #718096;
    }

    .club-logo {
        height: 30px;
        width: auto;
    }

    @media (max-width: 768px) {
        .license-content {
            grid-template-columns: 1fr;
            gap: 20px;
            padding: 20px;
        }

        .rider-name {
            font-size: 32px;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .license-header {
            padding: 20px;
        }

        .license-title {
            font-size: 20px;
        }
    }
</style>

    <main class="gs-main-content">
        <div class="gs-container">

            <!-- Back Button -->
            <div class="gs-mb-lg">
                <a href="/riders.php" class="gs-btn gs-btn-outline gs-btn-sm">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka till deltagare
                </a>
            </div>

            <!-- UCI License Card -->
            <div class="license-card-container">
                <div class="license-card">
                    <!-- UCI Color Stripe -->
                    <div class="uci-stripe"></div>

                    <!-- Header -->
                    <div class="license-header">
                        <div class="license-header-content">
                            <div class="license-title">Cycling License</div>
                            <div class="license-season"><?= $currentYear ?></div>
                        </div>
                    </div>

                    <!-- Main Content -->
                    <div class="license-content">
                        <!-- Photo & QR Section -->
                        <div class="license-photo">
                            <div class="photo-frame">
                                <?php if (!empty($rider['photo'])): ?>
                                    <img src="<?= h($rider['photo']) ?>" alt="<?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>">
                                <?php else: ?>
                                    <div class="photo-placeholder">👤</div>
                                <?php endif; ?>
                            </div>
                            <div class="qr-code">
                                QR-kod<br>
                                <?= h($rider['license_number'] ?? 'ID: ' . $riderId) ?>
                            </div>
                        </div>

                        <!-- Info Section -->
                        <div class="license-info">
                            <div class="rider-name">
                                <?= h($rider['firstname']) ?><br>
                                <?= h($rider['lastname']) ?>
                            </div>

                            <div class="info-grid">
                                <div class="info-field">
                                    <div class="info-label">Födelsedatum</div>
                                    <div class="info-value">
                                        <?= $rider['birth_year'] ? $rider['birth_year'] . '-XX-XX' : '–' ?>
                                    </div>
                                </div>

                                <div class="info-field">
                                    <div class="info-label">Ålder</div>
                                    <div class="info-value">
                                        <?= $age ?> år
                                    </div>
                                </div>

                                <div class="info-field">
                                    <div class="info-label">Kön</div>
                                    <div class="info-value">
                                        <?= $rider['gender'] === 'M' ? 'Man' : ($rider['gender'] === 'K' ? 'Kvinna' : '–') ?>
                                    </div>
                                </div>

                                <div class="info-field">
                                    <div class="info-label">Licens #</div>
                                    <div class="info-value">
                                        <?= h($rider['license_number']) ?: sprintf('#%04d', $riderId) ?>
                                    </div>
                                </div>

                                <?php if ($rider['club_name']): ?>
                                    <div class="info-field" style="grid-column: span 2;">
                                        <div class="info-label">Klubb</div>
                                        <div class="info-value"><?= h($rider['club_name']) ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($currentClass): ?>
                                    <div class="class-badge">
                                        <div class="class-info">
                                            <div class="class-label">Tävlingsklass <?= $currentYear ?></div>
                                            <div class="class-name"><?= h($currentClassName) ?></div>
                                        </div>
                                        <div class="class-code"><?= h($currentClass) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="license-footer">
                        <div>
                            <?php if ($rider['club_logo']): ?>
                                <img src="<?= h($rider['club_logo']) ?>" alt="<?= h($rider['club_name']) ?>" class="club-logo">
                            <?php else: ?>
                                TheHUB Cycling Management
                            <?php endif; ?>
                        </div>
                        <div>
                            Giltig: <?= $currentYear ?>-01-01 till <?= $currentYear ?>-12-31
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-5 gs-gap-md gs-mb-xl">
                <div class="gs-card" style="text-align: center;">
                    <div class="gs-h2 gs-text-primary"><?= $totalRaces ?></div>
                    <div class="gs-text-sm gs-text-secondary">Lopp</div>
                </div>
                <div class="gs-card" style="text-align: center;">
                    <div class="gs-h2" style="color: var(--gs-success);"><?= $wins ?></div>
                    <div class="gs-text-sm gs-text-secondary">Segrar</div>
                </div>
                <div class="gs-card" style="text-align: center;">
                    <div class="gs-h2" style="color: var(--gs-accent);"><?= $podiums ?></div>
                    <div class="gs-text-sm gs-text-secondary">Pallplatser</div>
                </div>
                <div class="gs-card" style="text-align: center;">
                    <div class="gs-h2 gs-text-primary"><?= $totalPoints ?></div>
                    <div class="gs-text-sm gs-text-secondary">Poäng</div>
                </div>
                <div class="gs-card" style="text-align: center;">
                    <div class="gs-h2" style="color: var(--gs-warning);"><?= $bestPosition ?? '-' ?></div>
                    <div class="gs-text-sm gs-text-secondary">Bästa placering</div>
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
                                                <th style="text-align: center;">Resultat</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($yearResults as $result): ?>
                                                <tr>
                                                    <td><?= date('Y-m-d', strtotime($result['event_date'])) ?></td>
                                                    <td>
                                                        <?php if ($result['event_id']): ?>
                                                            <a href="/event.php?id=<?= $result['event_id'] ?>" class="gs-link">
                                                                <strong><?= h($result['event_name']) ?></strong>
                                                            </a>
                                                        <?php else: ?>
                                                            <strong><?= h($result['event_name']) ?></strong>
                                                        <?php endif; ?>
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
                                                    <td style="text-align: center;">
                                                        <?php if ($result['event_id']): ?>
                                                            <a href="/event.php?id=<?= $result['event_id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Se alla resultat">
                                                                <i data-lucide="list" style="width: 14px; height: 14px;"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
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
