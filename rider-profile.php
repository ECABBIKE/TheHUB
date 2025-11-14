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
            <div class="gs-card-content" style="padding: var(--gs-space-xl);">
                <div class="gs-flex gs-justify-between gs-items-start">
                    <div>
                        <h1 class="gs-h1 gs-text-primary gs-mb-sm">
                            <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                        </h1>
                        <div class="gs-flex gs-gap-md gs-flex-wrap">
                            <?php if ($rider['club_name']): ?>
                                <span class="gs-badge gs-badge-secondary">
                                    <i data-lucide="building" style="width: 14px; height: 14px;"></i>
                                    <?= h($rider['club_name']) ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($rider['birth_year']): ?>
                                <span class="gs-badge gs-badge-secondary">
                                    <i data-lucide="calendar" style="width: 14px; height: 14px;"></i>
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
                                <div class="gs-text-lg" style="font-weight: 600;"><?= h($rider['license_number'] ?: '-') ?></div>
                            </div>
                            <div>
                                <div class="gs-text-sm gs-text-secondary">Licenstyp</div>
                                <div class="gs-text-lg" style="font-weight: 600;"><?= h($rider['license_type'] ?: '-') ?></div>
                            </div>
                            <div>
                                <div class="gs-text-sm gs-text-secondary">Giltig till</div>
                                <div class="gs-text-lg" style="font-weight: 600;">
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

                <!-- Results History -->
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="trophy"></i>
                            Mina resultat (<?= count($results) ?>)
                        </h2>
                    </div>
                    <div class="gs-card-content" style="padding: 0;">
                        <?php if (empty($results)): ?>
                            <div style="padding: var(--gs-space-lg); text-align: center;">
                                <p class="gs-text-secondary">Inga resultat registrerade ännu</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="gs-table">
                                    <thead>
                                        <tr>
                                            <th>Datum</th>
                                            <th>Tävling</th>
                                            <th>Kategori</th>
                                            <th style="text-align: center;">Placering</th>
                                            <th style="text-align: center;">Poäng</th>
                                            <th style="text-align: center;">Status</th>
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
                                                <td style="text-align: center;">
                                                    <?php if ($result['status'] === 'finished' && $result['position']): ?>
                                                        <strong><?= $result['position'] ?></strong>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align: center;"><?= $result['points'] ?: '-' ?></td>
                                                <td style="text-align: center;">
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
