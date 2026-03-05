<?php
/**
 * Venue/Destination Admins Management
 * Search activated rider → Make them admin for a specific venue/destination
 */
require_once __DIR__ . '/../config.php';
require_admin();

if (!hasRole('admin')) {
    http_response_code(403);
    die('Access denied');
}

$pdo = $GLOBALS['pdo'];
$currentAdmin = getCurrentAdmin();
$message = '';
$messageType = '';

// Check if venue_admins table exists
$tableExists = true;
try {
    $pdo->query("SELECT 1 FROM venue_admins LIMIT 1");
} catch (Exception $e) {
    $tableExists = false;
    $message = 'Tabellen venue_admins saknas. Kör migration 079 via migrationsverktyget.';
    $messageType = 'error';
}

// Get all venues for dropdown
$venues = [];
try {
    $venues = $pdo->query("SELECT id, name, city, region FROM venues WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $riderId = (int)($_POST['rider_id'] ?? 0);
        $venueId = (int)($_POST['venue_id'] ?? 0);

        if ($riderId && $venueId) {
            try {
                // Get rider info
                $stmt = $pdo->prepare("
                    SELECT r.id, r.firstname, r.lastname, r.email
                    FROM riders r
                    WHERE r.id = ? AND r.password IS NOT NULL AND r.password != ''
                ");
                $stmt->execute([$riderId]);
                $rider = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$rider) {
                    $message = 'Deltagare hittades inte eller har inte aktiverat konto';
                    $messageType = 'error';
                } else {
                    // Check/create admin_users entry
                    $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE email = ?");
                    $stmt->execute([$rider['email']]);
                    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingUser) {
                        $userId = $existingUser['id'];
                    } else {
                        $username = strtolower(preg_replace('/[^a-z0-9]/', '', $rider['firstname'] . $rider['lastname']));
                        $baseUsername = $username ?: 'user';
                        $counter = 1;
                        $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ?");
                        $stmt->execute([$username]);
                        while ($stmt->fetch()) {
                            $username = $baseUsername . $counter++;
                            $stmt->execute([$username]);
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO admin_users (username, email, full_name, role, active, created_at)
                            VALUES (?, ?, ?, 'venue_admin', 1, NOW())
                        ");
                        $stmt->execute([$username, $rider['email'], $rider['firstname'] . ' ' . $rider['lastname']]);
                        $userId = $pdo->lastInsertId();
                    }

                    // Check duplicate
                    $stmt = $pdo->prepare("SELECT id FROM venue_admins WHERE user_id = ? AND venue_id = ?");
                    $stmt->execute([$userId, $venueId]);

                    if ($stmt->fetch()) {
                        $message = 'Redan admin för denna destination';
                        $messageType = 'warning';
                    } else {
                        // Get venue name
                        $vStmt = $pdo->prepare("SELECT name FROM venues WHERE id = ?");
                        $vStmt->execute([$venueId]);
                        $venueName = $vStmt->fetchColumn();

                        $stmt = $pdo->prepare("
                            INSERT INTO venue_admins (user_id, venue_id, can_edit_profile, can_upload_media, granted_by, created_at)
                            VALUES (?, ?, 1, 1, ?, NOW())
                        ");
                        $stmt->execute([$userId, $venueId, $currentAdmin['id']]);

                        $message = $rider['firstname'] . ' ' . $rider['lastname'] . ' är nu admin för ' . $venueName;
                        $messageType = 'success';
                    }
                }
            } catch (Exception $e) {
                $message = 'Fel: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $pdo->prepare("DELETE FROM venue_admins WHERE id = ?")->execute([$id]);
                $message = 'Destinations-admin borttagen';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Fel: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get current venue admins
$venueAdmins = [];
if ($tableExists) {
    try {
        $venueAdmins = $pdo->query("
            SELECT va.id, au.full_name, au.email, v.name AS venue_name, v.city
            FROM venue_admins va
            JOIN admin_users au ON va.user_id = au.id
            JOIN venues v ON va.venue_id = v.id
            ORDER BY v.name, au.full_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = 'Kunde inte hämta destinations-admins: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$page_title = 'Destinations-admin';
$breadcrumbs = [['label' => 'Användare', 'url' => '/admin/users.php'], ['label' => 'Destinations-admin']];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert--<?= $messageType ?>" style="margin-bottom: var(--space-md);">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="card mb-lg">
    <div class="card-header">
        <h2>Lägg till destinations-admin</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">Sök efter deltagare med aktiverat konto och välj vilken destination de ska administrera.</p>

        <form method="POST" id="addForm">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="rider_id" id="riderId">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md); max-width: 600px;">
                <div>
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: var(--space-2xs); color: var(--color-text-secondary);">Deltagare</label>
                    <div class="search-container">
                        <input type="text" id="searchInput" class="form-input" placeholder="Sök namn..." autocomplete="off">
                        <div id="searchResults" class="search-results"></div>
                    </div>
                    <div id="selectedRider" class="selected-box" style="display:none;"></div>
                </div>
                <div>
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: var(--space-2xs); color: var(--color-text-secondary);">Destination</label>
                    <select name="venue_id" id="venueSelect" class="form-input" required>
                        <option value="">Välj destination...</option>
                        <?php foreach ($venues as $v): ?>
                            <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?><?= $v['city'] ? ' (' . htmlspecialchars($v['city']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" id="submitBtn" class="btn btn--primary mt-md" disabled>
                <i data-lucide="plus"></i> Lägg till som destinations-admin
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Nuvarande destinations-admins (<?= count($venueAdmins) ?>)</h2>
    </div>
    <div class="card-body">
        <?php if (empty($venueAdmins)): ?>
            <p class="text-secondary">Inga destinations-administratörer.</p>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach ($venueAdmins as $va): ?>
                <div class="admin-row">
                    <div class="admin-info">
                        <strong><?= htmlspecialchars($va['full_name'] ?: $va['email']) ?></strong>
                        <span class="text-secondary"><?= htmlspecialchars($va['venue_name']) ?><?= $va['city'] ? ' (' . htmlspecialchars($va['city']) . ')' : '' ?></span>
                    </div>
                    <form method="POST" onsubmit="return confirm('Ta bort?')">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="id" value="<?= $va['id'] ?>">
                        <button class="btn btn--danger btn--sm"><i data-lucide="x"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.search-container { position: relative; }
.search-results {
    position: absolute; top: 100%; left: 0; right: 0;
    background: #fff; border: 1px solid var(--color-border);
    border-radius: var(--radius-md); max-height: 300px; overflow-y: auto;
    z-index: 100; display: none; box-shadow: var(--shadow-lg);
}
.search-results.show { display: block; }
.search-item {
    padding: var(--space-sm) var(--space-md);
    cursor: pointer; border-bottom: 1px solid var(--color-border);
}
.search-item:hover { background: var(--color-bg-sunken); }
.search-item:last-child { border-bottom: none; }
.search-item-name { font-weight: 600; }
.search-item-club { font-size: 0.875rem; color: var(--color-text-secondary); }
.selected-box {
    margin-top: var(--space-xs); padding: var(--space-sm) var(--space-md);
    background: rgba(55, 212, 214, 0.1); border: 1px solid var(--color-accent);
    border-radius: var(--radius-sm);
}
.selected-box .name { font-weight: 600; }
.selected-box .clear { float: right; cursor: pointer; color: var(--color-text-secondary); }
.selected-box .clear:hover { color: var(--color-danger); }
.admin-list { display: flex; flex-direction: column; gap: var(--space-sm); }
.admin-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken); border-radius: var(--radius-sm);
}
.admin-info { display: flex; flex-direction: column; gap: 2px; }
@media (min-width: 600px) {
    .admin-info { flex-direction: row; gap: var(--space-md); align-items: center; }
}
@media (max-width: 767px) {
    form#addForm > div { grid-template-columns: 1fr !important; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('searchInput');
    const results = document.getElementById('searchResults');
    const selected = document.getElementById('selectedRider');
    const riderId = document.getElementById('riderId');
    const submitBtn = document.getElementById('submitBtn');
    const venueSelect = document.getElementById('venueSelect');
    let timeout;

    function checkReady() {
        submitBtn.disabled = !(riderId.value && venueSelect.value);
    }

    venueSelect.addEventListener('change', checkReady);

    input.addEventListener('input', function() {
        clearTimeout(timeout);
        const q = this.value.trim();
        if (q.length < 2) { results.classList.remove('show'); return; }

        timeout = setTimeout(() => {
            fetch('/api/search-riders.php?q=' + encodeURIComponent(q) + '&activated=1&limit=20')
                .then(r => r.json())
                .then(data => {
                    if (data.riders?.length) {
                        results.innerHTML = data.riders.map(r => `
                            <div class="search-item" data-id="${r.id}" data-name="${r.firstname} ${r.lastname}" data-club="${r.club_name || ''}">
                                <div class="search-item-name">${r.firstname} ${r.lastname}</div>
                                <div class="search-item-club">${r.club_name || 'Ingen klubb'}</div>
                            </div>
                        `).join('');
                    } else {
                        results.innerHTML = '<div class="search-item"><em>Inga träffar</em></div>';
                    }
                    results.classList.add('show');
                });
        }, 250);
    });

    results.addEventListener('click', function(e) {
        const item = e.target.closest('.search-item');
        if (item?.dataset.id) {
            riderId.value = item.dataset.id;
            selected.innerHTML = `<span class="clear" onclick="clearSelection()">x</span>
                <span class="name">${item.dataset.name}</span>`;
            selected.style.display = 'block';
            input.style.display = 'none';
            results.classList.remove('show');
            checkReady();
        }
    });

    window.clearSelection = function() {
        riderId.value = '';
        selected.style.display = 'none';
        input.style.display = 'block';
        input.value = '';
        checkReady();
    };

    document.addEventListener('click', e => {
        if (!e.target.closest('.search-container')) results.classList.remove('show');
    });

    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
