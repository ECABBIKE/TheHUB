<?php
echo "START"; exit;
/**
 * Promotor Management - Simple search tool
 * Search activated rider → Make them promotor
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_admin();

if (!hasRole('admin')) {
    http_response_code(403);
    die('Access denied');
}

$db = getDB();
$currentAdmin = getCurrentAdmin();
$message = '';
$messageType = '';

// Check if tables exist before handling actions
$canProcess = true;
try {
    $db->getRow("SELECT 1 FROM rider_profiles LIMIT 1");
} catch (Exception $e) {
    $canProcess = false;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canProcess) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $riderId = (int)($_POST['rider_id'] ?? 0);

        if ($riderId) {
            $rider = $db->getRow("
                SELECT r.id, r.firstname, r.lastname, r.email, r.password, c.name as club_name
                FROM riders r
                LEFT JOIN clubs c ON r.club_id = c.id
                WHERE r.id = ? AND r.password IS NOT NULL
            ", [$riderId]);

            if (!$rider) {
                $message = 'Rider hittades inte eller har inte aktiverat konto';
                $messageType = 'error';
            } else {
                // Get or create user account
                $userLink = $db->getRow("
                    SELECT rp.user_id, au.role FROM rider_profiles rp
                    JOIN admin_users au ON rp.user_id = au.id
                    WHERE rp.rider_id = ?
                ", [$riderId]);

                if ($userLink) {
                    if ($userLink['role'] === 'promotor') {
                        $message = $rider['firstname'] . ' ' . $rider['lastname'] . ' är redan promotör';
                        $messageType = 'warning';
                    } else {
                        $db->execute("UPDATE admin_users SET role = 'promotor' WHERE id = ?", [$userLink['user_id']]);
                        $message = $rider['firstname'] . ' ' . $rider['lastname'] . ' är nu promotör';
                        $messageType = 'success';
                    }
                } else {
                    // Create user account
                    $username = strtolower(preg_replace('/[^a-z0-9]/', '', $rider['firstname'] . $rider['lastname']));
                    $counter = 1;
                    $baseUsername = $username;
                    while ($db->getRow("SELECT id FROM admin_users WHERE username = ?", [$username])) {
                        $username = $baseUsername . $counter++;
                    }

                    $db->execute("
                        INSERT INTO admin_users (username, email, full_name, role, active, created_at)
                        VALUES (?, ?, ?, 'promotor', 1, NOW())
                    ", [$username, $rider['email'], $rider['firstname'] . ' ' . $rider['lastname']]);
                    $userId = $db->lastInsertId();

                    $db->execute("
                        INSERT INTO rider_profiles (user_id, rider_id, is_primary, created_at)
                        VALUES (?, ?, 1, NOW())
                    ", [$userId, $riderId]);

                    $message = $rider['firstname'] . ' ' . $rider['lastname'] . ' är nu promotör';
                    $messageType = 'success';
                }
            }
        }
    } elseif ($action === 'remove') {
        $riderId = (int)($_POST['rider_id'] ?? 0);
        if ($riderId) {
            $link = $db->getRow("
                SELECT rp.user_id FROM rider_profiles rp
                JOIN admin_users au ON rp.user_id = au.id
                WHERE rp.rider_id = ?
            ", [$riderId]);

            if ($link) {
                $db->execute("UPDATE admin_users SET role = 'rider' WHERE id = ?", [$link['user_id']]);
                $db->execute("DELETE FROM promotor_events WHERE user_id = ?", [$link['user_id']]);
                $message = 'Promotör-rollen borttagen';
                $messageType = 'success';
            }
        }
    }
}

// Get current promotors
$promotors = [];
$tablesExist = true;
try {
    // Check if rider_profiles table exists
    $db->getRow("SELECT 1 FROM rider_profiles LIMIT 1");
} catch (Exception $e) {
    $tablesExist = false;
    $message = 'Tabellen rider_profiles saknas. Kör migration 093 först.';
    $messageType = 'error';
}

if ($tablesExist) {
    try {
        $promotors = $db->getAll("
            SELECT
                r.id as rider_id,
                r.firstname,
                r.lastname,
                c.name as club_name,
                (SELECT COUNT(*) FROM promotor_events pe WHERE pe.user_id = au.id) as event_count
            FROM riders r
            JOIN rider_profiles rp ON r.id = rp.rider_id
            JOIN admin_users au ON rp.user_id = au.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE au.role = 'promotor'
            ORDER BY r.lastname, r.firstname
        ");
    } catch (Exception $e) {
        // promotor_events might not exist
        $promotors = $db->getAll("
            SELECT
                r.id as rider_id,
                r.firstname,
                r.lastname,
                c.name as club_name,
                0 as event_count
            FROM riders r
            JOIN rider_profiles rp ON r.id = rp.rider_id
            JOIN admin_users au ON rp.user_id = au.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE au.role = 'promotor'
            ORDER BY r.lastname, r.firstname
        ");
    }
}

$page_title = 'Promotörer';
$breadcrumbs = [['label' => 'Användare', 'url' => '/admin/users.php'], ['label' => 'Promotörer']];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert--<?= $messageType ?>" style="margin-bottom: var(--space-md);">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="card mb-lg">
    <div class="card-header">
        <h2>Lägg till promotör</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">Sök efter deltagare med aktiverat konto.</p>

        <form method="POST" id="addForm">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="rider_id" id="riderId">

            <div class="search-container">
                <input type="text" id="searchInput" class="form-input" placeholder="Sök namn..." autocomplete="off">
                <div id="searchResults" class="search-results"></div>
            </div>

            <div id="selectedRider" class="selected-box" style="display:none;"></div>

            <button type="submit" id="submitBtn" class="btn btn--primary mt-md" disabled>
                <i data-lucide="star"></i> Gör till promotör
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Nuvarande promotörer (<?= count($promotors) ?>)</h2>
    </div>
    <div class="card-body">
        <?php if (empty($promotors)): ?>
            <p class="text-secondary">Inga promotörer.</p>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach ($promotors as $p): ?>
                <div class="admin-row">
                    <div class="admin-info">
                        <strong><?= h($p['firstname'] . ' ' . $p['lastname']) ?></strong>
                        <span class="text-secondary"><?= h($p['club_name'] ?: 'Ingen klubb') ?></span>
                        <?php if ($p['event_count'] > 0): ?>
                            <span class="badge badge-sm"><?= $p['event_count'] ?> events</span>
                        <?php endif; ?>
                    </div>
                    <form method="POST" onsubmit="return confirm('Ta bort promotör-rollen?')">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="rider_id" value="<?= $p['rider_id'] ?>">
                        <button class="btn btn--danger btn--sm"><i data-lucide="x"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.search-container { position: relative; max-width: 400px; }
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
    margin-top: var(--space-md); padding: var(--space-md);
    background: rgba(97,206,112,0.1); border: 1px solid var(--color-accent);
    border-radius: var(--radius-md); max-width: 400px;
}
.selected-box .name { font-weight: 600; }
.selected-box .club { color: var(--color-text-secondary); }
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
.badge-sm { font-size: 0.75rem; padding: 2px 6px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('searchInput');
    const results = document.getElementById('searchResults');
    const selected = document.getElementById('selectedRider');
    const riderId = document.getElementById('riderId');
    const submitBtn = document.getElementById('submitBtn');
    let timeout;

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
                            <div class="search-item" data-id="${r.id}" data-name="${r.firstname} ${r.lastname}" data-club="${r.club_name || 'Ingen klubb'}">
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
            selected.innerHTML = `<span class="clear" onclick="clearSelection()">✕</span>
                <div class="name">${item.dataset.name}</div>
                <div class="club">${item.dataset.club}</div>`;
            selected.style.display = 'block';
            input.style.display = 'none';
            results.classList.remove('show');
            submitBtn.disabled = false;
        }
    });

    window.clearSelection = function() {
        riderId.value = '';
        selected.style.display = 'none';
        input.style.display = 'block';
        input.value = '';
        submitBtn.disabled = true;
    };

    document.addEventListener('click', e => {
        if (!e.target.closest('.search-container')) results.classList.remove('show');
    });
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
