<?php
/**
 * TheHUB Checkout Page
 *
 * Displays payment options for event registrations:
 * - Card payment via Stripe Checkout
 * - Discount codes and Gravity ID discounts
 */

require_once __DIR__ . '/../hub-config.php';
require_once __DIR__ . '/../includes/payment.php';

// Get order ID from URL
$orderId = isset($_GET['order']) ? intval($_GET['order']) : 0;

// Or create order from registration IDs (event registrations)
$registrationIds = isset($_GET['registration']) ? explode(',', $_GET['registration']) : [];
$registrationIds = array_filter(array_map('intval', $registrationIds));

// Or handle series registration
$seriesRegistrationId = isset($_GET['type']) && $_GET['type'] === 'series' ? intval($_GET['id'] ?? 0) : 0;

// Discount code from form
$discountCode = isset($_POST['discount_code']) ? trim($_POST['discount_code']) : null;
$discountCode = $discountCode ?: (isset($_GET['discount_code']) ? trim($_GET['discount_code']) : null);

$order = null;
$error = null;
$appliedDiscounts = [];
$gravityIdInfo = null;
$isSeries = false;
$seriesInfo = null;
$stripeSuccess = isset($_GET['stripe_success']);
$stripeCancelled = isset($_GET['stripe_cancelled']);

try {
    if ($orderId) {
        // Load existing order
        $order = getOrder($orderId);
        if (!$order) {
            $error = 'Order hittades inte.';
        }
        // If returning from Stripe success, check payment status
        if ($order && $stripeSuccess && $order['payment_status'] !== 'paid') {
            // Payment may not be confirmed yet by webhook - show pending message
            $order['stripe_pending'] = true;
        }
    } elseif ($seriesRegistrationId) {
        // Handle series registration checkout
        if (!hub_is_logged_in()) {
            header('Location: /login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }

        $currentUser = hub_current_user();
        $pdo = hub_db();

        // Get series registration
        $stmt = $pdo->prepare("
            SELECT sr.*, s.name as series_name, s.logo as series_logo,
                   c.name as class_name, c.display_name as class_display_name
            FROM series_registrations sr
            JOIN series s ON sr.series_id = s.id
            JOIN classes c ON sr.class_id = c.id
            WHERE sr.id = ?
        ");
        $stmt->execute([$seriesRegistrationId]);
        $seriesReg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$seriesReg) {
            $error = 'Serie-registrering hittades inte.';
        } elseif ($seriesReg['rider_id'] !== $currentUser['id'] &&
                  !hub_is_parent_of($currentUser['id'], $seriesReg['rider_id'])) {
            $error = 'Du har inte behörighet att betala för denna registrering.';
        } else {
            $isSeries = true;
            $seriesInfo = $seriesReg;

            // Check if order already exists
            if (!empty($seriesReg['order_id'])) {
                $order = getOrder($seriesReg['order_id']);
            }

            if (!$order) {
                // Create new series order
                $orderData = createSeriesOrder($seriesRegistrationId, $seriesReg['rider_id']);
                $order = getOrder($orderData['order_id']);
                $order['card_available'] = !empty(env('STRIPE_SECRET_KEY', ''));
            }
        }
    } elseif (!empty($registrationIds)) {
        // Check user is logged in
        if (!hub_is_logged_in()) {
            header('Location: /login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }

        $currentUser = hub_current_user();

        // Verify user owns these registrations
        $pdo = hub_db();
        $placeholders = implode(',', array_fill(0, count($registrationIds), '?'));
        $stmt = $pdo->prepare("
            SELECT er.*, e.id as event_id
            FROM event_registrations er
            JOIN events e ON er.event_id = e.id
            WHERE er.id IN ({$placeholders})
        ");
        $stmt->execute($registrationIds);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($registrations)) {
            $error = 'Inga giltiga registreringar hittades.';
        } else {
            // Verify ownership
            foreach ($registrations as $reg) {
                if ($reg['rider_id'] !== $currentUser['id'] &&
                    !hub_is_parent_of($currentUser['id'], $reg['rider_id'])) {
                    $error = 'Du har inte behörighet att betala för dessa registreringar.';
                    break;
                }
            }

            if (!$error) {
                $eventId = $registrations[0]['event_id'];

                // Check for Gravity ID (for display before order creation)
                $gravityIdInfo = checkGravityIdDiscount($currentUser['id'], $eventId);

                // Check if order already exists
                $stmt = $pdo->prepare("
                    SELECT order_id FROM event_registrations
                    WHERE id = ? AND order_id IS NOT NULL
                ");
                $stmt->execute([$registrationIds[0]]);
                $existingOrderId = $stmt->fetchColumn();

                if ($existingOrderId) {
                    // Use existing order
                    $order = getOrder($existingOrderId);
                } else {
                    // Create new order with discount code
                    $orderData = createOrder($registrationIds, $currentUser['id'], $eventId, $discountCode);
                    $order = getOrder($orderData['order_id']);
                    $appliedDiscounts = $orderData['applied_discounts'] ?? [];

                    $order['card_available'] = !empty(env('STRIPE_SECRET_KEY', ''));
                    $order['applied_discounts'] = $appliedDiscounts;
                }
            }
        }
    } else {
        $error = 'Ingen order eller registrering angiven.';
    }

    // Set Stripe availability if not already set
    if ($order && !isset($order['card_available'])) {
        $order['card_available'] = !empty(env('STRIPE_SECRET_KEY', ''));
    }

} catch (Exception $e) {
    $error = 'Ett fel uppstod: ' . $e->getMessage();
}

// Page setup
$pageInfo = [
    'title' => 'Betalning',
    'section' => 'checkout'
];

include __DIR__ . '/../components/header.php';
?>

<main class="main-content">
    <div class="container container--sm">
        <?php if ($error): ?>
            <div class="card">
                <div class="card-body text-center py-xl">
                    <i data-lucide="alert-circle" class="icon-xl text-error mb-md"></i>
                    <h1 class="text-xl mb-sm">Ett fel uppstod</h1>
                    <p class="text-secondary mb-lg"><?= htmlspecialchars($error) ?></p>
                    <a href="/calendar" class="btn btn--primary">
                        <i data-lucide="calendar"></i>
                        Till kalendern
                    </a>
                </div>
            </div>

        <?php elseif ($order && $order['payment_status'] === 'paid'): ?>
            <!-- Already paid -->
            <div class="card">
                <div class="card-body text-center py-xl">
                    <i data-lucide="check-circle" class="icon-xl text-success mb-md"></i>
                    <h1 class="text-xl mb-sm">Betalning genomförd!</h1>
                    <p class="text-secondary mb-lg">
                        Order <strong><?= htmlspecialchars($order['order_number']) ?></strong> är betald.
                    </p>
                    <a href="/profile/receipts" class="btn btn--primary">
                        <i data-lucide="shopping-bag"></i>
                        Mina köp
                    </a>
                </div>
            </div>
            <script>
            // Clear cart after successful payment
            if (typeof GlobalCart !== 'undefined') {
                GlobalCart.clearCart();
            }
            </script>

        <?php elseif ($order && !empty($order['stripe_pending'])): ?>
            <!-- Stripe payment processing -->
            <div class="card">
                <div class="card-body text-center py-xl">
                    <i data-lucide="loader" class="icon-xl text-accent mb-md spin"></i>
                    <h1 class="text-xl mb-sm">Betalning bearbetas</h1>
                    <p class="text-secondary mb-lg">
                        Din betalning behandlas. Sidan uppdateras automatiskt.
                    </p>
                    <p class="text-sm text-secondary">
                        Order: <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                    </p>
                </div>
            </div>
            <script>
            // Auto-refresh to check if payment confirmed
            setTimeout(function() { window.location.reload(); }, 5000);
            </script>

        <?php elseif ($order): ?>
            <!-- Order summary -->
            <div class="card mb-lg">
                <div class="card-header">
                    <h1 class="text-lg">
                        <i data-lucide="shopping-cart"></i>
                        Betalning
                    </h1>
                </div>
                <div class="card-body">
                    <!-- Series info (for series registration) -->
                    <?php if ($isSeries && $seriesInfo): ?>
                    <div class="mb-md pb-md border-bottom">
                        <div class="flex items-center gap-md">
                            <?php if (!empty($seriesInfo['series_logo'])): ?>
                            <img src="<?= htmlspecialchars($seriesInfo['series_logo']) ?>" alt="" style="width:48px;height:48px;object-fit:contain;">
                            <?php endif; ?>
                            <div>
                                <div class="text-sm text-secondary">Serie-pass</div>
                                <div class="font-medium"><?= htmlspecialchars($seriesInfo['series_name']) ?></div>
                                <div class="text-sm text-secondary">
                                    <?= htmlspecialchars($seriesInfo['class_display_name'] ?: $seriesInfo['class_name']) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif (!empty($order['event_name'])): ?>
                    <!-- Event info -->
                    <div class="mb-md pb-md border-bottom">
                        <div class="text-sm text-secondary">Event</div>
                        <div class="font-medium"><?= htmlspecialchars($order['event_name']) ?></div>
                        <div class="text-sm text-secondary">
                            <?= date('j M Y', strtotime($order['event_date'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Order items -->
                    <div class="mb-md">
                        <div class="text-sm text-secondary mb-sm">Anmälningar</div>
                        <?php foreach ($order['items'] as $item): ?>
                        <div class="flex justify-between items-center py-sm">
                            <span><?= htmlspecialchars($item['description']) ?></span>
                            <span class="font-medium"><?= number_format($item['total_price'], 0) ?> kr</span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Subtotal -->
                    <div class="flex justify-between items-center py-sm border-top">
                        <span>Delsumma</span>
                        <span class="font-medium"><?= number_format($order['subtotal'], 0) ?> kr</span>
                    </div>

                    <!-- Applied discounts -->
                    <?php if (!empty($order['applied_discounts']) || !empty($appliedDiscounts)): ?>
                        <?php $discounts = !empty($order['applied_discounts']) ? $order['applied_discounts'] : $appliedDiscounts; ?>
                        <?php foreach ($discounts as $discount): ?>
                        <div class="flex justify-between items-center py-sm text-success">
                            <span>
                                <i data-lucide="<?= $discount['type'] === 'gravity_id' ? 'badge-check' : 'tag' ?>" class="icon-sm"></i>
                                <?= htmlspecialchars($discount['label']) ?>
                            </span>
                            <span class="font-medium">-<?= number_format($discount['amount'], 0) ?> kr</span>
                        </div>
                        <?php endforeach; ?>
                    <?php elseif ($order['discount'] > 0): ?>
                        <div class="flex justify-between items-center py-sm text-success">
                            <span>
                                <i data-lucide="tag" class="icon-sm"></i>
                                Rabatt
                            </span>
                            <span class="font-medium">-<?= number_format($order['discount'], 0) ?> kr</span>
                        </div>
                    <?php endif; ?>

                    <!-- Show Gravity ID badge if applicable but no discount yet applied -->
                    <?php if ($gravityIdInfo && $gravityIdInfo['has_gravity_id'] && empty($appliedDiscounts)): ?>
                    <div class="flex justify-between items-center py-sm text-success">
                        <span>
                            <i data-lucide="badge-check" class="icon-sm"></i>
                            Gravity ID: <?= htmlspecialchars($gravityIdInfo['gravity_id']) ?>
                        </span>
                        <span class="font-medium">-<?= number_format($gravityIdInfo['discount'], 0) ?> kr</span>
                    </div>
                    <?php endif; ?>

                    <!-- Total -->
                    <div class="flex justify-between items-center pt-md border-top">
                        <span class="font-semibold">Att betala</span>
                        <span class="text-xl font-bold"><?= number_format($order['total_amount'], 0) ?> kr</span>
                    </div>

                    <!-- Order reference -->
                    <div class="mt-md pt-md border-top text-sm text-secondary">
                        Order: <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Discount code input (if order not yet paid) -->
            <?php if ($order['payment_status'] !== 'paid' && empty($order['discount_code_id'])): ?>
            <div class="card mb-lg">
                <div class="card-header">
                    <h2 class="text-lg">
                        <i data-lucide="tag"></i>
                        Rabattkod
                    </h2>
                </div>
                <div class="card-body">
                    <form method="GET" action="/checkout" id="discount-form" class="flex gap-sm">
                        <input type="hidden" name="order" value="<?= $order['id'] ?>">
                        <input type="text"
                               name="discount_code"
                               id="discount-code-input"
                               placeholder="Ange rabattkod"
                               class="form-input flex-1"
                               value="<?= htmlspecialchars($discountCode ?? '') ?>"
                               style="text-transform: uppercase;">
                        <button type="submit" class="btn btn--secondary" id="apply-discount-btn">
                            <i data-lucide="check"></i>
                            Använd
                        </button>
                    </form>
                    <div id="discount-message" class="mt-sm text-sm" style="display: none;"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payment options -->
            <div class="card">
                <div class="card-header">
                    <h2 class="text-lg">
                        <i data-lucide="credit-card"></i>
                        Betalning
                    </h2>
                </div>
                <div class="card-body">
                    <?php if (!empty($order['card_available'])): ?>
                    <!-- Stripe Checkout -->
                    <div class="payment-option payment-option--card">
                        <div class="flex items-center gap-md mb-md">
                            <div style="width:48px;height:48px;background:linear-gradient(135deg,#635bff,#5851db);border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;">
                                <i data-lucide="credit-card" style="width:24px;height:24px;color:white;"></i>
                            </div>
                            <div>
                                <h3 class="font-medium">Betala med kort</h3>
                                <p class="text-sm text-secondary">Visa, Mastercard, Apple Pay, Google Pay</p>
                            </div>
                        </div>

                        <button type="button"
                                id="stripe-pay-btn"
                                class="btn btn--primary btn--lg w-full"
                                onclick="startStripeCheckout(<?= $order['id'] ?>)">
                            <i data-lucide="lock"></i>
                            Betala <?= number_format($order['total_amount'], 0) ?> kr
                        </button>

                        <p class="text-xs text-secondary text-center mt-sm">
                            <i data-lucide="shield-check" class="icon-xs"></i>
                            Sakra betalningar via Stripe. Vi lagrar inga kortuppgifter.
                        </p>
                    </div>
                    <?php elseif (!empty($order['swish_number'])): ?>
                    <!-- Swish Payment -->
                    <div class="payment-option">
                        <div class="flex items-center gap-md mb-md">
                            <div style="width:48px;height:48px;background:#FF5C13;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;">
                                <i data-lucide="smartphone" style="width:24px;height:24px;color:white;"></i>
                            </div>
                            <div>
                                <h3 class="font-medium">Betala med Swish</h3>
                                <p class="text-sm text-secondary">Öppna Swish-appen för att betala</p>
                            </div>
                        </div>

                        <div style="background: var(--color-bg-card); padding: var(--space-lg); border-radius: var(--radius-md); border: 2px dashed var(--color-border);">
                            <div style="text-align: center; margin-bottom: var(--space-md);">
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-xs);">Swish-nummer</div>
                                <div style="font-size: var(--text-2xl); font-weight: var(--weight-bold); font-family: monospace; color: var(--color-accent);">
                                    <?= htmlspecialchars($order['swish_number']) ?>
                                </div>
                            </div>

                            <div style="text-align: center; margin-bottom: var(--space-md);">
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-xs);">Belopp</div>
                                <div style="font-size: var(--text-3xl); font-weight: var(--weight-bold); color: var(--color-text-primary);">
                                    <?= number_format($order['total_amount'], 0) ?> kr
                                </div>
                            </div>

                            <?php if (!empty($order['swish_message'])): ?>
                            <div style="text-align: center; margin-bottom: var(--space-md);">
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-xs);">Meddelande (viktigt!)</div>
                                <div style="font-size: var(--text-lg); font-weight: var(--weight-semibold); font-family: monospace; color: var(--color-accent); padding: var(--space-sm); background: var(--color-accent-light); border-radius: var(--radius-sm);">
                                    <?= htmlspecialchars($order['swish_message']) ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div style="text-align: center; padding: var(--space-md); background: var(--color-bg-page); border-radius: var(--radius-sm);">
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-sm);">
                                    <i data-lucide="info" style="width: 16px; height: 16px;"></i>
                                    Instruktioner
                                </div>
                                <ol style="text-align: left; font-size: var(--text-sm); color: var(--color-text-secondary); padding-left: var(--space-lg); margin: 0;">
                                    <li>Öppna Swish-appen</li>
                                    <li>Välj "Betala"</li>
                                    <li>Ange nummer: <strong><?= htmlspecialchars($order['swish_number']) ?></strong></li>
                                    <li>Ange belopp: <strong><?= number_format($order['total_amount'], 0) ?> kr</strong></li>
                                    <li>Ange meddelande: <strong><?= htmlspecialchars($order['swish_message'] ?? $order['order_number']) ?></strong></li>
                                    <li>Bekräfta betalningen</li>
                                </ol>
                            </div>
                        </div>

                        <div style="margin-top: var(--space-md); padding: var(--space-md); background: var(--color-accent-light); border-radius: var(--radius-sm); border-left: 4px solid var(--color-accent);">
                            <div style="font-size: var(--text-sm); color: var(--color-text-primary);">
                                <i data-lucide="alert-triangle" style="width: 16px; height: 16px; color: var(--color-accent);"></i>
                                <strong>OBS!</strong> Glöm inte att ange orderreferensen som meddelande i Swish. Din anmälan aktiveras automatiskt när betalningen mottagits.
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- No payment method available -->
                    <div class="text-center py-lg">
                        <i data-lucide="alert-circle" class="icon-lg text-warning mb-md"></i>
                        <h3 class="font-medium mb-sm">Betalning ej tillganglig</h3>
                        <p class="text-sm text-secondary">
                            Betalningssystemet ar inte konfigurerat annu.
                            Kontakta arrangoren for betalningsinstruktioner.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment confirmation info -->
            <div class="card mt-lg">
                <div class="card-body">
                    <h3 class="font-medium mb-sm">
                        <i data-lucide="clock"></i>
                        Efter betalning
                    </h3>
                    <p class="text-sm text-secondary">
                        Din anmalan bekraftas automatiskt nar betalningen ar genomford.
                        <?php if (!empty($order['customer_email'])): ?>
                        Du far ett bekraftelsemail till <strong><?= htmlspecialchars($order['customer_email']) ?></strong>.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
// Stripe Checkout
async function startStripeCheckout(orderId) {
    const btn = document.getElementById('stripe-pay-btn');
    if (!btn) return;

    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" class="spin"></i> Forbereder betalning...';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    try {
        const formData = new FormData();
        formData.append('order_id', orderId);

        const response = await fetch('/api/create-checkout-session.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success && data.url) {
            // Redirect to Stripe Checkout
            window.location.href = data.url;
        } else {
            alert(data.error || 'Kunde inte starta betalning. Forsok igen.');
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="lock"></i> Betala <?= number_format($order['total_amount'] ?? 0, 0) ?> kr';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (error) {
        alert('Nagot gick fel. Forsok igen.');
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="lock"></i> Betala <?= number_format($order['total_amount'] ?? 0, 0) ?> kr';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

// Discount code AJAX handling
document.addEventListener('DOMContentLoaded', function() {
    const discountForm = document.getElementById('discount-form');
    const discountInput = document.getElementById('discount-code-input');
    const discountMessage = document.getElementById('discount-message');
    const applyBtn = document.getElementById('apply-discount-btn');

    if (discountForm) {
        discountForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const code = discountInput.value.trim().toUpperCase();
            if (!code) {
                showMessage('Ange en rabattkod', 'error');
                return;
            }

            applyBtn.disabled = true;
            applyBtn.innerHTML = '<i data-lucide="loader" class="spin"></i> Kontrollerar...';
            if (typeof lucide !== 'undefined') lucide.createIcons();

            try {
                const formData = new FormData();
                formData.append('order_id', '<?= $order['id'] ?? 0 ?>');
                formData.append('code', code);

                const response = await fetch('/api/apply-discount.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showMessage('Rabattkod tillampad! Du sparade ' + data.discount_amount + ' kr', 'success');
                    setTimeout(() => { window.location.reload(); }, 1000);
                } else {
                    showMessage(data.error || 'Ogiltig rabattkod', 'error');
                }
            } catch (error) {
                showMessage('Nagot gick fel. Forsok igen.', 'error');
            }

            applyBtn.disabled = false;
            applyBtn.innerHTML = '<i data-lucide="check"></i> Anvand';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    }

    function showMessage(text, type) {
        if (!discountMessage) return;
        discountMessage.textContent = text;
        discountMessage.className = 'mt-sm text-sm ' + (type === 'success' ? 'text-success' : 'text-error');
        discountMessage.style.display = 'block';
    }
});
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
