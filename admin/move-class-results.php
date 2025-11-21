<?php
/**
 * Admin tool to move results from one class to another
 * Useful when a class was created incorrectly during import
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Handle move action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_results'])) {
    checkCsrf();

    $fromClassId = (int)$_POST['from_class_id'];
    $toClassId = (int)$_POST['to_class_id'];
    $eventId = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;

    if ($fromClassId && $toClassId && $fromClassId !== $toClassId) {
        try {
            $db->pdo->beginTransaction();

            // Build the update query
            $params = [$toClassId, $fromClassId];
            $whereClause = "class_id = ?";

            if ($eventId) {
                $whereClause .= " AND event_id = ?";
                $params[] = $eventId;
            }

            // Get count before moving
            $countParams = [$fromClassId];
            $countWhere = "class_id = ?";
            if ($eventId) {
                $countWhere .= " AND event_id = ?";
                $countParams[] = $eventId;
            }
            $count = $db->getRow("SELECT COUNT(*) as c FROM results WHERE $countWhere", $countParams)['c'];

            // Move results
            $db->query("UPDATE results SET class_id = ? WHERE $whereClause", $params);

            // Get class names for message
            $fromClass = $db->getRow("SELECT display_name FROM classes WHERE id = ?", [$fromClassId]);
            $toClass = $db->getRow("SELECT display_name FROM classes WHERE id = ?", [$toClassId]);

            $db->pdo->commit();

            $eventText = $eventId ? " (för valt event)" : "";
            $message = "Flyttade $count resultat från " . ($fromClass['display_name'] ?? "Klass $fromClassId") .
                       " till " . ($toClass['display_name'] ?? "Klass $toClassId") . $eventText;
            $messageType = 'success';

        } catch (Exception $e) {
            if ($db->pdo->inTransaction()) {
                $db->pdo->rollBack();
            }
            $message = "Fel vid flytt: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Välj två olika klasser";
        $messageType = 'error';
    }
}

// Handle delete class action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_class'])) {
    checkCsrf();

    $classId = (int)$_POST['class_id'];

    if ($classId) {
        try {
            // Check if class has results
            $resultCount = $db->getRow("SELECT COUNT(*) as c FROM results WHERE class_id = ?", [$classId])['c'];

            if ($resultCount > 0) {
                $message = "Kan inte ta bort klass med $resultCount resultat. Flytta resultaten först.";
                $messageType = 'error';
            } else {
                $className = $db->getRow("SELECT display_name FROM classes WHERE id = ?", [$classId]);
                $db->query("DELETE FROM classes WHERE id = ?", [$classId]);
                $message = "Tog bort klass: " . ($className['display_name'] ?? "ID $classId");
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = "Fel vid borttagning: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get all classes with result counts
$classes = $db->getAll("
    SELECT c.id, c.name, c.display_name, c.sort_order,
           COUNT(r.id) as result_count
    FROM classes c
    LEFT JOIN results r ON c.id = r.class_id
    GROUP BY c.id
    ORDER BY c.sort_order, c.display_name
");

// Get all events for filtering
$events = $db->getAll("
    SELECT id, name, date
    FROM events
    ORDER BY date DESC
    LIMIT 100
");

$pageTitle = 'Flytta klassresultat';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <div class="gs-mb-lg">
            <a href="/admin/system-settings.php?tab=debug" class="gs-btn gs-btn-outline gs-btn-sm">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <h1 class="gs-h1 gs-text-primary gs-mb-lg">
            <i data-lucide="move"></i>
            Flytta klassresultat
        </h1>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Move Results Form -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="arrow-right-left"></i>
                    Flytta resultat mellan klasser
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-md">
                        <div class="gs-form-group">
                            <label class="gs-label">Från klass *</label>
                            <select name="from_class_id" class="gs-input" required>
                                <option value="">Välj klass...</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>">
                                        <?= h($class['display_name']) ?> (<?= $class['result_count'] ?> resultat)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Till klass *</label>
                            <select name="to_class_id" class="gs-input" required>
                                <option value="">Välj klass...</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>">
                                        <?= h($class['display_name']) ?> (<?= $class['result_count'] ?> resultat)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Begränsa till event (valfritt)</label>
                            <select name="event_id" class="gs-input">
                                <option value="">Alla event</option>
                                <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['id'] ?>">
                                        <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="gs-mt-md">
                        <button type="submit" name="move_results" class="gs-btn gs-btn-warning"
                                onclick="return confirm('Flytta alla resultat från vald klass till den nya klassen?');">
                            <i data-lucide="move"></i>
                            Flytta resultat
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Classes List -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="layers"></i>
                    Alla klasser (<?= count($classes) ?>)
                </h2>
            </div>
            <div class="gs-card-content gs-p-0">
                <div class="gs-table-responsive">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Visningsnamn</th>
                                <th>Namn</th>
                                <th>Resultat</th>
                                <th>Sort</th>
                                <th>Åtgärd</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td><?= $class['id'] ?></td>
                                    <td><strong><?= h($class['display_name']) ?></strong></td>
                                    <td><?= h($class['name']) ?></td>
                                    <td>
                                        <span class="gs-badge <?= $class['result_count'] > 0 ? 'gs-badge-primary' : 'gs-badge-secondary' ?>">
                                            <?= $class['result_count'] ?>
                                        </span>
                                    </td>
                                    <td><?= $class['sort_order'] ?></td>
                                    <td>
                                        <?php if ($class['result_count'] == 0): ?>
                                            <form method="POST" style="display: inline;"
                                                  onsubmit="return confirm('Ta bort denna klass?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                <button type="submit" name="delete_class" class="gs-btn gs-btn-sm gs-btn-danger">
                                                    <i data-lucide="trash-2"></i>
                                                    Ta bort
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="gs-text-xs gs-text-secondary">Har resultat</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
