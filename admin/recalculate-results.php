<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Handle recalculate request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    checkCsrf();

    $eventId = (int)$_POST['event_id'];
    $newScaleId = !empty($_POST['point_scale_id']) ? (int)$_POST['point_scale_id'] : null;

    // Get event info
    $event = $db->getRow("SELECT name FROM events WHERE id = ?", [$eventId]);

    if (!$event) {
        $message = 'Event hittades inte';
        $messageType = 'error';
    } else {
        // Recalculate results
        $stats = recalculateEventResults($db, $eventId, $newScaleId);

        if (!empty($stats['errors'])) {
            $message = "Omräkning klar med fel: {$stats['positions_updated']} placeringar uppdaterade, {$stats['points_updated']} poäng uppdaterade. " . count($stats['errors']) . " fel uppstod.";
            $messageType = 'warning';
        } else {
            $message = "Omräkning klar! {$stats['positions_updated']} placeringar och {$stats['points_updated']} poäng uppdaterade för '{$event['name']}'.";
            $messageType = 'success';
        }
    }

    // Redirect back with message
    $_SESSION['recalc_message'] = $message;
    $_SESSION['recalc_type'] = $messageType;
    header('Location: /admin/results.php');
    exit;
}

// Get event ID from query string
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if (!$eventId) {
    header('Location: /admin/results.php');
    exit;
}

// Get event details
$event = $db->getRow("
    SELECT e.*,
           COUNT(r.id) as result_count,
           ps.name as current_scale_name
    FROM events e
    LEFT JOIN results r ON e.id = r.event_id
    LEFT JOIN point_scales ps ON e.point_scale_id = ps.id
    WHERE e.id = ?
    GROUP BY e.id
", [$eventId]);

if (!$event) {
    header('Location: /admin/results.php');
    exit;
}

// Get all point scales
$pointScales = getPointScales($db, null, true);

$pageTitle = 'Räkna om resultat - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container" style="max-width: 800px; margin: 2rem auto;">
        <div class="gs-card">
            <div class="gs-card-header">
                <div class="gs-flex gs-justify-between gs-items-center">
                    <h1 class="gs-h3 gs-text-primary">
                        <i data-lucide="refresh-cw"></i>
                        Räkna om resultat
                    </h1>
                    <a href="/admin/results.php" class="gs-btn gs-btn-outline gs-btn-sm">
                        <i data-lucide="arrow-left"></i>
                        Tillbaka
                    </a>
                </div>
            </div>

            <div class="gs-card-content">
                <!-- Event Info -->
                <div class="gs-mb-xl" style="padding: 1rem; background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                    <h2 class="gs-h4 gs-mb-sm"><?= h($event['name']) ?></h2>
                    <div class="gs-flex gs-gap-md gs-text-sm gs-text-secondary">
                        <span>
                            <i data-lucide="calendar" style="width: 14px; height: 14px;"></i>
                            <?= date('Y-m-d', strtotime($event['date'])) ?>
                        </span>
                        <span>
                            <i data-lucide="users" style="width: 14px; height: 14px;"></i>
                            <?= $event['result_count'] ?> resultat
                        </span>
                        <?php if ($event['current_scale_name']): ?>
                            <span>
                                <i data-lucide="award" style="width: 14px; height: 14px;"></i>
                                <?= h($event['current_scale_name']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Warning Alert -->
                <div class="gs-alert gs-alert-warning gs-mb-lg">
                    <i data-lucide="alert-triangle"></i>
                    <strong>Varning!</strong> Detta kommer att:
                    <ul style="margin: 0.5rem 0 0 1.5rem;">
                        <li>Räkna om alla placeringar inom varje kategori baserat på tid</li>
                        <li>Räkna om alla poäng baserat på nya placeringar</li>
                        <li>Kan ändra poängmall om du väljer en annan nedan</li>
                    </ul>
                </div>

                <!-- Recalculate Form -->
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">

                    <div class="gs-form-group">
                        <label class="gs-label">
                            <i data-lucide="target"></i>
                            Poängmall (valfritt)
                        </label>
                        <select name="point_scale_id" class="gs-input">
                            <option value="">Behåll nuvarande (<?= h($event['current_scale_name'] ?: 'Ingen') ?>)</option>
                            <?php foreach ($pointScales as $scale): ?>
                                <option value="<?= $scale['id'] ?>" <?= $event['point_scale_id'] == $scale['id'] ? 'selected' : '' ?>>
                                    <?= h($scale['name']) ?>
                                    <?php if ($scale['discipline'] !== 'ALL'): ?>
                                        (<?= h($scale['discipline']) ?>)
                                    <?php endif; ?>
                                    <?php if ($scale['is_default']): ?>
                                        ⭐ Standard
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="gs-text-sm gs-text-secondary">
                            Välj en poängmall om du vill ändra från nuvarande. Lämna tom för att behålla nuvarande mall.
                        </small>
                    </div>

                    <div class="gs-flex gs-justify-end gs-gap-md gs-mt-xl">
                        <a href="/admin/results.php" class="gs-btn gs-btn-outline">
                            <i data-lucide="x"></i>
                            Avbryt
                        </a>
                        <button type="submit" class="gs-btn gs-btn-primary gs-btn-lg"
                                onclick="return confirm('Är du säker på att du vill räkna om alla resultat för detta event?\n\nDetta kan inte ångras.');">
                            <i data-lucide="refresh-cw"></i>
                            Räkna om resultat
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
