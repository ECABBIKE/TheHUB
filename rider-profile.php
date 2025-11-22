<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rider-auth.php';

// Require authentication
require_rider();

$rider = get_current_rider();
$db = getDB();

// Get rider's results
$results = $db->getAll("
    SELECT
        r.*,
        e.name as event_name,
        e.date as event_date,
        e.id as event_id,
        cat.name as category_name
    FROM results r
    JOIN events e ON r.event_id = e.id
    LEFT JOIN categories cat ON r.category_id = cat.id
    WHERE r.cyclist_id = ?
    ORDER BY e.date DESC
    LIMIT 50
", [$rider['id']]);

// Get eligible categories based on age and gender
$eligibleCategories = [];
if ($rider['birth_year']) {
    $age = date('Y') - $rider['birth_year'];
    $gender = $rider['gender'];

    $eligibleCategories = $db->getAll("
        SELECT * FROM categories
        WHERE active = 1
        AND (age_min IS NULL OR age_min <= ?)
        AND (age_max IS NULL OR age_max >= ?)
        AND (gender = ? OR gender = 'All')
        ORDER BY name
    ", [$age, $age, $gender]);
}

// Check license status
$licenseStatus = checkLicense($rider);

// Get rider's series standings (individual points)
$seriesStats = $db->getAll("
    SELECT
        s.id as series_id,
        s.name as series_name,
        s.year,
        SUM(r.points) as total_points,
        COUNT(DISTINCT r.event_id) as events_count
    FROM results r
    JOIN events e ON r.event_id = e.id
    JOIN series_events se ON e.id = se.event_id
    JOIN series s ON se.series_id = s.id
    WHERE r.cyclist_id = ?
    AND r.status = 'finished'
    AND r.points > 0
    GROUP BY s.id
    ORDER BY s.year DESC, total_points DESC
", [$rider['id']]);

// Get rider's club points contribution
$clubPointsStats = [];
if ($rider['club_id']) {
    $clubPointsStats = $db->getAll("
        SELECT
            s.id as series_id,
            s.name as series_name,
            s.year,
            SUM(crp.club_points) as total_club_points,
            COUNT(DISTINCT crp.event_id) as events_count
        FROM club_rider_points crp
        JOIN series s ON crp.series_id = s.id
        WHERE crp.rider_id = ?
        AND crp.club_id = ?
        AND crp.club_points > 0
        GROUP BY s.id
        ORDER BY s.year DESC, total_club_points DESC
    ", [$rider['id'], $rider['club_id']]);
}

$pageTitle = 'Min profil';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <?php if (isset($_GET['welcome'])): ?>
            <div class="gs-alert gs-alert-success gs-mb-lg">
                <i data-lucide="check-circle"></i>
                <strong>Välkommen!</strong> Ditt konto har skapats. Du är nu inloggad.
            </div>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-content gs-p-xl">
                <div class="gs-flex gs-justify-between gs-items-start">
                    <div>
                        <h1 class="gs-h1 gs-text-primary gs-mb-sm">
                            <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                        </h1>
                        <div class="gs-flex gs-gap-md gs-flex-wrap">
                            <?php if ($rider['club_name']): ?>
                                <span class="gs-badge gs-badge-secondary">
                                    <i data-lucide="building" class="gs-icon-14"></i>
                                    <?= h($rider['club_name']) ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($rider['birth_year']): ?>
                                <span class="gs-badge gs-badge-secondary">
                                    <i data-lucide="calendar" class="gs-icon-14"></i>
                                    <?= calculateAge($rider['birth_year']) ?> år (<?= h($rider['birth_year']) ?>)
                                </span>
                            <?php endif; ?>

                            <?php if ($rider['gender']): ?>
                                <span class="gs-badge gs-badge-secondary">
                                    <?= $rider['gender'] == 'M' ? 'Herr' : ($rider['gender'] == 'F' ? 'Dam' : 'Annat') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <a href="/rider-logout.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="log-out"></i>
                        Logga ut
                    </a>
                </div>
            </div>
        </div>

        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg">
            <!-- Left Column - Info -->
            <div class="gs-md-col-span-2">
                <!-- License Status -->
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="award"></i>
                            Licensinformation
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                            <div>
                                <div class="gs-text-sm gs-text-secondary">Licensnummer</div>
                                <div class="gs-text-lg gs-font-semibold"><?= h($rider['license_number'] ?: '-') ?></div>
                            </div>
                            <div>
                                <div class="gs-text-sm gs-text-secondary">Licenstyp</div>
                                <div class="gs-text-lg gs-font-semibold"><?= h($rider['license_type'] ?: '-') ?></div>
                            </div>
                            <div>
                                <div class="gs-text-sm gs-text-secondary">Giltig till</div>
                                <div class="gs-text-lg gs-font-semibold">
                                    <?php if ($rider['license_valid_until'] && $rider['license_valid_until'] !== '0000-00-00'): ?>
                                        <?= date('Y-m-d', strtotime($rider['license_valid_until'])) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="gs-text-sm gs-text-secondary">Status</div>
                                <div>
                                    <span class="gs-badge <?= $licenseStatus['class'] ?>">
                                        <?= h($licenseStatus['message']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Series Points -->
                <?php if (!empty($seriesStats) || !empty($clubPointsStats)): ?>
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="bar-chart-3"></i>
                            Seriepoäng
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (!empty($seriesStats)): ?>
                        <div class="gs-mb-md">
                            <h3 class="gs-text-sm gs-font-semibold gs-text-secondary gs-mb-sm">Individuella poäng</h3>
                            <?php foreach ($seriesStats as $stat): ?>
                            <div class="gs-flex gs-justify-between gs-items-center gs-py-xs gs-border-b">
                                <div>
                                    <a href="/series-standings.php?id=<?= $stat['series_id'] ?>" class="gs-link gs-font-semibold">
                                        <?= h($stat['series_name']) ?>
                                    </a>
                                    <span class="gs-text-xs gs-text-secondary">(<?= $stat['events_count'] ?> events)</span>
                                </div>
                                <div class="gs-text-lg gs-font-bold gs-text-primary">
                                    <?= number_format($stat['total_points']) ?> p
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($clubPointsStats)): ?>
                        <div>
                            <h3 class="gs-text-sm gs-font-semibold gs-text-secondary gs-mb-sm">
                                Klubbpoäng (<?= h($rider['club_name']) ?>)
                            </h3>
                            <?php foreach ($clubPointsStats as $stat): ?>
                            <div class="gs-flex gs-justify-between gs-items-center gs-py-xs gs-border-b">
                                <div>
                                    <a href="/clubs/leaderboard.php?series_id=<?= $stat['series_id'] ?>" class="gs-link gs-font-semibold">
                                        <?= h($stat['series_name']) ?>
                                    </a>
                                    <span class="gs-text-xs gs-text-secondary">(<?= $stat['events_count'] ?> events)</span>
                                </div>
                                <div class="gs-text-lg gs-font-bold" style="color: #f59e0b;">
                                    <?= number_format($stat['total_club_points'], 1) ?> p
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Results History -->
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="trophy"></i>
                            Mina resultat (<?= count($results) ?>)
                        </h2>
                    </div>
                    <div class="gs-card-content gs-p-0">
                        <?php if (empty($results)): ?>
                            <div class="gs-empty-state">
                                <p class="gs-text-secondary">Inga resultat registrerade ännu</p>
                            </div>
                        <?php else: ?>
                            <div class="gs-table-scrollable">
                                <table class="gs-table">
                                    <thead>
                                        <tr>
                                            <th>Datum</th>
                                            <th>Tävling</th>
                                            <th>Kategori</th>
                                            <th class="gs-text-center">Placering</th>
                                            <th class="gs-text-center">Poäng</th>
                                            <th class="gs-text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results as $result): ?>
                                            <tr>
                                                <td><?= date('Y-m-d', strtotime($result['event_date'])) ?></td>
                                                <td>
                                                    <a href="/event.php?id=<?= $result['event_id'] ?>" class="gs-link">
                                                        <?= h($result['event_name']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php if ($result['category_name']): ?>
                                                        <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                            <?= h($result['category_name']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="gs-text-center">
                                                    <?php if ($result['status'] === 'finished' && $result['position']): ?>
                                                        <strong><?= $result['position'] ?></strong>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="gs-text-center"><?= $result['points'] ?: '-' ?></td>
                                                <td class="gs-text-center">
                                                    <?php
                                                    $statusClass = 'gs-badge-success';
                                                    $statusText = 'OK';
                                                    if ($result['status'] === 'dnf') {
                                                        $statusClass = 'gs-badge-danger';
                                                        $statusText = 'DNF';
                                                    } elseif ($result['status'] === 'dns') {
                                                        $statusClass = 'gs-badge-warning';
                                                        $statusText = 'DNS';
                                                    } elseif ($result['status'] === 'dq') {
                                                        $statusClass = 'gs-badge-danger';
                                                        $statusText = 'DQ';
                                                    }
                                                    ?>
                                                    <span class="gs-badge <?= $statusClass ?> gs-badge-sm">
                                                        <?= $statusText ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column - Actions & Info -->
            <div>
                <!-- Quick Actions -->
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h3 class="gs-h5 gs-text-primary">
                            <i data-lucide="zap"></i>
                            Snabbval
                        </h3>
                    </div>
                    <div class="gs-card-content">
                        <a href="/events.php" class="gs-btn gs-btn-primary gs-w-full gs-mb-sm">
                            <i data-lucide="calendar"></i>
                            Se kommande tävlingar
                        </a>
                        <a href="/rider-change-password.php" class="gs-btn gs-btn-outline gs-w-full">
                            <i data-lucide="key"></i>
                            Ändra lösenord
                        </a>
                    </div>
                </div>

                <!-- Eligible Categories -->
                <?php if (!empty($eligibleCategories)): ?>
                    <div class="gs-card">
                        <div class="gs-card-header">
                            <h3 class="gs-h5 gs-text-primary">
                                <i data-lucide="layers"></i>
                                Dina klasser
                            </h3>
                        </div>
                        <div class="gs-card-content">
                            <p class="gs-text-sm gs-text-secondary gs-mb-md">
                                Baserat på din ålder (<?= calculateAge($rider['birth_year']) ?> år) och kön kan du tävla i:
                            </p>
                            <div class="gs-flex gs-flex-wrap gs-gap-xs">
                                <?php foreach ($eligibleCategories as $cat): ?>
                                    <span class="gs-badge gs-badge-primary gs-badge-sm">
                                        <?= h($cat['name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
