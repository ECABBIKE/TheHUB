<?php
/**
 * Admin Rider Claims - Review and approve profile merge requests
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mail.php';
require_admin();

$db = getDB();

$message = '';
$messageType = 'info';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';
    $claimId = (int)($_POST['claim_id'] ?? 0);

    if ($claimId > 0) {
        $claim = $db->getRow("SELECT * FROM rider_claims WHERE id = ?", [$claimId]);

        if ($claim) {
            if ($action === 'approve') {
                // Approve and merge: move all data from claimant to target, then delete claimant
                try {
                    $pdo = $db->getPdo();
                    $pdo->beginTransaction();

                    $claimantId = $claim['claimant_rider_id'];
                    $targetId = $claim['target_rider_id'];

                    // Get both riders
                    $claimant = $db->getRow("SELECT * FROM riders WHERE id = ?", [$claimantId]);
                    $target = $db->getRow("SELECT * FROM riders WHERE id = ?", [$targetId]);

                    if (!$claimant || !$target) {
                        throw new Exception("En av profilerna kunde inte hittas");
                    }

                    // Update target with data from claimant (email, phone etc)
                    $updates = [];
                    if (empty($target['email']) && !empty($claimant['email'])) {
                        $updates['email'] = $claimant['email'];
                    }
                    if (empty($target['phone']) && !empty($claimant['phone'])) {
                        $updates['phone'] = $claimant['phone'];
                    }
                    if (empty($target['birth_year']) && !empty($claimant['birth_year'])) {
                        $updates['birth_year'] = $claimant['birth_year'];
                    }
                    if (empty($target['gender']) && !empty($claimant['gender'])) {
                        $updates['gender'] = $claimant['gender'];
                    }
                    if (empty($target['nationality']) && !empty($claimant['nationality'])) {
                        $updates['nationality'] = $claimant['nationality'];
                    }
                    // Prefer real UCI ID over SWE-generated
                    if (!empty($claimant['license_number']) && strpos($claimant['license_number'], 'SWE') !== 0) {
                        if (empty($target['license_number']) || strpos($target['license_number'], 'SWE') === 0) {
                            $updates['license_number'] = $claimant['license_number'];
                        }
                    }

                    if (!empty($updates)) {
                        $db->update('riders', $updates, 'id = ?', [$targetId]);
                    }

                    // Move results from claimant to target
                    $resultsToMove = $db->getAll("SELECT id, event_id, class_id FROM results WHERE cyclist_id = ?", [$claimantId]);
                    $moved = 0;
                    $skipped = 0;

                    foreach ($resultsToMove as $result) {
                        // Check if target already has result for same event/class
                        $existing = $db->getRow(
                            "SELECT id FROM results WHERE cyclist_id = ? AND event_id = ? AND class_id <=> ?",
                            [$targetId, $result['event_id'], $result['class_id']]
                        );

                        if (!$existing) {
                            $db->update('results', ['cyclist_id' => $targetId], 'id = ?', [$result['id']]);
                            $moved++;
                        } else {
                            $skipped++;
                        }
                    }

                    // Move rider_club_seasons
                    $db->query(
                        "UPDATE IGNORE rider_club_seasons SET rider_id = ? WHERE rider_id = ?",
                        [$targetId, $claimantId]
                    );

                    // Update claim status
                    $db->update('rider_claims', [
                        'status' => 'approved',
                        'reviewed_by' => $_SESSION['admin_id'],
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'admin_notes' => "Merged: {$moved} results moved, {$skipped} skipped"
                    ], 'id = ?', [$claimId]);

                    // Delete the claimant profile
                    $db->delete('riders', 'id = ?', [$claimantId]);

                    $pdo->commit();

                    // Send password reset email to the merged profile
                    $emailSent = false;
                    $targetEmail = $updates['email'] ?? $target['email'] ?? '';
                    if (!empty($targetEmail)) {
                        // Generate password reset token
                        $token = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

                        $db->query(
                            "UPDATE riders SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?",
                            [$token, $expires, $targetId]
                        );

                        $resetLink = 'https://thehub.gravityseries.se/reset-password?token=' . $token;
                        $riderName = trim($target['firstname'] . ' ' . $target['lastname']);

                        $emailSent = hub_send_password_reset_email($targetEmail, $riderName, $resetLink);
                    }

                    $emailStatus = $emailSent ? ' Mail med lösenordslänk skickat!' : '';
                    $message = "Profiler sammanslagna! {$claimant['firstname']} {$claimant['lastname']} → {$target['firstname']} {$target['lastname']}. {$moved} resultat flyttade.{$emailStatus}";
                    $messageType = 'success';

                } catch (Exception $e) {
                    if (isset($pdo)) $pdo->rollBack();
                    $message = 'Fel vid sammanslagning: ' . $e->getMessage();
                    $messageType = 'error';
                }

            } elseif ($action === 'reject') {
                $adminNotes = trim($_POST['admin_notes'] ?? '');

                $db->update('rider_claims', [
                    'status' => 'rejected',
                    'reviewed_by' => $_SESSION['admin_id'],
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'admin_notes' => $adminNotes
                ], 'id = ?', [$claimId]);

                $message = 'Förfrågan avvisad';
                $messageType = 'info';

            } elseif ($action === 'delete') {
                $db->delete('rider_claims', 'id = ?', [$claimId]);
                $message = 'Förfrågan borttagen';
                $messageType = 'info';
            }
        }
    }
}

// Get pending claims
$pendingClaims = $db->getAll("
    SELECT
        rc.*,
        r_claimant.firstname as claimant_firstname,
        r_claimant.lastname as claimant_lastname,
        r_claimant.email as claimant_email_actual,
        r_claimant.birth_year as claimant_birth_year,
        r_claimant.license_number as claimant_license,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = rc.claimant_rider_id) as claimant_results,
        r_target.firstname as target_firstname,
        r_target.lastname as target_lastname,
        r_target.email as target_email,
        r_target.birth_year as target_birth_year,
        r_target.license_number as target_license,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = rc.target_rider_id) as target_results
    FROM rider_claims rc
    JOIN riders r_claimant ON rc.claimant_rider_id = r_claimant.id
    JOIN riders r_target ON rc.target_rider_id = r_target.id
    WHERE rc.status = 'pending'
    ORDER BY rc.created_at DESC
");

// Get recent resolved claims
$resolvedClaims = $db->getAll("
    SELECT
        rc.*,
        r_claimant.firstname as claimant_firstname,
        r_claimant.lastname as claimant_lastname,
        r_target.firstname as target_firstname,
        r_target.lastname as target_lastname,
        au.full_name as reviewer_name
    FROM rider_claims rc
    LEFT JOIN riders r_claimant ON rc.claimant_rider_id = r_claimant.id
    LEFT JOIN riders r_target ON rc.target_rider_id = r_target.id
    LEFT JOIN admin_users au ON rc.reviewed_by = au.id
    WHERE rc.status != 'pending'
    ORDER BY rc.reviewed_at DESC
    LIMIT 20
");

$page_title = 'Profilförfrågningar';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools'],
    ['label' => 'Profilförfrågningar']
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'danger' : 'info') ?> mb-lg">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<!-- Pending Claims -->
<div class="card mb-lg">
    <div class="card-header">
        <h3><i data-lucide="user-check"></i> Väntande förfrågningar (<?= count($pendingClaims) ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($pendingClaims)): ?>
        <div class="alert alert-success">
            <i data-lucide="check-circle"></i>
            Inga väntande förfrågningar!
        </div>
        <?php else: ?>

        <?php foreach ($pendingClaims as $claim): ?>
        <div class="card mb-md" style="border: 2px solid var(--color-warning);">
            <div class="card-body">
                <div style="display: grid; grid-template-columns: 1fr auto 1fr; gap: var(--space-lg); align-items: start;">

                    <!-- Claimant (new profile with email) -->
                    <div>
                        <h4 class="text-secondary mb-sm">Ny profil (vill behålla)</h4>
                        <p class="mb-xs">
                            <strong>
                                <a href="/admin/rider-edit/<?= $claim['claimant_rider_id'] ?>" target="_blank">
                                    <?= h($claim['claimant_firstname'] . ' ' . $claim['claimant_lastname']) ?>
                                </a>
                            </strong>
                        </p>
                        <p class="text-secondary mb-xs">
                            <i data-lucide="mail" style="width: 14px;"></i>
                            <?= h($claim['claimant_email_actual'] ?: 'Ingen e-post') ?>
                        </p>
                        <p class="text-secondary mb-xs">
                            <i data-lucide="calendar" style="width: 14px;"></i>
                            Född: <?= $claim['claimant_birth_year'] ?: '-' ?>
                        </p>
                        <p class="text-secondary mb-xs">
                            <i data-lucide="trophy" style="width: 14px;"></i>
                            <?= $claim['claimant_results'] ?> resultat
                        </p>
                        <p class="text-secondary">
                            <i data-lucide="id-card" style="width: 14px;"></i>
                            <?= h($claim['claimant_license'] ?: 'Inget UCI') ?>
                        </p>
                    </div>

                    <!-- Arrow -->
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding-top: var(--space-xl);">
                        <i data-lucide="arrow-right" style="width: 32px; height: 32px; color: var(--color-warning);"></i>
                        <span class="text-secondary" style="font-size: 12px;">slå ihop med</span>
                    </div>

                    <!-- Target (historical profile) -->
                    <div>
                        <h4 class="text-secondary mb-sm">Historisk profil (ta över data)</h4>
                        <p class="mb-xs">
                            <strong>
                                <a href="/admin/rider-edit/<?= $claim['target_rider_id'] ?>" target="_blank">
                                    <?= h($claim['target_firstname'] . ' ' . $claim['target_lastname']) ?>
                                </a>
                            </strong>
                        </p>
                        <p class="text-secondary mb-xs">
                            <i data-lucide="mail" style="width: 14px;"></i>
                            <?= h($claim['target_email'] ?: 'Ingen e-post') ?>
                        </p>
                        <p class="text-secondary mb-xs">
                            <i data-lucide="calendar" style="width: 14px;"></i>
                            Född: <?= $claim['target_birth_year'] ?: '-' ?>
                        </p>
                        <p class="text-secondary mb-xs">
                            <i data-lucide="trophy" style="width: 14px;"></i>
                            <strong><?= $claim['target_results'] ?> resultat</strong>
                        </p>
                        <p class="text-secondary">
                            <i data-lucide="id-card" style="width: 14px;"></i>
                            <?= h($claim['target_license'] ?: 'Inget UCI') ?>
                        </p>
                    </div>
                </div>

                <?php if ($claim['reason']): ?>
                <div class="mt-md" style="background: var(--color-bg-secondary); padding: var(--space-sm); border-radius: var(--radius-sm);">
                    <strong>Användarens kommentar:</strong><br>
                    <?= nl2br(h($claim['reason'])) ?>
                </div>
                <?php endif; ?>

                <div class="mt-md" style="display: flex; gap: var(--space-sm); justify-content: flex-end; border-top: 1px solid var(--color-border); padding-top: var(--space-md);">
                    <span class="text-secondary" style="flex: 1;">
                        Skapad: <?= date('Y-m-d H:i', strtotime($claim['created_at'])) ?>
                    </span>

                    <!-- Reject form -->
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Avvisa denna förfrågan?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>">
                        <button type="submit" class="btn btn-secondary">
                            <i data-lucide="x"></i> Avvisa
                        </button>
                    </form>

                    <!-- Approve form -->
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Godkänn och slå ihop profilerna?\n\nDetta kommer:\n- Flytta resultat från ny profil till historisk profil\n- Kopiera e-post/kontaktinfo till historisk profil\n- Ta bort den nya profilen\n\nDetta kan inte ångras!');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>">
                        <button type="submit" class="btn btn-primary">
                            <i data-lucide="check"></i> Godkänn & Slå ihop
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Recent resolved -->
<?php if (!empty($resolvedClaims)): ?>
<div class="card">
    <div class="card-header">
        <h3>Senaste hanterade</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Från</th>
                        <th>Till</th>
                        <th>Status</th>
                        <th>Hanterad av</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resolvedClaims as $claim): ?>
                    <tr>
                        <td><?= date('Y-m-d', strtotime($claim['reviewed_at'])) ?></td>
                        <td><?= h(($claim['claimant_firstname'] ?? '[Raderad]') . ' ' . ($claim['claimant_lastname'] ?? '')) ?></td>
                        <td><?= h(($claim['target_firstname'] ?? '[Raderad]') . ' ' . ($claim['target_lastname'] ?? '')) ?></td>
                        <td>
                            <?php if ($claim['status'] === 'approved'): ?>
                                <span class="badge badge-success">Godkänd</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Avvisad</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($claim['reviewer_name'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
