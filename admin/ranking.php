<?php
/**
 * Admin Ranking Settings - V3 Design System
 * Manage the 24-month rolling ranking system for Enduro, Downhill, and Gravity
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ranking_functions.php';
require_admin();

$db = getDB();

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
        // Run full ranking update
        echo "<!DOCTYPE html><html><head><title>Ranking Calculation</title></head><body>";
        echo "<h1>Beräknar rankings...</h1>";
        echo "<p style='padding: 20px;'>Detta kan ta några sekunder...</p>";
        flush();

        try {
            $stats = runFullRankingUpdate($db, false);

            echo "<h2 style='color: green;'>✅ Beräkning Klar!</h2>";
            echo "<p><strong>Tid:</strong> {$stats['total_time']}s</p>";
            echo "<p><strong>Åkare:</strong> Enduro {$stats['enduro']['riders']}, DH {$stats['dh']['riders']}, Gravity {$stats['gravity']['riders']}</p>";
            echo "<p><strong>Klubbar:</strong> Enduro {$stats['enduro']['clubs']}, DH {$stats['dh']['clubs']}, Gravity {$stats['gravity']['clubs']}</p>";
            echo "<p><a href='/admin/ranking'>← Tillbaka till Ranking Admin</a> | <a href='/ranking/'>Visa Ranking →</a></p>";
            echo "</body></html>";
            exit;
        } catch (Exception $e) {
            echo "<h2 style='color: red;'>❌ Fel vid beräkning</h2>";
            echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
            echo "<p><a href='/admin/ranking'>← Tillbaka</a></p>";
            echo "</body></html>";
            exit;
        }

    } elseif (isset($_POST['save_multipliers'])) {
        try {
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
        } catch (Exception $e) {
            $message = 'Fel vid sparande: ' . $e->getMessage();
            $messageType = 'error';
        }

    } elseif (isset($_POST['save_decay'])) {
        $timeDecay = [
            'months_1_12' => max(0, min(1, (float)$_POST['decay_1_12'])),
            'months_13_24' => max(0, min(1, (float)$_POST['decay_13_24'])),
            'months_25_plus' => max(0, min(1, (float)$_POST['decay_25_plus']))
        ];

        saveTimeDecay($db, $timeDecay);
        $message = 'Tidsviktning sparad.';
        $messageType = 'success';

    } elseif (isset($_POST['save_event_level'])) {
        $eventLevel = [
            'national' => max(0, min(1, (float)$_POST['level_national'])),
            'sportmotion' => max(0, min(1, (float)$_POST['level_sportmotion']))
        ];

        saveEventLevelMultipliers($db, $eventLevel);
        $message = 'Eventtypsviktning sparad.';
        $messageType = 'success';

    } elseif (isset($_POST['reset_defaults'])) {
        try {
            saveFieldMultipliers($db, getDefaultFieldMultipliers());
            saveTimeDecay($db, getDefaultTimeDecay());
            saveEventLevelMultipliers($db, getDefaultEventLevelMultipliers());
            $message = 'Inställningar återställda till standardvärden.';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Fel vid återställning: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get current settings
try {
    $multipliers = getRankingFieldMultipliers($db);
    $timeDecay = getRankingTimeDecay($db);
    $eventLevelMultipliers = getEventLevelMultipliers($db);
    $lastCalc = getLastRankingCalculation($db);
    $disciplineStats = getRankingStats($db);
    $latestSnapshot = $db->getRow("SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots");
    $lastSnapshotDate = $latestSnapshot ? $latestSnapshot['snapshot_date'] : null;
} catch (Exception $e) {
    $message = 'Kunde inte ladda ranking-inställningar: ' . $e->getMessage();
    $messageType = 'error';
    $multipliers = getDefaultFieldMultipliers();
    $timeDecay = getDefaultTimeDecay();
    $eventLevelMultipliers = getDefaultEventLevelMultipliers();
    $lastCalc = ['date' => null, 'stats' => []];
    $disciplineStats = ['ENDURO' => ['riders' => 0, 'clubs' => 0, 'events' => 0], 'DH' => ['riders' => 0, 'clubs' => 0, 'events' => 0], 'GRAVITY' => ['riders' => 0, 'clubs' => 0, 'events' => 0]];
    $lastSnapshotDate = null;
}

// Page config for V3 layout
$page_title = 'Ranking';
$breadcrumbs = [['label' => 'Ranking']];
$page_actions = '<a href="/ranking/" target="_blank" class="btn-admin btn-admin-secondary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>
    Publik vy
</a>';

include __DIR__ . '/components/admin-layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="admin-stats-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-md); margin-bottom: var(--space-xl);">
    <div class="admin-card">
        <div class="admin-card-body" style="text-align: center; padding: var(--space-lg);">
            <h3 style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-sm);">Enduro</h3>
            <div style="font-size: var(--text-2xl); font-weight: bold; color: var(--color-accent);"><?= $disciplineStats['ENDURO']['riders'] ?? 0 ?></div>
            <div style="font-size: var(--text-xs); color: var(--color-text-secondary);">åkare • <?= $disciplineStats['ENDURO']['clubs'] ?? 0 ?> klubbar</div>
            <div style="font-size: var(--text-xs); color: var(--color-text-secondary);"><?= $disciplineStats['ENDURO']['events'] ?? 0 ?> events</div>
        </div>
    </div>
    <div class="admin-card">
        <div class="admin-card-body" style="text-align: center; padding: var(--space-lg);">
            <h3 style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-sm);">Downhill</h3>
            <div style="font-size: var(--text-2xl); font-weight: bold; color: var(--color-accent);"><?= $disciplineStats['DH']['riders'] ?? 0 ?></div>
            <div style="font-size: var(--text-xs); color: var(--color-text-secondary);">åkare • <?= $disciplineStats['DH']['clubs'] ?? 0 ?> klubbar</div>
            <div style="font-size: var(--text-xs); color: var(--color-text-secondary);"><?= $disciplineStats['DH']['events'] ?? 0 ?> events</div>
        </div>
    </div>
    <div class="admin-card">
        <div class="admin-card-body" style="text-align: center; padding: var(--space-lg);">
            <h3 style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-sm);">Gravity</h3>
            <div style="font-size: var(--text-2xl); font-weight: bold; color: var(--color-accent);"><?= $disciplineStats['GRAVITY']['riders'] ?? 0 ?></div>
            <div style="font-size: var(--text-xs); color: var(--color-text-secondary);">åkare • <?= $disciplineStats['GRAVITY']['clubs'] ?? 0 ?> klubbar</div>
            <div style="font-size: var(--text-xs); color: var(--color-text-secondary);"><?= $disciplineStats['GRAVITY']['events'] ?? 0 ?> events</div>
        </div>
    </div>
</div>

<!-- Info and Calculation Cards -->
<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-lg); margin-bottom: var(--space-xl);">
    <!-- Info Card -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Om rankingsystemet</h2>
        </div>
        <div class="admin-card-body">
            <ul style="margin: 0; padding-left: 1.5rem; line-height: 1.8; font-size: var(--text-sm);">
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
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Beräkning</h2>
        </div>
        <div class="admin-card-body">
            <p style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-md);">
                Senaste beräkning:
                <strong><?= $lastCalc['date'] ? date('Y-m-d H:i', strtotime($lastCalc['date'])) : 'Aldrig' ?></strong>
                <br><br>
                Senaste snapshot:
                <strong><?= $lastSnapshotDate ? date('Y-m-d', strtotime($lastSnapshotDate)) : 'Aldrig' ?></strong>
            </p>

            <form method="POST" style="display: inline-block;">
                <?= csrf_field() ?>
                <button type="submit" name="calculate" class="btn-admin btn-admin-primary"
                    onclick="return confirm('Kör fullständig omräkning av alla rankingpoäng?')">
                    Kör beräkning
                </button>
            </form>
            <a href="/admin/recalculate-all-points.php" class="btn-admin btn-admin-secondary" style="margin-left: var(--space-sm);">
                Räkna om alla poäng
            </a>
        </div>
    </div>
</div>

<!-- Event Level Multipliers -->
<div class="admin-card" style="margin-bottom: var(--space-xl);">
    <div class="admin-card-header">
        <h2>Eventtypsviktning</h2>
    </div>
    <div class="admin-card-body">
        <p style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-lg);">
            Nationella tävlingar ger fulla poäng. Sportmotion-event kan viktas ned för att spegla lägre tävlingsnivå.
        </p>

        <form method="POST">
            <?= csrf_field() ?>

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-lg);">
                <div class="admin-form-group">
                    <label class="admin-label">Nationell tävling</label>
                    <input type="number" name="level_national"
                        value="<?= number_format($eventLevelMultipliers['national'], 2) ?>"
                        min="0" max="1" step="0.01"
                        class="admin-input">
                    <small style="color: var(--color-text-secondary);">Officiella tävlingar (standard 100%)</small>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label">Sportmotion</label>
                    <input type="number" name="level_sportmotion"
                        value="<?= number_format($eventLevelMultipliers['sportmotion'], 2) ?>"
                        min="0" max="1" step="0.01"
                        class="admin-input">
                    <small style="color: var(--color-text-secondary);">Breddtävlingar (standard 50%)</small>
                </div>
            </div>

            <div style="margin-top: var(--space-lg);">
                <button type="submit" name="save_event_level" class="btn-admin btn-admin-primary">
                    Spara eventtypsviktning
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Field Multipliers -->
<div class="admin-card" style="margin-bottom: var(--space-xl);">
    <div class="admin-card-header">
        <h2>Fältstorleksmultiplikatorer</h2>
    </div>
    <div class="admin-card-body">
        <p style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-lg);">
            Ju fler åkare i klassen, desto mer värda är poängen. Multiplikatorn anger hur stor andel av originalpoängen som blir rankingpoäng.
        </p>

        <form method="POST" id="multipliersForm">
            <?= csrf_field() ?>

            <!-- Visual bar chart -->
            <div style="height: 100px; display: flex; align-items: flex-end; gap: 2px; margin-bottom: var(--space-md);">
                <?php for ($i = 1; $i <= 15; $i++): ?>
                <?php $value = $multipliers[$i] ?? 0.75; ?>
                <div style="flex: 1; display: flex; flex-direction: column; align-items: center;">
                    <div id="bar_<?= $i ?>"
                        style="width: 100%; background: var(--color-accent); border-radius: 2px 2px 0 0; transition: height 0.2s; height: <?= $value * 100 ?>px;">
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Input grid -->
            <div style="display: grid; grid-template-columns: repeat(15, 1fr); gap: 4px; font-size: 0.75rem;">
                <?php for ($i = 1; $i <= 15; $i++): ?>
                <div style="text-align: center;">
                    <label style="display: block; color: var(--color-text-secondary); margin-bottom: 2px;">
                        <?= $i === 15 ? '15+' : $i ?>
                    </label>
                    <input type="number"
                        name="mult_<?= $i ?>"
                        id="mult_<?= $i ?>"
                        value="<?= number_format($multipliers[$i] ?? 0.75, 2) ?>"
                        min="0" max="1" step="0.01"
                        class="admin-input"
                        style="padding: 4px; text-align: center; font-size: 0.75rem;"
                        oninput="updateBar(<?= $i ?>, this.value)">
                </div>
                <?php endfor; ?>
            </div>

            <div style="margin-top: var(--space-lg);">
                <button type="submit" name="save_multipliers" class="btn-admin btn-admin-primary">
                    Spara multiplikatorer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Time Decay Settings -->
<div class="admin-card" style="margin-bottom: var(--space-xl);">
    <div class="admin-card-header">
        <h2>Tidsviktning</h2>
    </div>
    <div class="admin-card-body">
        <form method="POST">
            <?= csrf_field() ?>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-lg);">
                <div class="admin-form-group">
                    <label class="admin-label">Månad 1-12</label>
                    <input type="number" name="decay_1_12"
                        value="<?= number_format($timeDecay['months_1_12'], 2) ?>"
                        min="0" max="1" step="0.01"
                        class="admin-input">
                    <small style="color: var(--color-text-secondary);">Senaste 12 månaderna</small>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label">Månad 13-24</label>
                    <input type="number" name="decay_13_24"
                        value="<?= number_format($timeDecay['months_13_24'], 2) ?>"
                        min="0" max="1" step="0.01"
                        class="admin-input">
                    <small style="color: var(--color-text-secondary);">Förra årets resultat</small>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label">Månad 25+</label>
                    <input type="number" name="decay_25_plus"
                        value="<?= number_format($timeDecay['months_25_plus'], 2) ?>"
                        min="0" max="1" step="0.01"
                        class="admin-input">
                    <small style="color: var(--color-text-secondary);">Äldre resultat (förfaller)</small>
                </div>
            </div>

            <div style="margin-top: var(--space-lg);">
                <button type="submit" name="save_decay" class="btn-admin btn-admin-primary">
                    Spara tidsviktning
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Defaults -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>Återställ</h2>
    </div>
    <div class="admin-card-body">
        <p style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-md);">
            Återställ alla inställningar till standardvärden. Detta påverkar inte beräknade rankingpoäng - kör ny beräkning efteråt.
        </p>

        <form method="POST">
            <?= csrf_field() ?>
            <button type="submit" name="reset_defaults" class="btn-admin btn-admin-secondary"
                onclick="return confirm('Återställ alla inställningar till standardvärden?')">
                Återställ till standard
            </button>
        </form>
    </div>
</div>

<script>
function updateBar(index, value) {
    const bar = document.getElementById('bar_' + index);
    if (bar) {
        const height = Math.max(5, parseFloat(value) * 100);
        bar.style.height = height + 'px';
    }
}
</script>

<?php include __DIR__ . '/components/admin-footer.php'; ?>
