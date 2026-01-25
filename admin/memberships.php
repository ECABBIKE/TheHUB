<?php
/**
 * Admin Membership Management
 * Manage membership plans and view subscriptions
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Medlemskap';

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create_plan':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $priceAmount = (int)($_POST['price_amount'] ?? 0);
                $billingInterval = $_POST['billing_interval'] ?? 'year';
                $discountPercent = (int)($_POST['discount_percent'] ?? 0);
                $benefits = array_filter(array_map('trim', explode("\n", $_POST['benefits'] ?? '')));

                if (empty($name) || $priceAmount <= 0) {
                    throw new Exception('Namn och pris kravs');
                }

                // Create in database
                $db->query("
                    INSERT INTO membership_plans
                    (name, description, price_amount, billing_interval, discount_percent, benefits, active)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ", [$name, $description, $priceAmount, $billingInterval, $discountPercent, json_encode($benefits)]);

                $planId = $db->lastInsertId();

                // Create in Stripe if configured
                $stripeKey = env('STRIPE_SECRET_KEY', '');
                if ($stripeKey) {
                    require_once __DIR__ . '/../includes/payment/StripeClient.php';
                    $stripe = new \TheHUB\Payment\StripeClient($stripeKey);

                    // Create product
                    $product = $stripe->createProduct([
                        'name' => $name,
                        'description' => $description,
                        'metadata' => ['plan_id' => $planId]
                    ]);

                    if ($product['success']) {
                        // Create price
                        $price = $stripe->createPrice([
                            'product_id' => $product['product_id'],
                            'amount' => $priceAmount,
                            'currency' => 'SEK',
                            'recurring' => true,
                            'interval' => $billingInterval,
                            'interval_count' => 1
                        ]);

                        if ($price['success']) {
                            // Update plan with Stripe IDs
                            $db->query("
                                UPDATE membership_plans
                                SET stripe_product_id = ?, stripe_price_id = ?
                                WHERE id = ?
                            ", [$product['product_id'], $price['price_id'], $planId]);
                        }
                    }
                }

                $message = "Medlemsplan '{$name}' skapad!";
                $messageType = 'success';
                break;

            case 'toggle_plan':
                $planId = (int)($_POST['plan_id'] ?? 0);
                $db->query("UPDATE membership_plans SET active = NOT active WHERE id = ?", [$planId]);
                $message = 'Plan uppdaterad';
                $messageType = 'success';
                break;

            case 'delete_plan':
                $planId = (int)($_POST['plan_id'] ?? 0);

                // Check for active subscriptions
                $activeCount = $db->getValue("
                    SELECT COUNT(*) FROM member_subscriptions
                    WHERE plan_id = ? AND stripe_subscription_status = 'active'
                ", [$planId]);

                if ($activeCount > 0) {
                    throw new Exception("Kan inte ta bort plan med {$activeCount} aktiva prenumerationer");
                }

                $db->query("DELETE FROM membership_plans WHERE id = ?", [$planId]);
                $message = 'Plan borttagen';
                $messageType = 'success';
                break;

            case 'cancel_subscription':
                $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
                $sub = $db->getRow("SELECT stripe_subscription_id FROM member_subscriptions WHERE id = ?", [$subscriptionId]);

                if ($sub) {
                    $stripeKey = env('STRIPE_SECRET_KEY', '');
                    if ($stripeKey) {
                        require_once __DIR__ . '/../includes/payment/StripeClient.php';
                        $stripe = new \TheHUB\Payment\StripeClient($stripeKey);
                        $result = $stripe->cancelSubscription($sub['stripe_subscription_id'], true);

                        if ($result['success']) {
                            $db->query("
                                UPDATE member_subscriptions
                                SET cancel_at_period_end = 1
                                WHERE id = ?
                            ", [$subscriptionId]);
                            $message = 'Prenumeration kommer avslutas vid periodens slut';
                            $messageType = 'success';
                        } else {
                            throw new Exception($result['error']);
                        }
                    }
                }
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Get current tab
$tab = $_GET['tab'] ?? 'plans';

// Fetch data based on tab
$plans = $db->getAll("SELECT * FROM membership_plans ORDER BY sort_order, name");

$subscriptions = [];
$stats = [];
if ($tab === 'subscriptions' || $tab === 'stats') {
    $subscriptions = $db->getAll("
        SELECT ms.*, mp.name as plan_name, mp.price_amount
        FROM member_subscriptions ms
        JOIN membership_plans mp ON ms.plan_id = mp.id
        ORDER BY ms.created_at DESC
        LIMIT 100
    ");

    $stats = [
        'total_active' => $db->getValue("SELECT COUNT(*) FROM member_subscriptions WHERE stripe_subscription_status = 'active'") ?? 0,
        'total_trialing' => $db->getValue("SELECT COUNT(*) FROM member_subscriptions WHERE stripe_subscription_status = 'trialing'") ?? 0,
        'total_canceled' => $db->getValue("SELECT COUNT(*) FROM member_subscriptions WHERE stripe_subscription_status = 'canceled'") ?? 0,
        'mrr' => $db->getValue("
            SELECT COALESCE(SUM(mp.price_amount), 0) / 12
            FROM member_subscriptions ms
            JOIN membership_plans mp ON ms.plan_id = mp.id
            WHERE ms.stripe_subscription_status = 'active' AND mp.billing_interval = 'year'
        ") ?? 0,
    ];
}

include __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1><i data-lucide="users"></i> <?= $pageTitle ?></h1>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <nav class="tabs-nav">
            <a href="?tab=plans" class="tab-btn <?= $tab === 'plans' ? 'active' : '' ?>">
                <i data-lucide="package"></i> Planer
            </a>
            <a href="?tab=subscriptions" class="tab-btn <?= $tab === 'subscriptions' ? 'active' : '' ?>">
                <i data-lucide="credit-card"></i> Prenumerationer
            </a>
            <a href="?tab=stats" class="tab-btn <?= $tab === 'stats' ? 'active' : '' ?>">
                <i data-lucide="bar-chart-3"></i> Statistik
            </a>
        </nav>
    </div>

    <?php if ($tab === 'plans'): ?>
        <!-- Plans Tab -->
        <div class="card" style="margin-top: var(--space-lg);">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h3>Medlemsplaner</h3>
                <button class="btn btn-primary" onclick="document.getElementById('createPlanModal').showModal()">
                    <i data-lucide="plus"></i> Ny plan
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($plans)): ?>
                    <p class="text-muted">Inga medlemsplaner skapade an. Kor migrering 025 forst.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Namn</th>
                                    <th>Pris</th>
                                    <th>Intervall</th>
                                    <th>Rabatt</th>
                                    <th>Stripe</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plans as $plan): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($plan['name']) ?></strong>
                                            <?php if ($plan['description']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars(substr($plan['description'], 0, 50)) ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= number_format($plan['price_amount'] / 100, 0) ?> kr</td>
                                        <td><?= $plan['billing_interval'] === 'year' ? 'Arsvis' : ucfirst($plan['billing_interval']) ?></td>
                                        <td><?= $plan['discount_percent'] ?>%</td>
                                        <td>
                                            <?php if ($plan['stripe_price_id']): ?>
                                                <span class="badge badge-success">Synkad</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Ej synkad</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($plan['active']): ?>
                                                <span class="badge badge-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle_plan">
                                                <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                                                <button type="submit" class="btn btn-ghost btn-sm">
                                                    <?= $plan['active'] ? 'Inaktivera' : 'Aktivera' ?>
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
        </div>

        <!-- Create Plan Modal -->
        <dialog id="createPlanModal" class="modal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>Skapa medlemsplan</h3>
                    <button onclick="this.closest('dialog').close()" class="btn btn-ghost">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_plan">
                    <div class="modal-body">
                        <div class="form-group">
                            <label class="form-label">Namn *</label>
                            <input type="text" name="name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Beskrivning</label>
                            <textarea name="description" class="form-input" rows="2"></textarea>
                        </div>
                        <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                            <div class="form-group">
                                <label class="form-label">Pris (ore) *</label>
                                <input type="number" name="price_amount" class="form-input" value="29900" required>
                                <small class="text-muted">29900 = 299 kr</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Intervall</label>
                                <select name="billing_interval" class="form-select">
                                    <option value="month">Manad</option>
                                    <option value="year" selected>Ar</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rabatt pa anmalningar (%)</label>
                            <input type="number" name="discount_percent" class="form-input" value="10" min="0" max="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Formaner (en per rad)</label>
                            <textarea name="benefits" class="form-input" rows="4" placeholder="10% rabatt pa anmalningar&#10;Tillgang till medlemsnytt&#10;..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" onclick="this.closest('dialog').close()" class="btn btn-secondary">Avbryt</button>
                        <button type="submit" class="btn btn-primary">Skapa plan</button>
                    </div>
                </form>
            </div>
        </dialog>

    <?php elseif ($tab === 'subscriptions'): ?>
        <!-- Subscriptions Tab -->
        <div class="card" style="margin-top: var(--space-lg);">
            <div class="card-header">
                <h3>Aktiva prenumerationer</h3>
            </div>
            <div class="card-body">
                <?php if (empty($subscriptions)): ?>
                    <p class="text-muted">Inga prenumerationer annu.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Medlem</th>
                                    <th>Plan</th>
                                    <th>Status</th>
                                    <th>Period</th>
                                    <th>Senaste betalning</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subscriptions as $sub): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($sub['name']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($sub['email']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($sub['plan_name']) ?></td>
                                        <td>
                                            <?php
                                            $statusBadge = match($sub['stripe_subscription_status']) {
                                                'active' => 'success',
                                                'trialing' => 'info',
                                                'past_due' => 'warning',
                                                'canceled', 'unpaid' => 'danger',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge badge-<?= $statusBadge ?>">
                                                <?= ucfirst($sub['stripe_subscription_status']) ?>
                                            </span>
                                            <?php if ($sub['cancel_at_period_end']): ?>
                                                <br><small class="text-warning">Avslutas vid periodens slut</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($sub['current_period_end']): ?>
                                                <?= date('Y-m-d', strtotime($sub['current_period_end'])) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($sub['last_payment_at']): ?>
                                                <?= date('Y-m-d', strtotime($sub['last_payment_at'])) ?>
                                                <br><small><?= number_format($sub['last_payment_amount'] / 100, 0) ?> kr</small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($sub['stripe_subscription_status'] === 'active' && !$sub['cancel_at_period_end']): ?>
                                                <form method="POST" onsubmit="return confirm('Ar du saker pa att du vill avsluta denna prenumeration?');">
                                                    <input type="hidden" name="action" value="cancel_subscription">
                                                    <input type="hidden" name="subscription_id" value="<?= $sub['id'] ?>">
                                                    <button type="submit" class="btn btn-ghost btn-sm text-danger">
                                                        <i data-lucide="x"></i> Avsluta
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

    <?php elseif ($tab === 'stats'): ?>
        <!-- Stats Tab -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-lg); margin-top: var(--space-lg);">
            <div class="card">
                <div class="card-body" style="text-align: center;">
                    <div style="font-size: 2.5rem; font-weight: bold; color: var(--color-success);">
                        <?= number_format($stats['total_active']) ?>
                    </div>
                    <div class="text-muted">Aktiva prenumerationer</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="text-align: center;">
                    <div style="font-size: 2.5rem; font-weight: bold; color: var(--color-info);">
                        <?= number_format($stats['total_trialing']) ?>
                    </div>
                    <div class="text-muted">Provperioder</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="text-align: center;">
                    <div style="font-size: 2.5rem; font-weight: bold; color: var(--color-accent);">
                        <?= number_format($stats['mrr'] / 100, 0) ?> kr
                    </div>
                    <div class="text-muted">MRR (Monthly Recurring Revenue)</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="text-align: center;">
                    <div style="font-size: 2.5rem; font-weight: bold; color: var(--color-text-muted);">
                        <?= number_format($stats['total_canceled']) ?>
                    </div>
                    <div class="text-muted">Avslutade</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.modal {
    border: none;
    border-radius: var(--radius-lg);
    padding: 0;
    background: var(--color-bg-card);
    color: var(--color-text-primary);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}
.modal::backdrop {
    background: rgba(0, 0, 0, 0.5);
}
.modal-content {
    width: 100%;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}
.modal-body {
    padding: var(--space-lg);
}
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-lg);
    border-top: 1px solid var(--color-border);
}
</style>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
