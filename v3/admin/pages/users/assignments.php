<?php
/**
 * TheHUB V3.5 Admin - Promotor Assignments
 * Manage which events/series a promotor can access
 */

// Require Super Admin
hub_require_role(ROLE_SUPER_ADMIN);

$userId = $route['params']['id'] ?? 0;
$pdo = hub_db();
$success = '';
$error = '';

// Fetch user
$stmt = $pdo->prepare("SELECT * FROM riders WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ' . admin_url('users') . '?error=not_found');
    exit;
}

if ($user['role_id'] != ROLE_PROMOTOR) {
    header('Location: ' . admin_url('users') . '?error=not_promotor');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_event') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId) {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO promotor_events (rider_id, event_id, assigned_by)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $eventId, $_SESSION['hub_user_id']]);
            $success = 'Event tillagt!';
        }
    }

    if ($action === 'add_series') {
        $seriesId = (int) ($_POST['series_id'] ?? 0);
        if ($seriesId) {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO promotor_series (rider_id, series_id, assigned_by)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $seriesId, $_SESSION['hub_user_id']]);
            $success = 'Serie tillagd!';
        }
    }

    if ($action === 'remove_event') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId) {
            $stmt = $pdo->prepare("DELETE FROM promotor_events WHERE rider_id = ? AND event_id = ?");
            $stmt->execute([$userId, $eventId]);
            $success = 'Event borttaget!';
        }
    }

    if ($action === 'remove_series') {
        $seriesId = (int) ($_POST['series_id'] ?? 0);
        if ($seriesId) {
            $stmt = $pdo->prepare("DELETE FROM promotor_series WHERE rider_id = ? AND series_id = ?");
            $stmt->execute([$userId, $seriesId]);
            $success = 'Serie borttagen!';
        }
    }
}

// Fetch assigned events
$stmt = $pdo->prepare("
    SELECT e.*, s.name as series_name, pe.assigned_at
    FROM promotor_events pe
    JOIN events e ON pe.event_id = e.id
    LEFT JOIN series s ON e.series_id = s.id
    WHERE pe.rider_id = ?
    ORDER BY e.date DESC
");
$stmt->execute([$userId]);
$assignedEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch assigned series
$stmt = $pdo->prepare("
    SELECT s.*, ps.assigned_at, COUNT(e.id) as event_count
    FROM promotor_series ps
    JOIN series s ON ps.series_id = s.id
    LEFT JOIN events e ON e.series_id = s.id
    WHERE ps.rider_id = ?
    GROUP BY s.id
    ORDER BY s.name
");
$stmt->execute([$userId]);
$assignedSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all events for dropdown (last 2 years)
$allEvents = $pdo->query("
    SELECT e.id, e.name, e.date, s.name as series_name
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    WHERE e.date >= DATE_SUB(NOW(), INTERVAL 2 YEAR)
    ORDER BY e.date DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all series for dropdown
$allSeries = $pdo->query("SELECT id, name FROM series ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get assigned IDs for filtering
$assignedEventIds = array_column($assignedEvents, 'id');
$assignedSeriesIds = array_column($assignedSeries, 'id');
?>

<div class="admin-page-header">
    <div>
        <a href="<?= admin_url('users') ?>" class="btn btn--ghost btn--sm mb-sm">
            <?= hub_icon('arrow-left', 'icon-sm') ?> Tillbaka
        </a>
        <h1><?= hub_icon('settings', 'icon-lg') ?> Tilldelningar</h1>
        <p class="text-secondary">
            <?= htmlspecialchars($user['firstname'] . ' ' . $user['lastname']) ?>
            &bull; <?= htmlspecialchars($user['email']) ?>
            <span class="badge badge--role-<?= $user['role_id'] ?> ml-sm"><?= hub_get_role_name($user['role_id']) ?></span>
        </p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert--success mb-lg">
    <?= hub_icon('check-circle', 'icon-sm') ?>
    <?= $success ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert--error mb-lg">
    <?= hub_icon('alert-circle', 'icon-sm') ?>
    <?= $error ?>
</div>
<?php endif; ?>

<div class="admin-grid admin-grid--2">

    <!-- Assigned Series -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><?= hub_icon('trophy', 'icon-sm') ?> Tilldelade Serier</h2>
            <span class="badge"><?= count($assignedSeries) ?></span>
        </div>

        <?php if ($assignedSeries): ?>
        <ul class="assignment-list">
            <?php foreach ($assignedSeries as $series): ?>
            <li class="assignment-item">
                <div class="assignment-info">
                    <strong><?= htmlspecialchars($series['name']) ?></strong>
                    <span class="text-secondary text-sm"><?= $series['event_count'] ?> events</span>
                </div>
                <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="remove_series">
                    <input type="hidden" name="series_id" value="<?= $series['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm text-error"
                            onclick="return confirm('Ta bort tilldelning for denna serie?')">
                        <?= hub_icon('x', 'icon-sm') ?>
                    </button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <p class="text-secondary text-center py-lg">Inga serier tilldelade.</p>
        <?php endif; ?>

        <div class="admin-card-footer">
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="add_series">
                <select name="series_id" class="form-select form-select--sm" required>
                    <option value="">Lagg till serie...</option>
                    <?php foreach ($allSeries as $s): ?>
                        <?php if (!in_array($s['id'], $assignedSeriesIds)): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn--primary btn--sm">
                    <?= hub_icon('plus', 'icon-sm') ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Assigned Events -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><?= hub_icon('calendar', 'icon-sm') ?> Tilldelade Events</h2>
            <span class="badge"><?= count($assignedEvents) ?></span>
        </div>

        <?php if ($assignedEvents): ?>
        <ul class="assignment-list assignment-list--scrollable">
            <?php foreach ($assignedEvents as $event): ?>
            <li class="assignment-item">
                <div class="assignment-info">
                    <strong><?= htmlspecialchars($event['name']) ?></strong>
                    <span class="text-secondary text-sm">
                        <?= date('Y-m-d', strtotime($event['date'])) ?>
                        <?php if ($event['series_name']): ?>
                            &bull; <?= htmlspecialchars($event['series_name']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="remove_event">
                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm text-error"
                            onclick="return confirm('Ta bort tilldelning for detta event?')">
                        <?= hub_icon('x', 'icon-sm') ?>
                    </button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <p class="text-secondary text-center py-lg">Inga enskilda events tilldelade.</p>
        <?php endif; ?>

        <div class="admin-card-footer">
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="add_event">
                <select name="event_id" class="form-select form-select--sm" required>
                    <option value="">Lagg till event...</option>
                    <?php foreach ($allEvents as $e): ?>
                        <?php if (!in_array($e['id'], $assignedEventIds)): ?>
                        <option value="<?= $e['id'] ?>">
                            <?= date('Y-m-d', strtotime($e['date'])) ?> - <?= htmlspecialchars($e['name']) ?>
                            <?php if ($e['series_name']): ?>(<?= htmlspecialchars($e['series_name']) ?>)<?php endif; ?>
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn--primary btn--sm">
                    <?= hub_icon('plus', 'icon-sm') ?>
                </button>
            </form>
        </div>
    </div>

</div>

<div class="alert alert--info mt-lg">
    <strong><?= hub_icon('info', 'icon-sm') ?> Tips:</strong>
    Nar du tilldelar en <strong>serie</strong> far promotorn automatiskt tillgang till alla events i den serien.
    Anvand enskilda event-tilldelningar endast for events som inte tillhor en serie.
</div>
