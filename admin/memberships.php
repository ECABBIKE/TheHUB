<?php
/**
 * Admin Membership Management
 * Manage membership plans and view subscriptions
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Handle form submissions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
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
                    throw new Exception('Namn och pris krävs');
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
        $messageType = 'error';
    }
}

// Get current tab
$tab = $_GET['tab'] ?? 'plans';

// Fetch data
$plans = [];
$subscriptions = [];
$stats = ['total_active' => 0, 'total_trialing' => 0, 'total_canceled' => 0, 'mrr' => 0];

try {
    $plans = $db->getAll("SELECT * FROM membership_plans ORDER BY sort_order, name");

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
} catch (Exception $e) {
    // Tables might not exist yet
    error_log("Memberships error: " . $e->getMessage());
}

// Page config for unified layout
$page_title = 'Medlemskap';
$breadcrumbs = [
    ['label' => 'Betalningar', 'url' => '/admin/ekonomi.php'],
    ['label' => 'Medlemskap']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Stats -->
<?php if ($tab === 'stats'): ?>
<div class="admin-stats-grid mb-lg">
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-success">
            <i data-lucide="users"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($stats['total_active']) ?></div>
            <div class="admin-stat-label">Aktiva prenumerationer</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-info">
            <i data-lucide="clock"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($stats['total_trialing']) ?></div>
            <div class="admin-stat-label">Provperioder</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-accent">
            <i data-lucide="wallet"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($stats['mrr'] / 100, 0) ?> kr</div>
            <div class="admin-stat-label">MRR</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-muted">
            <i data-lucide="user-x"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($stats['total_canceled']) ?></div>
            <div class="admin-stat-label">Avslutade</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="admin-tabs mb-lg">
    <a href="/admin/memberships.php?tab=plans" class="admin-tab <?= $tab === 'plans' ? 'active' : '' ?>">
        <i data-lucide="package"></i> Planer
    </a>
    <a href="/admin/memberships.php?tab=subscriptions" class="admin-tab <?= $tab === 'subscriptions' ? 'active' : '' ?>">
        <i data-lucide="credit-card"></i> Prenumerationer
    </a>
    <a href="/admin/memberships.php?tab=stats" class="admin-tab <?= $tab === 'stats' ? 'active' : '' ?>">
        <i data-lucide="bar-chart-3"></i> Statistik
    </a>
</div>

<?php if ($tab === 'plans'): ?>
<!-- Plans Tab -->
<div class="admin-card">
    <div class="admin-card-header flex justify-between items-center">
        <h2>Medlemsplaner</h2>
        <button class="btn-admin btn-admin-primary" onclick="document.getElementById('createPlanModal').showModal()">
            <i data-lucide="plus"></i> Ny plan
        </button>
    </div>
    <div class="admin-card-body p-0">
        <?php if (empty($plans)): ?>
            <div class="admin-empty-state">
                <i data-lucide="package"></i>
                <p>Inga medlemsplaner skapade</p>
                <p class="text-muted">Kör migrering 025 först, eller skapa en plan ovan.</p>
            </div>
        <?php else: ?>
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Namn</th>
                            <th>Pris</th>
                            <th>Intervall</th>
                            <th>Rabatt</th>
                            <th>Stripe</th>
                            <th>Status</th>
                            <th class="text-right">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plans as $plan): ?>
                            <tr>
                                <td>
                                    <strong><?= h($plan['name']) ?></strong>
                                    <?php if ($plan['description']): ?>
                                        <br><small class="text-muted"><?= h(substr($plan['description'], 0, 50)) ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($plan['price_amount'] / 100, 0) ?> kr</td>
                                <td><?= $plan['billing_interval'] === 'year' ? 'Årsvis' : ucfirst($plan['billing_interval']) ?></td>
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
                                        <span class="badge badge-muted">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <form method="POST" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_plan">
                                        <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                                        <button type="submit" class="btn-admin btn-admin-ghost btn-sm">
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
<dialog id="createPlanModal" class="admin-modal">
    <div class="admin-modal-content">
        <div class="admin-modal-header">
            <h3>Skapa medlemsplan</h3>
            <button onclick="this.closest('dialog').close()" class="btn-admin btn-admin-ghost">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_plan">
            <div class="admin-modal-body">
                <div class="admin-form-group">
                    <label class="admin-form-label">Namn *</label>
                    <input type="text" name="name" class="admin-form-input" required>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Beskrivning</label>
                    <textarea name="description" class="admin-form-input" rows="2"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-md">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Pris (öre) *</label>
                        <input type="number" name="price_amount" class="admin-form-input" value="29900" required>
                        <small class="text-muted">29900 = 299 kr</small>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Intervall</label>
                        <select name="billing_interval" class="admin-form-select">
                            <option value="month">Månad</option>
                            <option value="year" selected>År</option>
                        </select>
                    </div>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Rabatt på anmälningar (%)</label>
                    <input type="number" name="discount_percent" class="admin-form-input" value="10" min="0" max="100">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Förmåner (en per rad)</label>
                    <textarea name="benefits" class="admin-form-input" rows="4" placeholder="10% rabatt på anmälningar&#10;Tillgång till medlemsnytt&#10;..."></textarea>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" onclick="this.closest('dialog').close()" class="btn-admin btn-admin-secondary">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary">Skapa plan</button>
            </div>
        </form>
    </div>
</dialog>

<?php elseif ($tab === 'subscriptions'): ?>
<!-- Subscriptions Tab -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>Prenumerationer</h2>
    </div>
    <div class="admin-card-body p-0">
        <?php if (empty($subscriptions)): ?>
            <div class="admin-empty-state">
                <i data-lucide="credit-card"></i>
                <p>Inga prenumerationer ännu</p>
            </div>
        <?php else: ?>
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Medlem</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Period slutar</th>
                            <th>Senaste betalning</th>
                            <th class="text-right">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscriptions as $sub): ?>
                            <tr>
                                <td>
                                    <strong><?= h($sub['name']) ?></strong>
                                    <br><small class="text-muted"><?= h($sub['email']) ?></small>
                                </td>
                                <td><?= h($sub['plan_name']) ?></td>
                                <td>
                                    <?php
                                    $statusBadge = match($sub['stripe_subscription_status']) {
                                        'active' => 'success',
                                        'trialing' => 'info',
                                        'past_due' => 'warning',
                                        'canceled', 'unpaid' => 'error',
                                        default => 'muted'
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
                                <td class="text-right">
                                    <?php if ($sub['stripe_subscription_status'] === 'active' && !$sub['cancel_at_period_end']): ?>
                                        <form method="POST" onsubmit="return confirm('Är du säker på att du vill avsluta denna prenumeration?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="cancel_subscription">
                                            <input type="hidden" name="subscription_id" value="<?= $sub['id'] ?>">
                                            <button type="submit" class="btn-admin btn-admin-ghost btn-sm text-error">
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
<?php endif; ?>
