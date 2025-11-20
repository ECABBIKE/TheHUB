<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Get series ID
$seriesId = isset($_GET['series_id']) && is_numeric($_GET['series_id']) ? intval($_GET['series_id']) : null;

if (!$seriesId) {
    redirect('/admin/series.php');
}

// Get series info
$series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);

if (!$series) {
    redirect('/admin/series.php');
}

$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_event') {
        $eventId = intval($_POST['event_id']);
        $templateId = !empty($_POST['template_id']) ? intval($_POST['template_id']) : null;

        // Check if already exists
        $existing = $db->getRow(
            "SELECT id FROM series_events WHERE series_id = ? AND event_id = ?",
            [$seriesId, $eventId]
        );

        if ($existing) {
            $message = 'Detta event finns redan i serien';
            $messageType = 'error';
        } else {
            // Get max sort order
            $maxOrder = $db->getRow(
                "SELECT MAX(sort_order) as max_order FROM series_events WHERE series_id = ?",
                [$seriesId]
            );
            $sortOrder = ($maxOrder['max_order'] ?? 0) + 1;

            $db->insert('series_events', [
                'series_id' => $seriesId,
                'event_id' => $eventId,
                'template_id' => $templateId,
                'sort_order' => $sortOrder
            ]);

            $message = 'Event tillagt i serien!';
            $messageType = 'success';
        }
    } elseif ($action === 'update_template') {
        $seriesEventId = intval($_POST['series_event_id']);
        $templateId = !empty($_POST['template_id']) ? intval($_POST['template_id']) : null;

        $db->update('series_events', [
            'template_id' => $templateId
        ], 'id = ? AND series_id = ?', [$seriesEventId, $seriesId]);

        $message = 'Poängmall uppdaterad!';
        $messageType = 'success';
    } elseif ($action === 'remove_event') {
        $seriesEventId = intval($_POST['series_event_id']);

        $db->delete('series_events', 'id = ? AND series_id = ?', [$seriesEventId, $seriesId]);

        $message = 'Event borttaget från serien!';
        $messageType = 'success';
    } elseif ($action === 'update_order') {
        $orders = $_POST['orders'] ?? [];

        foreach ($orders as $seriesEventId => $sortOrder) {
            $db->update('series_events', [
                'sort_order' => intval($sortOrder)
            ], 'id = ? AND series_id = ?', [intval($seriesEventId), $seriesId]);
        }

        $message = 'Ordning uppdaterad!';
        $messageType = 'success';
    }
}

// Get events in this series
$seriesEvents = $db->getAll("
    SELECT se.*, e.name as event_name, e.date as event_date, e.location, e.discipline,
           qpt.name as template_name
    FROM series_events se
    JOIN events e ON se.event_id = e.id
    LEFT JOIN qualification_point_templates qpt ON se.template_id = qpt.id
    WHERE se.series_id = ?
    ORDER BY se.sort_order, e.date
", [$seriesId]);

// Get all events not in this series
$eventsNotInSeries = $db->getAll("
    SELECT e.id, e.name, e.date, e.location, e.discipline
    FROM events e
    WHERE e.id NOT IN (
        SELECT event_id FROM series_events WHERE series_id = ?
    )
    AND e.active = 1
    ORDER BY e.date DESC
", [$seriesId]);

// Get all point templates
$templates = $db->getAll("SELECT id, name FROM qualification_point_templates WHERE active = 1 ORDER BY name");

$pageTitle = 'Hantera Events - ' . $series['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <div>
                <h1 class="gs-h2">
                    <i data-lucide="calendar"></i>
                    <?= h($series['name']) ?>
                </h1>
                <p class="gs-text-secondary">Hantera events och poängmallar</p>
            </div>
            <a href="/admin/series.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="gs-grid gs-grid-cols-1 gs-lg-grid-cols-3 gs-gap-lg">
            <!-- Add Event Card -->
            <div class="gs-card gs-gradient-brand">
                <div class="gs-card-header">
                    <h2 class="gs-h5">
                        <i data-lucide="plus"></i>
                        Lägg till Event
                    </h2>
                </div>
                <div class="gs-card-content">
                    <?php if (empty($eventsNotInSeries)): ?>
                        <p class="gs-text-sm gs-text-secondary">Alla events är redan tillagda i serien.</p>
                    <?php else: ?>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_event">

                            <div class="gs-form-group">
                                <label for="event_id" class="gs-label">Välj event</label>
                                <select name="event_id" id="event_id" class="gs-input" required>
                                    <option value="">-- Välj event --</option>
                                    <?php foreach ($eventsNotInSeries as $event): ?>
                                        <option value="<?= $event['id'] ?>">
                                            <?= h($event['name']) ?>
                                            <?php if ($event['date']): ?>
                                                (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="gs-form-group">
                                <label for="template_id" class="gs-label">Poängmall (valfritt)</label>
                                <select name="template_id" id="template_id" class="gs-input">
                                    <option value="">-- Ingen mall --</option>
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?= $template['id'] ?>">
                                            <?= h($template['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="gs-text-xs gs-text-secondary">
                                    Du kan ändra detta senare
                                </small>
                            </div>

                            <button type="submit" class="gs-btn gs-btn-primary gs-w-full">
                                <i data-lucide="plus"></i>
                                Lägg till
                            </button>
                        </form>

                        <div class="gs-mt-md gs-pt-md gs-border-top">
                            <a href="/admin/point-templates.php" class="gs-text-sm gs-link">
                                <i data-lucide="settings"></i>
                                Hantera poängmallar
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Events List -->
            <div class="gs-lg-col-span-2">
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h5">
                            <i data-lucide="list"></i>
                            Events i serien (<?= count($seriesEvents) ?>)
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (empty($seriesEvents)): ?>
                            <div class="gs-alert gs-alert-warning">
                                <p>Inga events har lagts till i denna serie än.</p>
                            </div>
                        <?php else: ?>
                            <div class="gs-table-responsive">
                                <table class="gs-table">
                                    <thead>
                                        <tr>
                                            <th class="gs-table-col-w-50">Ordning</th>
                                            <th>Event</th>
                                            <th>Datum</th>
                                            <th>Plats</th>
                                            <th>Poängmall</th>
                                            <th class="gs-table-col-w-100">Åtgärder</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($seriesEvents as $se): ?>
                                            <tr>
                                                <td>
                                                    <span class="gs-badge gs-badge-sm"><?= $se['sort_order'] ?></span>
                                                </td>
                                                <td>
                                                    <strong><?= h($se['event_name']) ?></strong>
                                                    <?php if ($se['discipline']): ?>
                                                        <br><span class="gs-text-xs gs-text-secondary"><?= h($se['discipline']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $se['event_date'] ? date('Y-m-d', strtotime($se['event_date'])) : '-' ?></td>
                                                <td><?= h($se['location'] ?? '-') ?></td>
                                                <td>
                                                    <form method="POST" class="gs-flex gs-gap-xs gs-items-center gs-display-inline-block">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="update_template">
                                                        <input type="hidden" name="series_event_id" value="<?= $se['id'] ?>">
                                                        <select name="template_id"
                                                                class="gs-input gs-input-sm gs-input-min-w-150"
                                                                onchange="this.form.submit()">
                                                            <option value="">-- Ingen mall --</option>
                                                            <?php foreach ($templates as $template): ?>
                                                                <option value="<?= $template['id'] ?>"
                                                                    <?= $se['template_id'] == $template['id'] ? 'selected' : '' ?>>
                                                                    <?= h($template['name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </form>
                                                </td>
                                                <td>
                                                    <form method="POST" class="gs-display-inline" onsubmit="return confirm('Är du säker?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="remove_event">
                                                        <input type="hidden" name="series_event_id" value="<?= $se['id'] ?>">
                                                        <button type="submit" class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger">
                                                            <i data-lucide="trash-2"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
