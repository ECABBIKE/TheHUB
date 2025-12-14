<?php
/**
 * TheHUB V3.5 - Checkout Page
 *
 * Displays payment options for event registrations:
 * - Swish (direct link on mobile, QR on desktop)
 * - Card via WooCommerce (fallback)
 */

require_once __DIR__ . '/../v3-config.php';
require_once __DIR__ . '/../includes/payment.php';

// Get order ID from URL
$orderId = isset($_GET['order']) ? intval($_GET['order']) : 0;

// Or create order from registration IDs
$registrationIds = isset($_GET['registration']) ? explode(',', $_GET['registration']) : [];
$registrationIds = array_filter(array_map('intval', $registrationIds));

$order = null;
$error = null;

try {
    if ($orderId) {
        // Load existing order
        $order = getOrder($orderId);
        if (!$order) {
            $error = 'Order hittades inte.';
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
                    // Create new order
                    $eventId = $registrations[0]['event_id'];
                    $orderData = createOrder($registrationIds, $currentUser['id'], $eventId);
                    $order = getOrder($orderData['order_id']);

                    // Add payment URLs to order
                    $order['swish_url'] = $orderData['swish_url'];
                    $order['swish_qr'] = $orderData['swish_qr'];
                    $order['swish_available'] = $orderData['swish_available'];
                    $order['card_available'] = $orderData['card_available'];
                }
            }
        }
    } else {
        $error = 'Ingen order eller registrering angiven.';
    }

    // If order exists but doesn't have swish URLs, generate them
    if ($order && empty($order['swish_url']) && !empty($order['swish_number'])) {
        $order['swish_url'] = generateSwishUrl(
            $order['swish_number'],
            $order['total_amount'],
            $order['swish_message']
        );
        $order['swish_qr'] = generateSwishQR(
            $order['swish_number'],
            $order['total_amount'],
            $order['swish_message']
        );
        $order['swish_available'] = true;
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
                    <a href="/profile/registrations" class="btn btn--primary">
                        <i data-lucide="ticket"></i>
                        Mina anmälningar
                    </a>
                </div>
            </div>

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
                    <!-- Event info -->
                    <?php if (!empty($order['event_name'])): ?>
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

                    <!-- Total -->
                    <div class="flex justify-between items-center pt-md border-top">
                        <span class="font-semibold">Totalt</span>
                        <span class="text-xl font-bold"><?= number_format($order['total_amount'], 0) ?> kr</span>
                    </div>

                    <!-- Order reference -->
                    <div class="mt-md pt-md border-top text-sm text-secondary">
                        Order: <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Payment options -->
            <div class="card">
                <div class="card-header">
                    <h2 class="text-lg">
                        <i data-lucide="credit-card"></i>
                        Välj betalningsmetod
                    </h2>
                </div>
                <div class="card-body">
                    <?php if (!empty($order['swish_available'])): ?>
                    <!-- Swish payment -->
                    <div class="payment-option payment-option--swish mb-lg">
                        <div class="flex items-center gap-md mb-md">
                            <img src="/assets/images/swish-logo.svg" alt="Swish" class="payment-logo" onerror="this.style.display='none'">
                            <div>
                                <h3 class="font-medium">Betala med Swish</h3>
                                <p class="text-sm text-secondary">Direkt betalning till arrangören</p>
                            </div>
                        </div>

                        <!-- Mobile: Swish link button -->
                        <div class="swish-mobile mb-md">
                            <a href="<?= htmlspecialchars($order['swish_url']) ?>"
                               class="btn btn--swish btn--lg w-full"
                               id="swish-button">
                                <i data-lucide="smartphone"></i>
                                Öppna Swish - <?= number_format($order['total_amount'], 0) ?> kr
                            </a>
                        </div>

                        <!-- Desktop: QR code -->
                        <div class="swish-desktop text-center">
                            <p class="text-sm text-secondary mb-md">Scanna QR-koden med Swish-appen</p>
                            <div class="swish-qr-container">
                                <img src="<?= htmlspecialchars($order['swish_qr']) ?>"
                                     alt="Swish QR-kod"
                                     class="swish-qr"
                                     width="200"
                                     height="200">
                            </div>
                        </div>

                        <!-- Payment details -->
                        <div class="swish-details mt-md p-md bg-muted rounded-md">
                            <div class="grid grid-cols-2 gap-sm text-sm">
                                <div>
                                    <span class="text-secondary">Mottagare:</span><br>
                                    <strong><?= htmlspecialchars($order['swish_name'] ?? formatSwishNumber($order['swish_number'])) ?></strong>
                                </div>
                                <div>
                                    <span class="text-secondary">Swish-nummer:</span><br>
                                    <strong><?= formatSwishNumber($order['swish_number']) ?></strong>
                                </div>
                                <div>
                                    <span class="text-secondary">Belopp:</span><br>
                                    <strong><?= number_format($order['total_amount'], 0) ?> kr</strong>
                                </div>
                                <div>
                                    <span class="text-secondary">Meddelande:</span><br>
                                    <strong><?= htmlspecialchars($order['swish_message']) ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="mt-md p-md bg-warning-subtle rounded-md text-sm">
                            <i data-lucide="info" class="icon-sm"></i>
                            <strong>Viktigt:</strong> Ange meddelandet <strong><?= htmlspecialchars($order['swish_message']) ?></strong>
                            så vi kan koppla betalningen till din anmälan.
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($order['card_available'])): ?>
                    <!-- Card payment via WooCommerce -->
                    <div class="payment-option payment-option--card">
                        <div class="flex items-center gap-md mb-md">
                            <i data-lucide="credit-card" class="icon-lg"></i>
                            <div>
                                <h3 class="font-medium">Betala med kort</h3>
                                <p class="text-sm text-secondary">Visa, Mastercard, etc.</p>
                            </div>
                        </div>
                        <button type="button"
                                class="btn btn--secondary btn--lg w-full"
                                onclick="WooCommerce.openCheckout('<?= WC_CHECKOUT_URL ?>?order=<?= $order['id'] ?>')">
                            <i data-lucide="external-link"></i>
                            Betala med kort
                        </button>
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
                        Din anmälan bekräftas automatiskt när betalningen är genomförd.
                        Du får ett bekräftelsemail till <strong><?= htmlspecialchars($order['customer_email']) ?></strong>.
                    </p>
                    <p class="text-sm text-secondary mt-sm">
                        Swish-betalningar bekräftas vanligtvis inom några minuter.
                        Har du frågor? Kontakta arrangören.
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../components/footer.php'; ?>
