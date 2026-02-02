<?php
/**
 * Email Profile Groups Tool
 *
 * Finds all riders sharing the same email address and allows
 * setting which profile should be the "primary" (with password).
 *
 * @package TheHUB Admin Tools
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$db = getDB();
$message = '';
$messageType = '';

// Handle setting primary profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_primary'])) {
    checkCsrf();

    $riderId = (int)$_POST['rider_id'];
    $email = trim($_POST['email']);

    // Verify this rider has this email
    $rider = $db->getRow("SELECT * FROM riders WHERE id = ? AND email = ?", [$riderId, $email]);

    if ($rider) {
        // Check if rider already has a password
        if (empty($rider['password'])) {
            // Generate a temporary password reset token so user can set password
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

            $db->update('riders', [
                'password_reset_token' => $token,
                'password_reset_expires' => $expires
            ], 'id = ?', [$riderId]);

            $resetLink = SITE_URL . '/rider-reset-password.php?token=' . $token;
            $message = "Återställningslänk skapad för {$rider['firstname']} {$rider['lastname']}. Länk giltig i 7 dagar.";
            $messageType = 'success';

            // Log for easy access
            error_log("Password reset link for rider #{$riderId} ({$email}): {$resetLink}");
        } else {
            $message = "{$rider['firstname']} {$rider['lastname']} har redan ett lösenord och är huvudprofil.";
            $messageType = 'info';
        }
    } else {
        $message = "Kunde inte hitta deltagaren.";
        $messageType = 'error';
    }
}

// Get filter
$minProfiles = (int)($_GET['min'] ?? 2);
$searchEmail = trim($_GET['search'] ?? '');

// Find all emails with multiple riders
$query = "
    SELECT
        r.email,
        COUNT(*) as profile_count,
        GROUP_CONCAT(r.id ORDER BY r.birth_year DESC) as rider_ids,
        MAX(CASE WHEN r.password IS NOT NULL AND r.password != '' THEN 1 ELSE 0 END) as has_password
    FROM riders r
    WHERE r.email IS NOT NULL
      AND r.email != ''
      AND r.active = 1
";

$params = [];

if ($searchEmail) {
    $query .= " AND r.email LIKE ?";
    $params[] = "%{$searchEmail}%";
}

$query .= "
    GROUP BY r.email
    HAVING COUNT(*) >= ?
    ORDER BY profile_count DESC, r.email
    LIMIT 200
";
$params[] = $minProfiles;

$emailGroups = $db->getAll($query, $params);

// Get statistics
$stats = $db->getRow("
    SELECT
        COUNT(DISTINCT email) as unique_emails,
        (SELECT COUNT(*) FROM (
            SELECT email FROM riders
            WHERE email IS NOT NULL AND email != '' AND active = 1
            GROUP BY email HAVING COUNT(*) >= 2
        ) sub) as emails_with_multiple,
        (SELECT SUM(cnt) FROM (
            SELECT COUNT(*) as cnt FROM riders
            WHERE email IS NOT NULL AND email != '' AND active = 1
            GROUP BY email HAVING COUNT(*) >= 2
        ) sub2) as riders_in_groups
    FROM riders
    WHERE email IS NOT NULL AND email != '' AND active = 1
");

$pageTitle = 'E-post profilgrupper';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1><i data-lucide="users"></i> E-post profilgrupper</h1>
        <p class="text-secondary">Hitta och hantera deltagare som delar samma e-postadress</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> mb-lg">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-grid mb-xl">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['unique_emails']) ?></div>
            <div class="stat-label">Unika e-postadresser</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['emails_with_multiple']) ?></div>
            <div class="stat-label">E-poster med flera profiler</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['riders_in_groups'] ?? 0) ?></div>
            <div class="stat-label">Deltagare i grupper</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-lg">
        <div class="card-body">
            <form method="GET" class="filter-form">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>Sök e-post</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($searchEmail) ?>"
                               placeholder="namn@example.com" class="admin-form-input">
                    </div>
                    <div class="filter-group">
                        <label>Min antal profiler</label>
                        <select name="min" class="admin-form-select">
                            <option value="2" <?= $minProfiles == 2 ? 'selected' : '' ?>>2+</option>
                            <option value="3" <?= $minProfiles == 3 ? 'selected' : '' ?>>3+</option>
                            <option value="4" <?= $minProfiles == 4 ? 'selected' : '' ?>>4+</option>
                            <option value="5" <?= $minProfiles == 5 ? 'selected' : '' ?>>5+</option>
                        </select>
                    </div>
                    <div class="filter-group filter-actions">
                        <button type="submit" class="btn-admin btn-admin-primary">
                            <i data-lucide="search"></i> Filtrera
                        </button>
                        <a href="?" class="btn-admin btn-admin-secondary">Rensa</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Results -->
    <div class="card">
        <div class="card-header">
            <h3>E-postgrupper (<?= count($emailGroups) ?>)</h3>
        </div>
        <div class="card-body">
            <?php if (empty($emailGroups)): ?>
                <p class="text-secondary">Inga e-postadresser med <?= $minProfiles ?>+ profiler hittades.</p>
            <?php else: ?>
                <div class="email-groups">
                    <?php foreach ($emailGroups as $group): ?>
                        <?php
                        // Get full rider details for this group
                        $riderIds = explode(',', $group['rider_ids']);
                        $riders = $db->getAll(
                            "SELECT r.*, c.name as club_name,
                                    (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count
                             FROM riders r
                             LEFT JOIN clubs c ON r.club_id = c.id
                             WHERE r.id IN (" . implode(',', array_fill(0, count($riderIds), '?')) . ")
                             ORDER BY r.birth_year DESC",
                            $riderIds
                        );
                        ?>
                        <div class="email-group <?= $group['has_password'] ? 'has-primary' : 'no-primary' ?>">
                            <div class="email-group-header">
                                <div class="email-info">
                                    <span class="email-address"><?= htmlspecialchars($group['email']) ?></span>
                                    <span class="profile-count"><?= $group['profile_count'] ?> profiler</span>
                                    <?php if ($group['has_password']): ?>
                                        <span class="badge badge-success">Har huvudprofil</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Saknar lösenord</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="email-group-riders">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th>Namn</th>
                                            <th>Födelseår</th>
                                            <th>Klubb</th>
                                            <th>Resultat</th>
                                            <th>Status</th>
                                            <th>Åtgärd</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($riders as $rider): ?>
                                            <?php $hasPwd = !empty($rider['password']); ?>
                                            <tr class="<?= $hasPwd ? 'is-primary' : '' ?>">
                                                <td>
                                                    <a href="/admin/rider-edit.php?id=<?= $rider['id'] ?>">
                                                        <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?>
                                                    </a>
                                                    <?php if ($rider['gender']): ?>
                                                        <span class="text-muted text-xs">(<?= $rider['gender'] ?>)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= $rider['birth_year'] ?: '-' ?>
                                                    <?php if ($rider['birth_year']): ?>
                                                        <span class="text-muted text-xs">(<?= date('Y') - $rider['birth_year'] ?> år)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($rider['club_name'] ?? '-') ?></td>
                                                <td><?= $rider['result_count'] ?></td>
                                                <td>
                                                    <?php if ($hasPwd): ?>
                                                        <span class="badge badge-success">
                                                            <i data-lucide="key" class="icon-xs"></i> Huvudprofil
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!$hasPwd): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="rider_id" value="<?= $rider['id'] ?>">
                                                            <input type="hidden" name="email" value="<?= htmlspecialchars($group['email']) ?>">
                                                            <button type="submit" name="set_primary" class="btn-admin btn-admin-sm btn-admin-primary"
                                                                    onclick="return confirm('Skapa lösenordsåterställning för <?= htmlspecialchars($rider['firstname']) ?>?')">
                                                                <i data-lucide="user-check"></i> Gör huvudprofil
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-success text-sm">
                                                            <i data-lucide="check"></i> Aktiv
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-md);
}

.stat-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-accent);
}

.stat-label {
    color: var(--color-text-secondary);
    font-size: 0.85rem;
    margin-top: var(--space-xs);
}

.filter-form .filter-row {
    display: flex;
    gap: var(--space-md);
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.filter-group label {
    font-size: 0.85rem;
    color: var(--color-text-secondary);
}

.filter-actions {
    flex-direction: row;
    gap: var(--space-sm);
}

.email-groups {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
}

.email-group {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.email-group.no-primary {
    border-color: var(--color-warning);
}

.email-group.has-primary {
    border-color: var(--color-success);
}

.email-group-header {
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border-bottom: 1px solid var(--color-border);
}

.email-info {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex-wrap: wrap;
}

.email-address {
    font-weight: 600;
    font-size: 1.1rem;
}

.profile-count {
    color: var(--color-text-secondary);
    font-size: 0.9rem;
}

.email-group-riders {
    padding: var(--space-sm);
}

.email-group-riders .admin-table {
    margin: 0;
}

tr.is-primary {
    background: rgba(16, 185, 129, 0.05);
}

@media (max-width: 768px) {
    .email-group-riders {
        overflow-x: auto;
    }

    .filter-form .filter-row {
        flex-direction: column;
    }

    .filter-group {
        width: 100%;
    }
}
</style>

<script>
lucide.createIcons();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
