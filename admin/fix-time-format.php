<?php
/**
 * Fix Time Format Tool
 * Removes leading "0:" from times that were imported incorrectly
 * e.g., "0:04:17.45" -> "4:17.45"
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/components/admin-header.php';

$message = '';
$messageType = '';

// Get events with potentially bad times
$eventsWithBadTimes = $pdo->query("
    SELECT DISTINCT e.id, e.name, e.date, COUNT(*) as bad_count
    FROM results r
    JOIN events e ON r.event_id = e.id
    WHERE r.finish_time LIKE '0:%'
    GROUP BY e.id
    ORDER BY e.date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Handle fix request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_event'])) {
    $eventId = intval($_POST['fix_event']);

    // Get count before
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE event_id = ? AND finish_time LIKE '0:%'");
    $stmt->execute([$eventId]);
    $countBefore = $stmt->fetchColumn();

    if ($countBefore > 0) {
        // Fix the times - remove leading "0:"
        $stmt = $pdo->prepare("
            UPDATE results
            SET finish_time = SUBSTRING(finish_time, 3)
            WHERE event_id = ?
            AND finish_time LIKE '0:%'
        ");
        $stmt->execute([$eventId]);
        $affected = $stmt->rowCount();

        $message = "Fixade {$affected} tider för event #{$eventId}";
        $messageType = 'success';

        // Refresh the list
        $eventsWithBadTimes = $pdo->query("
            SELECT DISTINCT e.id, e.name, e.date, COUNT(*) as bad_count
            FROM results r
            JOIN events e ON r.event_id = e.id
            WHERE r.finish_time LIKE '0:%'
            GROUP BY e.id
            ORDER BY e.date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $message = "Inga tider att fixa för event #{$eventId}";
        $messageType = 'info';
    }
}

// Handle fix all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_all'])) {
    $stmt = $pdo->prepare("
        UPDATE results
        SET finish_time = SUBSTRING(finish_time, 3)
        WHERE finish_time LIKE '0:%'
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();

    $message = "Fixade {$affected} tider totalt";
    $messageType = 'success';

    $eventsWithBadTimes = [];
}
?>

<div class="admin-page">
    <div class="admin-header">
        <h1>🕐 Fixa tidsformat</h1>
        <p class="text-muted">Tar bort ledande "0:" från felaktigt importerade tider</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Exempel på problemet</h2>
        <p>Tider som <code>0:04:17.45</code> borde vara <code>4:17.45</code></p>
        <p>Detta verktyg tar bort den ledande <code>0:</code> från alla drabbade tider.</p>
    </div>

    <?php if (empty($eventsWithBadTimes)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-state-icon">✅</div>
            <p>Inga event med felaktiga tidsformat hittades!</p>
        </div>
    </div>
    <?php else: ?>

    <div class="card">
        <h2>Event med felaktiga tider</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Event</th>
                    <th>Datum</th>
                    <th>Antal felaktiga</th>
                    <th>Åtgärd</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventsWithBadTimes as $event): ?>
                <tr>
                    <td><?= $event['id'] ?></td>
                    <td><?= htmlspecialchars($event['name']) ?></td>
                    <td><?= $event['date'] ?></td>
                    <td><span class="badge badge-warning"><?= $event['bad_count'] ?></span></td>
                    <td>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="fix_event" value="<?= $event['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-primary">Fixa</button>
                        </form>
                        <a href="/admin/edit-results.php?event_id=<?= $event['id'] ?>" class="btn btn-sm">Visa</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--color-border);">
            <form method="post" onsubmit="return confirm('Fixa ALLA felaktiga tider?');">
                <input type="hidden" name="fix_all" value="1">
                <button type="submit" class="btn btn-warning">⚠️ Fixa alla event</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.card h2 {
    margin-top: 0;
    margin-bottom: var(--space-md);
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    padding: var(--space-sm);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}
.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
}
.badge-warning {
    background: #fef3c7;
    color: #92400e;
}
.btn {
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
    background: var(--color-bg-surface);
    color: inherit;
    cursor: pointer;
    text-decoration: none;
    font-size: var(--text-sm);
}
.btn-primary {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}
.btn-warning {
    background: #f59e0b;
    border-color: #f59e0b;
    color: white;
}
.btn-sm {
    padding: 4px 8px;
    font-size: var(--text-xs);
}
.alert {
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}
.alert-success {
    background: #d1fae5;
    color: #065f46;
}
.alert-info {
    background: #dbeafe;
    color: #1e40af;
}
.empty-state {
    text-align: center;
    padding: var(--space-xl);
}
.empty-state-icon {
    font-size: 3rem;
    margin-bottom: var(--space-md);
}
code {
    background: var(--color-bg-sunken);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    font-family: monospace;
}
</style>

<?php require_once __DIR__ . '/components/admin-footer.php'; ?>
