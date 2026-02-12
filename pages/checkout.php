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

// Auto-cleanup expired pending orders
if (file_exists(__DIR__ . '/../includes/order-manager.php')) {
    require_once __DIR__ . '/../includes/order-manager.php';
    if (function_exists('cleanupExpiredOrders')) {
        cleanupExpiredOrders();
    }
}

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
$seriesDiscountAmount = 0;
$perRegGravityId = [];
$perItemSeriesItems = [];
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

        // Build discount breakdown for display
        if ($order && $order['discount'] > 0) {
            $pdo = hub_db();

            // Calculate Gravity ID portion of discount (with per-registration tracking)
            if (hub_is_logged_in()) {
                $currentUser = hub_current_user();
                try {
                    // Get registrations with IDs for per-item display
                    $regStmt = $pdo->prepare("SELECT id, event_id, rider_id FROM event_registrations WHERE order_id = ?");
                    $regStmt->execute([$order['id']]);
                    $orderRegs = $regStmt->fetchAll(PDO::FETCH_ASSOC);

                    $gravityIdTotal = 0;
                    $gid = null;
                    foreach ($orderRegs as $reg) {
                        $info = checkGravityIdDiscount($reg['rider_id'], $reg['event_id']);
                        if ($info['has_gravity_id'] && $info['discount'] > 0) {
                            $gravityIdTotal += $info['discount'];
                            $perRegGravityId[$reg['id']] = $info['discount'];
                            if (!$gid) $gid = $info['gravity_id'];
                        }
                    }

                    if ($gravityIdTotal > 0 && $gid) {
                        $gravityIdInfo = [
                            'has_gravity_id' => true,
                            'gravity_id' => $gid,
                            'discount' => $gravityIdTotal
                        ];
                    }
                } catch (Exception $e) {
                    // Column may not exist
                }
            }

            // Series discount = total discount minus Gravity ID discount
            $seriesDiscountAmount = $order['discount'] - ($gravityIdInfo['discount'] ?? 0);

            // Track which items are part of a series (for inline badge)
            if ($seriesDiscountAmount > 0) {
                foreach ($order['items'] as $item) {
                    if (!empty($item['registration_id'])) {
                        $perItemSeriesItems[$item['registration_id']] = true;
                    }
                }
            }
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
                        <?php if (!empty($order['customer_email'])): ?>
                        <br>Kvitto skickas till <strong><?= htmlspecialchars($order['customer_email']) ?></strong>.
                        <?php endif; ?>
                    </p>
                    <div style="display:flex; flex-direction:column; gap:var(--space-sm); align-items:center;">
                        <?php if (!empty($order['event_id'])): ?>
                        <a href="/calendar/event/<?= (int)$order['event_id'] ?>" class="btn btn--primary">
                            <i data-lucide="calendar"></i> Till eventet
                        </a>
                        <?php endif; ?>
                        <a href="/profile/receipts" class="btn btn--secondary">
                            <i data-lucide="receipt"></i> Mina kvitton
                        </a>
                        <a href="/calendar" class="btn btn--ghost">
                            <i data-lucide="calendar"></i> Kalender
                        </a>
                    </div>
                </div>
            </div>
            <script>
            // Clear cart after successful payment
            if (typeof GlobalCart !== 'undefined') {
                GlobalCart.clearCart();
            }
            sessionStorage.removeItem('pending_order_id');
            </script>

        <?php elseif ($order && !empty($order['stripe_pending'])): ?>
            <!-- Stripe payment processing -->
            <div class="card">
                <div class="card-body text-center py-xl">
                    <i data-lucide="loader" class="icon-xl text-accent mb-md spin" id="pending-spinner"></i>
                    <h1 class="text-xl mb-sm" id="pending-title">Betalning bearbetas</h1>
                    <p class="text-secondary mb-lg" id="pending-message">
                        Din betalning behandlas. Sidan uppdateras automatiskt.
                    </p>
                    <p class="text-sm text-secondary">
                        Order: <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                    </p>
                    <p class="text-xs text-muted mt-md" id="pending-timer"></p>
                    <div id="pending-links" style="display:none; margin-top: var(--space-lg);">
                        <?php if (!empty($order['event_id'])): ?>
                        <a href="/calendar/event/<?= (int)$order['event_id'] ?>" class="btn btn--primary" style="margin-bottom:var(--space-sm);">
                            <i data-lucide="calendar"></i> Till eventet
                        </a>
                        <?php endif; ?>
                        <a href="/calendar" class="btn btn--secondary" style="margin-bottom:var(--space-sm);">
                            <i data-lucide="calendar"></i> Kalender
                        </a>
                        <a href="/profile/receipts" class="btn btn--ghost">
                            <i data-lucide="receipt"></i> Mina kvitton
                        </a>
                    </div>
                </div>
            </div>
            <script>
            // Clear cart immediately - payment was sent to Stripe
            if (typeof GlobalCart !== 'undefined') {
                GlobalCart.clearCart();
            }
            sessionStorage.removeItem('pending_order_id');

            // Poll for payment confirmation - check-order-status now verifies with Stripe directly
            (function() {
                let attempts = 0;
                const maxAttempts = 6;
                const orderId = <?= (int)$order['id'] ?>;

                function checkPayment() {
                    attempts++;
                    const timerEl = document.getElementById('pending-timer');
                    const messageEl = document.getElementById('pending-message');
                    const titleEl = document.getElementById('pending-title');
                    const spinnerEl = document.getElementById('pending-spinner');
                    const linksEl = document.getElementById('pending-links');

                    if (attempts > maxAttempts) {
                        if (titleEl) titleEl.textContent = 'Betalning mottagen!';
                        if (messageEl) messageEl.innerHTML = 'Betalningen är mottagen av Stripe!<br>Bekräftelsen kan ta några minuter. Du får ett bekräftelsemail.';
                        if (timerEl) timerEl.textContent = '';
                        if (spinnerEl) { spinnerEl.classList.remove('spin'); spinnerEl.setAttribute('data-lucide', 'check-circle'); spinnerEl.style.color = 'var(--color-success)'; }
                        if (linksEl) linksEl.style.display = 'block';
                        if (typeof lucide !== 'undefined') lucide.createIcons();
                        return;
                    }

                    if (timerEl) timerEl.textContent = 'Kontrollerar betalning...';

                    fetch('/api/check-order-status.php?order_id=' + orderId)
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.payment_status === 'paid') {
                                // Payment confirmed! Reload to show success page
                                window.location.href = '/checkout?order=' + orderId;
                            } else {
                                setTimeout(checkPayment, 3000);
                            }
                        })
                        .catch(function() {
                            setTimeout(checkPayment, 3000);
                        });
                }

                setTimeout(checkPayment, 2000);
            })();
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
                        <div class="py-sm">
                            <div class="flex justify-between items-center">
                                <span><?= htmlspecialchars($item['description']) ?></span>
                                <span class="font-medium"><?= number_format($item['total_price'], 0) ?> kr</span>
                            </div>
                            <?php if (!empty($perItemSeriesItems[$item['registration_id'] ?? 0])): ?>
                            <div class="text-xs text-success" style="display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                                <i data-lucide="tag" style="width:12px;height:12px;"></i> Serie
                            </div>
                            <?php endif; ?>
                            <?php $regId = $item['registration_id'] ?? 0; ?>
                            <?php if (!empty($perRegGravityId[$regId])): ?>
                            <div class="text-xs text-success" style="display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                                <i data-lucide="badge-check" style="width:12px;height:12px;"></i> Gravity ID: -<?= $perRegGravityId[$regId] ?> kr
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Subtotal -->
                    <div class="flex justify-between items-center py-sm border-top">
                        <span>Delsumma</span>
                        <span class="font-medium"><?= number_format($order['subtotal'], 0) ?> kr</span>
                    </div>

                    <!-- Applied discounts (from direct registration checkout) -->
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
                    <?php else: ?>
                        <?php if (!empty($seriesDiscountAmount) && $seriesDiscountAmount > 0): ?>
                        <div class="flex justify-between items-center py-sm text-success">
                            <span>
                                <i data-lucide="tag" class="icon-sm"></i>
                                Serierabatt
                            </span>
                            <span class="font-medium">-<?= number_format($seriesDiscountAmount, 0) ?> kr</span>
                        </div>
                        <?php endif; ?>

                        <?php if ($gravityIdInfo && $gravityIdInfo['has_gravity_id'] && $gravityIdInfo['discount'] > 0): ?>
                        <div class="flex justify-between items-center py-sm text-success">
                            <span>
                                <i data-lucide="badge-check" class="icon-sm"></i>
                                Gravity ID: <?= htmlspecialchars($gravityIdInfo['gravity_id']) ?>
                            </span>
                            <span class="font-medium">-<?= number_format($gravityIdInfo['discount'], 0) ?> kr</span>
                        </div>
                        <?php endif; ?>

                        <?php if ($order['discount'] > 0 && empty($gravityIdInfo) && empty($seriesDiscountAmount)): ?>
                        <div class="flex justify-between items-center py-sm text-success">
                            <span>
                                <i data-lucide="tag" class="icon-sm"></i>
                                Rabatt
                            </span>
                            <span class="font-medium">-<?= number_format($order['discount'], 0) ?> kr</span>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Total -->
                    <div class="flex justify-between items-center pt-md border-top">
                        <span class="font-semibold">Att betala</span>
                        <span class="text-xl font-bold"><?= number_format($order['total_amount'], 0) ?> kr</span>
                    </div>

                    <!-- VAT info (6% inclusive) -->
                    <?php
                    $vatAmount = round($order['total_amount'] * 6 / 106);
                    ?>
                    <div class="flex justify-between items-center pt-xs">
                        <span class="text-xs text-muted">varav moms (6%)</span>
                        <span class="text-xs text-muted"><?= number_format($vatAmount, 0) ?> kr</span>
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
            <?php
            $hasStripe = !empty($order['card_available']);
            $hasSwish = !empty($order['swish_number']);
            $hasAnyPayment = $hasStripe || $hasSwish;
            $swishMessage = $order['order_number'] ?? '';
            ?>

            <?php if ($hasStripe): ?>
            <!-- Stripe Card Payment -->
            <div class="card mb-lg">
                <div class="card-header">
                    <h2 class="text-lg">
                        <i data-lucide="credit-card"></i>
                        Betala med kort
                    </h2>
                </div>
                <div class="card-body">
                    <div class="payment-option payment-option--card">
                        <div class="flex items-center gap-sm mb-md">
                            <div style="width:40px;height:40px;flex-shrink:0;background:linear-gradient(135deg,#635bff,#5851db);border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;">
                                <i data-lucide="credit-card" style="width:20px;height:20px;color:white;"></i>
                            </div>
                            <div style="min-width:0;">
                                <h3 class="font-medium">Kortbetalning</h3>
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
                            Säkra betalningar via Stripe. Vi lagrar inga kortuppgifter.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($hasSwish): ?>
            <!-- Swish QR Payment (manually reconciled) -->
            <div class="card mb-lg">
                <div class="card-header">
                    <h2 class="text-lg">
                        <i data-lucide="smartphone"></i>
                        Betala med Swish
                    </h2>
                </div>
                <div class="card-body">
                    <div class="payment-option">
                        <div class="flex items-center gap-sm mb-md">
                            <div style="width:40px;height:40px;flex-shrink:0;background:#FF5C13;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;">
                                <i data-lucide="smartphone" style="width:20px;height:20px;color:white;"></i>
                            </div>
                            <div style="min-width:0;">
                                <h3 class="font-medium">Swish</h3>
                                <p class="text-sm text-secondary">Scanna QR-koden eller ange uppgifterna manuellt</p>
                            </div>
                        </div>

                        <?php
                        // Generate Swish QR code URL
                        $swishQrUrl = '';
                        if (function_exists('generateSwishQR')) {
                            $swishQrUrl = generateSwishQR($order['swish_number'], $order['total_amount'], $swishMessage, 250);
                        }
                        // Generate Swish deep link for mobile
                        $swishUrl = '';
                        if (function_exists('generateSwishUrl')) {
                            $swishUrl = generateSwishUrl($order['swish_number'], $order['total_amount'], $swishMessage);
                        }
                        ?>

                        <div style="background: var(--color-bg-card); padding: var(--space-md); border-radius: var(--radius-md); border: 2px dashed var(--color-border);">
                            <?php if ($swishUrl): ?>
                            <div style="text-align: center; margin-bottom: var(--space-md);">
                                <a href="<?= htmlspecialchars($swishUrl) ?>" class="btn btn--secondary w-full" style="background:#FF5C13;color:white;border-color:#FF5C13;">
                                    <i data-lucide="smartphone"></i>
                                    Öppna Swish-appen
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if ($swishQrUrl): ?>
                            <div style="text-align: center; margin-bottom: var(--space-md);">
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-sm);">Scanna med Swish-appen</div>
                                <img src="<?= htmlspecialchars($swishQrUrl) ?>" alt="Swish QR-kod" style="max-width:200px;width:100%;height:auto;aspect-ratio:1;margin:0 auto;display:block;border-radius:var(--radius-sm);background:white;padding:var(--space-sm);">
                            </div>
                            <?php endif; ?>

                            <div style="text-align: center; margin-bottom: var(--space-md);">
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-xs);">Swish-nummer</div>
                                <div style="font-size: var(--text-xl); font-weight: var(--weight-bold); font-family: monospace; color: var(--color-accent);">
                                    <?= htmlspecialchars($order['swish_number']) ?>
                                </div>
                                <?php if (!empty($order['swish_name'])): ?>
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary);"><?= htmlspecialchars($order['swish_name']) ?></div>
                                <?php endif; ?>
                            </div>

                            <div style="text-align: center; margin-bottom: var(--space-md);">
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-xs);">Belopp</div>
                                <div style="font-size: var(--text-2xl); font-weight: var(--weight-bold); color: var(--color-text-primary);">
                                    <?= number_format($order['total_amount'], 0) ?> kr
                                </div>
                            </div>

                            <div style="text-align: center;">
                                <div style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-xs);">Meddelande (viktigt!)</div>
                                <div style="font-size: var(--text-base); font-weight: var(--weight-semibold); font-family: monospace; color: var(--color-accent); padding: var(--space-sm); background: var(--color-accent-light); border-radius: var(--radius-sm); word-break: break-all;">
                                    <?= htmlspecialchars($swishMessage) ?>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top: var(--space-md); padding: var(--space-sm) var(--space-md); background: var(--color-accent-light); border-radius: var(--radius-sm); border-left: 4px solid var(--color-accent);">
                            <div style="font-size: var(--text-sm); color: var(--color-text-primary); display: flex; gap: var(--space-xs); align-items: flex-start;">
                                <i data-lucide="info" style="width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; color: var(--color-accent);"></i>
                                <span><strong>OBS!</strong> Ange ordernumret <strong><?= htmlspecialchars($swishMessage) ?></strong> som meddelande i Swish.
                                Din anmälan bekräftas manuellt efter att betalningen verifierats.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$hasAnyPayment): ?>
            <!-- No payment method available -->
            <div class="card mb-lg">
                <div class="card-body">
                    <div class="text-center py-lg">
                        <i data-lucide="alert-circle" class="icon-lg text-warning mb-md"></i>
                        <h3 class="font-medium mb-sm">Betalning ej tillgänglig</h3>
                        <p class="text-sm text-secondary">
                            Betalningssystemet är inte konfigurerat för detta event.
                            Kontakta arrangören för betalningsinstruktioner.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payment confirmation info -->
            <div class="card mt-lg">
                <div class="card-body">
                    <h3 class="font-medium mb-sm">
                        <i data-lucide="clock"></i>
                        Efter betalning
                    </h3>
                    <p class="text-sm text-secondary">
                        <?php if ($hasStripe): ?>
                        Kortbetalning bekräftas automatiskt.
                        <?php endif; ?>
                        <?php if ($hasSwish): ?>
                        Swish-betalning bekräftas manuellt av arrangören.
                        <?php endif; ?>
                        <?php if (!empty($order['customer_email'])): ?>
                        Du får ett bekräftelsemail till <strong><?= htmlspecialchars($order['customer_email']) ?></strong>.
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
    btn.innerHTML = '<i data-lucide="loader" class="spin"></i> Förbereder betalning...';
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
            // Clear cart BEFORE redirecting to Stripe - payment is committed
            if (typeof GlobalCart !== 'undefined') {
                GlobalCart.clearCart();
            }
            sessionStorage.removeItem('pending_order_id');
            // Redirect to Stripe Checkout
            window.location.href = data.url;
        } else {
            alert(data.error || 'Kunde inte starta betalning. Försök igen.');
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="lock"></i> Betala <?= number_format($order['total_amount'] ?? 0, 0) ?> kr';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (error) {
        alert('Något gick fel. Försök igen.');
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
                showMessage('Något gick fel. Försök igen.', 'error');
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
