<?php
/**
 * Fix DNS Status - Detect and fix riders with empty times or DNS text that should be DNS
 *
 * This tool finds riders marked as 'finished' but with no valid finish time,
 * which indicates they are actually DNS (Did Not Start).
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

$message = '';
$messageType = '';

// Get event filter
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;

// Common condition for detecting DNS riders with wrong status
// Checks: empty times, zero times, or DNS/DNF/DQ text in time fields
$dnsCondition = "
    r.status = 'finished'
    AND (
        -- No valid finish_time
        (r.finish_time IS NULL OR r.finish_time = '' OR r.finish_time = '0:00' OR r.finish_time = '0:00:00' OR r.finish_time = '0:00.00'
         OR UPPER(r.finish_time) IN ('DNS', 'DNF', 'DQ', 'DSQ'))
        -- AND no valid DH run times
        AND (r.run_1_time IS NULL OR r.run_1_time = '' OR r.run_1_time = '0:00' OR r.run_1_time = '0:00:00'
             OR UPPER(r.run_1_time) IN ('DNS', 'DNF', 'DQ', 'DSQ'))
        AND (r.run_2_time IS NULL OR r.run_2_time = '' OR r.run_2_time = '0:00' OR r.run_2_time = '0:00:00'
             OR UPPER(r.run_2_time) IN ('DNS', 'DNF', 'DQ', 'DSQ'))
        -- AND no valid Enduro split times
        AND (r.ss1 IS NULL OR r.ss1 = '' OR r.ss1 = '0:00' OR r.ss1 = '0:00:00'
             OR UPPER(r.ss1) IN ('DNS', 'DNF', 'DQ', 'DSQ'))
    )
";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix'])) {
    checkCsrf();

    $eventFilter = '';
    $params = [];

    if (!empty($_POST['event_id'])) {
        $eventFilter = ' AND r.event_id = ?';
        $params[] = (int)$_POST['event_id'];
    }

    // Count affected rows before fix
    $beforeStmt = $db->prepare("SELECT COUNT(*) as cnt FROM results r WHERE {$dnsCondition} {$eventFilter}");
    $beforeStmt->execute($params);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);

    // Fix status to DNS
    $fixStmt = $db->prepare("
        UPDATE results r
        SET r.status = 'dns', r.position = NULL, r.points = 0
        WHERE {$dnsCondition} {$eventFilter}
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
$countStmt = $db->prepare("SELECT COUNT(*) as cnt FROM results r WHERE {$dnsCondition} {$eventFilter}");
$countStmt->execute($params);
$affected = $countStmt->fetch(PDO::FETCH_ASSOC);

// Get sample of affected results
$sampleStmt = $db->prepare("
    SELECT r.id, r.position, r.status, r.finish_time, r.run_1_time, r.run_2_time, r.ss1,
           CONCAT(ri.firstname, ' ', ri.lastname) as rider_name,
           e.name as event_name, e.date as event_date, e.event_format
    FROM results r
    JOIN riders ri ON r.cyclist_id = ri.id
    JOIN events e ON r.event_id = e.id
    WHERE {$dnsCondition} {$eventFilter}
    ORDER BY e.date DESC
    LIMIT 30
");
$sampleStmt->execute($params);
$samples = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);

// Get events for filter dropdown
$events = $db->getAll("
    SELECT e.id, e.name, e.date,
           (SELECT COUNT(*) FROM results r WHERE r.event_id = e.id AND {$dnsCondition}) as dns_count
    FROM events e
    HAVING dns_count > 0
    ORDER BY e.date DESC
    LIMIT 50
");

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
            Detta verktyg hittar åkare som är markerade som <strong>'finished'</strong> men saknar giltig tid
            (tom tid, nolltid, eller "DNS"/"DNF"/"DQ" som tidvärde). Dessa bör ha <strong>status = 'dns'</strong>.
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
            <strong><?= $affected['cnt'] ?></strong> åkare har status='finished' men saknar giltig tid (bör vara DNS).
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
                        <th>Format</th>
                        <th>Status</th>
                        <th>Plac.</th>
                        <th>Tid/Åk1/SS1</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($samples as $row): ?>
                    <tr>
                        <td><?= h($row['rider_name']) ?></td>
                        <td><?= h($row['event_name']) ?></td>
                        <td><?= date('Y-m-d', strtotime($row['event_date'])) ?></td>
                        <td><span class="badge"><?= h($row['event_format'] ?? 'ENDURO') ?></span></td>
                        <td><span class="badge badge-success"><?= strtoupper($row['status']) ?></span></td>
                        <td class="text-danger"><?= $row['position'] ?: '-' ?></td>
                        <td class="text-secondary">
                            <?php
                            if (!empty($row['run_1_time'])) echo 'Åk1: ' . h($row['run_1_time']);
                            elseif (!empty($row['ss1'])) echo 'SS1: ' . h($row['ss1']);
                            elseif (!empty($row['finish_time'])) echo h($row['finish_time']);
                            else echo '(tom)';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="alert alert--success">
            <i data-lucide="check-circle"></i>
            Inga problem hittades! Alla åkare med status='finished' har en giltig tid.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
