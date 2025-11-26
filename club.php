<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/class-calculations.php';

$db = getDB();

// Get club ID from URL
$clubId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$clubId) {
    header('Location: /riders.php');
    exit;
}

// Fetch club details
$club = $db->getRow("
    SELECT * FROM clubs WHERE id = ?
", [$clubId]);

if (!$club) {
    header('Location: /riders.php');
    exit;
}

// Get selected tab and discipline
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'medlemmar';
if (!in_array($tab, ['medlemmar', 'ranking'])) {
    $tab = 'medlemmar';
}

$discipline = isset($_GET['discipline']) ? strtoupper($_GET['discipline']) : 'GRAVITY';
if (!in_array($discipline, ['ENDURO', 'DH', 'GRAVITY'])) {
    $discipline = 'GRAVITY';
}

// Fetch all riders from this club with their total points
// Use class position (not overall position) for best_position and wins
$clubRiders = $db->getAll("
    SELECT
        r.id,
        r.firstname,
        r.lastname,
        r.birth_year,
        r.gender,
        r.license_number,
        r.license_type,
        r.city,
        COUNT(DISTINCT res.id) as total_races,
        SUM(res.points) as total_points,
        MIN(
            CASE WHEN res.status = 'finished' THEN
                (SELECT COUNT(*) + 1
                 FROM results r2
                 WHERE r2.event_id = res.event_id
                 AND r2.class_id = res.class_id
                 AND r2.status = 'finished'
                 AND r2.id != res.id
                 AND (r2.finish_time < res.finish_time OR (r2.finish_time = res.finish_time AND r2.id < res.id)))
            ELSE NULL END
        ) as best_position,
        SUM(
            CASE WHEN res.status = 'finished' AND
                (SELECT COUNT(*) + 1
                 FROM results r2
                 WHERE r2.event_id = res.event_id
                 AND r2.class_id = res.class_id
                 AND r2.status = 'finished'
                 AND r2.id != res.id
                 AND (r2.finish_time < res.finish_time OR (r2.finish_time = res.finish_time AND r2.id < res.id))) = 1
            THEN 1 ELSE 0 END
        ) as wins
    FROM riders r
    LEFT JOIN results res ON r.id = res.cyclist_id
    WHERE r.club_id = ? AND r.active = 1
    GROUP BY r.id
    ORDER BY total_points DESC, r.lastname, r.firstname
", [$clubId]);

// Fetch ranking contributors if on ranking tab
$rankingContributors = [];
if ($tab === 'ranking') {
    require_once __DIR__ . '/includes/ranking_functions.php';

    // Check if ranking_points table exists
    $tablesExist = rankingTablesExist($db);

    if ($tablesExist) {
        try {
            // Build discipline filter
            $disciplineFilter = '';
            $params = [$clubId];

            if ($discipline === 'GRAVITY') {
                $disciplineFilter = "AND e.discipline IN ('ENDURO', 'DH')";
            } else {
                $disciplineFilter = "AND e.discipline = ?";
                $params[] = $discipline;
            }

            $rankingContributors = $db->getAll("
                SELECT
                    r.id as rider_id,
                    r.firstname,
                    r.lastname,
                    SUM(rp.ranking_points) as total_ranking_points,
                    COUNT(DISTINCT rp.event_id) as events_count
                FROM ranking_points rp
                JOIN riders r ON rp.rider_id = r.id
                JOIN events e ON rp.event_id = e.id
                WHERE r.club_id = ?
                AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
                {$disciplineFilter}
                GROUP BY r.id
                HAVING total_ranking_points > 0
                ORDER BY total_ranking_points DESC
            ", $params);
        } catch (Exception $e) {
            // Table doesn't exist, leave empty
            $rankingContributors = [];
        }
    }
}

$currentYear = date('Y');

$pageTitle = $club['name'];
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

        <!-- Club License Card -->
        <div class="license-card-container">
            <div class="license-card">
                <!-- Stripe -->
                <div class="uci-stripe"></div>

                <!-- Content -->
                <div class="license-content" style="flex-direction: column; align-items: center; text-align: center;">
                    <!-- Logo -->
                    <?php if (!empty($club['logo'])): ?>
                        <div class="license-photo" style="margin-bottom: 1rem;">
                            <img src="<?= h($club['logo']) ?>" alt="<?= h($club['name']) ?>" style="max-height: 80px; border-radius: 8px; object-fit: contain;">
                        </div>
                    <?php endif; ?>

                    <!-- Info Section -->
                    <div class="license-info" style="text-align: center; width: 100%;">
                        <!-- Club Name -->
                        <div class="rider-name" style="font-size: clamp(1.5rem, 5vw, 2rem);">
                            <?= h($club['name']) ?>
                        </div>

                        <!-- Location -->
                        <?php if ($club['city']): ?>
                            <div class="license-id" style="color: var(--gs-primary); font-weight: 600;">
                                <i data-lucide="map-pin" style="width: 14px; height: 14px; display: inline;"></i>
                                <?= h($club['city']) ?>
                                <?php if (!empty($club['region'])): ?>
                                    , <?= h($club['region']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Description -->
                        <?php if (!empty($club['description'])): ?>
                            <div class="gs-text-secondary gs-mt-md" style="max-width: 500px; margin: 0 auto; font-size: 0.875rem;">
                                <?= nl2br(h($club['description'])) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Stats Grid -->
                        <div class="info-grid-compact gs-mt-lg" style="grid-template-columns: repeat(4, 1fr);">
                            <div class="info-box">
                                <div class="info-box-label">Cyklister</div>
                                <div class="info-box-value"><?= count($clubRiders) ?></div>
                            </div>
                            <div class="info-box">
                                <div class="info-box-label">Lopp</div>
                                <div class="info-box-value"><?= array_sum(array_column($clubRiders, 'total_races')) ?></div>
                            </div>
                            <div class="info-box">
                                <div class="info-box-label">Poäng</div>
                                <div class="info-box-value"><?= array_sum(array_column($clubRiders, 'total_points')) ?: 0 ?></div>
                            </div>
                            <div class="info-box">
                                <div class="info-box-label">Segrar</div>
                                <div class="info-box-value"><?= array_sum(array_column($clubRiders, 'wins')) ?></div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <?php if (!empty($club['email']) || !empty($club['phone']) || !empty($club['website']) || !empty($club['facebook']) || !empty($club['instagram'])): ?>
                        <div class="gs-mt-lg" style="background: rgba(0,0,0,0.03); padding: 1rem; border-radius: 8px;">
                            <div class="gs-flex gs-gap-md gs-flex-wrap" style="justify-content: center;">
                                <?php if (!empty($club['website'])): ?>
                                <a href="<?= h($club['website']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="globe" class="gs-icon-sm"></i>
                                    Webb
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($club['email'])): ?>
                                <a href="mailto:<?= h($club['email']) ?>" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="mail" class="gs-icon-sm"></i>
                                    E-post
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($club['phone'])): ?>
                                <a href="tel:<?= h($club['phone']) ?>" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="phone" class="gs-icon-sm"></i>
                                    Telefon
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($club['facebook'])): ?>
                                <a href="<?= h($club['facebook']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="facebook" class="gs-icon-sm"></i>
                                    FB
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($club['instagram'])): ?>
                                <a href="<?= h($club['instagram']) ?>" target="_blank" rel="noopener" class="gs-flex gs-items-center gs-gap-xs gs-link">
                                    <i data-lucide="instagram" class="gs-icon-sm"></i>
                                    IG
                                </a>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($club['contact_person'])): ?>
                            <div class="gs-text-center gs-mt-sm gs-text-xs gs-text-secondary">
                                Kontakt: <?= h($club['contact_person']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="gs-tabs gs-mb-lg">
            <a href="?id=<?= $clubId ?>&tab=medlemmar" class="gs-tab <?= $tab === 'medlemmar' ? 'active' : '' ?>">
                <i data-lucide="users"></i>
                Medlemmar
            </a>
            <a href="?id=<?= $clubId ?>&tab=ranking&discipline=<?= $discipline ?>" class="gs-tab <?= $tab === 'ranking' ? 'active' : '' ?>">
                <i data-lucide="trophy"></i>
                Ranking
            </a>
        </div>

        <?php if ($tab === 'medlemmar'): ?>
        <!-- Medlemmar Tab: All members with points -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="users"></i>
                    Medlemmar med poäng
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($clubRiders)): ?>
                    <div class="gs-text-center gs-empty-state-container">
                        <i data-lucide="users" class="gs-empty-state-icon-lg"></i>
                        <h3 class="gs-h4 gs-mb-sm">Inga medlemmar hittades</h3>
                        <p class="gs-text-secondary">
                            Denna klubb har inga aktiva medlemmar registrerade.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-md">
                        <?php foreach ($clubRiders as $rider): ?>
                            <?php
                            // Determine rider's class
                            $riderClass = null;
                            $classId = null;
                            $ranking = '-';

                            if ($rider['birth_year'] && $rider['gender']) {
                                $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'));
                                if ($classId) {
                                    $class = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$classId]);
                                    $riderClass = $class['display_name'] ?? null;

                                    // Calculate ranking in class (position based on total points)
                                    if ($rider['total_points'] > 0) {
                                        $rankResult = $db->getRow("
                                            SELECT COUNT(*) + 1 as ranking
                                            FROM riders r
                                            WHERE r.active = 1
                                            AND r.id != ?
                                            AND (
                                                SELECT SUM(res.points)
                                                FROM results res
                                                WHERE res.cyclist_id = r.id
                                            ) > ?
                                            AND r.id IN (
                                                SELECT DISTINCT res2.cyclist_id
                                                FROM results res2
                                                JOIN events e ON res2.event_id = e.id
                                                WHERE res2.class_id = ?
                                            )
                                        ", [$rider['id'], $rider['total_points'], $classId]);
                                        $ranking = $rankResult['ranking'] ?? '-';
                                    }
                                }
                            }

                            $hasPoints = $rider['total_points'] > 0;
                            ?>
                            <a href="/rider.php?id=<?= $rider['id'] ?>" class="gs-rider-card <?= !$hasPoints ? 'gs-no-points' : '' ?>">
                                <div class="gs-rider-card-name">
                                    <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                                </div>

                                <?php if ($riderClass): ?>
                                    <div class="gs-badge gs-badge-primary gs-badge-sm gs-mb-2">
                                        <?= h($riderClass) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="gs-rider-stats">
                                    <div class="gs-rider-stat">
                                        <div class="gs-rider-stat-value"><?= $rider['total_races'] ?: 0 ?></div>
                                        <div class="gs-rider-stat-label">Lopp</div>
                                    </div>
                                    <div class="gs-rider-stat">
                                        <div class="gs-rider-stat-value"><?= $rider['total_points'] ?: 0 ?></div>
                                        <div class="gs-rider-stat-label">Poäng</div>
                                    </div>
                                    <div class="gs-rider-stat">
                                        <div class="gs-rider-stat-value"><?= $ranking ?></div>
                                        <div class="gs-rider-stat-label">Ranking</div>
                                    </div>
                                    <div class="gs-rider-stat">
                                        <div class="gs-rider-stat-value"><?= $rider['wins'] ?: 0 ?></div>
                                        <div class="gs-rider-stat-label">Segrar</div>
                                    </div>
                                    <div class="gs-rider-stat">
                                        <div class="gs-rider-stat-value"><?= $rider['best_position'] ?: '-' ?></div>
                                        <div class="gs-rider-stat-label">Bäst</div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <!-- Ranking Tab: Ranking contributors by discipline -->
        <div class="gs-card">
            <div class="gs-card-header gs-flex gs-items-center gs-gap-md">
                <h2 class="gs-h4 gs-text-primary gs-flex gs-items-center gs-gap-sm">
                    <i data-lucide="trophy"></i>
                    Ranking-bidrag
                </h2>

                <!-- Discipline selector -->
                <div class="gs-ml-auto">
                    <select class="gs-input gs-input-sm" onchange="window.location.href='?id=<?= $clubId ?>&tab=ranking&discipline=' + this.value">
                        <option value="GRAVITY" <?= $discipline === 'GRAVITY' ? 'selected' : '' ?>>Gravity</option>
                        <option value="ENDURO" <?= $discipline === 'ENDURO' ? 'selected' : '' ?>>Enduro</option>
                        <option value="DH" <?= $discipline === 'DH' ? 'selected' : '' ?>>Downhill</option>
                    </select>
                </div>
            </div>

            <div class="gs-card-content">
                <?php if (empty($rankingContributors)): ?>
                    <div class="gs-text-center gs-empty-state-container">
                        <i data-lucide="trophy" class="gs-empty-state-icon-lg"></i>
                        <h3 class="gs-h4 gs-mb-sm">Inga rankingpoäng ännu</h3>
                        <p class="gs-text-secondary">
                            Ingen från klubben har rankingpoäng i <?= $discipline === 'GRAVITY' ? 'Gravity' : ($discipline === 'ENDURO' ? 'Enduro' : 'Downhill') ?> under de senaste 24 månaderna.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="gs-ranking-info-banner gs-mb-lg" style="display: flex; align-items: flex-start; gap: 0.75rem; padding: 1rem; background: #E8F4FD; border-radius: 8px; font-size: 0.875rem;">
                        <i data-lucide="info" style="flex-shrink: 0; width: 16px; height: 16px; margin-top: 2px; color: #1e40af;"></i>
                        <span>Visar viktade rankingpoäng för <?= $discipline === 'GRAVITY' ? 'Gravity (Enduro + Downhill)' : ($discipline === 'ENDURO' ? 'Enduro' : 'Downhill') ?> från de senaste 24 månaderna.</span>
                    </div>

                    <!-- Ranking Contributors Table -->
                    <div class="gs-table-wrapper">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Åkare</th>
                                    <th class="gs-text-center">Events</th>
                                    <th class="gs-text-right">Rankingpoäng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $position = 1; ?>
                                <?php foreach ($rankingContributors as $contributor): ?>
                                    <tr class="gs-table-row-clickable" onclick="window.location.href='/rider.php?id=<?= $contributor['rider_id'] ?>'">
                                        <td><?= $position ?></td>
                                        <td>
                                            <strong><?= h($contributor['firstname'] . ' ' . $contributor['lastname']) ?></strong>
                                        </td>
                                        <td class="gs-text-center"><?= $contributor['events_count'] ?></td>
                                        <td class="gs-text-right">
                                            <strong class="gs-text-primary"><?= number_format($contributor['total_ranking_points'], 1) ?>p</strong>
                                        </td>
                                    </tr>
                                    <?php $position++; ?>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background: #f8f9fa; font-weight: bold;">
                                    <td colspan="3" class="gs-text-right">Total klubbpoäng:</td>
                                    <td class="gs-text-right">
                                        <strong class="gs-text-primary" style="font-size: 1.125rem;">
                                            <?= number_format(array_sum(array_column($rankingContributors, 'total_ranking_points')), 1) ?>p
                                        </strong>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<style>
/* Tab navigation styles */
.gs-tabs {
    display: flex;
    gap: 0.5rem;
    background: #f8f9fa;
    padding: 0.5rem;
    border-radius: 8px;
}

.gs-tab {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border-radius: 6px;
    font-weight: 500;
    font-size: 0.875rem;
    color: #64748b;
    text-decoration: none;
    transition: all 0.2s;
    background: transparent;
}

.gs-tab i {
    width: 16px;
    height: 16px;
}

.gs-tab:hover {
    color: #1e40af;
    background: #fff;
}

.gs-tab.active {
    background: #fff;
    color: #1e40af;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

/* Table row clickable */
.gs-table-row-clickable {
    cursor: pointer;
    transition: background-color 0.2s;
}

.gs-table-row-clickable:hover {
    background-color: #f8f9fa;
}

/* Table wrapper */
.gs-table-wrapper {
    overflow-x: auto;
}

.gs-table {
    width: 100%;
    border-collapse: collapse;
}

.gs-table th,
.gs-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.gs-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
}

.gs-table tbody tr:last-child td {
    border-bottom: none;
}

.gs-table tfoot td {
    border-top: 2px solid #e2e8f0;
    padding: 1rem;
}
</style>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
