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
// IMPORTANT: Only select PUBLIC fields - never expose private data (personnummer, address, phone, etc.)
// Try to select new fields, fall back to old schema if they don't exist
try {
    $rider = $db->getRow("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.birth_year,
            r.gender,
            r.club_id,
            r.license_number,
            r.license_type,
            r.license_category,
            r.discipline,
            r.license_valid_until,
            r.city,
            r.active,
            c.name as club_name,
            c.city as club_city
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.id = ?
    ", [$riderId]);

    // Set default values for new fields that might not exist in old schema
    $rider['team'] = $rider['team'] ?? null;
    $rider['disciplines'] = $rider['disciplines'] ?? null;
    $rider['license_year'] = $rider['license_year'] ?? null;
    $rider['country'] = $rider['country'] ?? null;
    $rider['district'] = $rider['district'] ?? null;
    $rider['photo'] = $rider['photo'] ?? null;

} catch (Exception $e) {
    // If even basic query fails, something else is wrong
    error_log("Error fetching rider: " . $e->getMessage());
    header('Location: /riders.php');
    exit;
}

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

// Get series standings for this rider - CLASS BASED
$seriesStandings = [];

// Get rider's current class
if ($rider['birth_year'] && $rider['gender']) {
    $riderClassId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'));

    if ($riderClassId) {
        $riderClass = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$riderClassId]);

        // Get series data for this rider
        $riderSeriesData = $db->getAll("
            SELECT
                s.id as series_id,
                s.name as series_name,
                s.year,
                SUM(r.points) as total_points,
                COUNT(DISTINCT r.event_id) as events_count
            FROM results r
            JOIN events e ON r.event_id = e.id
            JOIN series s ON e.series_id = s.id
            WHERE r.cyclist_id = ? AND s.active = 1
            GROUP BY s.id
            ORDER BY s.year DESC, total_points DESC
        ", [$riderId]);

        // Calculate class-based position for each series
        foreach ($riderSeriesData as $seriesData) {
            // Get all riders in the same class for this series
            $classRidersPoints = $db->getAll("
                SELECT
                    riders.id as cyclist_id,
                    riders.birth_year,
                    riders.gender,
                    SUM(results.points) as total_points
                FROM results
                JOIN events ON results.event_id = events.id
                JOIN riders ON results.cyclist_id = riders.id
                WHERE events.series_id = ?
                GROUP BY riders.id
                ORDER BY total_points DESC
            ", [$seriesData['series_id']]);

            // Filter to only riders in the same class and calculate position
            $position = 1;
            $classCount = 0;
            $riderPosition = null;

            foreach ($classRidersPoints as $riderPoints) {
                // Check if this rider is in the same class
                $otherRiderClassId = determineRiderClass($db, $riderPoints['birth_year'], $riderPoints['gender'], date('Y-m-d'));

                if ($otherRiderClassId == $riderClassId) {
                    $classCount++;
                    if ($riderPoints['cyclist_id'] == $riderId) {
                        $riderPosition = $position;
                    }
                    $position++;
                }
            }

            $seriesData['position'] = $riderPosition ?? '?';
            $seriesData['class_total'] = $classCount;
            $seriesData['class_name'] = $riderClass['display_name'];
            $seriesStandings[] = $seriesData;
        }
    }
}

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
$age = ($rider['birth_year'] && $rider['birth_year'] > 0)
    ? ($currentYear - $rider['birth_year'])
    : null;
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
        margin-bottom: 2rem;
    }

    .license-card {
        max-width: 600px;
        margin: 0 auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        overflow: hidden;
    }

    /* GravitySeries Stripe */
    .uci-stripe {
        height: 6px;
        background: linear-gradient(90deg,
            #004a98 0% 25%,
            #8A9A5B 25% 50%,
            #EF761F 50% 75%,
            #FFE009 75% 100%
        );
    }

    /* Header */
    .license-header {
        padding: 1.5rem;
        background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
        color: white;
        text-align: center;
    }

    .license-season {
        font-size: 1rem;
        font-weight: 600;
        background: rgba(255, 255, 255, 0.2);
        padding: 0.5rem 1rem;
        border-radius: 20px;
        display: inline-block;
    }

    /* Main Content */
    .license-content {
        padding: 1.5rem;
        display: grid;
        grid-template-columns: 180px 1fr;
        gap: 1.5rem;
        align-items: start;
    }

    /* Photo Section */
    .license-photo {
        width: 100%;
    }

    .photo-frame {
        width: 180px;
        height: 240px;
        border-radius: 8px;
        overflow: hidden;
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .photo-frame img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .photo-placeholder {
        font-size: 4rem;
        opacity: 0.3;
    }

    /* Info Section */
    .license-info {
        flex: 1;
    }

    /* Name */
    .rider-name {
        font-size: clamp(1.5rem, 5vw, 2rem);
        font-weight: 800;
        color: #1a202c;
        line-height: 1.2;
        text-transform: uppercase;
        margin-bottom: 0.75rem;
        text-align: left;
    }

    /* License ID */
    .license-id {
        text-align: left;
        font-size: clamp(0.875rem, 3vw, 1rem);
        color: #667eea;
        font-weight: 600;
        margin-bottom: 1.5rem;
    }

    /* Info Grid - Compact boxes */
    .info-grid-compact {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .info-box {
        background: #f8f9fa;
        padding: 0.75rem;
        border-radius: 8px;
        border-left: 3px solid #667eea;
        text-align: center;
    }

    .info-box-label {
        font-size: clamp(0.625rem, 2vw, 0.75rem);
        color: #718096;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.25rem;
    }

    .info-box-value {
        font-size: clamp(0.875rem, 3vw, 1rem);
        color: #1a202c;
        font-weight: 700;
        word-break: break-word;
    }

    /* Full width fields */
    .info-field-wide {
        background: #f8f9fa;
        padding: 0.75rem;
        border-radius: 8px;
        border-left: 3px solid #667eea;
        margin-bottom: 0.75rem;
    }

    .info-field-wide .info-box-label {
        text-align: left;
    }

    .info-field-wide .info-box-value {
        text-align: left;
        font-size: clamp(0.875rem, 3vw, 1rem);
    }

    /* Class Badge */
    .class-badge-compact {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 0.75rem;
        text-align: center;
    }

    .class-badge-compact .class-label {
        font-size: clamp(0.625rem, 2vw, 0.75rem);
        opacity: 0.9;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 0.25rem;
    }

    .class-badge-compact .class-name {
        font-size: clamp(1rem, 4vw, 1.25rem);
        font-weight: 800;
    }

    /* License Status Badge */
    .license-status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: clamp(0.75rem, 2.5vw, 0.875rem);
        font-weight: 600;
    }

    .license-status-badge.active {
        background: #10b981;
        color: white;
    }

    .license-status-badge.inactive {
        background: #ef4444;
        color: white;
    }

    /* Footer */
    .license-footer {
        padding: 0.75rem 1.5rem;
        background: rgba(0, 0, 0, 0.03);
        text-align: center;
        font-size: clamp(0.625rem, 2vw, 0.75rem);
        color: #718096;
    }

    @media (max-width: 768px) {
        .license-content {
            grid-template-columns: 1fr;
            gap: 1rem;
            padding: 1rem;
        }

        .photo-frame {
            width: 150px;
            height: 200px;
            margin: 0 auto;
        }

        .rider-name,
        .license-id {
            text-align: center;
        }
    }

    @media (max-width: 480px) {
        .info-grid-compact {
            grid-template-columns: repeat(2, 1fr);
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
                    <!-- GravitySeries Stripe -->
                    <div class="uci-stripe"></div>

                    <!-- Header -->
                    <div class="license-header">
                        <div class="license-season"><?= $currentYear ?></div>
                    </div>

                    <!-- Main Content -->
                    <div class="license-content">
                        <!-- Photo Section (LEFT) -->
                        <div class="license-photo">
                            <div class="photo-frame">
                                <?php if (!empty($rider['photo'])): ?>
                                    <img src="<?= h($rider['photo']) ?>" alt="<?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>">
                                <?php else: ?>
                                    <div class="photo-placeholder">👤</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Info Section (RIGHT) -->
                        <div class="license-info">
                            <!-- Rider Name -->
                            <div class="rider-name">
                                <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                            </div>

                            <!-- License ID -->
                            <div class="license-id">
                                <?php
                                $isUciLicense = !empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') !== 0;
                                if ($isUciLicense): ?>
                                    UCI: <?= h($rider['license_number']) ?>
                                <?php elseif (!empty($rider['license_number'])): ?>
                                    SWE-ID: <?= h($rider['license_number']) ?>
                                <?php else: ?>
                                    ID: #<?= sprintf('%04d', $riderId) ?>
                                <?php endif; ?>
                            </div>

                        <!-- Compact Info Boxes -->
                        <div class="info-grid-compact">
                            <div class="info-box">
                                <div class="info-box-label">Ålder</div>
                                <div class="info-box-value">
                                    <?= $age !== null ? $age . ' år' : '–' ?>
                                </div>
                            </div>

                            <div class="info-box">
                                <div class="info-box-label">Kön</div>
                                <div class="info-box-value">
                                    <?= $rider['gender'] === 'M' ? 'Man' : ($rider['gender'] === 'K' ? 'Kvinna' : '–') ?>
                                </div>
                            </div>

                            <?php if (!empty($rider['license_type']) && $rider['license_type'] !== 'None'): ?>
                                <div class="info-box">
                                    <div class="info-box-label">Licenstyp</div>
                                    <div class="info-box-value" style="font-size: clamp(0.75rem, 2.5vw, 0.875rem);">
                                        <?= h($rider['license_type']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($rider['license_year'])): ?>
                                <div class="info-box">
                                    <div class="info-box-label">Aktiv Licens</div>
                                    <div class="info-box-value">
                                        <?php
                                        $isActive = ($rider['license_year'] == $currentYear);
                                        ?>
                                        <span class="license-status-badge <?= $isActive ? 'active' : 'inactive' ?>">
                                            <?= $isActive ? '✓ ' . $currentYear : '✗ ' . ($rider['license_year'] ?? '–') ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Klubb (Full width) -->
                        <div class="info-field-wide">
                            <div class="info-box-label">Klubb</div>
                            <div class="info-box-value">
                                <?php if ($rider['club_name'] && $rider['club_id']): ?>
                                    <a href="/club.php?id=<?= $rider['club_id'] ?>" style="color: #667eea; text-decoration: none;">
                                        <?= h($rider['club_name']) ?>
                                    </a>
                                <?php else: ?>
                                    Klubbtillhörighet saknas
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Team (Full width) -->
                        <?php if (!empty($rider['team'])): ?>
                            <div class="info-field-wide">
                                <div class="info-box-label">Team</div>
                                <div class="info-box-value">
                                    <?= h($rider['team']) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- City & District -->
                        <?php if (!empty($rider['city']) || !empty($rider['district'])): ?>
                            <div class="info-grid-compact">
                                <?php if (!empty($rider['city'])): ?>
                                    <div class="info-box">
                                        <div class="info-box-label">Stad</div>
                                        <div class="info-box-value">
                                            <?= h($rider['city']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($rider['district'])): ?>
                                    <div class="info-box">
                                        <div class="info-box-label">Distrikt</div>
                                        <div class="info-box-value">
                                            <?= h($rider['district']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Disciplines -->
                        <?php if (!empty($rider['disciplines'])): ?>
                            <?php
                            $disciplines = json_decode($rider['disciplines'], true);
                            if ($disciplines && is_array($disciplines) && count($disciplines) > 0):
                            ?>
                                <div class="info-field-wide">
                                    <div class="info-box-label">Grenar</div>
                                    <div class="info-box-value" style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem;">
                                        <?php foreach ($disciplines as $discipline): ?>
                                            <span style="background: #667eea; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: clamp(0.75rem, 2.5vw, 0.875rem); font-weight: 600;">
                                                <?= h($discipline) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Class Badge -->
                        <?php if ($currentClass): ?>
                            <div class="class-badge-compact">
                                <div class="class-label">Tävlingsklass <?= $currentYear ?></div>
                                <div class="class-name"><?= h($currentClassName) ?> (<?= h($currentClass) ?>)</div>
                            </div>
                        <?php endif; ?>
                        </div><!-- .license-info -->
                    </div><!-- .license-content -->

                    <!-- Footer -->
                    <div class="license-footer">
                        TheHUB by GravitySeries • Giltig <?= $currentYear ?>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-sm gs-mb-xl">
                <div class="gs-card" style="text-align: center; padding: 0.75rem;">
                    <div class="gs-h3 gs-text-primary"><?= $totalRaces ?></div>
                    <div class="gs-text-xs gs-text-secondary">Race</div>
                </div>
                <div class="gs-card" style="text-align: center; padding: 0.75rem;">
                    <div class="gs-h3" style="color: var(--gs-success);"><?= $wins ?></div>
                    <div class="gs-text-xs gs-text-secondary">Segrar</div>
                </div>
                <div class="gs-card" style="text-align: center; padding: 0.75rem;">
                    <div class="gs-h3" style="color: var(--gs-warning);"><?= $bestPosition ?? '-' ?></div>
                    <div class="gs-text-xs gs-text-secondary">Bästa placering</div>
                </div>
                <div class="gs-card" style="text-align: center; padding: 0.75rem;">
                    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                        <?php if (!empty($seriesStandings)): ?>
                            <?php foreach ($seriesStandings as $standing): ?>
                                <div style="font-size: 10px; line-height: 1.4;">
                                    <strong><?= h($standing['series_name']) ?>:</strong>
                                    #<?= $standing['position'] ?? '?' ?> (<?= $standing['total_points'] ?>p)
                                    <br>
                                    <span style="color: #718096; font-size: 9px;">
                                        <?= h($standing['class_name']) ?> (<?= $standing['class_total'] ?> deltagare)
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="gs-h3 gs-text-primary">0</div>
                        <?php endif; ?>
                    </div>
                    <div class="gs-text-xs gs-text-secondary" style="margin-top: 0.25rem;">Points</div>
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
