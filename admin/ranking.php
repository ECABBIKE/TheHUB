<?php
/**
 * Admin Ranking Settings
 * Manage the 24-month rolling ranking system for Enduro, Downhill, and Gravity
 */

// Enable error reporting to catch any issues
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Wrap everything in try-catch to catch early failures
try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/ranking_functions.php';
    require_admin();

    $db = getDB();
    $current_admin = get_current_admin();
} catch (Exception $e) {
    // Show error if something fails during initialization
    echo "<h1>Initialization Error</h1>";
    echo "<pre>";
    echo "Message: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo "File: " . htmlspecialchars($e->getFile()) . "\n";
    echo "Line: " . $e->getLine() . "\n\n";
    echo "Stack trace:\n" . htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    echo "<p><a href='/admin/check-ranking-tables.php'>Check Database Tables</a> | <a href='/admin/'>Back to Admin</a></p>";
    exit;
}

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
        // Run full ranking update (lightweight on-the-fly calculation with snapshots)
        // Show progress output to prevent browser timeout
        echo "<!DOCTYPE html><html><head><title>Ranking Calculation</title></head><body>";
        echo "<h1>Calculating Rankings...</h1>";
        echo "<div style='font-family: monospace; padding: 20px;'>";
        flush();

        try {
            $stats = runFullRankingUpdate($db, true);

            echo "</div>";
            echo "<h2 style='color: green;'>✅ Beräkning Klar!</h2>";
            echo "<p><strong>Tid:</strong> {$stats['total_time']}s</p>";
            echo "<p><strong>Åkare:</strong> Enduro {$stats['enduro']['riders']}, DH {$stats['dh']['riders']}, Gravity {$stats['gravity']['riders']}</p>";
            echo "<p><strong>Klubbar:</strong> Enduro {$stats['enduro']['clubs']}, DH {$stats['dh']['clubs']}, Gravity {$stats['gravity']['clubs']}</p>";
            echo "<p><a href='/admin/ranking.php'>← Tillbaka till Ranking Admin</a> | <a href='/ranking/'>Visa Ranking →</a></p>";
            echo "</body></html>";
            exit;
        } catch (Exception $e) {
            echo "</div>";
            echo "<h2 style='color: red;'>❌ Fel vid beräkning</h2>";
            echo "<pre>" . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            echo "<p><a href='/admin/ranking.php'>← Tillbaka</a></p>";
            echo "</body></html>";
            error_log("Ranking calculation error: " . $e->getMessage());
            error_log($e->getTraceAsString());
            exit;
        }

    } elseif (isset($_POST['save_multipliers'])) {
        // Save field multipliers
        $multipliers = [];
        for ($i = 1; $i <= 15; $i++) {
            $key = "mult_$i";
            if (isset($_POST[$key])) {
                $multipliers[$i] = max(0, min(1, (float)$_POST[$key]));
            }
        }

        if (count($multipliers) === 15) {
            saveFieldMultipliers($db, $multipliers);
            $message = 'Fältstorleksmultiplikatorer sparade.';
            $messageType = 'success';
        } else {
            $message = 'Alla 15 multiplikatorer måste anges.';
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

    } elseif (isset($_POST['save_event_level'])) {
        // Save event level multipliers
        $eventLevel = [
            'national' => max(0, min(1, (float)$_POST['level_national'])),
            'sportmotion' => max(0, min(1, (float)$_POST['level_sportmotion']))
        ];

        saveEventLevelMultipliers($db, $eventLevel);
        $message = 'Eventtypsviktning sparad.';
        $messageType = 'success';

    } elseif (isset($_POST['reset_defaults'])) {
        // Reset to defaults
        saveFieldMultipliers($db, getDefaultFieldMultipliers());
        saveTimeDecay($db, getDefaultTimeDecay());
        saveEventLevelMultipliers($db, getDefaultEventLevelMultipliers());
        $message = 'Inställningar återställda till standardvärden.';
        $messageType = 'success';
    }
}

// Get current settings - wrap in try-catch to handle missing tables
try {
    $multipliers = getRankingFieldMultipliers($db);
    $timeDecay = getRankingTimeDecay($db);
    $eventLevelMultipliers = getEventLevelMultipliers($db);
    $lastCalc = getLastRankingCalculation($db);

    // Get statistics per discipline
    $disciplineStats = getRankingStats($db);

    // Get last snapshot date
    $latestSnapshot = $db->getRow("SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots");
    $lastSnapshotDate = $latestSnapshot ? $latestSnapshot['snapshot_date'] : null;
} catch (Exception $e) {
    // If settings can't be loaded, show clear error
    echo "<h1>Database Error</h1>";
    echo "<p>Could not load ranking settings. The ranking tables may not exist yet.</p>";
    echo "<pre>";
    echo "Error: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo "File: " . htmlspecialchars($e->getFile()) . "\n";
    echo "Line: " . $e->getLine() . "\n\n";
    echo "Stack trace:\n" . htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    echo "<p><strong>Solution:</strong></p>";
    echo "<ul>";
    echo "<li><a href='/admin/check-ranking-tables.php'>Check Database Tables</a> - Diagnose what's missing</li>";
    echo "<li><a href='/admin/migrate.php'>Run Migrations</a> - Create the ranking tables</li>";
    echo "<li><a href='/admin/'>Back to Admin</a></li>";
    echo "</ul>";
    exit;
}

$pageTitle = 'Ranking';
$pageType = 'admin';

// DEBUG: Output before header
echo "<!-- DEBUG: About to include header -->\n";
flush();

include __DIR__ . '/../includes/layout-header.php';

// DEBUG: Output after header
echo "<!-- DEBUG: Header included, starting main content -->\n";
flush();
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
                <?php if ($messageType === 'error'): ?>
                    <pre style="white-space: pre-wrap; font-size: 0.875rem; margin-top: 0.5rem; overflow-x: auto;"><?= h($message) ?></pre>
                <?php else: ?>
                    <?= h($message) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards per Discipline -->
        <div class="gs-grid gs-grid-cols-3 gs-gap-md gs-mb-lg">
            <!-- Enduro -->
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <h3 class="gs-text-sm gs-text-secondary gs-mb-sm">Enduro</h3>
                    <div class="gs-text-2xl gs-font-bold gs-text-primary"><?= $disciplineStats['ENDURO']['riders'] ?></div>
                    <div class="gs-text-xs gs-text-secondary">åkare • <?= $disciplineStats['ENDURO']['clubs'] ?> klubbar</div>
                    <div class="gs-text-xs gs-text-secondary"><?= $disciplineStats['ENDURO']['events'] ?> events</div>
                </div>
            </div>
            <!-- Downhill -->
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <h3 class="gs-text-sm gs-text-secondary gs-mb-sm">Downhill</h3>
                    <div class="gs-text-2xl gs-font-bold gs-text-primary"><?= $disciplineStats['DH']['riders'] ?></div>
                    <div class="gs-text-xs gs-text-secondary">åkare • <?= $disciplineStats['DH']['clubs'] ?> klubbar</div>
                    <div class="gs-text-xs gs-text-secondary"><?= $disciplineStats['DH']['events'] ?> events</div>
                </div>
            </div>
            <!-- Gravity -->
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <h3 class="gs-text-sm gs-text-secondary gs-mb-sm">Gravity</h3>
                    <div class="gs-text-2xl gs-font-bold gs-text-primary"><?= $disciplineStats['GRAVITY']['riders'] ?></div>
                    <div class="gs-text-xs gs-text-secondary">åkare • <?= $disciplineStats['GRAVITY']['clubs'] ?> klubbar</div>
                    <div class="gs-text-xs gs-text-secondary"><?= $disciplineStats['GRAVITY']['events'] ?> events</div>
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
                        <li>Tre rankingar: <strong>Enduro</strong>, <strong>Downhill</strong>, <strong>Gravity</strong> (kombinerad)</li>
                        <li>24 månaders rullande fönster</li>
                        <li>Poäng viktas efter fältstorlek (antal deltagare i klassen)</li>
                        <li>Nationella event: 100%, Sportmotion: 50% (justerbart)</li>
                        <li>Senaste 12 månader: 100% av poängen</li>
                        <li>Månad 13-24: 50% av poängen</li>
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
                        <?php if ($lastCalc['date'] && isset($lastCalc['stats']['total_time'])): ?>
                            <br>
                            Tog <?= $lastCalc['stats']['total_time'] ?>s att köra
                        <?php endif; ?>
                        <br><br>
                        Senaste snapshot:
                        <strong><?= $lastSnapshotDate ? date('Y-m-d', strtotime($lastSnapshotDate)) : 'Aldrig' ?></strong>
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

        <!-- Event Level Multipliers -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="trophy"></i>
                    Eventtypsviktning
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-sm gs-text-secondary gs-mb-lg">
                    Nationella tävlingar ger fulla poäng. Sportmotion-event kan viktas ned för att spegla lägre tävlingsnivå.
                </p>

                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="gs-grid gs-grid-cols-2 gs-gap-lg">
                        <div class="gs-form-group">
                            <label for="level_national" class="gs-label">Nationell tävling</label>
                            <input type="number" id="level_national" name="level_national"
                                   value="<?= number_format($eventLevelMultipliers['national'], 2) ?>"
                                   min="0" max="1" step="0.01"
                                   class="gs-input">
                            <small class="gs-text-secondary">Officiella tävlingar (standard 100%)</small>
                        </div>
                        <div class="gs-form-group">
                            <label for="level_sportmotion" class="gs-label">Sportmotion</label>
                            <input type="number" id="level_sportmotion" name="level_sportmotion"
                                   value="<?= number_format($eventLevelMultipliers['sportmotion'], 2) ?>"
                                   min="0" max="1" step="0.01"
                                   class="gs-input">
                            <small class="gs-text-secondary">Breddtävlingar (standard 50%)</small>
                        </div>
                    </div>

                    <div class="gs-flex gs-gap-sm gs-mt-lg">
                        <button type="submit" name="save_event_level" class="gs-btn gs-btn-primary">
                            <i data-lucide="save"></i>
                            Spara eventtypsviktning
                        </button>
                    </div>
                </form>
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
                        <?php for ($i = 1; $i <= 15; $i++): ?>
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
                    <div style="display: grid; grid-template-columns: repeat(15, 1fr); gap: 4px; font-size: 0.75rem;">
                        <?php for ($i = 1; $i <= 15; $i++): ?>
                            <div style="text-align: center;">
                                <label style="display: block; color: var(--gs-text-secondary); margin-bottom: 2px;">
                                    <?= $i === 15 ? '15+' : $i ?>
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

<style>
/* Mobile-responsive styles for admin ranking page */
@media (max-width: 767px) {
    /* Stack discipline stats to 1 column */
    .gs-grid.gs-grid-cols-3 {
        grid-template-columns: 1fr !important;
    }

    /* Stack info/calculation cards to 1 column */
    .gs-grid.gs-grid-cols-2 {
        grid-template-columns: 1fr !important;
    }

    /* Make header stack better */
    .gs-flex.gs-items-center.gs-justify-between {
        flex-direction: column;
        align-items: flex-start !important;
    }

    .gs-flex.gs-items-center.gs-justify-between .gs-btn {
        width: 100%;
        justify-content: center;
    }

    /* Make cards more compact */
    .gs-card {
        margin-bottom: var(--gs-space-md);
    }

    .gs-card-content {
        padding: var(--gs-space-md);
    }

    /* Make buttons full width and larger */
    .gs-btn {
        width: 100%;
        padding: var(--gs-space-md);
        font-size: 1rem;
    }

    .gs-flex.gs-gap-sm {
        flex-direction: column;
    }

    /* Make inputs larger and more touch-friendly */
    .gs-input {
        font-size: 16px !important; /* Prevents zoom on iOS */
        padding: var(--gs-space-md);
        min-height: 44px; /* Touch target size */
    }

    /* Stack forms better */
    .gs-form-group {
        margin-bottom: var(--gs-space-md);
    }

    .gs-form-group label {
        font-size: 1rem;
        margin-bottom: var(--gs-space-sm);
    }

    .gs-form-group small {
        font-size: 0.875rem;
        display: block;
        margin-top: var(--gs-space-xs);
    }
}

/* Specific mobile styles for field multipliers */
@media (max-width: 767px) {
    /* Reduce multipliers grid to 5 columns on mobile */
    #multipliersForm [style*="grid-template-columns: repeat(15"] {
        grid-template-columns: repeat(5, 1fr) !important;
    }

    /* Make multiplier inputs larger */
    #multipliersForm input[type="number"] {
        font-size: 14px !important;
        padding: 8px 4px !important;
        min-height: 44px;
    }

    #multipliersForm label {
        font-size: 0.8rem !important;
        margin-bottom: 4px;
    }

    /* Adjust bar chart for mobile */
    .gs-mb-lg[style*="height: 120px"] {
        height: 80px !important;
        margin-bottom: var(--gs-space-md) !important;
    }
}

/* Tablet styles */
@media (min-width: 768px) and (max-width: 1023px) {
    /* 2 columns for discipline stats on tablet */
    .gs-grid.gs-grid-cols-3:first-of-type {
        grid-template-columns: repeat(2, 1fr) !important;
    }

    /* Reduce multipliers to 8 columns on tablet */
    #multipliersForm [style*="grid-template-columns: repeat(15"] {
        grid-template-columns: repeat(8, 1fr) !important;
    }

    #multipliersForm input[type="number"] {
        font-size: 13px !important;
        padding: 6px 3px !important;
    }
}

/* Landscape phone - slightly different layout */
@media (max-width: 767px) and (orientation: landscape) {
    /* Keep stats in 3 columns in landscape */
    .gs-grid.gs-grid-cols-3:first-of-type {
        grid-template-columns: repeat(3, 1fr) !important;
    }

    /* 2 columns for event level and time decay in landscape */
    .gs-card:has(#level_national) .gs-grid,
    .gs-card:has(#decay_1_12) .gs-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}
</style>

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
    for (let i = 1; i <= 15; i++) {
        const bar = document.getElementById('bar_' + i);
        if (bar) {
            const value = parseFloat(bar.dataset.value) || 0.75;
            bar.style.height = (value * 100) + 'px';
        }
    }
});
</script>

<!-- DEBUG: About to include footer -->
<?php
echo "<!-- DEBUG: Including footer now -->\n";
flush();
include __DIR__ . '/../includes/layout-footer.php';
echo "<!-- DEBUG: Footer included, page complete -->\n";
?>
