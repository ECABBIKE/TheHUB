<?php
/**
 * Admin Payment Recipients - Manage payment accounts
 * Central management of payment recipients for series and events
 * Supports: Swish, Stripe Connect, Bank transfer
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
        <i data-lucide="alert-triangle"></i>
        Tabellen <code>payment_recipients</code> finns inte. Kör migrationen först.
    </div>
    <div class="admin-card">
        <div class="admin-card-body">
            <p>Kör följande migration för att skapa tabellen:</p>
            <code>database/migrations/054_payment_recipients_central.sql</code>
            <p class="mt-md">
                <a href="/admin/migrations.php" class="btn-admin btn-admin-primary">Gå till migrationer</a>
            </p>
        </div>
    </div>
    <?php
    include __DIR__ . '/components/unified-layout-footer.php';
    exit;
}

// Check what columns exist
$hasGatewayType = false;
$hasBankFields = false;
try {
    $cols = $db->getAll("SHOW COLUMNS FROM payment_recipients LIKE 'gateway_type'");
    $hasGatewayType = !empty($cols);
    $cols = $db->getAll("SHOW COLUMNS FROM payment_recipients LIKE 'bankgiro'");
    $hasBankFields = !empty($cols);
} catch (Exception $e) {}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $gatewayType = $_POST['gateway_type'] ?? 'swish';

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } else {
            $recipientData = [
                'name' => $name,
                'description' => trim($_POST['description'] ?? ''),
                'swish_number' => trim($_POST['swish_number'] ?? ''),
                'swish_name' => trim($_POST['swish_name'] ?? ''),
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            // Add gateway type if column exists
            if ($hasGatewayType) {
                $recipientData['gateway_type'] = $gatewayType;
            }

            // Add bank fields if columns exist
            if ($hasBankFields) {
                $recipientData['bankgiro'] = trim($_POST['bankgiro'] ?? '');
                $recipientData['plusgiro'] = trim($_POST['plusgiro'] ?? '');
                $recipientData['bank_account'] = trim($_POST['bank_account'] ?? '');
                $recipientData['bank_name'] = trim($_POST['bank_name'] ?? '');
                $recipientData['bank_clearing'] = trim($_POST['bank_clearing'] ?? '');
                $recipientData['contact_email'] = trim($_POST['contact_email'] ?? '');
                $recipientData['contact_phone'] = trim($_POST['contact_phone'] ?? '');
                $recipientData['org_number'] = trim($_POST['org_number'] ?? '');
            }

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

// Get all recipients with usage count
$recipients = $db->getAll("
    SELECT pr.*,
           (SELECT COUNT(*) FROM series s WHERE s.payment_recipient_id = pr.id) as series_count,
           (SELECT COUNT(*) FROM events e WHERE e.payment_recipient_id = pr.id) as events_count
    FROM payment_recipients pr
    ORDER BY pr.name ASC
");

// Check if Stripe is configured
$stripeConfigured = !empty(env('STRIPE_SECRET_KEY', ''));

// Page config
$page_title = 'Betalningsmottagare';
$page_group = 'economy';
$breadcrumbs = [
    ['label' => 'Ekonomi', 'url' => '/admin/ekonomi.php'],
    ['label' => 'Mottagare']
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'danger' : 'info') ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if (!$hasGatewayType || !$hasBankFields): ?>
<div class="alert alert-info mb-lg">
    <i data-lucide="info"></i>
    <div>
        <strong>Nya funktioner tillgängliga!</strong><br>
        Kör migration <code>027_payment_recipients_bank_details.sql</code> för att aktivera bankkontouppgifter och gateway-val.
        <a href="/admin/migrations.php" class="text-accent">Gå till migrationer</a>
    </div>
</div>
<?php endif; ?>

<!-- Header with actions -->
<div class="flex justify-between items-center mb-lg flex-wrap gap-md">
    <div>
        <p class="text-secondary m-0">Hantera betalningsmottagare för serier och event</p>
    </div>
    <div class="flex gap-sm">
        <?php if ($stripeConfigured): ?>
        <a href="/admin/stripe-connect.php" class="btn-admin btn-admin-secondary">
            <i data-lucide="credit-card"></i>
            Stripe Connect
        </a>
        <?php endif; ?>
        <button type="button" class="btn-admin btn-admin-primary" onclick="showModal('create')">
            <i data-lucide="plus"></i>
            Ny mottagare
        </button>
    </div>
</div>

<!-- Recipients list -->
<?php if (empty($recipients)): ?>
<div class="admin-card">
    <div class="admin-card-body text-center" style="padding: var(--space-2xl);">
        <i data-lucide="wallet" style="width: 48px; height: 48px; opacity: 0.3; margin-bottom: var(--space-md);"></i>
        <h3>Inga betalningsmottagare</h3>
        <p class="text-secondary">Skapa din första mottagare för att kunna ta emot betalningar.</p>
        <button type="button" class="btn-admin btn-admin-primary mt-md" onclick="showModal('create')">
            <i data-lucide="plus"></i>
            Skapa mottagare
        </button>
    </div>
</div>
<?php else: ?>

<div class="recipient-grid">
    <?php foreach ($recipients as $r): ?>
    <?php
    $gatewayType = $r['gateway_type'] ?? 'swish';
    $hasStripe = !empty($r['stripe_account_id']);
    $stripeStatus = $r['stripe_account_status'] ?? null;
    ?>
    <div class="recipient-card <?= !$r['active'] ? 'inactive' : '' ?>">
        <div class="recipient-card-header">
            <div class="recipient-icon <?= $gatewayType ?>">
                <?php if ($gatewayType === 'stripe'): ?>
                    <i data-lucide="credit-card"></i>
                <?php elseif ($gatewayType === 'bank'): ?>
                    <i data-lucide="landmark"></i>
                <?php else: ?>
                    <i data-lucide="smartphone"></i>
                <?php endif; ?>
            </div>
            <div class="recipient-info">
                <h3><?= htmlspecialchars($r['name']) ?></h3>
                <?php if ($r['description']): ?>
                <p><?= htmlspecialchars($r['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="recipient-status">
                <?php if (!$r['active']): ?>
                    <span class="badge badge-secondary">Inaktiv</span>
                <?php elseif ($gatewayType === 'stripe' && $hasStripe): ?>
                    <?php if ($stripeStatus === 'active'): ?>
                        <span class="badge badge-success">Stripe aktiv</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Stripe väntar</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge badge-success">Aktiv</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="recipient-card-body">
            <!-- Payment details -->
            <div class="recipient-details">
                <?php if ($gatewayType === 'swish' && $r['swish_number']): ?>
                <div class="detail-row">
                    <span class="detail-label">Swish</span>
                    <span class="detail-value"><?= htmlspecialchars($r['swish_number']) ?></span>
                </div>
                <?php if ($r['swish_name']): ?>
                <div class="detail-row">
                    <span class="detail-label">Mottagare</span>
                    <span class="detail-value"><?= htmlspecialchars($r['swish_name']) ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($gatewayType === 'stripe'): ?>
                <div class="detail-row">
                    <span class="detail-label">Gateway</span>
                    <span class="detail-value">Stripe Connect</span>
                </div>
                <?php if ($hasStripe): ?>
                <div class="detail-row">
                    <span class="detail-label">Account</span>
                    <span class="detail-value"><code><?= htmlspecialchars(substr($r['stripe_account_id'], 0, 20)) ?>...</code></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($gatewayType === 'bank'): ?>
                <?php if (!empty($r['bankgiro'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Bankgiro</span>
                    <span class="detail-value"><?= htmlspecialchars($r['bankgiro']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($r['plusgiro'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Plusgiro</span>
                    <span class="detail-value"><?= htmlspecialchars($r['plusgiro']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($r['bank_account'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Bankkonto</span>
                    <span class="detail-value"><?= htmlspecialchars($r['bank_clearing'] ?? '') ?> <?= htmlspecialchars($r['bank_account']) ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Usage -->
                <div class="detail-row usage">
                    <span class="detail-label">Används av</span>
                    <span class="detail-value">
                        <?php
                        $usage = [];
                        if ($r['series_count'] > 0) $usage[] = $r['series_count'] . ' serier';
                        if ($r['events_count'] > 0) $usage[] = $r['events_count'] . ' event';
                        echo $usage ? implode(', ', $usage) : 'Ingen';
                        ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="recipient-card-footer">
            <?php if ($gatewayType === 'stripe' && !$hasStripe && $stripeConfigured): ?>
            <a href="/admin/stripe-connect.php" class="btn-admin btn-admin-primary btn-admin-sm flex-1">
                <i data-lucide="link"></i>
                Anslut Stripe
            </a>
            <?php elseif ($gatewayType === 'stripe' && $hasStripe): ?>
            <a href="/admin/stripe-connect.php" class="btn-admin btn-admin-secondary btn-admin-sm flex-1">
                <i data-lucide="external-link"></i>
                Stripe Dashboard
            </a>
            <?php endif; ?>

            <button type="button" class="btn-admin btn-admin-secondary btn-admin-sm"
                    onclick='showModal("edit", <?= json_encode($r) ?>)'>
                <i data-lucide="pencil"></i>
                Redigera
            </button>

            <?php if ($r['series_count'] == 0 && $r['events_count'] == 0): ?>
            <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm"
                        onclick="return confirm('Ta bort <?= htmlspecialchars($r['name']) ?>?')">
                    <i data-lucide="trash-2"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Modal for create/edit -->
<div id="recipientModal" class="modal hidden">
    <div class="modal-backdrop" onclick="hideModal()"></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="modalTitle">Lägg till mottagare</h3>
            <button type="button" class="modal-close" onclick="hideModal()">&times;</button>
        </div>
        <form method="POST" id="recipientForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">

            <div class="modal-body">
                <!-- Basic info -->
                <div class="form-section">
                    <h4>Grunduppgifter</h4>

                    <div class="admin-form-group">
                        <label class="admin-form-label">Namn <span class="text-error">*</span></label>
                        <input type="text" name="name" id="formName" class="admin-form-input" required
                               placeholder="T.ex. GravitySeries, Järvsö IF">
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-form-label">Beskrivning</label>
                        <input type="text" name="description" id="formDescription" class="admin-form-input"
                               placeholder="T.ex. Centralt konto för GS-serier">
                    </div>

                    <?php if ($hasGatewayType): ?>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Betalningsmetod</label>
                        <div class="gateway-selector">
                            <label class="gateway-option">
                                <input type="radio" name="gateway_type" value="swish" checked onchange="toggleGatewayFields()">
                                <div class="gateway-option-content">
                                    <i data-lucide="smartphone"></i>
                                    <span>Swish</span>
                                </div>
                            </label>
                            <?php if ($stripeConfigured): ?>
                            <label class="gateway-option">
                                <input type="radio" name="gateway_type" value="stripe" onchange="toggleGatewayFields()">
                                <div class="gateway-option-content">
                                    <i data-lucide="credit-card"></i>
                                    <span>Stripe</span>
                                </div>
                            </label>
                            <?php endif; ?>
                            <label class="gateway-option">
                                <input type="radio" name="gateway_type" value="bank" onchange="toggleGatewayFields()">
                                <div class="gateway-option-content">
                                    <i data-lucide="landmark"></i>
                                    <span>Bank</span>
                                </div>
                            </label>
                            <label class="gateway-option">
                                <input type="radio" name="gateway_type" value="manual" onchange="toggleGatewayFields()">
                                <div class="gateway-option-content">
                                    <i data-lucide="hand-coins"></i>
                                    <span>Manuell</span>
                                </div>
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Swish fields -->
                <div class="form-section gateway-fields" id="swishFields">
                    <h4><i data-lucide="smartphone"></i> Swish-uppgifter</h4>
                    <div class="admin-form-row">
                        <div class="admin-form-group">
                            <label class="admin-form-label">Swish-nummer</label>
                            <input type="text" name="swish_number" id="formSwishNumber" class="admin-form-input"
                                   placeholder="070-123 45 67">
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Mottagarnamn</label>
                            <input type="text" name="swish_name" id="formSwishName" class="admin-form-input"
                                   placeholder="GravitySeries">
                            <small class="text-secondary">Visas i Swish-appen</small>
                        </div>
                    </div>
                </div>

                <!-- Stripe fields -->
                <div class="form-section gateway-fields" id="stripeFields" style="display: none;">
                    <h4><i data-lucide="credit-card"></i> Stripe Connect</h4>
                    <p class="text-secondary">
                        Stripe-kontot kopplas efter att mottagaren skapats.
                        Gå till <a href="/admin/stripe-connect.php">Stripe Connect</a> för att slutföra kopplingen.
                    </p>
                </div>

                <!-- Bank fields -->
                <?php if ($hasBankFields): ?>
                <div class="form-section gateway-fields" id="bankFields" style="display: none;">
                    <h4><i data-lucide="landmark"></i> Bankuppgifter</h4>
                    <div class="admin-form-row">
                        <div class="admin-form-group">
                            <label class="admin-form-label">Bankgiro</label>
                            <input type="text" name="bankgiro" id="formBankgiro" class="admin-form-input"
                                   placeholder="123-4567">
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Plusgiro</label>
                            <input type="text" name="plusgiro" id="formPlusgiro" class="admin-form-input"
                                   placeholder="12 34 56-7">
                        </div>
                    </div>
                    <div class="admin-form-row">
                        <div class="admin-form-group">
                            <label class="admin-form-label">Clearingnummer</label>
                            <input type="text" name="bank_clearing" id="formBankClearing" class="admin-form-input"
                                   placeholder="1234">
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Kontonummer</label>
                            <input type="text" name="bank_account" id="formBankAccount" class="admin-form-input"
                                   placeholder="12 345 67-8">
                        </div>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Banknamn</label>
                        <input type="text" name="bank_name" id="formBankName" class="admin-form-input"
                               placeholder="Swedbank, SEB, Nordea...">
                    </div>
                </div>

                <!-- Contact info -->
                <div class="form-section">
                    <h4><i data-lucide="user"></i> Kontaktuppgifter</h4>
                    <div class="admin-form-row">
                        <div class="admin-form-group">
                            <label class="admin-form-label">E-post</label>
                            <input type="email" name="contact_email" id="formContactEmail" class="admin-form-input"
                                   placeholder="ekonomi@forening.se">
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Telefon</label>
                            <input type="text" name="contact_phone" id="formContactPhone" class="admin-form-input"
                                   placeholder="070-123 45 67">
                        </div>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Organisationsnummer</label>
                        <input type="text" name="org_number" id="formOrgNumber" class="admin-form-input"
                               placeholder="123456-7890">
                    </div>
                </div>
                <?php endif; ?>

                <!-- Status -->
                <div class="form-section">
                    <div class="admin-form-group">
                        <label class="admin-form-label flex items-center gap-sm cursor-pointer">
                            <input type="checkbox" name="active" id="formActive" value="1" checked>
                            Aktiv
                        </label>
                        <small class="text-secondary">Inaktiva mottagare kan inte väljas för nya serier/event</small>
                    </div>
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
/* Recipient Grid */
.recipient-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: var(--space-md);
}

@media (max-width: 767px) {
    .recipient-grid {
        grid-template-columns: 1fr;
        gap: 0;
        margin-left: calc(-1 * var(--space-md));
        margin-right: calc(-1 * var(--space-md));
    }
}

/* Recipient Card */
.recipient-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.recipient-card.inactive {
    opacity: 0.7;
}

@media (max-width: 767px) {
    .recipient-card {
        border-radius: 0;
        border-left: none;
        border-right: none;
        margin-bottom: -1px;
    }
}

.recipient-card-header {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-hover);
    border-bottom: 1px solid var(--color-border);
}

.recipient-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.recipient-icon i {
    width: 20px;
    height: 20px;
}

.recipient-icon.swish {
    background: linear-gradient(135deg, #78bd1c, #59a60d);
    color: white;
}

.recipient-icon.stripe {
    background: linear-gradient(135deg, #635bff, #5851ea);
    color: white;
}

.recipient-icon.bank {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}

.recipient-icon.manual {
    background: var(--color-bg-surface);
    color: var(--color-text-secondary);
    border: 1px solid var(--color-border);
}

.recipient-info {
    flex: 1;
    min-width: 0;
}

.recipient-info h3 {
    margin: 0;
    font-size: var(--text-base);
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.recipient-info p {
    margin: 0;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.recipient-card-body {
    padding: var(--space-md) var(--space-lg);
}

.recipient-details {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.detail-row {
    display: flex;
    justify-content: space-between;
    font-size: var(--text-sm);
}

.detail-row.usage {
    margin-top: var(--space-sm);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
}

.detail-label {
    color: var(--color-text-secondary);
}

.detail-value {
    font-weight: 500;
}

.detail-value code {
    background: var(--color-bg-hover);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: var(--text-xs);
}

.recipient-card-footer {
    display: flex;
    gap: var(--space-sm);
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-hover);
    border-top: 1px solid var(--color-border);
}

/* Modal */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: var(--space-lg);
    overflow-y: auto;
}

.modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
}

.modal-content {
    position: relative;
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 500px;
    margin-top: var(--space-xl);
    box-shadow: var(--shadow-xl);
}

.modal-content.modal-lg {
    max-width: 600px;
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

.modal-body {
    padding: var(--space-lg);
    max-height: 70vh;
    overflow-y: auto;
}

.modal-footer {
    display: flex;
    gap: var(--space-sm);
    justify-content: flex-end;
    padding: var(--space-md) var(--space-lg);
    border-top: 1px solid var(--color-border);
}

/* Form sections */
.form-section {
    margin-bottom: var(--space-lg);
    padding-bottom: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.form-section:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.form-section h4 {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0 0 var(--space-md) 0;
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.form-section h4 i {
    width: 16px;
    height: 16px;
}

/* Gateway selector */
.gateway-selector {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-sm);
}

@media (max-width: 500px) {
    .gateway-selector {
        grid-template-columns: repeat(2, 1fr);
    }
}

.gateway-option {
    cursor: pointer;
}

.gateway-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.gateway-option-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-md);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    transition: all 0.15s ease;
}

.gateway-option-content i {
    width: 24px;
    height: 24px;
}

.gateway-option-content span {
    font-size: var(--text-sm);
    font-weight: 500;
}

.gateway-option input:checked + .gateway-option-content {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
    color: var(--color-accent);
}

.admin-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}

@media (max-width: 500px) {
    .admin-form-row {
        grid-template-columns: 1fr;
    }

    .modal {
        padding: 0;
    }

    .modal-content {
        max-width: 100%;
        margin: 0;
        border-radius: 0;
        min-height: 100vh;
    }
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

        // Gateway type
        const gatewayType = data.gateway_type || 'swish';
        const gatewayRadio = document.querySelector(`input[name="gateway_type"][value="${gatewayType}"]`);
        if (gatewayRadio) gatewayRadio.checked = true;

        // Bank fields
        if (document.getElementById('formBankgiro')) {
            document.getElementById('formBankgiro').value = data.bankgiro || '';
            document.getElementById('formPlusgiro').value = data.plusgiro || '';
            document.getElementById('formBankAccount').value = data.bank_account || '';
            document.getElementById('formBankClearing').value = data.bank_clearing || '';
            document.getElementById('formBankName').value = data.bank_name || '';
            document.getElementById('formContactEmail').value = data.contact_email || '';
            document.getElementById('formContactPhone').value = data.contact_phone || '';
            document.getElementById('formOrgNumber').value = data.org_number || '';
        }

        document.getElementById('formSubmitBtn').textContent = 'Uppdatera';
        toggleGatewayFields();
    } else {
        title.textContent = 'Ny mottagare';
        document.getElementById('formAction').value = 'create';
        document.getElementById('formId').value = '';
        form.reset();
        document.getElementById('formActive').checked = true;

        const swishRadio = document.querySelector('input[name="gateway_type"][value="swish"]');
        if (swishRadio) swishRadio.checked = true;

        document.getElementById('formSubmitBtn').textContent = 'Skapa';
        toggleGatewayFields();
    }

    modal.classList.remove('hidden');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function hideModal() {
    document.getElementById('recipientModal').classList.add('hidden');
}

function toggleGatewayFields() {
    const selectedGateway = document.querySelector('input[name="gateway_type"]:checked');
    const gateway = selectedGateway ? selectedGateway.value : 'swish';

    const swishFields = document.getElementById('swishFields');
    const stripeFields = document.getElementById('stripeFields');
    const bankFields = document.getElementById('bankFields');

    if (swishFields) swishFields.style.display = gateway === 'swish' ? 'block' : 'none';
    if (stripeFields) stripeFields.style.display = gateway === 'stripe' ? 'block' : 'none';
    if (bankFields) bankFields.style.display = gateway === 'bank' ? 'block' : 'none';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideModal();
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
