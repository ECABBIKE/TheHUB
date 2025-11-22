<?php
/**
 * Admin Club Points Detail
 * Shows detailed breakdown of a club's points in a series
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/club-points-system.php';
require_admin();

$db = getDB();

// Get parameters
$clubId = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
$seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : 0;

if (!$clubId || !$seriesId) {
    header('Location: /admin/club-points.php');
    exit;
}

// Get detailed breakdown
$detail = getClubPointsDetail($db, $clubId, $seriesId);

if (!$detail || !$detail['club']) {
    set_flash('error', 'Klubb hittades inte');
    header('Location: /admin/club-points.php');
    exit;
}

$club = $detail['club'];
$standing = $detail['standing'];
$events = $detail['events'];
$riderDetails = $detail['rider_details'];

// Get series info
$series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);

$pageTitle = $club['name'] . ' - Klubbpoäng';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <!-- Back Button -->
        <div class="gs-mb-lg">
            <a href="/admin/club-points.php?series_id=<?= $seriesId ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                <i data-lucide="arrow-left"></i>
                Tillbaka till ranking
            </a>
        </div>

        <!-- Header -->
        <div class="gs-flex gs-items-center gs-gap-lg gs-mb-xl">
            <?php if ($club['logo']): ?>
                <img src="<?= h($club['logo']) ?>" alt="" style="width: 64px; height: 64px; object-fit: contain;">
            <?php endif; ?>
            <div>
                <h1 class="gs-h1 gs-text-primary gs-mb-0">
                    <?= h($club['name']) ?>
                </h1>
                <?php if ($club['city']): ?>
                    <p class="gs-text-secondary gs-mt-xs">
                        <i data-lucide="map-pin" class="gs-icon-sm"></i>
                        <?= h($club['city']) ?>
                        <?php if ($club['region']): ?>, <?= h($club['region']) ?><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary Card -->
        <?php if ($standing): ?>
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="award"></i>
                    Sammanfattning - <?= h($series['name']) ?>
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-5 gs-gap-lg">
                    <div class="gs-text-center">
                        <div class="gs-text-3xl gs-font-bold <?= $standing['ranking'] <= 3 ? 'gs-text-warning' : 'gs-text-primary' ?>">
                            #<?= $standing['ranking'] ?>
                        </div>
                        <div class="gs-text-sm gs-text-secondary">Ranking</div>
                    </div>
                    <div class="gs-text-center">
                        <div class="gs-text-3xl gs-font-bold gs-text-primary">
                            <?= number_format($standing['total_points']) ?>
                        </div>
                        <div class="gs-text-sm gs-text-secondary">Totala poäng</div>
                    </div>
                    <div class="gs-text-center">
                        <div class="gs-text-3xl gs-font-bold gs-text-primary">
                            <?= $standing['total_participants'] ?>
                        </div>
                        <div class="gs-text-sm gs-text-secondary">Deltagare</div>
                    </div>
                    <div class="gs-text-center">
                        <div class="gs-text-3xl gs-font-bold gs-text-primary">
                            <?= $standing['events_count'] ?>
                        </div>
                        <div class="gs-text-sm gs-text-secondary">Events</div>
                    </div>
                    <div class="gs-text-center">
                        <div class="gs-text-3xl gs-font-bold gs-text-primary">
                            <?= number_format($standing['best_event_points']) ?>
                        </div>
                        <div class="gs-text-sm gs-text-secondary">Bästa event</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Events Breakdown -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="calendar"></i>
                    Poäng per event
                </h2>
            </div>
            <div class="gs-card-content gs-p-0">
                <?php if (empty($events)): ?>
                    <div class="gs-text-center gs-py-xl">
                        <p class="gs-text-secondary">Inga eventpoäng registrerade.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($events as $event): ?>
                        <div class="gs-border-b" style="border-color: var(--gs-border);">
                            <!-- Event Header -->
                            <div class="gs-p-md gs-bg-light gs-flex gs-items-center gs-justify-between">
                                <div>
                                    <strong><?= h($event['event_name']) ?></strong>
                                    <span class="gs-text-sm gs-text-secondary gs-ml-sm">
                                        <?= date('Y-m-d', strtotime($event['event_date'])) ?>
                                        <?php if ($event['location']): ?>
                                            | <?= h($event['location']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="gs-flex gs-items-center gs-gap-lg">
                                    <span class="gs-text-sm gs-text-secondary">
                                        <?= $event['participants_count'] ?> deltagare
                                    </span>
                                    <span class="gs-font-bold gs-text-primary">
                                        <?= number_format($event['total_points']) ?> p
                                    </span>
                                </div>
                            </div>

                            <!-- Rider Details -->
                            <?php if (isset($riderDetails[$event['event_id']]) && !empty($riderDetails[$event['event_id']])): ?>
                                <table class="gs-table gs-table-sm gs-mb-0">
                                    <thead>
                                        <tr>
                                            <th>Åkare</th>
                                            <th>Klass</th>
                                            <th class="gs-text-right">Original</th>
                                            <th class="gs-text-center">%</th>
                                            <th class="gs-text-right">Klubbpoäng</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($riderDetails[$event['event_id']] as $rider): ?>
                                            <tr class="<?= $rider['club_points'] == 0 ? 'gs-text-secondary' : '' ?>">
                                                <td>
                                                    <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                                                    <?php if ($rider['rider_rank_in_club'] == 1): ?>
                                                        <span class="gs-badge gs-badge-warning gs-badge-sm gs-ml-xs">1:a</span>
                                                    <?php elseif ($rider['rider_rank_in_club'] == 2): ?>
                                                        <span class="gs-badge gs-badge-secondary gs-badge-sm gs-ml-xs">2:a</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h($rider['class_name'] ?? '-') ?></td>
                                                <td class="gs-text-right"><?= $rider['original_points'] ?></td>
                                                <td class="gs-text-center">
                                                    <?php if ($rider['percentage_applied'] == 100): ?>
                                                        <span class="gs-badge gs-badge-success gs-badge-sm">100%</span>
                                                    <?php elseif ($rider['percentage_applied'] == 50): ?>
                                                        <span class="gs-badge gs-badge-warning gs-badge-sm">50%</span>
                                                    <?php else: ?>
                                                        <span class="gs-badge gs-badge-secondary gs-badge-sm">0%</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="gs-text-right gs-font-bold">
                                                    <?= $rider['club_points'] ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Points Summary by Class -->
        <?php
        // Calculate points by class
        $classTotals = [];
        foreach ($riderDetails as $eventId => $riders) {
            foreach ($riders as $rider) {
                $className = $rider['class_name'] ?? 'Okänd';
                if (!isset($classTotals[$className])) {
                    $classTotals[$className] = ['points' => 0, 'riders' => 0];
                }
                $classTotals[$className]['points'] += $rider['club_points'];
                if ($rider['club_points'] > 0) {
                    $classTotals[$className]['riders']++;
                }
            }
        }
        arsort($classTotals);
        ?>

        <?php if (!empty($classTotals)): ?>
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="layers"></i>
                    Poäng per klass
                </h2>
            </div>
            <div class="gs-card-content gs-p-0">
                <table class="gs-table gs-mb-0">
                    <thead>
                        <tr>
                            <th>Klass</th>
                            <th class="gs-text-right">Poänggivande åkare</th>
                            <th class="gs-text-right">Totala poäng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classTotals as $className => $data): ?>
                            <tr>
                                <td><strong><?= h($className) ?></strong></td>
                                <td class="gs-text-right"><?= $data['riders'] ?></td>
                                <td class="gs-text-right gs-font-bold"><?= number_format($data['points']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
