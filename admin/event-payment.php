<?php
/**
 * Event Payment Setup
 * Consolidated page for all payment/ticketing configuration for an event
 *
 * Includes:
 * - Enable/disable ticketing
 * - Payment methods (Swish, Card)
 * - Swish recipient configuration (event-specific or inherited)
 * - Pricing per class with early bird
 * - Order statistics
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';
require_admin();

$db = getDB();

// Get event ID
$eventId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($eventId <= 0) {
    $_SESSION['flash_message'] = 'Ogiltigt event-ID';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/events.php');
    exit;
}

// Fetch event with series info
$event = $db->getRow("
    SELECT e.*, s.name as series_name, s.id as series_id
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    WHERE e.id = ?
", [$eventId]);

if (!$event) {
    $_SESSION['flash_message'] = 'Event hittades inte';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/events.php');
    exit;
}

// Get current payment config (may be inherited)
$paymentConfig = getPaymentConfig($eventId);
$configSource = $paymentConfig['config_source'] ?? 'woocommerce';
$sourceName = $paymentConfig['source_name'] ?? 'WooCommerce';

// Get event-specific config (if exists)
$eventPaymentConfig = $db->getRow("SELECT * FROM payment_configs WHERE event_id = ?", [$eventId]);

// Get series config (if event belongs to series)
$seriesPaymentConfig = null;
if ($event['series_id']) {
    $seriesPaymentConfig = $db->getRow("SELECT * FROM payment_configs WHERE series_id = ?", [$event['series_id']]);
}

// Initialize message
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        // Save ticketing settings
        $enabled = isset($_POST['ticketing_enabled']) ? 1 : 0;
        $deadlineDays = intval($_POST['ticket_deadline_days'] ?? 7);

        $db->update('events', [
            'ticketing_enabled' => $enabled,
            'ticket_deadline_days' => $deadlineDays
        ], 'id = ?', [$eventId]);

        $message = 'Grundinställningar sparade!';
        $messageType = 'success';

        // Refresh event data
        $event = $db->getRow("SELECT e.*, s.name as series_name FROM events e LEFT JOIN series s ON e.series_id = s.id WHERE e.id = ?", [$eventId]);

    } elseif ($action === 'save_payment_config') {
        // Save event-specific payment configuration
        $useEventConfig = isset($_POST['use_event_config']) ? 1 : 0;

        if ($useEventConfig) {
            $swishEnabled = isset($_POST['swish_enabled']) ? 1 : 0;
            $swishNumber = trim($_POST['swish_number'] ?? '');
            $swishName = trim($_POST['swish_name'] ?? '');
            $cardEnabled = isset($_POST['card_enabled']) ? 1 : 0;
            $wooProductId = trim($_POST['woo_product_id'] ?? '') ?: null;

            if ($eventPaymentConfig) {
                // Update existing
                $db->update('payment_configs', [
                    'swish_enabled' => $swishEnabled,
                    'swish_number' => $swishNumber ?: null,
                    'swish_name' => $swishName ?: null,
                    'card_enabled' => $cardEnabled,
                    'woo_vendor_id' => $wooProductId
                ], 'id = ?', [$eventPaymentConfig['id']]);
            } else {
                // Create new
                $db->insert('payment_configs', [
                    'event_id' => $eventId,
                    'swish_enabled' => $swishEnabled,
                    'swish_number' => $swishNumber ?: null,
                    'swish_name' => $swishName ?: null,
                    'card_enabled' => $cardEnabled,
                    'woo_vendor_id' => $wooProductId
                ]);
            }

            // Also update woo_product_id on event
            $db->update('events', ['woo_product_id' => $wooProductId], 'id = ?', [$eventId]);

            $message = 'Betalningsinställningar sparade!';
            $messageType = 'success';
        } else {
            // Remove event-specific config (inherit from series/promotor)
            if ($eventPaymentConfig) {
                $db->execute("DELETE FROM payment_configs WHERE id = ?", [$eventPaymentConfig['id']]);
            }
            $message = 'Eventet ärver nu betalningsinställningar.';
            $messageType = 'success';
        }

        // Refresh configs
        $eventPaymentConfig = $db->getRow("SELECT * FROM payment_configs WHERE event_id = ?", [$eventId]);
        $paymentConfig = getPaymentConfig($eventId);
        $configSource = $paymentConfig['config_source'] ?? 'woocommerce';

    } elseif ($action === 'save_pricing') {
        // Save pricing rules
        $classIds = $_POST['class_id'] ?? [];
        $basePrices = $_POST['base_price'] ?? [];
        $earlyBirdDiscounts = $_POST['early_bird_discount'] ?? [];
        $earlyBirdEndDates = $_POST['early_bird_end_date'] ?? [];

        $saved = 0;
        foreach ($classIds as $index => $classId) {
            $basePrice = floatval($basePrices[$index] ?? 0);
            $earlyBirdDiscount = floatval($earlyBirdDiscounts[$index] ?? 0);
            $earlyBirdEndDate = trim($earlyBirdEndDates[$index] ?? '');

            if ($basePrice > 0) {
                $existing = $db->getRow("SELECT id FROM event_pricing_rules WHERE event_id = ? AND class_id = ?", [$eventId, $classId]);

                if ($existing) {
                    $db->execute("UPDATE event_pricing_rules SET base_price = ?, early_bird_discount_percent = ?, early_bird_end_date = ?, updated_at = NOW() WHERE id = ?",
                        [$basePrice, $earlyBirdDiscount, $earlyBirdEndDate ?: null, $existing['id']]);
                } else {
                    $db->execute("INSERT INTO event_pricing_rules (event_id, class_id, base_price, early_bird_discount_percent, early_bird_end_date, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                        [$eventId, $classId, $basePrice, $earlyBirdDiscount, $earlyBirdEndDate ?: null]);
                }
                $saved++;
            } else {
                // Delete if price is 0
                $db->execute("DELETE FROM event_pricing_rules WHERE event_id = ? AND class_id = ?", [$eventId, $classId]);
            }
        }

        $message = "Sparade {$saved} priser!";
        $messageType = 'success';
    }
}

// Get classes with pricing
$classes = $db->getAll("
    SELECT c.id, c.name, c.display_name, c.sort_order,
           epr.base_price, epr.early_bird_discount_percent, epr.early_bird_end_date
    FROM classes c
    LEFT JOIN event_pricing_rules epr ON c.id = epr.class_id AND epr.event_id = ?
    ORDER BY c.sort_order ASC
", [$eventId]);

// Get order statistics
$orderStats = $db->getRow("
    SELECT
        COUNT(*) as total_orders,
        SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_orders,
        SUM(CASE WHEN payment_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as total_revenue
    FROM orders
    WHERE event_id = ?
", [$eventId]);

// Calculate default early-bird end date (event date - 14 days)
$eventDate = new DateTime($event['date']);
$defaultEarlyBirdEnd = clone $eventDate;
$defaultEarlyBirdEnd->modify('-14 days');

// Set page variables for layout
$active_event_tab = 'payment';
$pageTitle = 'Betalning - ' . $event['name'];
$pageType = 'admin';

include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
    <div class="container">
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> mb-lg">
            <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Order Statistics -->
        <?php if ($orderStats['total_orders'] > 0): ?>
        <div class="card mb-lg">
            <div class="card-header">
                <h2>
                    <i data-lucide="bar-chart-2"></i>
                    Orderstatistik
                </h2>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-2 gs-md-grid-cols-4 gap-md">
                    <div class="stat-card">
                        <div class="stat-value text-warning"><?= $orderStats['pending_orders'] ?></div>
                        <div class="stat-label">Väntande</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-success"><?= $orderStats['paid_orders'] ?></div>
                        <div class="stat-label">Betalda</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-secondary"><?= $orderStats['cancelled_orders'] ?></div>
                        <div class="stat-label">Avbrutna</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($orderStats['total_revenue'], 0, ',', ' ') ?> kr</div>
                        <div class="stat-label">Intäkter</div>
                    </div>
                </div>
                <div class="mt-md">
                    <a href="/admin/event-orders.php?id=<?= $eventId ?>" class="btn btn--secondary btn--sm">
                        <i data-lucide="list"></i>
                        Visa alla ordrar
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Basic Settings -->
        <div class="card mb-lg">
            <div class="card-header">
                <h2>
                    <i data-lucide="settings"></i>
                    Grundinställningar
                </h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_settings">

                    <div class="grid grid-cols-1 md-grid-cols-2 gap-lg">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="ticketing_enabled" value="1"
                                    <?= ($event['ticketing_enabled'] ?? 0) ? 'checked' : '' ?>>
                                <span>Aktivera betalning/biljetter</span>
                            </label>
                            <small class="text-secondary">Visar prisinfo och möjliggör betalning vid anmälan</small>
                        </div>

                        <div class="form-group">
                            <label class="label">Anmälningsfrist (dagar före event)</label>
                            <input type="number" name="ticket_deadline_days" class="input"
                                   value="<?= $event['ticket_deadline_days'] ?? 7 ?>" min="0" max="90">
                            <small class="text-secondary">Anmälan stänger detta antal dagar före eventdatum</small>
                        </div>
                    </div>

                    <div class="mt-md">
                        <button type="submit" class="btn btn--primary">
                            <i data-lucide="save"></i>
                            Spara
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payment Configuration -->
        <div class="card mb-lg">
            <div class="card-header">
                <div class="flex justify-between items-center">
                    <h2>
                        <i data-lucide="credit-card"></i>
                        Betalningsmetoder
                    </h2>
                    <?php if (!$eventPaymentConfig && $paymentConfig): ?>
                    <span class="badge badge-info">
                        Ärver från: <?= htmlspecialchars($sourceName ?: ucfirst($configSource)) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_payment_config">

                    <!-- Toggle: Use event-specific config -->
                    <div class="form-group mb-lg">
                        <label class="checkbox-label">
                            <input type="checkbox" name="use_event_config" value="1" id="use-event-config"
                                <?= $eventPaymentConfig ? 'checked' : '' ?>
                                onchange="toggleEventConfig(this.checked)">
                            <span><strong>Använd event-specifik konfiguration</strong></span>
                        </label>
                        <small class="text-secondary">
                            <?php if ($event['series_name']): ?>
                                Om avmarkerat ärver eventet inställningar från serien "<?= htmlspecialchars($event['series_name']) ?>"
                            <?php else: ?>
                                Om avmarkerat används systemets standardinställningar (WooCommerce)
                            <?php endif; ?>
                        </small>
                    </div>

                    <!-- Event-specific config fields -->
                    <div id="event-config-fields" class="<?= $eventPaymentConfig ? '' : 'hidden' ?>">
                        <div class="p-md bg-muted rounded-md mb-lg">
                            <!-- Swish Settings -->
                            <h3 class="text-base font-medium mb-md">
                                <i data-lucide="smartphone"></i>
                                Swish
                            </h3>
                            <div class="grid grid-cols-1 md-grid-cols-3 gap-md mb-lg">
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="swish_enabled" value="1"
                                            <?= ($eventPaymentConfig['swish_enabled'] ?? $paymentConfig['swish_enabled'] ?? 0) ? 'checked' : '' ?>>
                                        <span>Aktivera Swish</span>
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="label">Swish-nummer</label>
                                    <input type="text" name="swish_number" class="input"
                                           value="<?= htmlspecialchars($eventPaymentConfig['swish_number'] ?? $paymentConfig['swish_number'] ?? '') ?>"
                                           placeholder="070-123 45 67">
                                </div>
                                <div class="form-group">
                                    <label class="label">Mottagarnamn</label>
                                    <input type="text" name="swish_name" class="input"
                                           value="<?= htmlspecialchars($eventPaymentConfig['swish_name'] ?? $paymentConfig['swish_name'] ?? '') ?>"
                                           placeholder="Klubbnamn">
                                </div>
                            </div>

                            <!-- Card Settings -->
                            <h3 class="text-base font-medium mb-md">
                                <i data-lucide="credit-card"></i>
                                Kortbetalning
                            </h3>
                            <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="card_enabled" value="1"
                                            <?= ($eventPaymentConfig['card_enabled'] ?? 0) ? 'checked' : '' ?>>
                                        <span>Aktivera kortbetalning (WooCommerce)</span>
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="label">WooCommerce Produkt-ID</label>
                                    <input type="text" name="woo_product_id" class="input"
                                           value="<?= htmlspecialchars($event['woo_product_id'] ?? '') ?>"
                                           placeholder="12345">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Show inherited config when not using event-specific -->
                    <?php if (!$eventPaymentConfig && $paymentConfig): ?>
                    <div id="inherited-config-display" class="p-md bg-muted rounded-md mb-lg">
                        <h4 class="text-sm font-medium text-secondary mb-sm">Ärvda inställningar:</h4>
                        <div class="grid grid-cols-2 gap-sm text-sm">
                            <div>
                                <span class="text-secondary">Swish:</span>
                                <?= $paymentConfig['swish_enabled'] ? '<span class="badge badge-success">Aktivt</span>' : '<span class="badge badge-secondary">Inaktivt</span>' ?>
                            </div>
                            <?php if ($paymentConfig['swish_number']): ?>
                            <div>
                                <span class="text-secondary">Nummer:</span>
                                <strong><?= htmlspecialchars($paymentConfig['swish_number']) ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($paymentConfig['swish_name']): ?>
                            <div>
                                <span class="text-secondary">Mottagare:</span>
                                <strong><?= htmlspecialchars($paymentConfig['swish_name']) ?></strong>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn--primary">
                        <i data-lucide="save"></i>
                        Spara betalningsinställningar
                    </button>
                </form>
            </div>
        </div>

        <!-- Pricing -->
        <div class="card mb-lg">
            <div class="card-header">
                <h2>
                    <i data-lucide="tag"></i>
                    Prissättning per klass
                </h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_pricing">

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Klass</th>
                                    <th style="width: 120px;">Pris (kr)</th>
                                    <th style="width: 100px;">Early bird %</th>
                                    <th style="width: 150px;">Early bird t.o.m.</th>
                                    <th style="width: 100px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="class_id[]" value="<?= $class['id'] ?>">
                                        <strong><?= htmlspecialchars($class['display_name']) ?></strong>
                                        <span class="text-secondary text-xs">(<?= htmlspecialchars($class['name']) ?>)</span>
                                    </td>
                                    <td>
                                        <input type="number" name="base_price[]" class="input input--sm"
                                               value="<?= $class['base_price'] ?? '' ?>"
                                               placeholder="0" min="0" step="10">
                                    </td>
                                    <td>
                                        <input type="number" name="early_bird_discount[]" class="input input--sm"
                                               value="<?= $class['early_bird_discount_percent'] ?? '20' ?>"
                                               placeholder="20" min="0" max="100">
                                    </td>
                                    <td>
                                        <input type="date" name="early_bird_end_date[]" class="input input--sm"
                                               value="<?= $class['early_bird_end_date'] ?? $defaultEarlyBirdEnd->format('Y-m-d') ?>">
                                    </td>
                                    <td>
                                        <?php if ($class['base_price'] > 0): ?>
                                            <span class="badge badge-success">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Ej satt</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-lg">
                        <button type="submit" class="btn btn--primary">
                            <i data-lucide="save"></i>
                            Spara priser
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <i data-lucide="link"></i>
                    Snabblänkar
                </h2>
            </div>
            <div class="card-body">
                <div class="flex gap-md flex-wrap">
                    <a href="/admin/event-tickets.php?id=<?= $eventId ?>" class="btn btn--secondary">
                        <i data-lucide="ticket"></i>
                        Hantera biljetter
                    </a>
                    <a href="/event/<?= $eventId ?>?tab=biljetter" class="btn btn--secondary" target="_blank">
                        <i data-lucide="external-link"></i>
                        Förhandsgranska publik sida
                    </a>
                    <?php if ($event['series_id']): ?>
                    <a href="/admin/payment-settings.php" class="btn btn--secondary">
                        <i data-lucide="settings"></i>
                        Serie-betalningsinställningar
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.stat-card {
    text-align: center;
    padding: var(--space-md);
    background: var(--color-bg-base);
    border-radius: var(--radius-md);
}

.stat-value {
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    line-height: 1.2;
}

.stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-xs);
}

.bg-muted {
    background: var(--color-bg-base);
}

.hidden {
    display: none !important;
}

.input--sm {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-sm);
}
</style>

<script>
function toggleEventConfig(checked) {
    const fields = document.getElementById('event-config-fields');
    const inherited = document.getElementById('inherited-config-display');

    if (checked) {
        fields.classList.remove('hidden');
        if (inherited) inherited.classList.add('hidden');
    } else {
        fields.classList.add('hidden');
        if (inherited) inherited.classList.remove('hidden');
    }
}

// Initialize Lucide icons
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
