<?php
/**
 * Manage Promotor Event Assignments
 * Only accessible by super_admin
 */
require_once __DIR__ . '/../config.php';
require_admin();

// Only super_admin can access this page
if (!hasRole('super_admin')) {
    http_response_code(403);
    die('Access denied: Only super administrators can manage user events.');
}

$db = getDB();

// Get user ID
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? intval($_GET['id']) : null;

if (!$id) {
    header('Location: /admin/users.php');
    exit;
}

// Fetch user
$user = $db->getRow("SELECT * FROM admin_users WHERE id = ?", [$id]);

if (!$user || $user['role'] !== 'promotor') {
    header('Location: /admin/users.php');
    exit;
}

$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $eventId = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $canEdit = isset($_POST['can_edit']) ? 1 : 0;
        $canResults = isset($_POST['can_manage_results']) ? 1 : 0;
        $canRegistrations = isset($_POST['can_manage_registrations']) ? 1 : 0;

        if ($eventId) {
            try {
                // Check if already exists
                $existing = $db->getRow(
                    "SELECT id FROM promotor_events WHERE user_id = ? AND event_id = ?",
                    [$id, $eventId]
                );

                $currentAdmin = getCurrentAdmin();

                if ($existing) {
                    // Update existing
                    $db->update('promotor_events', [
                        'can_edit' => $canEdit,
                        'can_manage_results' => $canResults,
                        'can_manage_registrations' => $canRegistrations,
                        'granted_by' => $currentAdmin['id']
                    ], 'id = ?', [$existing['id']]);
                    $message = 'Event-behörighet uppdaterad!';
                } else {
                    // Insert new
                    $db->insert('promotor_events', [
                        'user_id' => $id,
                        'event_id' => $eventId,
                        'can_edit' => $canEdit,
                        'can_manage_results' => $canResults,
                        'can_manage_registrations' => $canRegistrations,
                        'granted_by' => $currentAdmin['id']
                    ]);
                    $message = 'Event tillagt!';
                }
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'remove') {
        $eventId = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        if ($eventId) {
            try {
                $db->delete('promotor_events', 'user_id = ? AND event_id = ?', [$id, $eventId]);
                $message = 'Event borttaget!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get user's assigned events
$assignedEvents = $db->getAll("
    SELECT pe.*, e.name as event_name, e.date as event_date, e.location,
           s.name as series_name,
           au.full_name as granted_by_name
    FROM promotor_events pe
    JOIN events e ON pe.event_id = e.id
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN admin_users au ON pe.granted_by = au.id
    WHERE pe.user_id = ?
    ORDER BY e.date DESC
", [$id]);

$assignedEventIds = array_column($assignedEvents, 'event_id');

// Get available events (not already assigned)
$availableEvents = $db->getAll("
    SELECT e.id, e.name, e.date, e.location, s.name as series_name
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    WHERE e.id NOT IN (SELECT event_id FROM promotor_events WHERE user_id = ?)
    ORDER BY e.date DESC
    LIMIT 100
", [$id]);

$pageTitle = 'Hantera Promotor Events';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <div>
                <h1 class="gs-h2">
                    <i data-lucide="calendar-check"></i>
                    Event-tilldelning
                </h1>
                <p class="gs-text-secondary">
                    Hantera events för <strong><?= h($user['full_name'] ?: $user['username']) ?></strong>
                </p>
            </div>
            <a href="/admin/user-edit.php?id=<?= $id ?>" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <!-- Message -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="gs-grid gs-grid-cols-1 gs-lg-grid-cols-2 gs-gap-lg">
            <!-- Assigned Events -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4">
                        <i data-lucide="check-circle"></i>
                        Tilldelade events (<?= count($assignedEvents) ?>)
                    </h2>
                </div>
                <div class="gs-card-content gs-p-0">
                    <?php if (empty($assignedEvents)): ?>
                        <div class="gs-text-center gs-text-secondary gs-py-xl">
                            <i data-lucide="calendar-x" class="gs-icon-xl gs-mb-md"></i>
                            <p>Inga events tilldelade</p>
                        </div>
                    <?php else: ?>
                        <div class="gs-table-container">
                            <table class="gs-table">
                                <thead>
                                    <tr>
                                        <th>Event</th>
                                        <th>Behörigheter</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignedEvents as $event): ?>
                                        <tr>
                                            <td>
                                                <div class="gs-font-medium"><?= h($event['event_name']) ?></div>
                                                <div class="gs-text-xs gs-text-secondary">
                                                    <?= date('Y-m-d', strtotime($event['event_date'])) ?>
                                                    <?php if ($event['series_name']): ?>
                                                        • <?= h($event['series_name']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="gs-flex gs-gap-xs gs-flex-wrap">
                                                    <?php if ($event['can_edit']): ?>
                                                        <span class="gs-badge gs-badge-sm gs-badge-primary">Redigera</span>
                                                    <?php endif; ?>
                                                    <?php if ($event['can_manage_results']): ?>
                                                        <span class="gs-badge gs-badge-sm gs-badge-success">Resultat</span>
                                                    <?php endif; ?>
                                                    <?php if ($event['can_manage_registrations']): ?>
                                                        <span class="gs-badge gs-badge-sm gs-badge-warning">Anmälningar</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="gs-text-right">
                                                <form method="POST" style="display: inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="remove">
                                                    <input type="hidden" name="event_id" value="<?= $event['event_id'] ?>">
                                                    <button type="submit" class="gs-btn gs-btn-sm gs-btn-error" onclick="return confirm('Ta bort detta event?')">
                                                        <i data-lucide="x"></i>
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

            <!-- Add Event -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4">
                        <i data-lucide="plus-circle"></i>
                        Lägg till event
                    </h2>
                </div>
                <div class="gs-card-content">
                    <?php if (empty($availableEvents)): ?>
                        <div class="gs-text-center gs-text-secondary gs-py-lg">
                            <i data-lucide="check" class="gs-icon-xl gs-mb-md"></i>
                            <p>Alla events är redan tilldelade</p>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add">

                            <div class="gs-mb-md">
                                <label for="event_id" class="gs-label">Välj event</label>
                                <select id="event_id" name="event_id" class="gs-input" required>
                                    <option value="">-- Välj event --</option>
                                    <?php foreach ($availableEvents as $event): ?>
                                        <option value="<?= $event['id'] ?>">
                                            <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                                            <?= $event['series_name'] ? ' - ' . h($event['series_name']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="gs-mb-md">
                                <label class="gs-label">Behörigheter</label>
                                <div class="gs-flex gs-flex-col gs-gap-sm">
                                    <label class="gs-checkbox gs-flex gs-items-center gs-gap-sm">
                                        <input type="checkbox" name="can_edit" value="1" checked>
                                        <span>Kan redigera eventinformation</span>
                                    </label>
                                    <label class="gs-checkbox gs-flex gs-items-center gs-gap-sm">
                                        <input type="checkbox" name="can_manage_results" value="1" checked>
                                        <span>Kan hantera resultat</span>
                                    </label>
                                    <label class="gs-checkbox gs-flex gs-items-center gs-gap-sm">
                                        <input type="checkbox" name="can_manage_registrations" value="1" checked>
                                        <span>Kan hantera anmälningar</span>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="gs-btn gs-btn-primary gs-w-full">
                                <i data-lucide="plus"></i>
                                Lägg till event
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Help Text -->
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-content">
                <h3 class="gs-font-medium gs-mb-sm">Om behörigheter</h3>
                <ul class="gs-text-sm gs-text-secondary" style="list-style: disc; padding-left: 1.5rem;">
                    <li><strong>Redigera</strong> - Kan ändra eventinformation (namn, datum, plats, etc.)</li>
                    <li><strong>Resultat</strong> - Kan importera och redigera resultat</li>
                    <li><strong>Anmälningar</strong> - Kan hantera anmälningar och deltagare</li>
                </ul>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
