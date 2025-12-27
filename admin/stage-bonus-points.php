<?php
/**
 * Admin tool to add bonus points for stage performance (PS/SS)
 * Award extra points based on stage times
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$message = '';
$messageType = 'info';

// Check for message from redirect
if (isset($_SESSION['stage_bonus_message'])) {
    $message = $_SESSION['stage_bonus_message'];
    $messageType = $_SESSION['stage_bonus_message_type'] ?? 'info';
    unset($_SESSION['stage_bonus_message'], $_SESSION['stage_bonus_message_type']);
}

// Default point scales
$defaultScales = [
    'top3' => ['name' => 'Topp 3', 'points' => [25, 20, 16]],
    'top5' => ['name' => 'Topp 5', 'points' => [25, 20, 16, 13, 11]],
    'top10' => ['name' => 'Topp 10', 'points' => [25, 20, 16, 13, 11, 10, 9, 8, 7, 6]],
    'top3_small' => ['name' => 'Topp 3 (liten)', 'points' => [10, 7, 5]],
    'top5_small' => ['name' => 'Topp 5 (liten)', 'points' => [10, 7, 5, 3, 2]],
];

// Get all events with results and stage times
$events = $db->getAll("
    SELECT e.id, e.name, e.date, e.stage_names,
           COUNT(DISTINCT r.id) as result_count,
           SUM(CASE WHEN r.ss1 IS NOT NULL THEN 1 ELSE 0 END) as has_ss1,
           SUM(CASE WHEN r.ss2 IS NOT NULL THEN 1 ELSE 0 END) as has_ss2,
           SUM(CASE WHEN r.ss3 IS NOT NULL THEN 1 ELSE 0 END) as has_ss3,
           SUM(CASE WHEN r.ss4 IS NOT NULL THEN 1 ELSE 0 END) as has_ss4,
           SUM(CASE WHEN r.ss5 IS NOT NULL THEN 1 ELSE 0 END) as has_ss5
    FROM events e
    INNER JOIN results r ON e.id = r.event_id
    GROUP BY e.id
    HAVING result_count > 0
    ORDER BY e.date DESC
    LIMIT 100
");

// Handle preview action
$previewResults = [];
$previewEventId = null;
$previewClassId = null;
$previewStage = null;
$previewScale = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_bonus'])) {
    $previewEventId = (int)$_POST['event_id'];
    $previewClassId = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
    $previewStage = $_POST['stage'] ?? 'ss1';
    $previewScale = $_POST['point_scale'] ?? 'top3';

    if ($previewEventId && $previewStage) {
        $stageColumn = preg_replace('/[^a-z0-9_]/', '', $previewStage);

        // Build query
        $sql = "
            SELECT r.id as result_id, r.cyclist_id, r.class_id, r.points as current_points,
                   r.{$stageColumn} as stage_time,
                   rd.firstname, rd.lastname,
                   cls.display_name as class_name
            FROM results r
            INNER JOIN riders rd ON r.cyclist_id = rd.id
            LEFT JOIN classes cls ON r.class_id = cls.id
            WHERE r.event_id = ?
              AND r.{$stageColumn} IS NOT NULL
              AND r.{$stageColumn} != ''
              AND r.{$stageColumn} != '0'
        ";
        $params = [$previewEventId];

        if ($previewClassId) {
            $sql .= " AND r.class_id = ?";
            $params[] = $previewClassId;
        }

        $sql .= " ORDER BY cls.sort_order, r.{$stageColumn} ASC";

        $results = $db->getAll($sql, $params);

        // Group by class and rank
        $byClass = [];
        foreach ($results as $result) {
            $classKey = $result['class_id'] ?? 0;
            if (!isset($byClass[$classKey])) {
                $byClass[$classKey] = [
                    'class_name' => $result['class_name'] ?? 'Okänd klass',
                    'riders' => []
                ];
            }
            $byClass[$classKey]['riders'][] = $result;
        }

        // Calculate bonus points
        $pointValues = $defaultScales[$previewScale]['points'] ?? [25, 20, 16];

        foreach ($byClass as $classId => &$classData) {
            $rank = 1;
            foreach ($classData['riders'] as &$rider) {
                $bonusPoints = 0;
                if ($rank <= count($pointValues)) {
                    $bonusPoints = $pointValues[$rank - 1];
                }
                $rider['rank'] = $rank;
                $rider['bonus_points'] = $bonusPoints;
                $rider['new_total'] = ($rider['current_points'] ?? 0) + $bonusPoints;
                $rank++;
            }
        }
        unset($classData);

        $previewResults = $byClass;
    }
}

// Handle apply action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_bonus'])) {
    $eventId = (int)$_POST['event_id'];
    $classId = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
    $stage = $_POST['stage'] ?? 'ss1';
    $scale = $_POST['point_scale'] ?? 'top3';

    if ($eventId && $stage) {
        try {
            $db->pdo->beginTransaction();

            $stageColumn = preg_replace('/[^a-z0-9_]/', '', $stage);
            $pointValues = $defaultScales[$scale]['points'] ?? [25, 20, 16];

            // Get results grouped by class
            $sql = "
                SELECT r.id as result_id, r.class_id, r.{$stageColumn} as stage_time
                FROM results r
                WHERE r.event_id = ?
                  AND r.{$stageColumn} IS NOT NULL
                  AND r.{$stageColumn} != ''
                  AND r.{$stageColumn} != '0'
            ";
            $params = [$eventId];

            if ($classId) {
                $sql .= " AND r.class_id = ?";
                $params[] = $classId;
            }

            $sql .= " ORDER BY r.class_id, r.{$stageColumn} ASC";

            $results = $db->getAll($sql, $params);

            // Group by class
            $byClass = [];
            foreach ($results as $result) {
                $cId = $result['class_id'] ?? 0;
                if (!isset($byClass[$cId])) {
                    $byClass[$cId] = [];
                }
                $byClass[$cId][] = $result;
            }

            // Apply bonus points
            $updated = 0;
            foreach ($byClass as $cId => $classResults) {
                $rank = 1;
                foreach ($classResults as $result) {
                    if ($rank <= count($pointValues)) {
                        $bonusPoints = $pointValues[$rank - 1];
                        $db->query(
                            "UPDATE results SET points = COALESCE(points, 0) + ? WHERE id = ?",
                            [$bonusPoints, $result['result_id']]
                        );
                        $updated++;
                    }
                    $rank++;
                }
            }

            $db->pdo->commit();

            $stageName = strtoupper($stage);
            $scaleName = $defaultScales[$scale]['name'] ?? $scale;
            $_SESSION['stage_bonus_message'] = "Lade till bonuspoäng för {$stageName} ({$scaleName}) - {$updated} deltagare uppdaterade";
            $_SESSION['stage_bonus_message_type'] = 'success';

        } catch (Exception $e) {
            if ($db->pdo->inTransaction()) {
                $db->pdo->rollBack();
            }
            $_SESSION['stage_bonus_message'] = "Fel: " . $e->getMessage();
            $_SESSION['stage_bonus_message_type'] = 'error';
        }

        header('Location: /admin/stage-bonus-points.php');
        exit;
    }
}

// Get classes for the selected event (via AJAX or initial load)
$eventClasses = [];
if ($previewEventId) {
    $eventClasses = $db->getAll("
        SELECT DISTINCT c.id, c.display_name, c.sort_order
        FROM classes c
        INNER JOIN results r ON c.id = r.class_id
        WHERE r.event_id = ?
        ORDER BY c.sort_order
    ", [$previewEventId]);
}

$page_title = 'Sträckbonus';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Sträckbonus']
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> mb-lg">
        <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Info Box -->
<div class="alert alert-info mb-lg">
    <i data-lucide="info"></i>
    <div>
        <strong>Sträckbonus:</strong> Ge extra poäng till de snabbaste på en specifik sträcka (PS/SS).
        Poängen läggs till befintliga poäng.
    </div>
</div>

<!-- Selection Form -->
<div class="card mb-lg">
    <div class="card-header">
        <h2><i data-lucide="trophy"></i> Välj event och sträcka</h2>
    </div>
    <div class="card-body">
        <form method="POST" id="bonus-form">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Event *</label>
                    <select name="event_id" id="event_id" class="form-select" required onchange="loadClasses(this.value)">
                        <option value="">Välj event...</option>
                        <?php foreach ($events as $event): ?>
                            <option value="<?= $event['id'] ?>"
                                    data-stages="<?= htmlspecialchars(json_encode([
                                        'ss1' => $event['has_ss1'] > 0,
                                        'ss2' => $event['has_ss2'] > 0,
                                        'ss3' => $event['has_ss3'] > 0,
                                        'ss4' => $event['has_ss4'] > 0,
                                        'ss5' => $event['has_ss5'] > 0,
                                    ])) ?>"
                                    <?= $previewEventId == $event['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Klass (valfritt)</label>
                    <select name="class_id" id="class_id" class="form-select">
                        <option value="">Alla klasser</option>
                        <?php foreach ($eventClasses as $class): ?>
                            <option value="<?= $class['id'] ?>" <?= $previewClassId == $class['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($class['display_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Sträcka *</label>
                    <select name="stage" id="stage" class="form-select" required>
                        <option value="ss1" <?= $previewStage == 'ss1' ? 'selected' : '' ?>>SS1 / PS1</option>
                        <option value="ss2" <?= $previewStage == 'ss2' ? 'selected' : '' ?>>SS2 / PS2</option>
                        <option value="ss3" <?= $previewStage == 'ss3' ? 'selected' : '' ?>>SS3 / PS3</option>
                        <option value="ss4" <?= $previewStage == 'ss4' ? 'selected' : '' ?>>SS4 / PS4</option>
                        <option value="ss5" <?= $previewStage == 'ss5' ? 'selected' : '' ?>>SS5 / PS5</option>
                        <option value="ss6" <?= $previewStage == 'ss6' ? 'selected' : '' ?>>SS6 / PS6</option>
                        <option value="ss7" <?= $previewStage == 'ss7' ? 'selected' : '' ?>>SS7 / PS7</option>
                        <option value="ss8" <?= $previewStage == 'ss8' ? 'selected' : '' ?>>SS8 / PS8</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Poängskala *</label>
                    <select name="point_scale" id="point_scale" class="form-select" required>
                        <?php foreach ($defaultScales as $key => $scale): ?>
                            <option value="<?= $key ?>" <?= $previewScale == $key ? 'selected' : '' ?>>
                                <?= htmlspecialchars($scale['name']) ?> (<?= implode(', ', $scale['points']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="btn-group mt-md">
                <button type="submit" name="preview_bonus" class="btn btn-secondary">
                    <i data-lucide="eye"></i>
                    Förhandsgranska
                </button>
                <?php if (!empty($previewResults)): ?>
                <button type="submit" name="apply_bonus" class="btn btn-primary"
                        onclick="return confirm('Lägga till bonuspoäng? Poängen adderas till befintliga poäng.');">
                    <i data-lucide="plus-circle"></i>
                    Lägg till bonuspoäng
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Preview Results -->
<?php if (!empty($previewResults)): ?>
<div class="card">
    <div class="card-header">
        <h2><i data-lucide="list"></i> Förhandsgranskning - <?= strtoupper($previewStage) ?> bonus</h2>
    </div>
    <div class="card-body">
        <?php foreach ($previewResults as $classId => $classData): ?>
        <div class="mb-lg">
            <h3 class="text-accent mb-sm"><?= htmlspecialchars($classData['class_name']) ?></h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Rank</th>
                            <th>Namn</th>
                            <th>Sträcktid</th>
                            <th class="text-right">Nuvarande</th>
                            <th class="text-right">Bonus</th>
                            <th class="text-right">Nytt total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classData['riders'] as $rider): ?>
                        <tr class="<?= $rider['bonus_points'] > 0 ? 'highlight-row' : '' ?>">
                            <td>
                                <?php if ($rider['rank'] == 1): ?>
                                    <span class="badge badge-warning">1</span>
                                <?php elseif ($rider['rank'] == 2): ?>
                                    <span class="badge badge-secondary">2</span>
                                <?php elseif ($rider['rank'] == 3): ?>
                                    <span class="badge badge-secondary">3</span>
                                <?php else: ?>
                                    <?= $rider['rank'] ?>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></strong></td>
                            <td><?= htmlspecialchars($rider['stage_time']) ?></td>
                            <td class="text-right"><?= $rider['current_points'] ?? 0 ?></td>
                            <td class="text-right">
                                <?php if ($rider['bonus_points'] > 0): ?>
                                    <span class="text-success">+<?= $rider['bonus_points'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><strong><?= $rider['new_total'] ?></strong></td>
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

<style>
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
}
.highlight-row {
    background: rgba(97, 206, 112, 0.1);
}
.text-success {
    color: var(--color-success);
    font-weight: 600;
}
.btn-group {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
}
</style>

<script>
// Store event classes data
const eventClassesData = {};

// Load classes when event changes
async function loadClasses(eventId) {
    const classSelect = document.getElementById('class_id');
    classSelect.innerHTML = '<option value="">Alla klasser</option>';

    if (!eventId) return;

    try {
        const response = await fetch(`/admin/api/event-classes.php?event_id=${eventId}`);
        const classes = await response.json();

        classes.forEach(cls => {
            const option = document.createElement('option');
            option.value = cls.id;
            option.textContent = cls.display_name;
            classSelect.appendChild(option);
        });
    } catch (e) {
        console.error('Failed to load classes:', e);
    }
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
