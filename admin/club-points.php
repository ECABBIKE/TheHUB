<?php
/**
 * Admin Club Points Standings
 * Shows club rankings per series with filtering and statistics
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/club-points-system.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Check if tables exist
if (!clubPointsTablesExist($db)) {
    $message = 'Klubbpoängtabeller saknas. Kör migration 021_club_points_system.sql för att skapa dem.';
    $messageType = 'warning';
}

// Get all series for filter
$seriesList = $db->getAll("
    SELECT id, name, year, discipline
    FROM series
    WHERE active = 1
    ORDER BY year DESC, name ASC
");

// Get selected series (default to first or most recent)
$selectedSeriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : 0;
if (!$selectedSeriesId && !empty($seriesList)) {
    $selectedSeriesId = $seriesList[0]['id'];
}

// Handle recalculate action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate'])) {
    checkCsrf();

    $seriesIdToRecalc = (int)$_POST['series_id'];
    if ($seriesIdToRecalc) {
        $stats = recalculateSeriesClubPoints($db, $seriesIdToRecalc);
        $message = "Omräkning klar! {$stats['events_processed']} events, {$stats['total_clubs']} klubbar, {$stats['total_points']} poäng.";
        $messageType = 'success';
        $selectedSeriesId = $seriesIdToRecalc;
    }
}

// Get standings for selected series
$standings = [];
$seriesInfo = null;
if ($selectedSeriesId) {
    $standings = getClubStandings($db, $selectedSeriesId);
    $seriesInfo = $db->getRow("SELECT * FROM series WHERE id = ?", [$selectedSeriesId]);
}

// Calculate summary stats
$totalClubs = count($standings);
$totalPoints = array_sum(array_column($standings, 'total_points'));
$totalParticipants = array_sum(array_column($standings, 'total_participants'));

$pageTitle = 'Klubbpoäng';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <?php render_admin_header('Serier & Poäng'); ?>
        <div class="gs-mb-lg">
            <a href="/clubs/leaderboard.php<?= $selectedSeriesId ? '?series_id=' . $selectedSeriesId : '' ?>" class="gs-btn gs-btn-outline" target="_blank">
                <i data-lucide="external-link"></i>
                Publik vy
            </a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Filters and Actions -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <div class="gs-flex gs-items-end gs-gap-lg gs-flex-wrap">
                    <!-- Series Filter -->
                    <div class="gs-form-group gs-mb-0" style="flex: 1; min-width: 200px;">
                        <label for="series_filter" class="gs-label">Välj serie</label>
                        <select id="series_filter" class="gs-input" onchange="window.location.href='?series_id=' + this.value">
                            <option value="">-- Välj serie --</option>
                            <?php foreach ($seriesList as $series): ?>
                                <option value="<?= $series['id'] ?>" <?= $series['id'] == $selectedSeriesId ? 'selected' : '' ?>>
                                    <?= h($series['name']) ?> (<?= $series['year'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Recalculate Button -->
                    <?php if ($selectedSeriesId): ?>
                    <form method="POST" class="gs-mb-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="series_id" value="<?= $selectedSeriesId ?>">
                        <button type="submit" name="recalculate" class="gs-btn gs-btn-secondary"
                                onclick="return confirm('Räkna om alla klubbpoäng för denna serie?')">
                            <i data-lucide="refresh-cw"></i>
                            Räkna om
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($selectedSeriesId && $seriesInfo): ?>
            <!-- Summary Stats -->
            <div class="gs-grid gs-grid-cols-4 gs-gap-md gs-mb-lg">
                <div class="gs-card gs-text-center">
                    <div class="gs-card-content">
                        <div class="gs-text-3xl gs-font-bold gs-text-primary"><?= $totalClubs ?></div>
                        <div class="gs-text-sm gs-text-secondary">Klubbar</div>
                    </div>
                </div>
                <div class="gs-card gs-text-center">
                    <div class="gs-card-content">
                        <div class="gs-text-3xl gs-font-bold gs-text-primary"><?= number_format($totalPoints) ?></div>
                        <div class="gs-text-sm gs-text-secondary">Totala poäng</div>
                    </div>
                </div>
                <div class="gs-card gs-text-center">
                    <div class="gs-card-content">
                        <div class="gs-text-3xl gs-font-bold gs-text-primary"><?= $totalParticipants ?></div>
                        <div class="gs-text-sm gs-text-secondary">Deltagare</div>
                    </div>
                </div>
                <div class="gs-card gs-text-center">
                    <div class="gs-card-content">
                        <div class="gs-text-3xl gs-font-bold gs-text-primary">
                            <?= !empty($standings) ? $standings[0]['events_count'] : 0 ?>
                        </div>
                        <div class="gs-text-sm gs-text-secondary">Events</div>
                    </div>
                </div>
            </div>

            <!-- Standings Table -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="list-ordered"></i>
                        Klubbranking - <?= h($seriesInfo['name']) ?>
                    </h2>
                </div>
                <div class="gs-card-content gs-p-0">
                    <?php if (empty($standings)): ?>
                        <div class="gs-text-center gs-py-xl">
                            <i data-lucide="info" class="gs-icon-lg gs-text-secondary gs-mb-md"></i>
                            <p class="gs-text-secondary">Inga klubbpoäng har beräknats för denna serie ännu.</p>
                            <p class="gs-text-sm gs-text-secondary gs-mt-sm">
                                Klicka på "Räkna om" för att beräkna klubbpoäng baserat på resultat.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="gs-table-responsive">
                            <table class="gs-table">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">Rank</th>
                                        <th>Klubb</th>
                                        <th>Ort</th>
                                        <th class="gs-text-right">Poäng</th>
                                        <th class="gs-text-right">Deltagare</th>
                                        <th class="gs-text-right">Events</th>
                                        <th class="gs-text-right">Bästa event</th>
                                        <th style="width: 80px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($standings as $index => $club): ?>
                                        <tr>
                                            <td>
                                                <?php if ($club['ranking'] <= 3): ?>
                                                    <span class="gs-badge gs-badge-<?= $club['ranking'] == 1 ? 'warning' : ($club['ranking'] == 2 ? 'secondary' : 'primary') ?>">
                                                        <?= $club['ranking'] ?>
                                                    </span>
                                                <?php else: ?>
                                                    <?= $club['ranking'] ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="gs-flex gs-items-center gs-gap-sm">
                                                    <?php if ($club['logo']): ?>
                                                        <img src="<?= h($club['logo']) ?>" alt="" style="width: 24px; height: 24px; object-fit: contain;">
                                                    <?php endif; ?>
                                                    <strong><?= h($club['club_name']) ?></strong>
                                                    <?php if ($club['short_name']): ?>
                                                        <span class="gs-text-xs gs-text-secondary">(<?= h($club['short_name']) ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="gs-text-secondary"><?= h($club['city'] ?? '-') ?></td>
                                            <td class="gs-text-right gs-font-bold"><?= number_format($club['total_points']) ?></td>
                                            <td class="gs-text-right"><?= $club['total_participants'] ?></td>
                                            <td class="gs-text-right"><?= $club['events_count'] ?></td>
                                            <td class="gs-text-right"><?= number_format($club['best_event_points']) ?></td>
                                            <td>
                                                <a href="/admin/club-points-detail.php?club_id=<?= $club['club_id'] ?>&series_id=<?= $selectedSeriesId ?>"
                                                   class="gs-btn gs-btn-sm gs-btn-outline">
                                                    <i data-lucide="eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- No series selected -->
            <div class="gs-card">
                <div class="gs-card-content gs-text-center gs-py-xl">
                    <i data-lucide="award" class="gs-icon-lg gs-text-secondary gs-mb-md"></i>
                    <h3 class="gs-h4 gs-mb-sm">Välj en serie</h3>
                    <p class="gs-text-secondary">
                        Välj en serie ovan för att se klubbranking.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Info Card -->
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h3 class="gs-h5 gs-text-secondary">
                    <i data-lucide="info"></i>
                    Om klubbpoäng
                </h3>
            </div>
            <div class="gs-card-content">
                <ul class="gs-text-sm gs-text-secondary" style="margin: 0; padding-left: 1.5rem;">
                    <li>Bästa åkare från varje klubb per klass får 100% av sina poäng</li>
                    <li>Näst bästa åkare från samma klubb/klass får 50%</li>
                    <li>Övriga åkare från klubben i samma klass får 0%</li>
                    <li>Poängen ackumuleras över alla events i serien</li>
                </ul>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
