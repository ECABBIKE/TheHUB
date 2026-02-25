<?php
/**
 * Betalningsmottagare - Admin CRUD
 * Hantera betalningsmottagare (Swish, bank, Stripe)
 */
require_once __DIR__ . '/../config.php';
require_admin();

if (!hasRole('admin')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/admin/dashboard');
}

$db = getDB();

// Check if admin_user_id column exists (migration 059)
$hasAdminUserCol = false;
try {
    $colCheck = $db->getAll("SHOW COLUMNS FROM payment_recipients LIKE 'admin_user_id'");
    $hasAdminUserCol = !empty($colCheck);
} catch (Exception $e) {}

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $orgNumber = trim($_POST['org_number'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $swishNumber = trim($_POST['swish_number'] ?? '');
        $swishName = trim($_POST['swish_name'] ?? '');
        $gatewayType = $_POST['gateway_type'] ?? 'swish';
        $bankgiro = trim($_POST['bankgiro'] ?? '');
        $plusgiro = trim($_POST['plusgiro'] ?? '');
        $bankAccount = trim($_POST['bank_account'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $bankClearing = trim($_POST['bank_clearing'] ?? '');
        $platformFeeType = $_POST['platform_fee_type'] ?? 'percent';
        $platformFeePercent = floatval($_POST['platform_fee_percent'] ?? 2.00);
        $platformFeeFixed = floatval($_POST['platform_fee_fixed'] ?? 0);
        $adminUserId = !empty($_POST['admin_user_id']) ? intval($_POST['admin_user_id']) : null;
        $active = isset($_POST['active']) ? 1 : 0;

        if (empty($name)) {
            $error = 'Namn är obligatoriskt';
        } else {
            $data = [
                'name' => $name,
                'description' => $description,
                'org_number' => $orgNumber ?: null,
                'contact_email' => $contactEmail ?: null,
                'contact_phone' => $contactPhone ?: null,
                'swish_number' => $swishNumber ?: '',
                'swish_name' => $swishName ?: '',
                'gateway_type' => $gatewayType,
                'bankgiro' => $bankgiro ?: null,
                'plusgiro' => $plusgiro ?: null,
                'bank_account' => $bankAccount ?: null,
                'bank_name' => $bankName ?: null,
                'bank_clearing' => $bankClearing ?: null,
                'platform_fee_type' => $platformFeeType,
                'platform_fee_percent' => $platformFeePercent,
                'platform_fee_fixed' => $platformFeeFixed,
                'active' => $active
            ];

            if ($hasAdminUserCol) {
                $data['admin_user_id'] = $adminUserId;
            }

            try {
                if ($action === 'create') {
                    $db->insert('payment_recipients', $data);
                    $message = 'Betalningsmottagare skapad';
                } else {
                    $id = intval($_POST['id'] ?? 0);
                    if ($id > 0) {
                        $db->update('payment_recipients', $data, 'id = ?', [$id]);
                        $message = 'Betalningsmottagare uppdaterad';
                    }
                }
            } catch (Exception $e) {
                $error = 'Fel: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        $newStatus = intval($_POST['new_status'] ?? 0);
        if ($id > 0) {
            try {
                $db->execute("UPDATE payment_recipients SET active = ? WHERE id = ?", [$newStatus, $id]);
                $message = $newStatus ? 'Mottagare aktiverad' : 'Mottagare inaktiverad';
            } catch (Exception $e) {
                $error = 'Fel: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all recipients
$recipients = [];
try {
    $adminUserJoin = $hasAdminUserCol
        ? "LEFT JOIN admin_users au ON pr.admin_user_id = au.id"
        : "";
    $adminUserSelect = $hasAdminUserCol
        ? ", pr.admin_user_id, au.full_name as admin_user_name, au.username as admin_username"
        : ", NULL as admin_user_id, NULL as admin_user_name, NULL as admin_username";

    $recipients = $db->getAll("
        SELECT pr.*
            {$adminUserSelect},
            COUNT(DISTINCT e.id) as event_count,
            COUNT(DISTINCT s.id) as series_count
        FROM payment_recipients pr
        {$adminUserJoin}
        LEFT JOIN events e ON e.payment_recipient_id = pr.id
        LEFT JOIN series s ON s.payment_recipient_id = pr.id
        GROUP BY pr.id
        ORDER BY pr.active DESC, pr.name
    ");
} catch (Exception $e) {
    $error = 'Kunde inte hämta mottagare: ' . $e->getMessage();
}

// Get promotor users for dropdown
$promotorUsers = [];
try {
    $promotorUsers = $db->getAll("SELECT id, username, full_name, email FROM admin_users WHERE role IN ('promotor', 'admin', 'super_admin') AND active = 1 ORDER BY full_name, username");
} catch (Exception $e) {}

// Get recipient being edited (if any)
$editId = intval($_GET['edit'] ?? 0);
$editRecipient = null;
if ($editId > 0) {
    try {
        $editRecipient = $db->getRow("SELECT * FROM payment_recipients WHERE id = ?", [$editId]);
    } catch (Exception $e) {}
}

$page_title = 'Betalningsmottagare';
$breadcrumbs = [
    ['label' => 'Ekonomi'],
    ['label' => 'Betalningsmottagare']
];
$current_admin_page = 'payment-recipients';

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.recipient-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
@media (max-width: 767px) {
    .recipient-grid {
        grid-template-columns: 1fr;
    }
}
.recipient-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    position: relative;
}
.recipient-card.inactive {
    opacity: 0.6;
}
.recipient-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
}
.recipient-name {
    font-weight: 600;
    font-size: 1.1rem;
    color: var(--color-text-primary);
}
.recipient-meta {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-bottom: var(--space-xs);
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.recipient-meta i { width: 14px; height: 14px; }
.recipient-stats {
    display: flex;
    gap: var(--space-md);
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
}
.recipient-stat {
    text-align: center;
}
.recipient-stat-value {
    font-weight: 700;
    font-size: 1.2rem;
    color: var(--color-text-primary);
}
.recipient-stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    text-transform: uppercase;
}
.recipient-actions {
    display: flex;
    gap: var(--space-xs);
    margin-top: var(--space-md);
}
.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}
.form-grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: var(--space-md);
}
@media (max-width: 767px) {
    .form-grid-2, .form-grid-3 {
        grid-template-columns: 1fr;
    }
}
.form-section-label {
    font-size: var(--text-sm);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-muted);
    margin: var(--space-lg) 0 var(--space-sm);
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.form-section-label:first-child { margin-top: 0; }
.form-section-label i { width: 16px; height: 16px; }
.fee-preview {
    background: var(--color-bg-hover);
    border-radius: var(--radius-sm);
    padding: var(--space-sm) var(--space-md);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-xs);
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Befintliga mottagare -->
<?php if (empty($recipients) && !$editRecipient): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: var(--space-2xl);">
            <i data-lucide="building-2" style="width: 48px; height: 48px; color: var(--color-text-muted);"></i>
            <p style="margin-top: var(--space-md); color: var(--color-text-secondary);">Inga betalningsmottagare ännu</p>
            <a href="?edit=0&new=1" class="btn btn-primary" style="margin-top: var(--space-md);">Skapa ny mottagare</a>
        </div>
    </div>
<?php else: ?>

<?php if (!$editRecipient && !isset($_GET['new'])): ?>
<div style="margin-bottom: var(--space-lg); display: flex; justify-content: flex-end;">
    <a href="?new=1" class="btn btn-primary"><i data-lucide="plus" style="width:16px;height:16px;"></i> Ny mottagare</a>
</div>

<div class="recipient-grid">
    <?php foreach ($recipients as $r): ?>
    <div class="recipient-card <?= $r['active'] ? '' : 'inactive' ?>">
        <div class="recipient-header">
            <span class="recipient-name"><?= htmlspecialchars($r['name']) ?></span>
            <?php if ($r['active']): ?>
                <span class="badge badge-success">Aktiv</span>
            <?php else: ?>
                <span class="badge badge-danger">Inaktiv</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($r['description'])): ?>
            <div class="recipient-meta"><?= htmlspecialchars($r['description']) ?></div>
        <?php endif; ?>

        <?php if (!empty($r['org_number'])): ?>
            <div class="recipient-meta"><i data-lucide="building-2"></i> <?= htmlspecialchars($r['org_number']) ?></div>
        <?php endif; ?>

        <?php if (!empty($r['contact_email'])): ?>
            <div class="recipient-meta"><i data-lucide="mail"></i> <?= htmlspecialchars($r['contact_email']) ?></div>
        <?php endif; ?>

        <?php if (!empty($r['swish_number'])): ?>
            <div class="recipient-meta"><i data-lucide="smartphone"></i> Swish: <?= htmlspecialchars($r['swish_number']) ?></div>
        <?php endif; ?>

        <?php if (!empty($r['bankgiro'])): ?>
            <div class="recipient-meta"><i data-lucide="landmark"></i> Bankgiro: <?= htmlspecialchars($r['bankgiro']) ?></div>
        <?php elseif (!empty($r['plusgiro'])): ?>
            <div class="recipient-meta"><i data-lucide="landmark"></i> Plusgiro: <?= htmlspecialchars($r['plusgiro']) ?></div>
        <?php elseif (!empty($r['bank_account'])): ?>
            <div class="recipient-meta"><i data-lucide="landmark"></i> Bank: <?= htmlspecialchars($r['bank_name'] ?? '') ?> <?= htmlspecialchars($r['bank_clearing'] ?? '') ?>-<?= htmlspecialchars($r['bank_account']) ?></div>
        <?php endif; ?>

        <?php
        $feeDesc = '';
        $feeType = $r['platform_fee_type'] ?? 'percent';
        if ($feeType === 'percent') {
            $feeDesc = number_format($r['platform_fee_percent'] ?? 2, 1) . '%';
        } elseif ($feeType === 'fixed') {
            $feeDesc = number_format($r['platform_fee_fixed'] ?? 0, 0) . ' kr/order';
        } else {
            $feeDesc = number_format($r['platform_fee_percent'] ?? 2, 1) . '% + ' . number_format($r['platform_fee_fixed'] ?? 0, 0) . ' kr';
        }
        ?>
        <div class="recipient-meta"><i data-lucide="percent"></i> Plattformsavgift: <?= $feeDesc ?></div>

        <?php if (!empty($r['admin_user_name']) || !empty($r['admin_username'])): ?>
            <div class="recipient-meta"><i data-lucide="user"></i> Promotor: <?= htmlspecialchars($r['admin_user_name'] ?: $r['admin_username']) ?></div>
        <?php endif; ?>

        <div class="recipient-stats">
            <div class="recipient-stat">
                <div class="recipient-stat-value"><?= (int)$r['event_count'] ?></div>
                <div class="recipient-stat-label">Event</div>
            </div>
            <div class="recipient-stat">
                <div class="recipient-stat-value"><?= (int)$r['series_count'] ?></div>
                <div class="recipient-stat-label">Serier</div>
            </div>
        </div>

        <div class="recipient-actions">
            <a href="?edit=<?= $r['id'] ?>" class="btn btn-primary" style="flex:1;"><i data-lucide="pencil" style="width:14px;height:14px;"></i> Redigera</a>
            <form method="post" style="flex:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $r['active'] ? 0 : 1 ?>">
                <button type="submit" class="btn <?= $r['active'] ? 'btn-ghost' : 'btn-secondary' ?>" title="<?= $r['active'] ? 'Inaktivera' : 'Aktivera' ?>">
                    <i data-lucide="<?= $r['active'] ? 'eye-off' : 'eye' ?>" style="width:14px;height:14px;"></i>
                </button>
            </form>
            <a href="/admin/settlements.php?recipient=<?= $r['id'] ?>" class="btn btn-ghost" title="Avräkning">
                <i data-lucide="receipt" style="width:14px;height:14px;"></i>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Formulär: skapa / redigera -->
<?php if ($editRecipient || isset($_GET['new'])): ?>
<?php
$r = $editRecipient ?: [
    'id' => 0, 'name' => '', 'description' => '', 'org_number' => '',
    'contact_email' => '', 'contact_phone' => '',
    'swish_number' => '', 'swish_name' => '', 'gateway_type' => 'swish',
    'bankgiro' => '', 'plusgiro' => '', 'bank_account' => '', 'bank_name' => '', 'bank_clearing' => '',
    'platform_fee_type' => 'percent', 'platform_fee_percent' => 2.00, 'platform_fee_fixed' => 0,
    'admin_user_id' => null, 'active' => 1
];
$isNew = empty($editRecipient);
?>
<div class="card">
    <div class="card-header">
        <h3><?= $isNew ? 'Ny betalningsmottagare' : 'Redigera: ' . htmlspecialchars($r['name']) ?></h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'update' ?>">
            <?php if (!$isNew): ?>
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <?php endif; ?>

            <!-- Grundinfo -->
            <div class="form-section-label"><i data-lucide="info"></i> Grunduppgifter</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Namn *</label>
                    <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($r['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Org.nummer</label>
                    <input type="text" name="org_number" class="form-input" value="<?= htmlspecialchars($r['org_number'] ?? '') ?>" placeholder="XXXXXX-XXXX">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Beskrivning</label>
                <input type="text" name="description" class="form-input" value="<?= htmlspecialchars($r['description'] ?? '') ?>" placeholder="T.ex. klubbnamn eller serienamn">
            </div>

            <!-- Kontakt -->
            <div class="form-section-label"><i data-lucide="mail"></i> Kontakt</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">E-post</label>
                    <input type="email" name="contact_email" class="form-input" value="<?= htmlspecialchars($r['contact_email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Telefon</label>
                    <input type="text" name="contact_phone" class="form-input" value="<?= htmlspecialchars($r['contact_phone'] ?? '') ?>">
                </div>
            </div>

            <!-- Swish -->
            <div class="form-section-label"><i data-lucide="smartphone"></i> Swish</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Swish-nummer</label>
                    <input type="text" name="swish_number" class="form-input" value="<?= htmlspecialchars($r['swish_number'] ?? '') ?>" placeholder="123 456 78 90">
                </div>
                <div class="form-group">
                    <label class="form-label">Swish-namn (visas för betalare)</label>
                    <input type="text" name="swish_name" class="form-input" value="<?= htmlspecialchars($r['swish_name'] ?? '') ?>">
                </div>
            </div>

            <!-- Bank -->
            <div class="form-section-label"><i data-lucide="landmark"></i> Bankuppgifter</div>
            <div class="form-grid-3">
                <div class="form-group">
                    <label class="form-label">Bankgiro</label>
                    <input type="text" name="bankgiro" class="form-input" value="<?= htmlspecialchars($r['bankgiro'] ?? '') ?>" placeholder="XXXX-XXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">Plusgiro</label>
                    <input type="text" name="plusgiro" class="form-input" value="<?= htmlspecialchars($r['plusgiro'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Banknamn</label>
                    <input type="text" name="bank_name" class="form-input" value="<?= htmlspecialchars($r['bank_name'] ?? '') ?>">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Clearing</label>
                    <input type="text" name="bank_clearing" class="form-input" value="<?= htmlspecialchars($r['bank_clearing'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Kontonummer</label>
                    <input type="text" name="bank_account" class="form-input" value="<?= htmlspecialchars($r['bank_account'] ?? '') ?>">
                </div>
            </div>

            <!-- Gateway -->
            <div class="form-group">
                <label class="form-label">Primär betalväg</label>
                <select name="gateway_type" class="form-select">
                    <option value="swish" <?= ($r['gateway_type'] ?? '') === 'swish' ? 'selected' : '' ?>>Swish</option>
                    <option value="stripe" <?= ($r['gateway_type'] ?? '') === 'stripe' ? 'selected' : '' ?>>Stripe (kort)</option>
                    <option value="bank" <?= ($r['gateway_type'] ?? '') === 'bank' ? 'selected' : '' ?>>Banköverföring</option>
                    <option value="manual" <?= ($r['gateway_type'] ?? '') === 'manual' ? 'selected' : '' ?>>Manuell</option>
                </select>
            </div>

            <!-- Avgifter -->
            <div class="form-section-label"><i data-lucide="percent"></i> Plattformsavgift</div>
            <div class="form-grid-3">
                <div class="form-group">
                    <label class="form-label">Avgiftstyp</label>
                    <select name="platform_fee_type" class="form-select" id="feeType">
                        <option value="percent" <?= ($r['platform_fee_type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Procent</option>
                        <option value="fixed" <?= ($r['platform_fee_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fast belopp</option>
                        <option value="both" <?= ($r['platform_fee_type'] ?? '') === 'both' ? 'selected' : '' ?>>Procent + fast</option>
                    </select>
                </div>
                <div class="form-group" id="feePercentGroup">
                    <label class="form-label">Procent (%)</label>
                    <input type="number" name="platform_fee_percent" class="form-input" value="<?= number_format($r['platform_fee_percent'] ?? 2, 2, '.', '') ?>" step="0.01" min="0" max="100">
                </div>
                <div class="form-group" id="feeFixedGroup">
                    <label class="form-label">Fast belopp (SEK/order)</label>
                    <input type="number" name="platform_fee_fixed" class="form-input" value="<?= number_format($r['platform_fee_fixed'] ?? 0, 2, '.', '') ?>" step="0.01" min="0">
                </div>
            </div>
            <div class="fee-preview" id="feePreview"></div>

            <!-- Kopplad promotor -->
            <?php if ($hasAdminUserCol): ?>
            <div class="form-section-label"><i data-lucide="user"></i> Kopplad promotor</div>
            <div class="form-group">
                <label class="form-label">Promotor-användare</label>
                <select name="admin_user_id" class="form-select">
                    <option value="">Ingen koppling</option>
                    <?php foreach ($promotorUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (($r['admin_user_id'] ?? '') == $u['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?> (<?= htmlspecialchars($u['email'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Aktiv -->
            <div class="form-group" style="margin-top: var(--space-lg);">
                <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                    <input type="checkbox" name="active" value="1" <?= ($r['active'] ?? 1) ? 'checked' : '' ?>>
                    <span class="form-label" style="margin:0;">Aktiv</span>
                </label>
            </div>

            <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-lg);">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Skapa' : 'Spara' ?></button>
                <a href="/admin/payment-recipients.php" class="btn btn-ghost">Avbryt</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Fee type toggle
var feeTypeSelect = document.getElementById('feeType');
var feePercentGroup = document.getElementById('feePercentGroup');
var feeFixedGroup = document.getElementById('feeFixedGroup');
var feePreview = document.getElementById('feePreview');

function updateFeeVisibility() {
    if (!feeTypeSelect) return;
    var type = feeTypeSelect.value;
    feePercentGroup.style.display = (type === 'fixed') ? 'none' : '';
    feeFixedGroup.style.display = (type === 'percent') ? 'none' : '';

    var pct = parseFloat(document.querySelector('[name="platform_fee_percent"]').value) || 0;
    var fixed = parseFloat(document.querySelector('[name="platform_fee_fixed"]').value) || 0;
    var example = 500;
    var fee = 0;
    if (type === 'percent') fee = example * pct / 100;
    else if (type === 'fixed') fee = fixed;
    else fee = (example * pct / 100) + fixed;

    feePreview.textContent = 'Exempel: På en order om ' + example + ' kr blir plattformsavgiften ' + fee.toFixed(2) + ' kr';
}

if (feeTypeSelect) {
    feeTypeSelect.addEventListener('change', updateFeeVisibility);
    document.querySelector('[name="platform_fee_percent"]').addEventListener('input', updateFeeVisibility);
    document.querySelector('[name="platform_fee_fixed"]').addEventListener('input', updateFeeVisibility);
    updateFeeVisibility();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
