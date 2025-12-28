<?php
/**
 * Admin Elimination Manage - Hantera kvalificering och brackets för ett event
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

$eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
if (!$eventId) {
    header('Location: /admin/elimination.php');
    exit;
}

// Get event info
$event = $db->getOne("SELECT e.*, s.name as series_name FROM events e LEFT JOIN series s ON e.series_id = s.id WHERE e.id = ?", [$eventId]);
if (!$event) {
    $_SESSION['error'] = 'Event hittades inte';
    header('Location: /admin/elimination.php');
    exit;
}

// Get all classes for this event (from existing results or all active classes)
$classes = $db->getAll("
    SELECT DISTINCT c.id, c.name, c.display_name
    FROM classes c
    WHERE c.active = 1
    AND (c.discipline LIKE '%DUAL_SLALOM%' OR c.discipline LIKE '%DH%' OR c.discipline IS NULL)
    ORDER BY c.sort_order, c.name
");

$selectedClassId = isset($_GET['class_id']) ? intval($_GET['class_id']) : ($classes[0]['id'] ?? 0);

// Check if elimination tables exist
$tablesExist = true;
try {
    $pdo->query("SELECT 1 FROM elimination_qualifying LIMIT 1");
} catch (Exception $e) {
    $tablesExist = false;
}

// Get qualifying results for selected class
$qualifyingResults = [];
$brackets = [];
$finalResults = [];

if ($tablesExist && $selectedClassId) {
    $qualifyingResults = $db->getAll("
        SELECT eq.*, r.firstname, r.lastname, cl.name as club_name
        FROM elimination_qualifying eq
        JOIN riders r ON eq.rider_id = r.id
        LEFT JOIN clubs cl ON r.club_id = cl.id
        WHERE eq.event_id = ? AND eq.class_id = ?
        ORDER BY eq.seed_position ASC, eq.best_time ASC
    ", [$eventId, $selectedClassId]);

    // Get brackets grouped by round
    $bracketsRaw = $db->getAll("
        SELECT eb.*,
            r1.firstname as rider1_firstname, r1.lastname as rider1_lastname,
            r2.firstname as rider2_firstname, r2.lastname as rider2_lastname,
            w.firstname as winner_firstname, w.lastname as winner_lastname
        FROM elimination_brackets eb
        LEFT JOIN riders r1 ON eb.rider_1_id = r1.id
        LEFT JOIN riders r2 ON eb.rider_2_id = r2.id
        LEFT JOIN riders w ON eb.winner_id = w.id
        WHERE eb.event_id = ? AND eb.class_id = ?
        ORDER BY eb.round_number ASC, eb.heat_number ASC
    ", [$eventId, $selectedClassId]);

    // Group brackets by round
    foreach ($bracketsRaw as $b) {
        $brackets[$b['round_name']][] = $b;
    }

    $finalResults = $db->getAll("
        SELECT er.*, r.firstname, r.lastname, cl.name as club_name
        FROM elimination_results er
        JOIN riders r ON er.rider_id = r.id
        LEFT JOIN clubs cl ON r.club_id = cl.id
        WHERE er.event_id = ? AND er.class_id = ?
        ORDER BY er.final_position ASC
    ", [$eventId, $selectedClassId]);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'import_qualifying') {
        // Handle CSV import of qualifying results
        // TODO: Implement CSV import
    }

    if ($action === 'generate_brackets') {
        // Generate brackets from qualifying results
        $bracketSize = intval($_POST['bracket_size'] ?? 16);
        $classId = intval($_POST['class_id'] ?? 0);

        if ($classId && in_array($bracketSize, [8, 16, 32])) {
            try {
                // Get top N qualifiers
                $qualifiers = $db->getAll("
                    SELECT * FROM elimination_qualifying
                    WHERE event_id = ? AND class_id = ? AND status = 'finished'
                    ORDER BY best_time ASC
                    LIMIT ?
                ", [$eventId, $classId, $bracketSize]);

                if (count($qualifiers) >= 2) {
                    // Clear existing brackets for this class
                    $pdo->prepare("DELETE FROM elimination_brackets WHERE event_id = ? AND class_id = ?")->execute([$eventId, $classId]);

                    // Update seed positions
                    $seedPos = 1;
                    foreach ($qualifiers as $q) {
                        $pdo->prepare("UPDATE elimination_qualifying SET seed_position = ?, advances_to_bracket = 1 WHERE id = ?")
                            ->execute([$seedPos, $q['id']]);
                        $seedPos++;
                    }

                    // Generate first round matchups using standard bracket seeding
                    // 1 vs 16, 8 vs 9, 5 vs 12, 4 vs 13, 3 vs 14, 6 vs 11, 7 vs 10, 2 vs 15
                    $seedPairs = [];
                    $numQualifiers = count($qualifiers);

                    if ($bracketSize == 8) {
                        $seedPairs = [[1,8], [4,5], [3,6], [2,7]];
                    } elseif ($bracketSize == 16) {
                        $seedPairs = [[1,16], [8,9], [5,12], [4,13], [3,14], [6,11], [7,10], [2,15]];
                    } else { // 32
                        $seedPairs = [
                            [1,32], [16,17], [9,24], [8,25], [5,28], [12,21], [13,20], [4,29],
                            [3,30], [14,19], [11,22], [6,27], [7,26], [10,23], [15,18], [2,31]
                        ];
                    }

                    $roundName = $bracketSize == 8 ? 'quarterfinal' : ($bracketSize == 16 ? 'round_of_16' : 'round_of_32');
                    $heatNum = 1;

                    foreach ($seedPairs as $pair) {
                        $rider1 = isset($qualifiers[$pair[0] - 1]) ? $qualifiers[$pair[0] - 1] : null;
                        $rider2 = isset($qualifiers[$pair[1] - 1]) ? $qualifiers[$pair[1] - 1] : null;

                        // If one rider is missing, it's a BYE
                        $status = ($rider1 && $rider2) ? 'pending' : 'bye';
                        $winnerId = null;
                        $loserId = null;

                        if ($status === 'bye') {
                            $winnerId = $rider1 ? $rider1['rider_id'] : ($rider2 ? $rider2['rider_id'] : null);
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO elimination_brackets
                            (event_id, class_id, bracket_type, round_name, round_number, heat_number,
                             rider_1_id, rider_2_id, rider_1_seed, rider_2_seed, winner_id, status, bracket_position)
                            VALUES (?, ?, 'main', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $eventId, $classId, $roundName, $heatNum,
                            $rider1 ? $rider1['rider_id'] : null,
                            $rider2 ? $rider2['rider_id'] : null,
                            $pair[0], $pair[1],
                            $winnerId, $status, $heatNum
                        ]);
                        $heatNum++;
                    }

                    $_SESSION['success'] = "Bracket genererat för " . count($qualifiers) . " åkare!";
                } else {
                    $_SESSION['error'] = "Minst 2 kvalificerade åkare krävs för att generera bracket.";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Fel vid generering: " . $e->getMessage();
            }
        }

        header("Location: /admin/elimination-manage.php?event_id={$eventId}&class_id={$classId}");
        exit;
    }

    if ($action === 'save_heat') {
        // Save heat result
        $heatId = intval($_POST['heat_id'] ?? 0);
        $rider1Run1 = floatval($_POST['rider1_run1'] ?? 0);
        $rider1Run2 = floatval($_POST['rider1_run2'] ?? 0);
        $rider2Run1 = floatval($_POST['rider2_run1'] ?? 0);
        $rider2Run2 = floatval($_POST['rider2_run2'] ?? 0);

        if ($heatId) {
            $rider1Total = $rider1Run1 + $rider1Run2;
            $rider2Total = $rider2Run1 + $rider2Run2;

            // Determine winner (lower total time wins)
            $heat = $db->getOne("SELECT * FROM elimination_brackets WHERE id = ?", [$heatId]);
            $winnerId = null;
            $loserId = null;

            if ($rider1Total > 0 && $rider2Total > 0) {
                if ($rider1Total < $rider2Total) {
                    $winnerId = $heat['rider_1_id'];
                    $loserId = $heat['rider_2_id'];
                } else {
                    $winnerId = $heat['rider_2_id'];
                    $loserId = $heat['rider_1_id'];
                }
            }

            $stmt = $pdo->prepare("
                UPDATE elimination_brackets SET
                    rider_1_run1 = ?, rider_1_run2 = ?, rider_1_total = ?,
                    rider_2_run1 = ?, rider_2_run2 = ?, rider_2_total = ?,
                    winner_id = ?, loser_id = ?, status = 'completed'
                WHERE id = ?
            ");
            $stmt->execute([
                $rider1Run1, $rider1Run2, $rider1Total,
                $rider2Run1, $rider2Run2, $rider2Total,
                $winnerId, $loserId, $heatId
            ]);

            $_SESSION['success'] = "Heat sparat!";
        }

        header("Location: /admin/elimination-manage.php?event_id={$eventId}&class_id={$selectedClassId}");
        exit;
    }
}

// Page config
$page_title = 'Hantera Elimination - ' . $event['name'];
$breadcrumbs = [
    ['label' => 'Tävlingar', 'url' => '/admin/events.php'],
    ['label' => 'Elimination', 'url' => '/admin/elimination.php'],
    ['label' => $event['name']]
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success mb-lg">
        <i data-lucide="check-circle"></i>
        <?= h($_SESSION['success']) ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger mb-lg">
        <i data-lucide="alert-circle"></i>
        <?= h($_SESSION['error']) ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if (!$tablesExist): ?>
    <div class="admin-card">
        <div class="admin-card-body">
            <div class="alert alert-warning">
                <i data-lucide="database"></i>
                <strong>Databastabeller saknas!</strong>
                <p>Elimination-tabellerna har inte skapats än. Kör migrationen först:</p>
                <a href="/admin/run-migrations.php" class="btn-admin btn-admin-primary mt-md">Kör Migrationer</a>
            </div>
        </div>
    </div>
<?php else: ?>

<!-- Event Info Header -->
<div class="admin-card mb-lg">
    <div class="admin-card-body">
        <div class="flex items-center justify-between flex-wrap gap-md">
            <div>
                <h2 style="margin: 0;"><?= h($event['name']) ?></h2>
                <p style="margin: var(--space-xs) 0 0; color: var(--color-text-secondary);">
                    <?= date('Y-m-d', strtotime($event['date'])) ?> &middot; <?= h($event['location'] ?? 'Okänd plats') ?>
                    <?php if ($event['series_name']): ?>
                        &middot; <?= h($event['series_name']) ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex gap-sm">
                <a href="/admin/elimination-live.php?event_id=<?= $eventId ?>&class_id=<?= $selectedClassId ?>" class="btn-admin btn-admin-primary">
                    <i data-lucide="radio"></i> Live Entry
                </a>
                <a href="/event/<?= $eventId ?>?tab=elimination" class="btn-admin btn-admin-secondary">
                    <i data-lucide="eye"></i> Visa publikt
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Class Selector -->
<div class="admin-card mb-lg">
    <div class="admin-card-body">
        <form method="GET" class="admin-form-row">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <div class="admin-form-group" style="margin-bottom: 0; flex: 1;">
                <label for="class-select" class="admin-form-label">Välj Klass</label>
                <select id="class-select" name="class_id" class="admin-form-select" onchange="this.form.submit()">
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= $selectedClassId == $class['id'] ? 'selected' : '' ?>>
                            <?= h($class['display_name'] ?? $class['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Tabs for different sections -->
<div class="tabs mb-lg">
    <nav class="tabs-nav">
        <button class="tab-btn active" data-tab="qualifying">
            <i data-lucide="timer"></i> Kvalificering
            <span class="admin-badge admin-badge-<?= count($qualifyingResults) > 0 ? 'success' : 'secondary' ?> ml-sm"><?= count($qualifyingResults) ?></span>
        </button>
        <button class="tab-btn" data-tab="brackets">
            <i data-lucide="git-branch"></i> Bracket
            <span class="admin-badge admin-badge-<?= !empty($brackets) ? 'success' : 'secondary' ?> ml-sm"><?= array_sum(array_map('count', $brackets)) ?></span>
        </button>
        <button class="tab-btn" data-tab="results">
            <i data-lucide="trophy"></i> Resultat
            <span class="admin-badge admin-badge-<?= count($finalResults) > 0 ? 'success' : 'secondary' ?> ml-sm"><?= count($finalResults) ?></span>
        </button>
    </nav>

    <!-- QUALIFYING TAB -->
    <div class="tab-content active" id="qualifying">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3>Kvalificeringsresultat</h3>
                <div class="flex gap-sm">
                    <a href="/admin/elimination-import-qualifying.php?event_id=<?= $eventId ?>&class_id=<?= $selectedClassId ?>" class="btn-admin btn-admin-primary btn-admin-sm">
                        <i data-lucide="upload"></i> Importera
                    </a>
                    <a href="/admin/elimination-add-qualifying.php?event_id=<?= $eventId ?>&class_id=<?= $selectedClassId ?>" class="btn-admin btn-admin-secondary btn-admin-sm">
                        <i data-lucide="plus"></i> Lägg till
                    </a>
                </div>
            </div>
            <div class="admin-card-body">
                <?php if (empty($qualifyingResults)): ?>
                    <div class="admin-empty-state">
                        <i data-lucide="timer"></i>
                        <h3>Inga kvalresultat</h3>
                        <p>Importera eller lägg till kvalificeringsresultat för att komma igång.</p>
                    </div>
                <?php else: ?>
                    <div class="admin-table-container">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Seed</th>
                                    <th>Nr</th>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th class="text-right">Åk 1</th>
                                    <th class="text-right">Åk 2</th>
                                    <th class="text-right">Bäst</th>
                                    <th class="text-center">Till bracket</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($qualifyingResults as $qr): ?>
                                    <tr class="<?= $qr['advances_to_bracket'] ? 'row-highlight' : '' ?>">
                                        <td><strong><?= $qr['seed_position'] ?? '-' ?></strong></td>
                                        <td><?= h($qr['bib_number'] ?? '-') ?></td>
                                        <td><?= h($qr['firstname'] . ' ' . $qr['lastname']) ?></td>
                                        <td><?= h($qr['club_name'] ?? '-') ?></td>
                                        <td class="text-right"><?= $qr['run_1_time'] ? number_format($qr['run_1_time'], 3) : '-' ?></td>
                                        <td class="text-right"><?= $qr['run_2_time'] ? number_format($qr['run_2_time'], 3) : '-' ?></td>
                                        <td class="text-right"><strong><?= $qr['best_time'] ? number_format($qr['best_time'], 3) : '-' ?></strong></td>
                                        <td class="text-center">
                                            <?php if ($qr['advances_to_bracket']): ?>
                                                <span class="admin-badge admin-badge-success">Ja</span>
                                            <?php else: ?>
                                                <span class="admin-badge admin-badge-secondary">Nej</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Generate Bracket Button -->
                    <div class="mt-lg pt-lg" style="border-top: 1px solid var(--color-border);">
                        <form method="POST" class="flex items-end gap-md flex-wrap">
                            <input type="hidden" name="action" value="generate_brackets">
                            <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                            <div class="admin-form-group" style="margin-bottom: 0;">
                                <label class="admin-form-label">Bracket-storlek</label>
                                <select name="bracket_size" class="admin-form-select">
                                    <option value="8">8 åkare</option>
                                    <option value="16" selected>16 åkare</option>
                                    <option value="32">32 åkare</option>
                                </select>
                            </div>
                            <button type="submit" class="btn-admin btn-admin-primary">
                                <i data-lucide="git-branch"></i> Generera Bracket
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- BRACKETS TAB -->
    <div class="tab-content" id="brackets">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3>Elimination Bracket</h3>
            </div>
            <div class="admin-card-body">
                <?php if (empty($brackets)): ?>
                    <div class="admin-empty-state">
                        <i data-lucide="git-branch"></i>
                        <h3>Ingen bracket genererad</h3>
                        <p>Lägg först in kvalificeringsresultat och generera sedan bracket.</p>
                    </div>
                <?php else: ?>
                    <?php
                    $roundNames = [
                        'round_of_32' => '32-delsfinal',
                        'round_of_16' => '16-delsfinal',
                        'quarterfinal' => 'Kvartsfinal',
                        'semifinal' => 'Semifinal',
                        'third_place' => 'Brons',
                        'final' => 'Final'
                    ];
                    ?>
                    <?php foreach ($brackets as $roundName => $heats): ?>
                        <div class="bracket-round mb-lg">
                            <h4 class="mb-md"><?= $roundNames[$roundName] ?? ucfirst($roundName) ?></h4>
                            <div class="bracket-heats">
                                <?php foreach ($heats as $heat): ?>
                                    <div class="bracket-heat admin-card mb-sm" style="background: var(--color-bg-secondary);">
                                        <div class="admin-card-body" style="padding: var(--space-sm);">
                                            <div class="flex justify-between items-center mb-sm">
                                                <span class="text-sm text-secondary">Heat <?= $heat['heat_number'] ?></span>
                                                <span class="admin-badge admin-badge-<?= $heat['status'] === 'completed' ? 'success' : ($heat['status'] === 'bye' ? 'warning' : 'secondary') ?>">
                                                    <?= $heat['status'] === 'completed' ? 'Klar' : ($heat['status'] === 'bye' ? 'BYE' : 'Väntar') ?>
                                                </span>
                                            </div>

                                            <?php if ($heat['status'] === 'bye'): ?>
                                                <div class="bracket-matchup">
                                                    <div class="bracket-rider <?= $heat['winner_id'] == $heat['rider_1_id'] ? 'winner' : '' ?>">
                                                        <span class="seed"><?= $heat['rider_1_seed'] ?></span>
                                                        <span class="name"><?= h($heat['rider1_firstname'] . ' ' . $heat['rider1_lastname']) ?></span>
                                                        <span class="time">BYE</span>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <form method="POST" class="bracket-matchup-form">
                                                    <input type="hidden" name="action" value="save_heat">
                                                    <input type="hidden" name="heat_id" value="<?= $heat['id'] ?>">

                                                    <!-- Rider 1 -->
                                                    <div class="bracket-rider <?= $heat['winner_id'] == $heat['rider_1_id'] ? 'winner' : '' ?>">
                                                        <span class="seed"><?= $heat['rider_1_seed'] ?></span>
                                                        <span class="name"><?= h($heat['rider1_firstname'] . ' ' . $heat['rider1_lastname']) ?></span>
                                                        <div class="times">
                                                            <input type="number" step="0.001" name="rider1_run1" value="<?= $heat['rider_1_run1'] ?: '' ?>" placeholder="Åk1" class="time-input">
                                                            <input type="number" step="0.001" name="rider1_run2" value="<?= $heat['rider_1_run2'] ?: '' ?>" placeholder="Åk2" class="time-input">
                                                            <span class="total"><?= $heat['rider_1_total'] ? number_format($heat['rider_1_total'], 3) : '-' ?></span>
                                                        </div>
                                                    </div>

                                                    <div class="vs">vs</div>

                                                    <!-- Rider 2 -->
                                                    <div class="bracket-rider <?= $heat['winner_id'] == $heat['rider_2_id'] ? 'winner' : '' ?>">
                                                        <span class="seed"><?= $heat['rider_2_seed'] ?></span>
                                                        <span class="name"><?= h($heat['rider2_firstname'] . ' ' . $heat['rider2_lastname']) ?></span>
                                                        <div class="times">
                                                            <input type="number" step="0.001" name="rider2_run1" value="<?= $heat['rider_2_run1'] ?: '' ?>" placeholder="Åk1" class="time-input">
                                                            <input type="number" step="0.001" name="rider2_run2" value="<?= $heat['rider_2_run2'] ?: '' ?>" placeholder="Åk2" class="time-input">
                                                            <span class="total"><?= $heat['rider_2_total'] ? number_format($heat['rider_2_total'], 3) : '-' ?></span>
                                                        </div>
                                                    </div>

                                                    <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm mt-sm">
                                                        <i data-lucide="save"></i> Spara
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RESULTS TAB -->
    <div class="tab-content" id="results">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3>Slutresultat</h3>
            </div>
            <div class="admin-card-body">
                <?php if (empty($finalResults)): ?>
                    <div class="admin-empty-state">
                        <i data-lucide="trophy"></i>
                        <h3>Inga slutresultat</h3>
                        <p>Slutresultat genereras automatiskt när alla heats är klara.</p>
                    </div>
                <?php else: ?>
                    <div class="admin-table-container">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Plac</th>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th>Kval</th>
                                    <th>Bracket</th>
                                    <th class="text-right">Poäng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($finalResults as $result): ?>
                                    <tr>
                                        <td>
                                            <?php if ($result['final_position'] <= 3): ?>
                                                <span class="position-badge position-<?= $result['final_position'] ?>"><?= $result['final_position'] ?></span>
                                            <?php else: ?>
                                                <?= $result['final_position'] ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($result['firstname'] . ' ' . $result['lastname']) ?></td>
                                        <td><?= h($result['club_name'] ?? '-') ?></td>
                                        <td><?= $result['qualifying_position'] ?? '-' ?></td>
                                        <td>
                                            <?php if ($result['bracket_type'] === 'consolation'): ?>
                                                <span class="admin-badge admin-badge-warning">B</span>
                                            <?php else: ?>
                                                <span class="admin-badge admin-badge-success">A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right"><?= $result['points'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
/* Bracket styling */
.bracket-heats {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-md);
}

.bracket-rider {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) var(--space-sm);
    background: var(--color-bg);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-xs);
}

.bracket-rider.winner {
    background: var(--color-success);
    color: white;
}

.bracket-rider .seed {
    font-weight: 600;
    min-width: 24px;
    text-align: center;
}

.bracket-rider .name {
    flex: 1;
}

.bracket-rider .times {
    display: flex;
    gap: var(--space-xs);
    align-items: center;
}

.time-input {
    width: 70px;
    padding: var(--space-xs);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
    text-align: right;
}

.bracket-rider .total {
    font-weight: 600;
    min-width: 60px;
    text-align: right;
}

.vs {
    text-align: center;
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    margin: var(--space-xs) 0;
}

.row-highlight {
    background: rgba(97, 206, 112, 0.1);
}

.position-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-weight: 600;
    color: white;
}

.position-1 { background: gold; color: #333; }
.position-2 { background: silver; color: #333; }
.position-3 { background: #cd7f32; }

/* Tabs */
.tabs-nav {
    display: flex;
    gap: var(--space-xs);
    border-bottom: 2px solid var(--color-border);
    margin-bottom: var(--space-lg);
}

.tab-btn {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    border: none;
    background: none;
    cursor: pointer;
    color: var(--color-text-secondary);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}

.tab-btn:hover {
    color: var(--color-text);
}

.tab-btn.active {
    color: var(--color-accent);
    border-bottom-color: var(--color-accent);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}
</style>

<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    });
});
</script>

<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
