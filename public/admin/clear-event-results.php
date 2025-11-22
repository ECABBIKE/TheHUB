<?php
/**
 * Admin tool to clear results for a specific event
 * Useful when import went wrong and rollback isn't available
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_results') {
        $eventId = (int)$_POST['event_id'];

        if (!$eventId) {
            $message = 'Inget event valt';
            $messageType = 'error';
        } else {
            try {
                // Get count before delete
                $count = $db->getRow("SELECT COUNT(*) as cnt FROM results WHERE event_id = ?", [$eventId]);
                $resultCount = $count['cnt'] ?? 0;

                // Delete results
                $db->delete('results', 'event_id = ?', [$eventId]);

                $message = "Raderade {$resultCount} resultat för eventet";
                $messageType = 'success';

            } catch (Exception $e) {
                $message = 'Fel vid radering: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'cleanup_orphans') {
        try {
            // Count before delete
            $beforeCount = $db->getRow("SELECT COUNT(*) as cnt FROM riders WHERE id NOT IN (SELECT DISTINCT cyclist_id FROM results)");

            // Delete riders that have no results
            $db->query("
                DELETE FROM riders
                WHERE id NOT IN (SELECT DISTINCT cyclist_id FROM results)
            ");

            $deletedCount = $beforeCount['cnt'] ?? 0;

            $message = "Raderade {$deletedCount} föräldralösa deltagare (utan resultat)";
            $messageType = 'success';

        } catch (Exception $e) {
            $message = 'Fel vid rensning: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get selected event
$selectedEventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;

// Get all events with result counts
$events = $db->getAll("
    SELECT
        e.id,
        e.name,
        e.date,
        e.location,
        COUNT(r.id) as result_count
    FROM events e
    LEFT JOIN results r ON e.id = r.event_id
    GROUP BY e.id
    ORDER BY e.date DESC
");

// Get results for selected event
$eventResults = [];
$selectedEvent = null;
if ($selectedEventId) {
    $selectedEvent = $db->getRow("SELECT * FROM events WHERE id = ?", [$selectedEventId]);

    if ($selectedEvent) {
        $eventResults = $db->getAll("
            SELECT
                r.*,
                CONCAT(ri.firstname, ' ', ri.lastname) as rider_name,
                ri.license_number,
                c.name as club_name,
                cls.display_name as class_name
            FROM results r
            JOIN riders ri ON r.cyclist_id = ri.id
            LEFT JOIN clubs c ON ri.club_id = c.id
            LEFT JOIN classes cls ON r.class_id = cls.id
            WHERE r.event_id = ?
            ORDER BY cls.sort_order ASC, r.position ASC
        ", [$selectedEventId]);
    }
}

// Get orphan count
$orphanCount = $db->getRow("
    SELECT COUNT(*) as cnt
    FROM riders
    WHERE id NOT IN (SELECT DISTINCT cyclist_id FROM results)
");

$pageTitle = 'Rensa event-resultat';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h1 gs-text-primary gs-mb-lg">
            <i data-lucide="trash-2"></i>
            Rensa event-resultat
        </h1>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Warning -->
        <div class="gs-alert gs-alert-warning gs-mb-lg">
            <i data-lucide="alert-triangle"></i>
            <div>
                <strong>Varning!</strong> Att radera resultat kan inte ångras.
                Se till att du har en backup eller är helt säker innan du fortsätter.
            </div>
        </div>

        <div class="gs-grid gs-grid-cols-1 gs-lg-grid-cols-2 gs-gap-lg">
            <!-- Event Selector -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="calendar"></i>
                        Välj event att rensa
                    </h2>
                </div>
                <div class="gs-card-content">
                    <form method="GET" class="gs-mb-md">
                        <div class="gs-form-group">
                            <label class="gs-label">Event</label>
                            <select name="event_id" class="gs-input" onchange="this.form.submit()">
                                <option value="">-- Välj ett event --</option>
                                <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['id'] ?>" <?= $selectedEventId == $event['id'] ? 'selected' : '' ?>>
                                        <?= h($event['name']) ?>
                                        (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                                        - <?= $event['result_count'] ?> resultat
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <?php if ($selectedEvent && count($eventResults) > 0): ?>
                        <form method="POST" onsubmit="return confirm('VARNING!\n\nDu är på väg att radera <?= count($eventResults) ?> resultat för:\n<?= addslashes($selectedEvent['name']) ?>\n\nDetta kan INTE ångras!\n\nÄr du helt säker?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_results">
                            <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                            <button type="submit" class="gs-btn gs-btn-danger gs-w-full">
                                <i data-lucide="trash-2"></i>
                                Radera alla <?= count($eventResults) ?> resultat
                            </button>
                        </form>
                    <?php elseif ($selectedEvent): ?>
                        <div class="gs-alert gs-alert-info">
                            <i data-lucide="info"></i>
                            Inga resultat att radera för detta event
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cleanup Orphans -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="user-x"></i>
                        Rensa föräldralösa deltagare
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        Radera deltagare som inte har några resultat kopplade till sig.
                        Detta kan vara deltagare som skapades vid import men vars resultat sedan raderades.
                    </p>

                    <div class="gs-stat-card gs-mb-md">
                        <div class="gs-stat-number"><?= $orphanCount['cnt'] ?? 0 ?></div>
                        <div class="gs-stat-label">Deltagare utan resultat</div>
                    </div>

                    <?php if (($orphanCount['cnt'] ?? 0) > 0): ?>
                        <form method="POST" onsubmit="return confirm('Radera <?= $orphanCount['cnt'] ?> deltagare utan resultat?\n\nDetta kan INTE ångras!');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cleanup_orphans">
                            <button type="submit" class="gs-btn gs-btn-warning gs-w-full">
                                <i data-lucide="user-x"></i>
                                Rensa <?= $orphanCount['cnt'] ?> föräldralösa
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="gs-alert gs-alert-success">
                            <i data-lucide="check"></i>
                            Inga föräldralösa deltagare att rensa
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Results Preview -->
        <?php if ($selectedEvent && count($eventResults) > 0): ?>
            <div class="gs-card gs-mt-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="list"></i>
                        Resultat för <?= h($selectedEvent['name']) ?> (<?= count($eventResults) ?>)
                    </h2>
                </div>
                <div class="gs-card-content gs-padding-0">
                    <div class="gs-table-responsive" style="max-height: 400px; overflow: auto;">
                        <table class="gs-table gs-table-sm">
                            <thead style="position: sticky; top: 0; background: var(--gs-white);">
                                <tr>
                                    <th>Plac</th>
                                    <th>Namn</th>
                                    <th>UCI-ID</th>
                                    <th>Klubb</th>
                                    <th>Klass</th>
                                    <th>Tid</th>
                                    <th>Poäng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eventResults as $result): ?>
                                    <tr>
                                        <td><?= $result['position'] ?: '-' ?></td>
                                        <td><strong><?= h($result['rider_name']) ?></strong></td>
                                        <td><code class="gs-text-xs"><?= h($result['license_number']) ?: '-' ?></code></td>
                                        <td><?= h($result['club_name']) ?: '-' ?></td>
                                        <td>
                                            <?php if ($result['class_name']): ?>
                                                <span class="gs-badge gs-badge-sm gs-badge-primary"><?= h($result['class_name']) ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-family: monospace;"><?= h($result['finish_time']) ?: '-' ?></td>
                                        <td><?= (int)$result['points'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="gs-mt-lg">
            <a href="/admin/import-results.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka till import
            </a>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
