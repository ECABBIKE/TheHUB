<?php
/**
 * Admin Payment Recipients - Manage Swish payment accounts
 * Central management of payment recipients for series and events
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Initialize message variables
$message = '';
$messageType = 'info';

// Check if payment_recipients table exists
$tableExists = false;
try {
    $check = $db->getAll("SHOW TABLES LIKE 'payment_recipients'");
    $tableExists = !empty($check);
} catch (Exception $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $page_title = 'Betalningsmottagare';
    $page_group = 'economy';
    include __DIR__ . '/components/unified-layout.php';
    ?>
    <div class="alert alert-warning">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Tabellen <code>payment_recipients</code> finns inte. Kör migrationen först.
    </div>
    <div class="admin-card">
        <div class="admin-card-body">
            <p>Kör följande migration för att skapa tabellen:</p>
            <code>database/migrations/054_payment_recipients_central.sql</code>
            <p style="margin-top: var(--space-md);">
                <a href="/admin/migrations" class="btn-admin btn-admin-primary">Gå till migrationer</a>
            </p>
        </div>
    </div>
    <?php
    include __DIR__ . '/components/unified-layout-footer.php';
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $swishNumber = trim($_POST['swish_number'] ?? '');
        $swishName = trim($_POST['swish_name'] ?? '');

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } elseif (empty($swishNumber)) {
            $message = 'Swish-nummer är obligatoriskt';
            $messageType = 'error';
        } elseif (empty($swishName)) {
            $message = 'Swish-mottagarnamn är obligatoriskt';
            $messageType = 'error';
        } else {
            $recipientData = [
                'name' => $name,
                'description' => trim($_POST['description'] ?? ''),
                'swish_number' => $swishNumber,
                'swish_name' => $swishName,
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            try {
                if ($action === 'create') {
                    $db->insert('payment_recipients', $recipientData);
                    $message = 'Betalningsmottagare skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('payment_recipients', $recipientData, 'id = ?', [$id]);
                    $message = 'Betalningsmottagare uppdaterad!';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        try {
            // First unlink any series/events using this recipient
            $db->query("UPDATE series SET payment_recipient_id = NULL WHERE payment_recipient_id = ?", [$id]);
            $db->query("UPDATE events SET payment_recipient_id = NULL WHERE payment_recipient_id = ?", [$id]);
            $db->delete('payment_recipients', 'id = ?', [$id]);
            $message = 'Betalningsmottagare borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Check if columns exist on series/events
$seriesHasRecipient = false;
$eventsHasRecipient = false;
try {
    $cols = $db->getAll("SHOW COLUMNS FROM series LIKE 'payment_recipient_id'");
    $seriesHasRecipient = !empty($cols);
    $cols = $db->getAll("SHOW COLUMNS FROM events LIKE 'payment_recipient_id'");
    $eventsHasRecipient = !empty($cols);
} catch (Exception $e) {}

// Get all recipients with usage count
$recipients = $db->getAll("
    SELECT pr.*,
           (SELECT COUNT(*) FROM series s WHERE s.payment_recipient_id = pr.id) as series_count,
           (SELECT COUNT(*) FROM events e WHERE e.payment_recipient_id = pr.id) as events_count
    FROM payment_recipients pr
    ORDER BY pr.name ASC
");

// Page config
$page_title = 'Betalningsmottagare';
$page_group = 'economy';
$breadcrumbs = [
    ['label' => 'Betalningar', 'url' => '/admin/orders'],
    ['label' => 'Mottagare']
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?> mb-lg">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <?php if ($messageType === 'success'): ?>
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        <?php elseif ($messageType === 'error'): ?>
            <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>
        <?php else: ?>
            <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/>
        <?php endif; ?>
    </svg>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<!-- Info box -->
<div class="admin-card mb-lg">
    <div class="admin-card-body">
        <div style="display: flex; align-items: flex-start; gap: var(--space-md);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 24px; height: 24px; flex-shrink: 0; color: var(--color-accent);">
                <rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>
            </svg>
            <div>
                <h3 style="margin: 0 0 var(--space-xs) 0;">Swish-mottagare</h3>
                <p style="margin: 0; color: var(--color-text-secondary);">
                    Skapa betalningsmottagare här och koppla dem till serier eller enskilda event.
                    Ett event utan egen mottagare använder seriens mottagare automatiskt.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Add new recipient button -->
<div class="mb-lg">
    <button type="button" class="btn-admin btn-admin-primary" onclick="showModal('create')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Lägg till mottagare
    </button>
</div>

<!-- Recipients list -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px;">
                <rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>
            </svg>
            Betalningsmottagare (<?= count($recipients) ?>)
        </h2>
    </div>
    <div class="admin-card-body gs-p-0">
        <?php if (empty($recipients)): ?>
        <div style="padding: var(--space-xl); text-align: center; color: var(--color-text-secondary);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 48px; height: 48px; margin-bottom: var(--space-md); opacity: 0.3;">
                <rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>
            </svg>
            <p>Inga betalningsmottagare ännu.</p>
            <p>Klicka på "Lägg till mottagare" för att skapa den första.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Namn</th>
                        <th>Swish-nummer</th>
                        <th>Mottagarnamn</th>
                        <th>Används av</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recipients as $r): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($r['name']) ?></strong>
                            <?php if ($r['description']): ?>
                            <div style="font-size: 0.75rem; color: var(--color-text-secondary);">
                                <?= htmlspecialchars($r['description']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code style="background: var(--color-bg-secondary); padding: 2px 6px; border-radius: 4px;">
                                <?= htmlspecialchars($r['swish_number']) ?>
                            </code>
                        </td>
                        <td><?= htmlspecialchars($r['swish_name']) ?></td>
                        <td>
                            <?php
                            $usage = [];
                            if ($r['series_count'] > 0) $usage[] = $r['series_count'] . ' serier';
                            if ($r['events_count'] > 0) $usage[] = $r['events_count'] . ' event';
                            echo $usage ? implode(', ', $usage) : '<span style="color: var(--color-text-secondary);">-</span>';
                            ?>
                        </td>
                        <td>
                            <?php if ($r['active']): ?>
                            <span class="badge badge-success">Aktiv</span>
                            <?php else: ?>
                            <span class="badge badge-secondary">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <button type="button" class="btn-admin btn-admin-secondary btn-admin-sm"
                                    onclick='showModal("edit", <?= json_encode($r) ?>)'>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                    <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                                </svg>
                            </button>
                            <?php if ($r['series_count'] == 0 && $r['events_count'] == 0): ?>
                            <form method="POST" style="display: inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm"
                                        onclick="return confirm('Ta bort <?= htmlspecialchars($r['name']) ?>?')">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                        <path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                    </svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal for create/edit -->
<div id="recipientModal" class="modal" style="display: none;">
    <div class="modal-backdrop" onclick="hideModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Lägg till mottagare</h3>
            <button type="button" class="modal-close" onclick="hideModal()">&times;</button>
        </div>
        <form method="POST" id="recipientForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">

            <div class="modal-body">
                <div class="admin-form-group">
                    <label class="admin-form-label">Namn <span style="color: var(--color-error);">*</span></label>
                    <input type="text" name="name" id="formName" class="admin-form-input" required
                           placeholder="T.ex. GravitySeries, Järvsö IF">
                    <small style="color: var(--color-text-secondary);">Internt namn för att identifiera mottagaren</small>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Beskrivning</label>
                    <input type="text" name="description" id="formDescription" class="admin-form-input"
                           placeholder="T.ex. Centralt konto för GS-serier">
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Swish-nummer <span style="color: var(--color-error);">*</span></label>
                        <input type="text" name="swish_number" id="formSwishNumber" class="admin-form-input" required
                               placeholder="070-123 45 67">
                        <small style="color: var(--color-text-secondary);">Mobilnummer eller företagsnummer</small>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-form-label">Mottagarnamn i Swish <span style="color: var(--color-error);">*</span></label>
                        <input type="text" name="swish_name" id="formSwishName" class="admin-form-input" required
                               placeholder="GravitySeries">
                        <small style="color: var(--color-text-secondary);">Visas för betalaren i Swish-appen</small>
                    </div>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label" style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                        <input type="checkbox" name="active" id="formActive" value="1" checked>
                        Aktiv
                    </label>
                    <small style="color: var(--color-text-secondary);">Inaktiva mottagare kan inte väljas för nya serier/event</small>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-admin btn-admin-secondary" onclick="hideModal()">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary" id="formSubmitBtn">Spara</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
}

.modal-content {
    position: relative;
    background: var(--color-bg);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--color-text-secondary);
    line-height: 1;
}

.modal-close:hover {
    color: var(--color-text);
}

.modal-body {
    padding: var(--space-lg);
}

.modal-footer {
    display: flex;
    gap: var(--space-sm);
    justify-content: flex-end;
    padding: var(--space-md) var(--space-lg);
    border-top: 1px solid var(--color-border);
}

.admin-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}

@media (max-width: 600px) {
    .admin-form-row {
        grid-template-columns: 1fr;
    }
}

.btn-admin-sm {
    padding: var(--space-xs) var(--space-sm);
}
</style>

<script>
function showModal(action, data = null) {
    const modal = document.getElementById('recipientModal');
    const form = document.getElementById('recipientForm');
    const title = document.getElementById('modalTitle');

    if (action === 'edit' && data) {
        title.textContent = 'Redigera mottagare';
        document.getElementById('formAction').value = 'update';
        document.getElementById('formId').value = data.id;
        document.getElementById('formName').value = data.name || '';
        document.getElementById('formDescription').value = data.description || '';
        document.getElementById('formSwishNumber').value = data.swish_number || '';
        document.getElementById('formSwishName').value = data.swish_name || '';
        document.getElementById('formActive').checked = data.active == 1;
        document.getElementById('formSubmitBtn').textContent = 'Uppdatera';
    } else {
        title.textContent = 'Lägg till mottagare';
        document.getElementById('formAction').value = 'create';
        document.getElementById('formId').value = '';
        form.reset();
        document.getElementById('formActive').checked = true;
        document.getElementById('formSubmitBtn').textContent = 'Skapa';
    }

    modal.style.display = 'flex';
}

function hideModal() {
    document.getElementById('recipientModal').style.display = 'none';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideModal();
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
