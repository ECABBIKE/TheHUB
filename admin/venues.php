<?php
/**
 * Admin Venues - V3 Design System
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            $message = 'Venue namn är obligatoriskt';
            $messageType = 'error';
        } else {
            $venueData = [
                'name' => $name,
                'city' => trim($_POST['city'] ?? ''),
                'region' => trim($_POST['region'] ?? ''),
                'country' => trim($_POST['country'] ?? 'Sverige'),
                'address' => trim($_POST['address'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'gps_lat' => !empty($_POST['gps_lat']) ? floatval($_POST['gps_lat']) : null,
                'gps_lng' => !empty($_POST['gps_lng']) ? floatval($_POST['gps_lng']) : null,
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            try {
                if ($action === 'create') {
                    $db->insert('venues', $venueData);
                    $message = 'Venue skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('venues', $venueData, 'id = ?', [$id]);
                    $message = 'Venue uppdaterad!';
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
            $db->delete('venues', 'id = ?', [$id]);
            $message = 'Venue borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Check if editing
$editVenue = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editVenue = $db->getRow("SELECT * FROM venues WHERE id = ?", [intval($_GET['edit'])]);
}

// Get venues with event count
$sql = "SELECT
    v.id, v.name, v.city, v.region, v.country, v.address, v.description,
    v.gps_lat, v.gps_lng, v.active,
    COUNT(DISTINCT e.id) as event_count
FROM venues v
LEFT JOIN events e ON v.id = e.venue_id
GROUP BY v.id
ORDER BY v.name";

try {
    $venues = $db->getAll($sql);
} catch (Exception $e) {
    $venues = [];
    $error = $e->getMessage();
}

// Calculate stats
$totalVenues = count($venues);
$activeCount = count(array_filter($venues, fn($v) => $v['active'] == 1));
$totalEvents = array_sum(array_column($venues, 'event_count'));

// Page config
$page_title = 'Banor';
$page_actions = '<button onclick="openVenueModal()" class="btn-admin btn-admin-primary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
    Ny Venue
</button>';

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error">
        <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-info-light); color: var(--color-info);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $totalVenues ?></div>
            <div class="admin-stat-label">Totalt venues</div>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-success-light); color: var(--color-success);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $activeCount ?></div>
            <div class="admin-stat-label">Aktiva</div>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-accent-light); color: var(--color-accent);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $totalEvents ?></div>
            <div class="admin-stat-label">Totalt events</div>
        </div>
    </div>
</div>

<!-- Venues Table -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><?= count($venues) ?> venues</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <?php if (empty($venues)): ?>
            <div class="admin-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <h3>Inga venues hittades</h3>
                <p>Skapa en ny venue för att komma igång.</p>
                <button onclick="openVenueModal()" class="btn-admin btn-admin-primary">Skapa venue</button>
            </div>
        <?php else: ?>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Namn</th>
                            <th>Stad/Region</th>
                            <th>Land</th>
                            <th>GPS</th>
                            <th>Events</th>
                            <th>Status</th>
                            <th style="width: 120px;">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($venues as $venue): ?>
                            <tr>
                                <td>
                                    <a href="?edit=<?= $venue['id'] ?>" style="color: var(--color-accent); text-decoration: none; font-weight: 500;">
                                        <?= htmlspecialchars($venue['name']) ?>
                                    </a>
                                    <?php if (!empty($venue['description'])): ?>
                                        <div style="font-size: var(--text-xs); color: var(--color-text-secondary); margin-top: 2px;">
                                            <?= htmlspecialchars(substr($venue['description'], 0, 80)) ?><?= strlen($venue['description']) > 80 ? '...' : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--color-text-secondary);">
                                    <?php if ($venue['city'] || $venue['region']): ?>
                                        <?= htmlspecialchars($venue['city']) ?><?= $venue['city'] && $venue['region'] ? ', ' : '' ?><?= htmlspecialchars($venue['region']) ?>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td style="color: var(--color-text-secondary);"><?= htmlspecialchars($venue['country'] ?? 'Sverige') ?></td>
                                <td>
                                    <?php if ($venue['gps_lat'] && $venue['gps_lng']): ?>
                                        <a href="https://www.google.com/maps?q=<?= $venue['gps_lat'] ?>,<?= $venue['gps_lng'] ?>" target="_blank" style="color: var(--color-accent); text-decoration: none; font-size: var(--text-xs);">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 12px; height: 12px; vertical-align: middle;"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                            Visa karta
                                        </a>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($venue['event_count'] > 0): ?>
                                        <a href="/admin/events?venue_id=<?= $venue['id'] ?>" class="btn-admin btn-admin-sm btn-admin-secondary">
                                            <?= $venue['event_count'] ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--color-text-secondary);">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-badge <?= $venue['active'] ? 'admin-badge-success' : 'admin-badge-secondary' ?>">
                                        <?= $venue['active'] ? 'Aktiv' : 'Inaktiv' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?edit=<?= $venue['id'] ?>" class="btn-admin btn-admin-sm btn-admin-secondary" title="Redigera">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                        </a>
                                        <button onclick="deleteVenue(<?= $venue['id'] ?>, '<?= addslashes($venue['name']) ?>')" class="btn-admin btn-admin-sm btn-admin-danger" title="Ta bort">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Venue Modal -->
<div id="venueModal" class="admin-modal" style="display: none;">
    <div class="admin-modal-overlay" onclick="closeVenueModal()"></div>
    <div class="admin-modal-content" style="max-width: 600px;">
        <div class="admin-modal-header">
            <h2 id="modalTitle">Ny Venue</h2>
            <button type="button" class="admin-modal-close" onclick="closeVenueModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="venueForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="venueId" value="">

            <div class="admin-modal-body">
                <div class="admin-form-group">
                    <label class="admin-form-label">Namn <span style="color: var(--color-error);">*</span></label>
                    <input type="text" id="name" name="name" class="admin-form-input" required placeholder="T.ex. Järvsö Bergscykelpark">
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Stad</label>
                        <input type="text" id="city" name="city" class="admin-form-input" placeholder="T.ex. Järvsö">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Region</label>
                        <input type="text" id="region" name="region" class="admin-form-input" placeholder="T.ex. Gävleborg">
                    </div>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Land</label>
                    <input type="text" id="country" name="country" class="admin-form-input" value="Sverige" placeholder="Sverige">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Adress</label>
                    <input type="text" id="address" name="address" class="admin-form-input" placeholder="T.ex. Järvsöbacken 10">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Beskrivning</label>
                    <textarea id="description" name="description" class="admin-form-textarea" rows="3" placeholder="Information om venue..."></textarea>
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label class="admin-form-label">GPS Latitud</label>
                        <input type="number" step="0.0000001" id="gps_lat" name="gps_lat" class="admin-form-input" placeholder="61.7218">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">GPS Longitud</label>
                        <input type="number" step="0.0000001" id="gps_lng" name="gps_lng" class="admin-form-input" placeholder="16.1506">
                    </div>
                </div>

                <div class="admin-form-group">
                    <label class="admin-checkbox-label">
                        <input type="checkbox" id="active" name="active" checked>
                        <span>Aktiv</span>
                    </label>
                </div>
            </div>

            <div class="admin-modal-footer">
                <button type="button" class="btn-admin btn-admin-secondary" onclick="closeVenueModal()">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary" id="submitButton">Skapa</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = '<?= htmlspecialchars(generate_csrf_token()) ?>';

function openVenueModal() {
    document.getElementById('venueModal').style.display = 'flex';
    document.getElementById('venueForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('venueId').value = '';
    document.getElementById('modalTitle').textContent = 'Ny Venue';
    document.getElementById('submitButton').textContent = 'Skapa';
    document.getElementById('active').checked = true;
    document.getElementById('country').value = 'Sverige';
}

function closeVenueModal() {
    document.getElementById('venueModal').style.display = 'none';
}

function deleteVenue(id, name) {
    if (!confirm('Är du säker på att du vill ta bort "' + name + '"?')) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="delete">' +
                     '<input type="hidden" name="id" value="' + id + '">' +
                     '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
    document.body.appendChild(form);
    form.submit();
}

// Handle edit mode from URL parameter
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($editVenue): ?>
        document.getElementById('formAction').value = 'update';
        document.getElementById('venueId').value = '<?= $editVenue['id'] ?>';
        document.getElementById('name').value = '<?= addslashes($editVenue['name']) ?>';
        document.getElementById('city').value = '<?= addslashes($editVenue['city'] ?? '') ?>';
        document.getElementById('region').value = '<?= addslashes($editVenue['region'] ?? '') ?>';
        document.getElementById('country').value = '<?= addslashes($editVenue['country'] ?? 'Sverige') ?>';
        document.getElementById('address').value = '<?= addslashes($editVenue['address'] ?? '') ?>';
        document.getElementById('description').value = '<?= addslashes($editVenue['description'] ?? '') ?>';
        document.getElementById('gps_lat').value = '<?= $editVenue['gps_lat'] ?? '' ?>';
        document.getElementById('gps_lng').value = '<?= $editVenue['gps_lng'] ?? '' ?>';
        document.getElementById('active').checked = <?= $editVenue['active'] ? 'true' : 'false' ?>;

        document.getElementById('modalTitle').textContent = 'Redigera Venue';
        document.getElementById('submitButton').textContent = 'Uppdatera';
        document.getElementById('venueModal').style.display = 'flex';
    <?php endif; ?>
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeVenueModal();
});
</script>

<style>
.admin-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; }
.admin-modal-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); }
.admin-modal-content { position: relative; background: var(--color-bg); border-radius: var(--radius-lg); box-shadow: var(--shadow-xl); width: 90%; max-width: 600px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }
.admin-modal-header { display: flex; align-items: center; justify-content: space-between; padding: var(--space-lg); border-bottom: 1px solid var(--color-border); }
.admin-modal-header h2 { margin: 0; font-size: var(--text-xl); }
.admin-modal-close { background: none; border: none; padding: var(--space-xs); cursor: pointer; color: var(--color-text-secondary); border-radius: var(--radius-sm); }
.admin-modal-close:hover { background: var(--color-bg-tertiary); color: var(--color-text); }
.admin-modal-close svg { width: 20px; height: 20px; }
.admin-modal-body { padding: var(--space-lg); overflow-y: auto; flex: 1; }
.admin-modal-footer { display: flex; justify-content: flex-end; gap: var(--space-sm); padding: var(--space-lg); border-top: 1px solid var(--color-border); }
.admin-form-textarea { width: 100%; padding: var(--space-sm) var(--space-md); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-bg); color: var(--color-text); font-family: inherit; font-size: var(--text-sm); resize: vertical; }
.admin-form-textarea:focus { outline: none; border-color: var(--color-accent); box-shadow: 0 0 0 3px var(--color-accent-alpha); }
.admin-checkbox-label { display: flex; align-items: center; gap: var(--space-xs); cursor: pointer; font-size: var(--text-sm); }
.admin-checkbox-label input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--color-accent); }
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
