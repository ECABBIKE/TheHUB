<?php
/**
 * Force Delete Results - Radera resultat för specifika events
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$pdo = $db->getPdo();

$message = '';
$messageType = '';

// Hämta alla serier
$series = $db->getAll("SELECT id, name, year FROM series ORDER BY year DESC, name");

// Hämta events för vald serie
$selectedSeriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : null;
$events = [];
if ($selectedSeriesId) {
    $events = $db->getAll("
        SELECT e.id, e.name, e.date, e.location,
               (SELECT COUNT(*) FROM results WHERE event_id = e.id) as result_count,
               (SELECT COUNT(*) FROM series_results WHERE event_id = e.id) as series_result_count
        FROM events e
        JOIN series_events se ON e.id = se.event_id
        WHERE se.series_id = ?
        ORDER BY e.date
    ", [$selectedSeriesId]);
}

// Hantera radering
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    if (isset($_POST['delete_event_results'])) {
        $eventId = (int)$_POST['event_id'];

        try {
            $pdo->beginTransaction();

            // Radera series_results först
            $stmt = $pdo->prepare("DELETE FROM series_results WHERE event_id = ?");
            $stmt->execute([$eventId]);
            $seriesDeleted = $stmt->rowCount();

            // Radera results
            $stmt = $pdo->prepare("DELETE FROM results WHERE event_id = ?");
            $stmt->execute([$eventId]);
            $resultsDeleted = $stmt->rowCount();

            $pdo->commit();

            $message = "Raderade {$resultsDeleted} resultat och {$seriesDeleted} serieresultat för event #{$eventId}";
            $messageType = 'success';

            // Uppdatera events-listan
            if ($selectedSeriesId) {
                $events = $db->getAll("
                    SELECT e.id, e.name, e.date, e.location,
                           (SELECT COUNT(*) FROM results WHERE event_id = e.id) as result_count,
                           (SELECT COUNT(*) FROM series_results WHERE event_id = e.id) as series_result_count
                    FROM events e
                    JOIN series_events se ON e.id = se.event_id
                    WHERE se.series_id = ?
                    ORDER BY e.date
                ", [$selectedSeriesId]);
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Fel: " . $e->getMessage();
            $messageType = 'error';
        }
    }

    if (isset($_POST['delete_all_series_results'])) {
        $seriesId = (int)$_POST['series_id'];

        try {
            $pdo->beginTransaction();

            // Hämta alla event-IDs i serien
            $eventIds = $db->getAll("SELECT event_id FROM series_events WHERE series_id = ?", [$seriesId]);

            $totalResults = 0;
            $totalSeriesResults = 0;

            foreach ($eventIds as $e) {
                $eventId = $e['event_id'];

                // Radera series_results
                $stmt = $pdo->prepare("DELETE FROM series_results WHERE event_id = ? AND series_id = ?");
                $stmt->execute([$eventId, $seriesId]);
                $totalSeriesResults += $stmt->rowCount();

                // Radera results
                $stmt = $pdo->prepare("DELETE FROM results WHERE event_id = ?");
                $stmt->execute([$eventId]);
                $totalResults += $stmt->rowCount();
            }

            $pdo->commit();

            $message = "Raderade {$totalResults} resultat och {$totalSeriesResults} serieresultat för hela serien";
            $messageType = 'success';

            // Uppdatera events-listan
            $events = $db->getAll("
                SELECT e.id, e.name, e.date, e.location,
                       (SELECT COUNT(*) FROM results WHERE event_id = e.id) as result_count,
                       (SELECT COUNT(*) FROM series_results WHERE event_id = e.id) as series_result_count
                FROM events e
                JOIN series_events se ON e.id = se.event_id
                WHERE se.series_id = ?
                ORDER BY e.date
            ", [$seriesId]);

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Fel: " . $e->getMessage();
            $messageType = 'error';
        }
    }

    if (isset($_POST['delete_duplicate_riders'])) {
        try {
            // Hitta och radera åkare som ENDAST har resultat från raderade events (inga resultat kvar)
            $stmt = $pdo->query("
                DELETE FROM riders
                WHERE id NOT IN (SELECT DISTINCT cyclist_id FROM results WHERE cyclist_id IS NOT NULL)
                AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $deleted = $stmt->rowCount();

            $message = "Raderade {$deleted} åkare utan resultat (skapade senaste 7 dagarna)";
            $messageType = 'success';

        } catch (Exception $e) {
            $message = "Fel: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$pageTitle = 'Tvångsradera resultat';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1><i data-lucide="trash-2"></i> <?= $pageTitle ?></h1>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> mb-lg">
        <?= h($message) ?>
    </div>
    <?php endif; ?>

    <div class="card mb-lg">
        <div class="card-header">
            <h3>Välj serie</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="flex gap-md items-end">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Serie</label>
                    <select name="series_id" class="form-select">
                        <option value="">-- Välj serie --</option>
                        <?php foreach ($series as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $selectedSeriesId == $s['id'] ? 'selected' : '' ?>>
                            <?= h($s['name']) ?> (<?= $s['year'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Visa events</button>
            </form>
        </div>
    </div>

    <?php if ($selectedSeriesId && !empty($events)): ?>
    <div class="card mb-lg">
        <div class="card-header">
            <h3>Events i serien</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Event</th>
                            <th>Datum</th>
                            <th>Resultat</th>
                            <th>Serieresultat</th>
                            <th>Åtgärd</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= $event['id'] ?></td>
                            <td><strong><?= h($event['name']) ?></strong></td>
                            <td><?= $event['date'] ?></td>
                            <td>
                                <?php if ($event['result_count'] > 0): ?>
                                <span class="badge badge-primary"><?= $event['result_count'] ?></span>
                                <?php else: ?>
                                <span class="badge badge-secondary">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($event['series_result_count'] > 0): ?>
                                <span class="badge badge-warning"><?= $event['series_result_count'] ?></span>
                                <?php else: ?>
                                <span class="badge badge-secondary">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($event['result_count'] > 0 || $event['series_result_count'] > 0): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Radera ALLA resultat för <?= h($event['name']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <button type="submit" name="delete_event_results" class="btn btn-danger btn-sm">
                                        <i data-lucide="trash-2"></i> Radera
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted">Inga resultat</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-lg pt-lg" style="border-top: 1px solid var(--color-border);">
                <form method="POST" onsubmit="return confirm('VARNING! Detta raderar ALLA resultat för ALLA events i serien. Är du säker?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="series_id" value="<?= $selectedSeriesId ?>">
                    <button type="submit" name="delete_all_series_results" class="btn btn-danger btn-lg">
                        <i data-lucide="alert-triangle"></i>
                        Radera ALLA resultat i serien
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Rensa upp åkare</h3>
        </div>
        <div class="card-body">
            <p class="mb-md">Radera åkare som skapats senaste 7 dagarna och som inte har några resultat kvar.</p>
            <form method="POST" onsubmit="return confirm('Radera åkare utan resultat?')">
                <?= csrf_field() ?>
                <button type="submit" name="delete_duplicate_riders" class="btn btn-warning">
                    <i data-lucide="users"></i>
                    Radera åkare utan resultat
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
