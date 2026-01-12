<?php
/**
 * Promotor Series Settings
 * Manage Swish settings for promotor's series
 * Shows events in each series below
 */
require_once __DIR__ . '/../config.php';
require_admin();

// Only for promotors
if (!hasRole('promotor')) {
    redirect('/admin/dashboard');
}

$db = getDB();
$currentAdmin = getCurrentAdmin();
$userId = $currentAdmin['id'];

$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_series_swish') {
        $seriesId = intval($_POST['series_id'] ?? 0);
        $swishNumber = trim($_POST['swish_number'] ?? '');
        $swishName = trim($_POST['swish_name'] ?? '');

        // Verify promotor owns this series
        $owns = $db->getRow("SELECT 1 FROM promotor_series WHERE user_id = ? AND series_id = ?", [$userId, $seriesId]);

        if ($owns && $seriesId) {
            // Check if payment_configs entry exists for this series
            $existing = $db->getRow("SELECT id FROM payment_configs WHERE series_id = ?", [$seriesId]);

            if ($existing) {
                $db->update('payment_configs', [
                    'swish_enabled' => 1,
                    'swish_number' => $swishNumber ?: null,
                    'swish_name' => $swishName ?: null
                ], 'id = ?', [$existing['id']]);
            } else {
                $db->insert('payment_configs', [
                    'series_id' => $seriesId,
                    'swish_enabled' => 1,
                    'swish_number' => $swishNumber ?: null,
                    'swish_name' => $swishName ?: null
                ]);
            }

            $message = 'Swish-inställningar sparade!';
            $messageType = 'success';
        } else {
            $message = 'Du har inte behörighet att ändra denna serie.';
            $messageType = 'danger';
        }
    }
}

// Get promotor's series with their events
// Include series from both:
// 1. promotor_series (direct series assignment - can edit Swish)
// 2. promotor_events (via assigned events - read-only view)
$series = $db->getAll("
    SELECT DISTINCT s.id, s.name, s.year, s.logo,
           pc.swish_number, pc.swish_name, pc.swish_enabled,
           CASE WHEN ps.user_id IS NOT NULL THEN 1 ELSE 0 END as can_edit_swish
    FROM series s
    LEFT JOIN promotor_series ps ON ps.series_id = s.id AND ps.user_id = ?
    LEFT JOIN payment_configs pc ON pc.series_id = s.id
    LEFT JOIN events e ON e.series_id = s.id
    LEFT JOIN promotor_events pe ON pe.event_id = e.id AND pe.user_id = ?
    WHERE ps.user_id IS NOT NULL OR pe.user_id IS NOT NULL
    ORDER BY s.year DESC, s.name
", [$userId, $userId]);

// Get events for each series - ONLY events the promotor has access to
$seriesEvents = [];
foreach ($series as $s) {
    if ($s['can_edit_swish']) {
        // Has series-level access - show all events in series
        $seriesEvents[$s['id']] = $db->getAll("
            SELECT e.id, e.name, e.date, e.location
            FROM events e
            WHERE e.series_id = ?
            ORDER BY e.date DESC
        ", [$s['id']]);
    } else {
        // Only event-level access - show only assigned events
        $seriesEvents[$s['id']] = $db->getAll("
            SELECT e.id, e.name, e.date, e.location
            FROM events e
            JOIN promotor_events pe ON pe.event_id = e.id AND pe.user_id = ?
            WHERE e.series_id = ?
            ORDER BY e.date DESC
        ", [$userId, $s['id']]);
    }
}

$page_title = 'Serie-inställningar';
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= h($messageType) ?> mb-lg">
    <?= h($message) ?>
</div>
<?php endif; ?>

<?php if (empty($series)): ?>
<div class="card">
    <div class="card-body">
        <p class="text-muted">Du har inga tilldelade serier.</p>
    </div>
</div>
<?php else: ?>

<?php foreach ($series as $s): ?>
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <?php if ($s['logo']): ?>
            <img src="<?= h($s['logo']) ?>" alt="" style="height: 24px; margin-right: var(--space-sm); vertical-align: middle;">
            <?php endif; ?>
            <?= h($s['name']) ?>
        </h2>
    </div>
    <div class="card-body">
        <!-- Swish Settings -->
        <?php if ($s['can_edit_swish']): ?>
        <form method="POST" class="mb-lg">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_series_swish">
            <input type="hidden" name="series_id" value="<?= $s['id'] ?>">

            <h3 class="mb-md" style="font-size: 1rem; color: var(--color-text-secondary);">
                <i data-lucide="smartphone" style="width: 18px; height: 18px; vertical-align: middle;"></i>
                Swish-inställningar
            </h3>

            <div class="grid grid-2 gap-md">
                <div class="form-group">
                    <label class="form-label">Swish-nummer</label>
                    <input type="text" name="swish_number" class="form-input"
                           value="<?= h($s['swish_number'] ?? '') ?>"
                           placeholder="073-123 45 67">
                </div>
                <div class="form-group">
                    <label class="form-label">Visningsnamn</label>
                    <input type="text" name="swish_name" class="form-input"
                           value="<?= h($s['swish_name'] ?? '') ?>"
                           placeholder="Seriens namn">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i data-lucide="save"></i>
                Spara Swish
            </button>

            <?php if ($s['swish_number']): ?>
            <span class="badge badge-success ml-md">Swish aktivt</span>
            <?php endif; ?>
        </form>
        <?php else: ?>
        <div class="mb-lg">
            <p class="text-muted" style="font-size: 0.9rem;">
                <i data-lucide="info" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                Du har tillgång via tilldelade tävlingar. Kontakta admin för att få serie-behörighet om du behöver ändra Swish-inställningar.
            </p>
            <?php if ($s['swish_number']): ?>
            <p style="margin-top: var(--space-sm);">
                <strong>Swish:</strong> <?= h($s['swish_number']) ?>
                <?php if ($s['swish_name']): ?> (<?= h($s['swish_name']) ?>)<?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Events in this series -->
        <?php if (!empty($seriesEvents[$s['id']])): ?>
        <h3 class="mb-md" style="font-size: 1rem; color: var(--color-text-secondary);">
            <i data-lucide="calendar" style="width: 18px; height: 18px; vertical-align: middle;"></i>
            Tävlingar i serien
        </h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tävling</th>
                        <th>Datum</th>
                        <th>Plats</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($seriesEvents[$s['id']] as $event): ?>
                    <tr>
                        <td><?= h($event['name']) ?></td>
                        <td><?= date('Y-m-d', strtotime($event['date'])) ?></td>
                        <td><?= h($event['location'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted">Inga tävlingar i denna serie ännu.</p>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<style>
.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
}
@media (max-width: 767px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}
.gap-md {
    gap: var(--space-md);
}
.ml-md {
    margin-left: var(--space-md);
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
