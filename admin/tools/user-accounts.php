<?php
/**
 * User Accounts Management Tool
 *
 * Manages the user_accounts table and rider profile relationships.
 * Allows:
 * - Migrating existing email-based groups to proper user accounts
 * - Splitting profiles (remove a rider from a user account)
 * - Reassigning profiles (move a rider between user accounts)
 * - Merging user accounts (combine two accounts into one)
 * - Consolidating legacy accounts (fix unlinked same-email groups)
 *
 * @package TheHUB Admin Tools
 */

require_once __DIR__ . '/../../config.php';
require_admin();

$db = getDB();
global $pdo;

// Check if user_accounts table exists
$tableExists = false;
try {
    $result = $pdo->query("SHOW TABLES LIKE 'user_accounts'");
    $tableExists = $result->rowCount() > 0;
} catch (Exception $e) {
    // Table doesn't exist
}

// Check if user_account_id column exists on riders
$columnExists = false;
try {
    $result = $pdo->query("SHOW COLUMNS FROM riders LIKE 'user_account_id'");
    $columnExists = $result->rowCount() > 0;
} catch (Exception $e) {
    // Column doesn't exist
}

$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists && $columnExists) {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        // =========================================================
        // ACTION: Migrate existing email-based groups to user_accounts
        // =========================================================
        case 'migrate':
            try {
                $pdo->beginTransaction();

                // Find all unique emails that have a password set
                $emails = $pdo->query("
                    SELECT DISTINCT email
                    FROM riders
                    WHERE email IS NOT NULL
                      AND email != ''
                      AND active = 1
                    ORDER BY email
                ")->fetchAll(PDO::FETCH_COLUMN);

                $created = 0;
                $linked = 0;
                $skipped = 0;

                foreach ($emails as $email) {
                    $email = trim($email);
                    if (empty($email)) continue;

                    // Check if user_account already exists for this email
                    $existing = $pdo->prepare("SELECT id FROM user_accounts WHERE email = ?");
                    $existing->execute([$email]);
                    $accountId = $existing->fetchColumn();

                    if (!$accountId) {
                        // Find the primary rider (one with password set)
                        $primaryRider = $pdo->prepare("
                            SELECT id, password, last_login, remember_token, remember_token_expires,
                                   password_reset_token, password_reset_expires
                            FROM riders
                            WHERE email = ? AND password IS NOT NULL AND password != ''
                            ORDER BY last_login DESC
                            LIMIT 1
                        ");
                        $primaryRider->execute([$email]);
                        $primary = $primaryRider->fetch(PDO::FETCH_ASSOC);

                        // Create user account
                        $insert = $pdo->prepare("
                            INSERT INTO user_accounts (email, password_hash, remember_token, remember_token_expires,
                                                       password_reset_token, password_reset_expires, last_login, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                        ");
                        $insert->execute([
                            $email,
                            $primary ? $primary['password'] : null,
                            $primary ? $primary['remember_token'] : null,
                            $primary ? $primary['remember_token_expires'] : null,
                            $primary ? $primary['password_reset_token'] : null,
                            $primary ? $primary['password_reset_expires'] : null,
                            $primary ? $primary['last_login'] : null,
                        ]);
                        $accountId = $pdo->lastInsertId();
                        $created++;
                    } else {
                        $skipped++;
                    }

                    // Link all riders with this email to the user account
                    $update = $pdo->prepare("
                        UPDATE riders SET user_account_id = ?
                        WHERE email = ? AND (user_account_id IS NULL OR user_account_id != ?)
                    ");
                    $update->execute([$accountId, $email, $accountId]);
                    $linked += $update->rowCount();
                }

                $pdo->commit();
                $message = "Migration klar: {$created} nya konton, {$linked} profiler kopplade, {$skipped} redan existerande.";
                $messageType = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Fel vid migration: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;

        // =========================================================
        // ACTION: Split a rider from their user account
        // =========================================================
        case 'split':
            try {
                $riderId = (int)($_POST['rider_id'] ?? 0);
                if (!$riderId) throw new Exception("Rider ID saknas");

                $rider = $db->getRow("SELECT id, firstname, lastname, email, user_account_id FROM riders WHERE id = ?", [$riderId]);
                if (!$rider) throw new Exception("Deltagare hittades inte");
                if (!$rider['user_account_id']) throw new Exception("Deltagaren har inget kopplat konto");

                // Check how many riders are on this account
                $count = $db->getRow("SELECT COUNT(*) as cnt FROM riders WHERE user_account_id = ?", [$rider['user_account_id']]);
                if ($count['cnt'] <= 1) throw new Exception("Kan inte splittra - det finns bara en profil pa kontot");

                $pdo->beginTransaction();

                // Create a new user account for this rider
                $newEmail = $rider['email'] . '.split.' . $riderId;
                $stmt = $pdo->prepare("
                    INSERT INTO user_accounts (email, status, notes)
                    VALUES (?, 'pending', ?)
                ");
                $stmt->execute([$newEmail, "Splittat fran konto #{$rider['user_account_id']} - tilldelad {$rider['firstname']} {$rider['lastname']}. Andrar e-post manuellt."]);
                $newAccountId = $pdo->lastInsertId();

                // Move rider to new account
                $stmt = $pdo->prepare("UPDATE riders SET user_account_id = ? WHERE id = ?");
                $stmt->execute([$newAccountId, $riderId]);

                // Also clear the old linked_to_rider_id
                $stmt = $pdo->prepare("UPDATE riders SET linked_to_rider_id = NULL WHERE id = ?");
                $stmt->execute([$riderId]);

                $pdo->commit();
                $message = "Profil '{$rider['firstname']} {$rider['lastname']}' har splittrats till nytt konto #{$newAccountId}. Uppdatera e-post manuellt.";
                $messageType = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Fel vid splittring: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;

        // =========================================================
        // ACTION: Reassign a rider to a different user account
        // =========================================================
        case 'reassign':
            try {
                $riderId = (int)($_POST['rider_id'] ?? 0);
                $targetAccountId = (int)($_POST['target_account_id'] ?? 0);
                if (!$riderId || !$targetAccountId) throw new Exception("Rider ID och malkonto kravs");

                $rider = $db->getRow("SELECT id, firstname, lastname FROM riders WHERE id = ?", [$riderId]);
                if (!$rider) throw new Exception("Deltagare hittades inte");

                $targetAccount = $db->getRow("SELECT id, email FROM user_accounts WHERE id = ?", [$targetAccountId]);
                if (!$targetAccount) throw new Exception("Malkonto hittades inte");

                $pdo->beginTransaction();

                // Move rider
                $stmt = $pdo->prepare("UPDATE riders SET user_account_id = ?, email = ? WHERE id = ?");
                $stmt->execute([$targetAccountId, $targetAccount['email'], $riderId]);

                // Update linked_to_rider_id to match the new group
                $primaryInTarget = $pdo->prepare("
                    SELECT id FROM riders
                    WHERE user_account_id = ? AND id != ? AND password IS NOT NULL AND password != ''
                    ORDER BY last_login DESC LIMIT 1
                ");
                $primaryInTarget->execute([$targetAccountId, $riderId]);
                $primaryId = $primaryInTarget->fetchColumn();

                if ($primaryId) {
                    $stmt = $pdo->prepare("UPDATE riders SET linked_to_rider_id = ? WHERE id = ?");
                    $stmt->execute([$primaryId, $riderId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE riders SET linked_to_rider_id = NULL WHERE id = ?");
                    $stmt->execute([$riderId]);
                }

                $pdo->commit();
                $message = "'{$rider['firstname']} {$rider['lastname']}' har flyttats till konto #{$targetAccountId} ({$targetAccount['email']}).";
                $messageType = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Fel vid flytt: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;

        // =========================================================
        // ACTION: Merge two user accounts
        // =========================================================
        case 'merge':
            try {
                $sourceAccountId = (int)($_POST['source_account_id'] ?? 0);
                $targetAccountId = (int)($_POST['target_account_id'] ?? 0);
                if (!$sourceAccountId || !$targetAccountId) throw new Exception("Bade kallkonto och malkonto kravs");
                if ($sourceAccountId === $targetAccountId) throw new Exception("Kallkonto och malkonto kan inte vara samma");

                $source = $db->getRow("SELECT * FROM user_accounts WHERE id = ?", [$sourceAccountId]);
                $target = $db->getRow("SELECT * FROM user_accounts WHERE id = ?", [$targetAccountId]);
                if (!$source || !$target) throw new Exception("Ett av kontona hittades inte");

                $pdo->beginTransaction();

                // Move all riders from source to target
                $stmt = $pdo->prepare("UPDATE riders SET user_account_id = ?, email = ? WHERE user_account_id = ?");
                $stmt->execute([$targetAccountId, $target['email'], $sourceAccountId]);
                $moved = $stmt->rowCount();

                // Copy password if target doesn't have one
                if (empty($target['password_hash']) && !empty($source['password_hash'])) {
                    $stmt = $pdo->prepare("UPDATE user_accounts SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$source['password_hash'], $targetAccountId]);
                }

                // Disable the source account
                $stmt = $pdo->prepare("UPDATE user_accounts SET status = 'disabled', notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?");
                $stmt->execute(["\nSammanfogat med konto #{$targetAccountId} (" . date('Y-m-d H:i') . ")", $sourceAccountId]);

                $pdo->commit();
                $message = "Konto #{$sourceAccountId} sammanfogat med #{$targetAccountId}. {$moved} profiler flyttade.";
                $messageType = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Fel vid sammanslagning: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;

        // =========================================================
        // ACTION: Consolidate legacy accounts (fix linked_to_rider_id)
        // =========================================================
        case 'consolidate':
            try {
                $pdo->beginTransaction();

                // Find all email groups where riders share email but linked_to_rider_id is not set properly
                $groups = $pdo->query("
                    SELECT email, GROUP_CONCAT(id ORDER BY
                        CASE WHEN password IS NOT NULL AND password != '' THEN 0 ELSE 1 END,
                        last_login DESC,
                        id ASC
                    ) as rider_ids
                    FROM riders
                    WHERE email IS NOT NULL AND email != '' AND active = 1
                    GROUP BY email
                    HAVING COUNT(*) >= 2
                ")->fetchAll(PDO::FETCH_ASSOC);

                $fixed = 0;
                foreach ($groups as $group) {
                    $ids = explode(',', $group['rider_ids']);
                    $primaryId = (int)$ids[0]; // First one has password or latest login

                    foreach ($ids as $i => $id) {
                        $id = (int)$id;
                        if ($i === 0) {
                            // Primary: ensure linked_to_rider_id is NULL
                            $stmt = $pdo->prepare("UPDATE riders SET linked_to_rider_id = NULL WHERE id = ? AND linked_to_rider_id IS NOT NULL");
                            $stmt->execute([$id]);
                            $fixed += $stmt->rowCount();
                        } else {
                            // Secondary: ensure linked_to_rider_id points to primary
                            $stmt = $pdo->prepare("UPDATE riders SET linked_to_rider_id = ? WHERE id = ? AND (linked_to_rider_id IS NULL OR linked_to_rider_id != ?)");
                            $stmt->execute([$primaryId, $id, $primaryId]);
                            $fixed += $stmt->rowCount();
                        }
                    }
                }

                $pdo->commit();
                $message = "Konsolidering klar: {$fixed} profiler fick uppdaterad linked_to_rider_id.";
                $messageType = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Fel vid konsolidering: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
    }
}

// =========================================================
// GATHER STATISTICS
// =========================================================
$stats = [
    'total_user_accounts' => 0,
    'accounts_with_password' => 0,
    'accounts_without_password' => 0,
    'disabled_accounts' => 0,
    'riders_linked' => 0,
    'riders_unlinked' => 0,
    'legacy_groups' => 0,
    'legacy_riders' => 0,
];

if ($tableExists && $columnExists) {
    try {
        $s = $pdo->query("SELECT COUNT(*) FROM user_accounts WHERE status = 'active'")->fetchColumn();
        $stats['total_user_accounts'] = (int)$s;

        $s = $pdo->query("SELECT COUNT(*) FROM user_accounts WHERE password_hash IS NOT NULL AND password_hash != '' AND status = 'active'")->fetchColumn();
        $stats['accounts_with_password'] = (int)$s;

        $s = $pdo->query("SELECT COUNT(*) FROM user_accounts WHERE (password_hash IS NULL OR password_hash = '') AND status = 'active'")->fetchColumn();
        $stats['accounts_without_password'] = (int)$s;

        $s = $pdo->query("SELECT COUNT(*) FROM user_accounts WHERE status = 'disabled'")->fetchColumn();
        $stats['disabled_accounts'] = (int)$s;

        $s = $pdo->query("SELECT COUNT(*) FROM riders WHERE user_account_id IS NOT NULL AND active = 1")->fetchColumn();
        $stats['riders_linked'] = (int)$s;

        $s = $pdo->query("SELECT COUNT(*) FROM riders WHERE user_account_id IS NULL AND email IS NOT NULL AND email != '' AND active = 1")->fetchColumn();
        $stats['riders_unlinked'] = (int)$s;
    } catch (Exception $e) {
        // Stats error, silently skip
    }
}

// Count legacy groups (same email, no linking)
try {
    $s = $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT email FROM riders
            WHERE email IS NOT NULL AND email != '' AND active = 1
              AND linked_to_rider_id IS NULL
            GROUP BY email
            HAVING COUNT(*) >= 2
              AND MAX(CASE WHEN password IS NOT NULL AND password != '' THEN 1 ELSE 0 END) > 0
        ) sub
    ")->fetchColumn();
    $stats['legacy_groups'] = (int)$s;

    $s = $pdo->query("
        SELECT COALESCE(SUM(cnt), 0) FROM (
            SELECT COUNT(*) as cnt FROM riders
            WHERE email IS NOT NULL AND email != '' AND active = 1
              AND linked_to_rider_id IS NULL
            GROUP BY email
            HAVING COUNT(*) >= 2
              AND MAX(CASE WHEN password IS NOT NULL AND password != '' THEN 1 ELSE 0 END) > 0
        ) sub
    ")->fetchColumn();
    $stats['legacy_riders'] = (int)$s;
} catch (Exception $e) {
    // Ignore
}

// =========================================================
// LOAD ACCOUNTS LIST (if table exists)
// =========================================================
$accounts = [];
$searchQuery = trim($_GET['search'] ?? '');
$filterType = $_GET['filter'] ?? 'all';
$viewAccountId = (int)($_GET['account'] ?? 0);

if ($tableExists && $columnExists) {
    $sql = "
        SELECT ua.*,
               (SELECT COUNT(*) FROM riders WHERE user_account_id = ua.id AND active = 1) as rider_count
        FROM user_accounts ua
        WHERE 1=1
    ";
    $params = [];

    if ($searchQuery) {
        $sql .= " AND ua.email LIKE ?";
        $params[] = "%{$searchQuery}%";
    }

    switch ($filterType) {
        case 'multi':
            $sql .= " HAVING rider_count >= 2";
            break;
        case 'single':
            $sql .= " HAVING rider_count = 1";
            break;
        case 'empty':
            $sql .= " HAVING rider_count = 0";
            break;
        case 'no_password':
            $sql .= " AND (ua.password_hash IS NULL OR ua.password_hash = '')";
            break;
        case 'disabled':
            $sql .= " AND ua.status = 'disabled'";
            break;
    }

    $sql .= " ORDER BY rider_count DESC, ua.email LIMIT 200";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Query error
    }
}

// If viewing a specific account, load its riders
$accountDetail = null;
$accountRiders = [];
if ($viewAccountId && $tableExists) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_accounts WHERE id = ?");
        $stmt->execute([$viewAccountId]);
        $accountDetail = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($accountDetail) {
            $stmt = $pdo->prepare("
                SELECT r.*, c.name as club_name,
                       (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count,
                       (SELECT COUNT(*) FROM event_registrations WHERE rider_id = r.id AND status != 'cancelled') as reg_count
                FROM riders r
                LEFT JOIN clubs c ON r.club_id = c.id
                WHERE r.user_account_id = ? AND r.active = 1
                ORDER BY r.birth_year DESC, r.lastname
            ");
            $stmt->execute([$viewAccountId]);
            $accountRiders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Error loading detail
    }
}

// =========================================================
// PAGE SETUP
// =========================================================
$page_title = 'Anvandarkonton';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Anvandarkonton']
];

include __DIR__ . '/../components/unified-layout.php';
?>

<style>
.ua-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.ua-stat-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}
.ua-stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--color-accent);
}
.ua-stat-value.warning { color: var(--color-warning); }
.ua-stat-value.danger { color: var(--color-error); }
.ua-stat-value.success { color: var(--color-success); }
.ua-stat-label {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    margin-top: var(--space-2xs);
}

.action-buttons {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
    margin-bottom: var(--space-xl);
}

.account-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}
.account-row {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: border-color 0.15s ease;
}
.account-row:hover {
    border-color: var(--color-accent);
}
.account-email {
    font-weight: 600;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.account-meta {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex-shrink: 0;
}
.account-meta .badge {
    font-size: var(--text-xs);
}

.rider-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-sm);
}
.rider-card-info {
    flex: 1;
    min-width: 0;
}
.rider-card-name {
    font-weight: 600;
    color: var(--color-text-primary);
}
.rider-card-meta {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    display: flex;
    gap: var(--space-md);
    flex-wrap: wrap;
    margin-top: var(--space-2xs);
}
.rider-card-actions {
    display: flex;
    gap: var(--space-xs);
    flex-shrink: 0;
}

.filter-bar {
    display: flex;
    gap: var(--space-md);
    flex-wrap: wrap;
    align-items: flex-end;
    margin-bottom: var(--space-lg);
}
.filter-bar .filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2xs);
}
.filter-bar label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    font-weight: 600;
}
.filter-bar .filter-actions {
    display: flex;
    gap: var(--space-xs);
}

.detail-header {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
}
.detail-header h2 {
    margin: 0;
    flex: 1;
}
.detail-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}
.detail-meta-item {
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-surface);
    border-radius: var(--radius-sm);
}
.detail-meta-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    text-transform: uppercase;
}
.detail-meta-value {
    font-weight: 600;
    color: var(--color-text-primary);
}

.merge-form {
    display: flex;
    gap: var(--space-md);
    align-items: flex-end;
    flex-wrap: wrap;
}

@media (max-width: 767px) {
    .account-row {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-sm);
        margin-left: calc(-1 * var(--container-padding, 16px));
        margin-right: calc(-1 * var(--container-padding, 16px));
        border-radius: 0;
        border-left: none;
        border-right: none;
    }
    .account-meta {
        width: 100%;
        flex-wrap: wrap;
    }
    .filter-bar {
        flex-direction: column;
    }
    .filter-bar .filter-group {
        width: 100%;
    }
    .rider-card {
        flex-direction: column;
        align-items: flex-start;
    }
    .rider-card-actions {
        width: 100%;
    }
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-triangle' ?>"></i>
        <div><?= htmlspecialchars($message) ?></div>
    </div>
<?php endif; ?>

<?php if (!$tableExists || !$columnExists): ?>
    <!-- Migration not yet run -->
    <div class="card">
        <div class="card-header">
            <h3><i data-lucide="database"></i> Migration kravs</h3>
        </div>
        <div class="card-body">
            <p>Tabellen <code>user_accounts</code> <?= $tableExists ? 'finns' : 'saknas' ?>.
               Kolumnen <code>riders.user_account_id</code> <?= $columnExists ? 'finns' : 'saknas' ?>.</p>
            <p>Kor migration <strong>035_user_accounts.sql</strong> via
                <a href="/admin/migrations.php">Databasmigrationer</a> innan du kan anvanda detta verktyg.
            </p>
        </div>
    </div>
<?php elseif ($viewAccountId && $accountDetail): ?>
    <!-- =========================================================
         ACCOUNT DETAIL VIEW
         ========================================================= -->
    <div class="detail-header">
        <a href="?<?= $searchQuery ? 'search=' . urlencode($searchQuery) . '&' : '' ?>filter=<?= urlencode($filterType) ?>" class="btn btn-secondary">
            <i data-lucide="arrow-left"></i> Tillbaka
        </a>
        <h2>Konto #<?= $accountDetail['id'] ?>: <?= htmlspecialchars($accountDetail['email']) ?></h2>
        <?php if ($accountDetail['status'] === 'disabled'): ?>
            <span class="badge badge-danger">Inaktiverat</span>
        <?php elseif ($accountDetail['status'] === 'pending'): ?>
            <span class="badge badge-warning">Vantande</span>
        <?php else: ?>
            <span class="badge badge-success">Aktivt</span>
        <?php endif; ?>
    </div>

    <!-- Account metadata -->
    <div class="detail-meta-grid">
        <div class="detail-meta-item">
            <div class="detail-meta-label">Losenord</div>
            <div class="detail-meta-value"><?= !empty($accountDetail['password_hash']) ? 'Ja' : 'Nej' ?></div>
        </div>
        <div class="detail-meta-item">
            <div class="detail-meta-label">Senaste inloggning</div>
            <div class="detail-meta-value"><?= $accountDetail['last_login'] ? date('Y-m-d H:i', strtotime($accountDetail['last_login'])) : '-' ?></div>
        </div>
        <div class="detail-meta-item">
            <div class="detail-meta-label">Status</div>
            <div class="detail-meta-value"><?= ucfirst($accountDetail['status']) ?></div>
        </div>
        <div class="detail-meta-item">
            <div class="detail-meta-label">Skapad</div>
            <div class="detail-meta-value"><?= date('Y-m-d', strtotime($accountDetail['created_at'])) ?></div>
        </div>
    </div>

    <?php if ($accountDetail['notes']): ?>
    <div class="alert alert-info mb-lg">
        <i data-lucide="info"></i>
        <div><strong>Anteckningar:</strong><br><?= nl2br(htmlspecialchars($accountDetail['notes'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Linked riders -->
    <div class="card mb-lg">
        <div class="card-header">
            <h3><i data-lucide="users"></i> Kopplade profiler (<?= count($accountRiders) ?>)</h3>
        </div>
        <div class="card-body">
            <?php if (empty($accountRiders)): ?>
                <p class="text-secondary">Inga profiler kopplade till detta konto.</p>
            <?php else: ?>
                <?php foreach ($accountRiders as $rider): ?>
                    <div class="rider-card">
                        <div class="rider-card-info">
                            <div class="rider-card-name">
                                <a href="/admin/rider-edit.php?id=<?= $rider['id'] ?>">
                                    <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?>
                                </a>
                                <span class="text-muted text-sm">(ID: <?= $rider['id'] ?>)</span>
                            </div>
                            <div class="rider-card-meta">
                                <?php if ($rider['birth_year']): ?>
                                    <span><i data-lucide="calendar" style="width:14px;height:14px;display:inline;vertical-align:middle;"></i> <?= $rider['birth_year'] ?> (<?= date('Y') - $rider['birth_year'] ?> ar)</span>
                                <?php endif; ?>
                                <?php if ($rider['club_name']): ?>
                                    <span><i data-lucide="shield" style="width:14px;height:14px;display:inline;vertical-align:middle;"></i> <?= htmlspecialchars($rider['club_name']) ?></span>
                                <?php endif; ?>
                                <span><?= $rider['result_count'] ?> resultat</span>
                                <span><?= $rider['reg_count'] ?> anmalningar</span>
                            </div>
                        </div>
                        <div class="rider-card-actions">
                            <?php if (count($accountRiders) > 1): ?>
                                <form method="POST" onsubmit="return confirm('Splittra denna profil till eget konto?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="split">
                                    <input type="hidden" name="rider_id" value="<?= $rider['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning">
                                        <i data-lucide="scissors"></i> Splittra
                                    </button>
                                </form>
                            <?php endif; ?>
                            <a href="/admin/rider-edit.php?id=<?= $rider['id'] ?>" class="btn btn-sm btn-secondary">
                                <i data-lucide="pencil"></i> Redigera
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reassign form -->
    <div class="card mb-lg">
        <div class="card-header">
            <h3><i data-lucide="move"></i> Flytta profil till annat konto</h3>
        </div>
        <div class="card-body">
            <form method="POST" class="merge-form" onsubmit="return confirm('Flytta vald profil till annat konto?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reassign">
                <div class="filter-group">
                    <label>Profil att flytta</label>
                    <select name="rider_id" class="form-select" required>
                        <option value="">Valj profil...</option>
                        <?php foreach ($accountRiders as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['firstname'] . ' ' . $r['lastname']) ?> (<?= $r['id'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Mal-konto ID</label>
                    <input type="number" name="target_account_id" class="form-input" placeholder="Konto-ID" required min="1" style="width: 120px;">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="move"></i> Flytta
                </button>
            </form>
        </div>
    </div>

    <!-- Merge form -->
    <div class="card">
        <div class="card-header">
            <h3><i data-lucide="merge"></i> Sammanfoga med annat konto</h3>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-md">Flyttar alla profiler fran detta konto till malkontot och inaktiverar detta konto.</p>
            <form method="POST" class="merge-form" onsubmit="return confirm('Sammanfoga konto #<?= $accountDetail['id'] ?> med malkontot? Alla profiler flyttas.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="merge">
                <input type="hidden" name="source_account_id" value="<?= $accountDetail['id'] ?>">
                <div class="filter-group">
                    <label>Mal-konto ID</label>
                    <input type="number" name="target_account_id" class="form-input" placeholder="Konto-ID" required min="1" style="width: 120px;">
                </div>
                <button type="submit" class="btn btn-danger">
                    <i data-lucide="merge"></i> Sammanfoga
                </button>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- =========================================================
         MAIN LIST VIEW
         ========================================================= -->

    <!-- Statistics -->
    <div class="ua-stats-grid">
        <div class="ua-stat-card">
            <div class="ua-stat-value"><?= number_format($stats['total_user_accounts']) ?></div>
            <div class="ua-stat-label">Aktiva konton</div>
        </div>
        <div class="ua-stat-card">
            <div class="ua-stat-value success"><?= number_format($stats['accounts_with_password']) ?></div>
            <div class="ua-stat-label">Med losenord</div>
        </div>
        <div class="ua-stat-card">
            <div class="ua-stat-value"><?= number_format($stats['riders_linked']) ?></div>
            <div class="ua-stat-label">Kopplade profiler</div>
        </div>
        <div class="ua-stat-card">
            <div class="ua-stat-value warning"><?= number_format($stats['riders_unlinked']) ?></div>
            <div class="ua-stat-label">Ej kopplade</div>
        </div>
        <div class="ua-stat-card">
            <div class="ua-stat-value danger"><?= number_format($stats['legacy_groups']) ?></div>
            <div class="ua-stat-label">Legacy-grupper</div>
        </div>
    </div>

    <!-- Action buttons -->
    <div class="action-buttons">
        <?php if ($stats['riders_unlinked'] > 0 || $stats['total_user_accounts'] === 0): ?>
            <form method="POST" onsubmit="return confirm('Skapa user_accounts fran befintlig data? Befintliga konton paverkas inte.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="migrate">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="database"></i> Migrera e-post till konton
                </button>
            </form>
        <?php endif; ?>

        <?php if ($stats['legacy_groups'] > 0): ?>
            <form method="POST" onsubmit="return confirm('Konsolidera <?= $stats['legacy_groups'] ?> legacy-grupper? Satter linked_to_rider_id korrekt.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="consolidate">
                <button type="submit" class="btn btn-warning">
                    <i data-lucide="link"></i> Konsolidera legacy (<?= $stats['legacy_groups'] ?> grupper)
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <form method="GET" class="filter-bar">
        <div class="filter-group" style="flex: 1; min-width: 200px;">
            <label>Sok e-post</label>
            <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>"
                   placeholder="namn@example.com" class="form-input">
        </div>
        <div class="filter-group">
            <label>Visa</label>
            <select name="filter" class="form-select">
                <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>Alla</option>
                <option value="multi" <?= $filterType === 'multi' ? 'selected' : '' ?>>Flera profiler</option>
                <option value="single" <?= $filterType === 'single' ? 'selected' : '' ?>>Enstaka profil</option>
                <option value="empty" <?= $filterType === 'empty' ? 'selected' : '' ?>>Inga profiler</option>
                <option value="no_password" <?= $filterType === 'no_password' ? 'selected' : '' ?>>Utan losenord</option>
                <option value="disabled" <?= $filterType === 'disabled' ? 'selected' : '' ?>>Inaktiverade</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><i data-lucide="search"></i> Filtrera</button>
            <a href="?" class="btn btn-secondary">Rensa</a>
        </div>
    </form>

    <!-- Account list -->
    <?php if ($stats['total_user_accounts'] === 0 && !$tableExists): ?>
        <div class="card">
            <div class="card-body" style="text-align: center; padding: var(--space-3xl);">
                <i data-lucide="users" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
                <h3>Inga anvandarkonton</h3>
                <p class="text-secondary">Kor migrationen och klicka sedan "Migrera e-post till konton" for att skapa konton fran befintlig data.</p>
            </div>
        </div>
    <?php elseif (empty($accounts)): ?>
        <div class="card">
            <div class="card-body" style="text-align: center; padding: var(--space-xl);">
                <p class="text-secondary">Inga konton matchar filtret. <?php if ($stats['total_user_accounts'] === 0): ?>Klicka "Migrera e-post till konton" for att komma igang.<?php endif; ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h3>Anvandarkonton (<?= count($accounts) ?><?= count($accounts) >= 200 ? '+' : '' ?>)</h3>
            </div>
            <div class="card-body">
                <div class="account-list">
                    <?php foreach ($accounts as $acc): ?>
                        <a href="?account=<?= $acc['id'] ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>&filter=<?= urlencode($filterType) ?>" class="account-row">
                            <span class="account-email"><?= htmlspecialchars($acc['email']) ?></span>
                            <div class="account-meta">
                                <span class="badge <?= $acc['rider_count'] >= 2 ? 'badge-info' : '' ?>"><?= $acc['rider_count'] ?> profiler</span>
                                <?php if (!empty($acc['password_hash'])): ?>
                                    <span class="badge badge-success">Losenord</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Inget losenord</span>
                                <?php endif; ?>
                                <?php if ($acc['status'] === 'disabled'): ?>
                                    <span class="badge badge-danger">Inaktiv</span>
                                <?php elseif ($acc['status'] === 'pending'): ?>
                                    <span class="badge badge-warning">Vantande</span>
                                <?php endif; ?>
                                <?php if ($acc['last_login']): ?>
                                    <span class="text-muted text-sm"><?= date('Y-m-d', strtotime($acc['last_login'])) ?></span>
                                <?php endif; ?>
                                <i data-lucide="chevron-right" style="width:16px; height:16px; color: var(--color-text-muted);"></i>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
