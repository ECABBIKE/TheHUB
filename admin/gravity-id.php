<?php
/**
 * Gravity ID Management Panel
 * TheHUB - Manage Gravity ID members and discounts
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_settings') {
        $defaultDiscount = floatval($_POST['default_discount'] ?? 50);
        $validYears = intval($_POST['valid_for_years'] ?? 1);
        $enabled = isset($_POST['enabled']) ? '1' : '0';

        try {
            $db->query("INSERT INTO gravity_id_settings (setting_key, setting_value) VALUES ('default_discount', ?)
                        ON DUPLICATE KEY UPDATE setting_value = ?", [$defaultDiscount, $defaultDiscount]);
            $db->query("INSERT INTO gravity_id_settings (setting_key, setting_value) VALUES ('valid_for_years', ?)
                        ON DUPLICATE KEY UPDATE setting_value = ?", [$validYears, $validYears]);
            $db->query("INSERT INTO gravity_id_settings (setting_key, setting_value) VALUES ('enabled', ?)
                        ON DUPLICATE KEY UPDATE setting_value = ?", [$enabled, $enabled]);
            $message = "Inställningar sparade!";
        } catch (Exception $e) {
            $error = "Kunde inte spara: " . $e->getMessage();
        }
    } elseif ($action === 'add_gravity_id') {
        $riderId = intval($_POST['rider_id']);
        $gravityId = trim($_POST['gravity_id']);
        $validUntil = $_POST['valid_until'] ?: null;

        if ($riderId && $gravityId) {
            try {
                $db->query("UPDATE riders SET gravity_id = ?, gravity_id_since = CURDATE(), gravity_id_valid_until = ? WHERE id = ?",
                    [$gravityId, $validUntil, $riderId]);
                $message = "Gravity ID tilldelat!";
            } catch (Exception $e) {
                $error = "Kunde inte tilldela: " . $e->getMessage();
            }
        }
    } elseif ($action === 'remove_gravity_id') {
        $riderId = intval($_POST['rider_id']);
        try {
            $db->query("UPDATE riders SET gravity_id = NULL, gravity_id_since = NULL, gravity_id_valid_until = NULL WHERE id = ?", [$riderId]);
            $message = "Gravity ID borttaget";
        } catch (Exception $e) {
            $error = "Kunde inte ta bort: " . $e->getMessage();
        }
    }
}

// Get settings
$settings = [];
try {
    $settingsRows = $db->getAll("SELECT setting_key, setting_value FROM gravity_id_settings");
    foreach ($settingsRows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Table might not exist yet
}

$defaultDiscount = $settings['default_discount'] ?? 50;
$validYears = $settings['valid_for_years'] ?? 1;
$enabled = ($settings['enabled'] ?? '1') === '1';

// Get members with Gravity ID
$members = [];
try {
    $members = $db->getAll("
        SELECT r.id, r.firstname, r.lastname, r.gravity_id, r.gravity_id_since, r.gravity_id_valid_until,
               r.email, r.club_id, c.name as club_name,
               (SELECT COUNT(*) FROM event_registrations er WHERE er.rider_id = r.id AND er.status = 'confirmed') as reg_count
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.gravity_id IS NOT NULL
        ORDER BY r.gravity_id_since DESC
    ");
} catch (Exception $e) {
    // Columns might not exist yet
}

// Get stats
$totalMembers = count($members);
$activeMembers = count(array_filter($members, fn($m) => !$m['gravity_id_valid_until'] || strtotime($m['gravity_id_valid_until']) >= time()));
$expiredMembers = $totalMembers - $activeMembers;

// For search
$searchRiders = [];
if (isset($_GET['search']) && strlen($_GET['search']) >= 2) {
    $search = '%' . $_GET['search'] . '%';
    $searchRiders = $db->getAll("
        SELECT id, firstname, lastname, email, gravity_id
        FROM riders
        WHERE (firstname LIKE ? OR lastname LIKE ? OR email LIKE ?) AND gravity_id IS NULL
        LIMIT 20
    ", [$search, $search, $search]);
}

// Page config
$page_title = 'Gravity ID';
$breadcrumbs = [
    ['label' => 'Ekonomi', 'url' => '/admin/payment-settings.php'],
    ['label' => 'Gravity ID']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.gid-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}
.gid-stat {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}
.gid-stat-value {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--color-accent);
}
.gid-stat-label {
    font-size: 0.875rem;
    color: var(--color-text-muted);
    margin-top: var(--space-xs);
}
.settings-card, .add-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.settings-card h3, .add-card h3 {
    margin: 0 0 var(--space-md);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}
.form-group label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: var(--space-2xs);
}
.form-group input,
.form-group select {
    width: 100%;
    padding: var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-page);
    color: var(--color-text-primary);
}
.member-table {
    width: 100%;
    border-collapse: collapse;
}
.member-table th,
.member-table td {
    padding: var(--space-sm);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}
.member-table th {
    background: var(--color-bg-page);
    font-weight: 600;
    color: var(--color-text-secondary);
    font-size: 0.875rem;
}
.gid-badge {
    font-family: monospace;
    font-weight: 600;
    color: var(--color-accent);
    background: var(--color-accent-light);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
}
.status-active {
    color: var(--color-success);
}
.status-expired {
    color: var(--color-error);
}
.search-results {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    margin-top: var(--space-sm);
    max-height: 300px;
    overflow-y: auto;
}
.search-result {
    padding: var(--space-sm);
    border-bottom: 1px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.search-result:last-child {
    border-bottom: none;
}
.search-result:hover {
    background: var(--color-bg-hover);
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success" style="margin-bottom: var(--space-md);"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: var(--space-md);"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="gid-stats">
    <div class="gid-stat">
        <div class="gid-stat-value"><?= $totalMembers ?></div>
        <div class="gid-stat-label">Totalt medlemmar</div>
    </div>
    <div class="gid-stat">
        <div class="gid-stat-value" style="color: var(--color-success);"><?= $activeMembers ?></div>
        <div class="gid-stat-label">Aktiva</div>
    </div>
    <div class="gid-stat">
        <div class="gid-stat-value" style="color: var(--color-error);"><?= $expiredMembers ?></div>
        <div class="gid-stat-label">Utgångna</div>
    </div>
    <div class="gid-stat">
        <div class="gid-stat-value"><?= number_format($defaultDiscount, 0) ?> kr</div>
        <div class="gid-stat-label">Rabatt per event</div>
    </div>
</div>

<!-- Settings -->
<div class="settings-card">
    <h3><i data-lucide="settings"></i> Inställningar</h3>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_settings">

        <div class="form-row">
            <div class="form-group">
                <label>Standard rabatt (SEK)</label>
                <input type="number" name="default_discount" value="<?= $defaultDiscount ?>" min="0" step="1">
            </div>
            <div class="form-group">
                <label>Giltighetstid (år)</label>
                <input type="number" name="valid_for_years" value="<?= $validYears ?>" min="1" max="5">
            </div>
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: var(--space-sm); margin-top: var(--space-md);">
                    <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                    Aktivera Gravity ID-rabatter
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i data-lucide="save"></i> Spara inställningar
        </button>
    </form>
</div>

<!-- Add New Member -->
<div class="add-card">
    <h3><i data-lucide="user-plus"></i> Lägg till Gravity ID</h3>

    <form method="GET" id="search-form">
        <div class="form-group">
            <label>Sök åkare (namn eller e-post)</label>
            <input type="text" name="search" id="rider-search" placeholder="Skriv minst 2 tecken..."
                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" autocomplete="off">
        </div>
    </form>

    <?php if (!empty($searchRiders)): ?>
        <div class="search-results">
            <?php foreach ($searchRiders as $rider): ?>
                <div class="search-result">
                    <div>
                        <strong><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                        <span style="color: var(--color-text-muted); font-size: 0.875rem;">
                            (<?= htmlspecialchars($rider['email'] ?? 'ingen e-post') ?>)
                        </span>
                    </div>
                    <form method="POST" style="display: flex; gap: var(--space-xs); align-items: center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_gravity_id">
                        <input type="hidden" name="rider_id" value="<?= $rider['id'] ?>">
                        <input type="text" name="gravity_id" placeholder="GID-XXXXX" required
                               style="width: 120px; padding: var(--space-xs);">
                        <input type="date" name="valid_until" placeholder="Giltig till"
                               style="width: 140px; padding: var(--space-xs);">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i data-lucide="plus"></i>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (isset($_GET['search']) && strlen($_GET['search']) >= 2): ?>
        <p style="color: var(--color-text-muted); padding: var(--space-md);">
            Inga åkare hittades utan Gravity ID som matchar "<?= htmlspecialchars($_GET['search']) ?>"
        </p>
    <?php endif; ?>
</div>

<!-- Members List -->
<div class="settings-card">
    <h3><i data-lucide="users"></i> Medlemmar med Gravity ID (<?= $totalMembers ?>)</h3>

    <?php if (empty($members)): ?>
        <p style="color: var(--color-text-muted); text-align: center; padding: var(--space-lg);">
            Inga medlemmar med Gravity ID ännu. Kör migrering 103 först, sedan kan du lägga till medlemmar ovan
            eller importera via <a href="/admin/import-gravity-id.php" style="color: var(--color-accent);">CSV-import</a>.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="member-table">
                <thead>
                    <tr>
                        <th>Gravity ID</th>
                        <th>Namn</th>
                        <th>Klubb</th>
                        <th>Sedan</th>
                        <th>Giltig till</th>
                        <th>Status</th>
                        <th>Anmälningar</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $member):
                        $isExpired = $member['gravity_id_valid_until'] && strtotime($member['gravity_id_valid_until']) < time();
                    ?>
                        <tr>
                            <td><span class="gid-badge"><?= htmlspecialchars($member['gravity_id']) ?></span></td>
                            <td>
                                <a href="/admin/rider-edit.php?id=<?= $member['id'] ?>" style="color: var(--color-text-primary);">
                                    <?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($member['club_name'] ?? '-') ?></td>
                            <td><?= $member['gravity_id_since'] ? date('Y-m-d', strtotime($member['gravity_id_since'])) : '-' ?></td>
                            <td><?= $member['gravity_id_valid_until'] ? date('Y-m-d', strtotime($member['gravity_id_valid_until'])) : 'Obegränsat' ?></td>
                            <td>
                                <?php if ($isExpired): ?>
                                    <span class="status-expired"><i data-lucide="x-circle"></i> Utgånget</span>
                                <?php else: ?>
                                    <span class="status-active"><i data-lucide="check-circle"></i> Aktivt</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $member['reg_count'] ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Ta bort Gravity ID för denna åkare?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_gravity_id">
                                    <input type="hidden" name="rider_id" value="<?= $member['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" title="Ta bort">
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

<!-- Quick Links -->
<div style="display: flex; gap: var(--space-md); flex-wrap: wrap;">
    <a href="/admin/import-gravity-id.php" class="btn btn-secondary">
        <i data-lucide="upload"></i> Importera CSV
    </a>
    <a href="/admin/migrations/103_gravity_id_discount_codes.php" class="btn btn-ghost">
        <i data-lucide="database"></i> Kör migrering 103
    </a>
</div>

<script>
lucide.createIcons();
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
