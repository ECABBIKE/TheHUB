<?php
/**
 * Fix DNS Status - Detect and fix riders with empty times that should be DNS
 *
 * This tool finds riders marked as 'finished' but with no finish time,
 * which indicates they are actually DNS (Did Not Start).
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

$message = '';
$messageType = '';

// Get event filter
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix'])) {
    checkCsrf();

    $eventFilter = '';
    $params = [];

    if (!empty($_POST['event_id'])) {
        $eventFilter = ' AND r.event_id = ?';
        $params[] = (int)$_POST['event_id'];
    }

    // Count affected rows before fix
    $beforeStmt = $db->prepare("
        SELECT COUNT(*) as cnt
        FROM results r
        WHERE r.status = 'finished'
        AND (r.finish_time IS NULL OR r.finish_time = '' OR r.finish_time = '0:00' OR r.finish_time = '0:00:00' OR r.finish_time = '0:00.00')
        AND (r.run_1_time IS NULL OR r.run_1_time = '' OR r.run_1_time = '0:00' OR r.run_1_time = '0:00:00')
        AND (r.ss1 IS NULL OR r.ss1 = '' OR r.ss1 = '0:00' OR r.ss1 = '0:00:00')
        {$eventFilter}
    ");
    $beforeStmt->execute($params);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);

    // Fix status to DNS
    $fixStmt = $db->prepare("
        UPDATE results r
        SET r.status = 'dns', r.position = NULL, r.points = 0
        WHERE r.status = 'finished'
        AND (r.finish_time IS NULL OR r.finish_time = '' OR r.finish_time = '0:00' OR r.finish_time = '0:00:00' OR r.finish_time = '0:00.00')
        AND (r.run_1_time IS NULL OR r.run_1_time = '' OR r.run_1_time = '0:00' OR r.run_1_time = '0:00:00')
        AND (r.ss1 IS NULL OR r.ss1 = '' OR r.ss1 = '0:00' OR r.ss1 = '0:00:00')
        {$eventFilter}
    ");
    $fixStmt->execute($params);

    $fixed = $before['cnt'];
    $message = "Fixat! {$fixed} åkare ändrade från 'finished' till 'dns' (position och poäng nollställda).";
    $messageType = 'success';
}

// Build query for affected results
$eventFilter = '';
$params = [];
if ($eventId) {
    $eventFilter = ' AND r.event_id = ?';
    $params[] = $eventId;
}

// Count current affected rows
$countStmt = $db->prepare("
    SELECT COUNT(*) as cnt
    FROM results r
    WHERE r.status = 'finished'
    AND (r.finish_time IS NULL OR r.finish_time = '' OR r.finish_time = '0:00' OR r.finish_time = '0:00:00' OR r.finish_time = '0:00.00')
    AND (r.run_1_time IS NULL OR r.run_1_time = '' OR r.run_1_time = '0:00' OR r.run_1_time = '0:00:00')
    AND (r.ss1 IS NULL OR r.ss1 = '' OR r.ss1 = '0:00' OR r.ss1 = '0:00:00')
    {$eventFilter}
");
$countStmt->execute($params);
$affected = $countStmt->fetch(PDO::FETCH_ASSOC);

// Get sample of affected results
$sampleStmt = $db->prepare("
    SELECT r.id, r.position, r.status, r.finish_time, r.run_1_time, r.ss1,
           CONCAT(ri.firstname, ' ', ri.lastname) as rider_name,
           e.name as event_name, e.date as event_date
    FROM results r
    JOIN riders ri ON r.cyclist_id = ri.id
    JOIN events e ON r.event_id = e.id
    WHERE r.status = 'finished'
    AND (r.finish_time IS NULL OR r.finish_time = '' OR r.finish_time = '0:00' OR r.finish_time = '0:00:00' OR r.finish_time = '0:00.00')
    AND (r.run_1_time IS NULL OR r.run_1_time = '' OR r.run_1_time = '0:00' OR r.run_1_time = '0:00:00')
    AND (r.ss1 IS NULL OR r.ss1 = '' OR r.ss1 = '0:00' OR r.ss1 = '0:00:00')
    {$eventFilter}
    ORDER BY e.date DESC
    LIMIT 30
");
$sampleStmt->execute($params);
$samples = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);

// Get events for filter dropdown
$events = $db->query("
    SELECT e.id, e.name, e.date,
           (SELECT COUNT(*) FROM results r
            WHERE r.event_id = e.id
            AND r.status = 'finished'
            AND (r.finish_time IS NULL OR r.finish_time = '' OR r.finish_time = '0:00' OR r.finish_time = '0:00:00')
            AND (r.run_1_time IS NULL OR r.run_1_time = '' OR r.run_1_time = '0:00' OR r.run_1_time = '0:00:00')
            AND (r.ss1 IS NULL OR r.ss1 = '' OR r.ss1 = '0:00' OR r.ss1 = '0:00:00')
           ) as dns_count
    FROM events e
    HAVING dns_count > 0
    ORDER BY e.date DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Fixa DNS-status';
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin'],
    ['label' => 'Fixa DNS-status']
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="text-primary">
            <i data-lucide="user-x"></i>
            Hitta och fixa DNS-åkare
        </h2>
    </div>
    <div class="card-body">
        <p class="mb-lg">
            Detta verktyg hittar åkare som är markerade som <strong>'finished'</strong> men saknar tid
            (finish_time, run_1_time och ss1 är tomma). Dessa bör vara <strong>DNS</strong> (Did Not Start).
        </p>

        <!-- Event filter -->
        <form method="GET" class="mb-lg">
            <div class="form-group">
                <label class="form-label">Filtrera på event</label>
                <select name="event_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Alla events --</option>
                    <?php foreach ($events as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= $eventId == $ev['id'] ? 'selected' : '' ?>>
                        <?= h($ev['name']) ?> (<?= date('Y-m-d', strtotime($ev['date'])) ?>) - <?= $ev['dns_count'] ?> st
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <div class="alert alert--info mb-lg">
            <i data-lucide="info"></i>
            <strong><?= $affected['cnt'] ?></strong> åkare har status='finished' men saknar tid (bör vara DNS).
        </div>

        <?php if ($affected['cnt'] > 0): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <?php if ($eventId): ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <?php endif; ?>
            <button type="submit" name="fix" value="1" class="btn btn--primary btn-lg">
                <i data-lucide="wrench"></i>
                Fixa <?= $affected['cnt'] ?> åkare (sätt status=dns)
            </button>
        </form>

        <?php if (!empty($samples)): ?>
        <h3 class="mt-xl mb-md">Exempel på berörda resultat:</h3>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Åkare</th>
                        <th>Event</th>
                        <th>Datum</th>
                        <th>Status</th>
                        <th>Placering</th>
                        <th>Tid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($samples as $row): ?>
                    <tr>
                        <td><?= h($row['rider_name']) ?></td>
                        <td><?= h($row['event_name']) ?></td>
                        <td><?= date('Y-m-d', strtotime($row['event_date'])) ?></td>
                        <td><span class="badge badge-success"><?= strtoupper($row['status']) ?></span></td>
                        <td><span class="text-danger"><?= $row['position'] ?: '-' ?></span></td>
                        <td class="text-secondary"><?= $row['finish_time'] ?: '(tom)' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="alert alert--success">
            <i data-lucide="check-circle"></i>
            Inga problem hittades! Alla åkare med status='finished' har en tid.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
