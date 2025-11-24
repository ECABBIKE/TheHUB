<?php
/**
 * Admin Ranking Settings
 * Manage the 24-month rolling ranking system for GravitySeries riders
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ranking_functions.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Check if tables exist
if (!rankingTablesExist($db)) {
    $message = 'Rankingtabeller saknas. Kör migration 028_ranking_system.sql för att skapa dem.';
    $messageType = 'warning';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    if (isset($_POST['calculate'])) {
        // Run full calculation
        $calcStats = calculateAllRankingPoints($db);
        $snapshotStats = createRankingSnapshot($db);

        $message = "Beräkning klar! {$calcStats['events_processed']} events, {$calcStats['riders_processed']} resultat, {$snapshotStats['riders_ranked']} åkare rankade.";
        $messageType = 'success';

    } elseif (isset($_POST['save_multipliers'])) {
        // Save field multipliers
        $multipliers = [];
        for ($i = 1; $i <= 26; $i++) {
            $key = "mult_$i";
            if (isset($_POST[$key])) {
                $multipliers[$i] = max(0, min(1, (float)$_POST[$key]));
            }
        }

        if (count($multipliers) === 26) {
            saveFieldMultipliers($db, $multipliers);
            $message = 'Fältstorleksmultiplikatorer sparade.';
            $messageType = 'success';
        } else {
            $message = 'Alla 26 multiplikatorer måste anges.';
            $messageType = 'error';
        }

    } elseif (isset($_POST['save_decay'])) {
        // Save time decay settings
        $timeDecay = [
            'months_1_12' => max(0, min(1, (float)$_POST['decay_1_12'])),
            'months_13_24' => max(0, min(1, (float)$_POST['decay_13_24'])),
            'months_25_plus' => max(0, min(1, (float)$_POST['decay_25_plus']))
        ];

        saveTimeDecay($db, $timeDecay);
        $message = 'Tidsviktning sparad.';
        $messageType = 'success';

    } elseif (isset($_POST['reset_defaults'])) {
        // Reset to defaults
        saveFieldMultipliers($db, getDefaultFieldMultipliers());
        saveTimeDecay($db, getDefaultTimeDecay());
        $message = 'Inställningar återställda till standardvärden.';
        $messageType = 'success';
    }
}

// Get current settings
$multipliers = getRankingFieldMultipliers($db);
$timeDecay = getRankingTimeDecay($db);
$lastCalc = getLastRankingCalculation($db);

// Get statistics
$stats = [
    'riders_ranked' => 0,
    'total_results' => 0,
    'last_snapshot' => null,
    'total_events' => 0
];

if (rankingTablesExist($db)) {
    $latestSnapshot = $db->getRow("SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots");
    $stats['last_snapshot'] = $latestSnapshot ? $latestSnapshot['snapshot_date'] : null;

    if ($stats['last_snapshot']) {
        $ridersRanked = $db->getRow(
            "SELECT COUNT(*) as count FROM ranking_snapshots WHERE snapshot_date = ?",
            [$stats['last_snapshot']]
        );
        $stats['riders_ranked'] = $ridersRanked ? $ridersRanked['count'] : 0;
    }

    $totalResults = $db->getRow("SELECT COUNT(*) as count FROM ranking_points");
    $stats['total_results'] = $totalResults ? $totalResults['count'] : 0;

    $totalEvents = $db->getRow("SELECT COUNT(DISTINCT event_id) as count FROM ranking_points");
    $stats['total_events'] = $totalEvents ? $totalEvents['count'] : 0;
}

$pageTitle = 'Ranking';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl gs-flex-wrap gs-gap-md">
            <h1 class="gs-h1 gs-text-primary">
                <i data-lucide="trending-up"></i>
                Ranking
            </h1>
            <a href="/ranking/" class="gs-btn gs-btn-outline" target="_blank">
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

        <!-- Statistics Cards -->
        <div class="gs-grid gs-grid-cols-4 gs-gap-md gs-mb-lg">
            <div class="gs-card gs-text-center">
                <div class="gs-card-content">
                    <div class="gs-text-3xl gs-font-bold gs-text-primary"><?= $stats['riders_ranked'] ?></div>
                    <div class="gs-text-sm gs-text-secondary">Rankade åkare</div>
                </div>
            </div>
            <div class="gs-card gs-text-center">
                <div class="gs-card-content">
                    <div class="gs-text-3xl gs-font-bold gs-text-primary"><?= $stats['total_results'] ?></div>
                    <div class="gs-text-sm gs-text-secondary">Resultat</div>
                </div>
            </div>
            <div class="gs-card gs-text-center">
                <div class="gs-card-content">
                    <div class="gs-text-3xl gs-font-bold gs-text-primary"><?= $stats['total_events'] ?></div>
                    <div class="gs-text-sm gs-text-secondary">Events</div>
                </div>
            </div>
            <div class="gs-card gs-text-center">
                <div class="gs-card-content">
                    <div class="gs-text-sm gs-font-bold gs-text-primary">
                        <?= $stats['last_snapshot'] ? date('Y-m-d', strtotime($stats['last_snapshot'])) : 'Aldrig' ?>
                    </div>
                    <div class="gs-text-sm gs-text-secondary">Senaste snapshot</div>
                </div>
            </div>
        </div>

        <!-- Info and Calculation Card -->
        <div class="gs-grid gs-grid-cols-2 gs-gap-lg gs-mb-lg">
            <!-- Info Card -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="info"></i>
                        Om rankingsystemet
                    </h2>
                </div>
                <div class="gs-card-content">
                    <ul class="gs-text-sm" style="margin: 0; padding-left: 1.5rem; line-height: 1.8;">
                        <li>24 månaders rullande ranking för GravitySeries Total</li>
                        <li>Poäng viktas efter fältstorlek (antal deltagare i klassen)</li>
                        <li>Senaste 12 månader: 100% av poängen</li>
                        <li>Månad 13-24: 50% av poängen</li>
                        <li>Äldre än 24 månader: förfaller helt</li>
                        <li>Uppdateras automatiskt 1:e varje månad</li>
                    </ul>
                </div>
            </div>

            <!-- Calculation Card -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="calculator"></i>
                        Beräkning
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-sm gs-text-secondary gs-mb-md">
                        Senaste beräkning:
                        <strong><?= $lastCalc['date'] ? date('Y-m-d H:i', strtotime($lastCalc['date'])) : 'Aldrig' ?></strong>
                        <?php if ($lastCalc['date']): ?>
                            <br>
                            <?= $lastCalc['events_processed'] ?> events, <?= $lastCalc['riders_processed'] ?> resultat
                        <?php endif; ?>
                    </p>

                    <form method="POST">
                        <?= csrf_field() ?>
                        <button type="submit" name="calculate" class="gs-btn gs-btn-primary"
                                onclick="return confirm('Kör fullständig omräkning av alla rankingpoäng?')">
                            <i data-lucide="refresh-cw"></i>
                            Kör beräkning
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Field Multipliers -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="users"></i>
                    Fältstorleksmultiplikatorer
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-sm gs-text-secondary gs-mb-lg">
                    Ju fler åkare i klassen, desto mer värda är poängen. Multiplikatorn anger hur stor andel av originalpoängen som blir rankingpoäng.
                </p>

                <form method="POST" id="multipliersForm">
                    <?= csrf_field() ?>

                    <!-- Visual bar chart -->
                    <div class="gs-mb-lg" style="height: 120px; display: flex; align-items: flex-end; gap: 2px;">
                        <?php for ($i = 1; $i <= 26; $i++): ?>
                            <?php $value = $multipliers[$i] ?? 0.75; ?>
                            <div style="flex: 1; display: flex; flex-direction: column; align-items: center;">
                                <div id="bar_<?= $i ?>"
                                     style="width: 100%; background: var(--gs-primary); border-radius: 2px 2px 0 0; transition: height 0.2s;"
                                     data-value="<?= $value ?>">
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Input grid -->
                    <div style="display: grid; grid-template-columns: repeat(13, 1fr); gap: 4px; font-size: 0.75rem;">
                        <?php for ($i = 1; $i <= 26; $i++): ?>
                            <div style="text-align: center;">
                                <label style="display: block; color: var(--gs-text-secondary); margin-bottom: 2px;">
                                    <?= $i === 26 ? '26+' : $i ?>
                                </label>
                                <input type="number"
                                       name="mult_<?= $i ?>"
                                       id="mult_<?= $i ?>"
                                       value="<?= number_format($multipliers[$i] ?? 0.75, 2) ?>"
                                       min="0" max="1" step="0.01"
                                       class="gs-input"
                                       style="padding: 4px; text-align: center; font-size: 0.75rem;"
                                       oninput="updateBar(<?= $i ?>, this.value)">
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div class="gs-flex gs-gap-sm gs-mt-lg">
                        <button type="submit" name="save_multipliers" class="gs-btn gs-btn-primary">
                            <i data-lucide="save"></i>
                            Spara multiplikatorer
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Time Decay Settings -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="clock"></i>
                    Tidsviktning
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="gs-grid gs-grid-cols-3 gs-gap-lg">
                        <div class="gs-form-group">
                            <label for="decay_1_12" class="gs-label">Månad 1-12</label>
                            <input type="number" id="decay_1_12" name="decay_1_12"
                                   value="<?= number_format($timeDecay['months_1_12'], 2) ?>"
                                   min="0" max="1" step="0.01"
                                   class="gs-input">
                            <small class="gs-text-secondary">Senaste 12 månaderna</small>
                        </div>
                        <div class="gs-form-group">
                            <label for="decay_13_24" class="gs-label">Månad 13-24</label>
                            <input type="number" id="decay_13_24" name="decay_13_24"
                                   value="<?= number_format($timeDecay['months_13_24'], 2) ?>"
                                   min="0" max="1" step="0.01"
                                   class="gs-input">
                            <small class="gs-text-secondary">Förra årets resultat</small>
                        </div>
                        <div class="gs-form-group">
                            <label for="decay_25_plus" class="gs-label">Månad 25+</label>
                            <input type="number" id="decay_25_plus" name="decay_25_plus"
                                   value="<?= number_format($timeDecay['months_25_plus'], 2) ?>"
                                   min="0" max="1" step="0.01"
                                   class="gs-input">
                            <small class="gs-text-secondary">Äldre resultat (förfaller)</small>
                        </div>
                    </div>

                    <div class="gs-flex gs-gap-sm gs-mt-lg">
                        <button type="submit" name="save_decay" class="gs-btn gs-btn-primary">
                            <i data-lucide="save"></i>
                            Spara tidsviktning
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reset Defaults -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-secondary">
                    <i data-lucide="rotate-ccw"></i>
                    Återställ
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-sm gs-text-secondary gs-mb-md">
                    Återställ alla inställningar till standardvärden. Detta påverkar inte beräknade rankingpoäng - kör ny beräkning efteråt.
                </p>

                <form method="POST">
                    <?= csrf_field() ?>
                    <button type="submit" name="reset_defaults" class="gs-btn gs-btn-outline"
                            onclick="return confirm('Återställ alla inställningar till standardvärden?')">
                        <i data-lucide="rotate-ccw"></i>
                        Återställ till standard
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
// Update bar chart visualization
function updateBar(index, value) {
    const bar = document.getElementById('bar_' + index);
    if (bar) {
        const height = Math.max(5, parseFloat(value) * 100);
        bar.style.height = height + 'px';
    }
}

// Initialize bars on page load
document.addEventListener('DOMContentLoaded', function() {
    for (let i = 1; i <= 26; i++) {
        const bar = document.getElementById('bar_' + i);
        if (bar) {
            const value = parseFloat(bar.dataset.value) || 0.75;
            bar.style.height = (value * 100) + 'px';
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
