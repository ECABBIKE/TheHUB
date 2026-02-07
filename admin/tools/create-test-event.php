<?php
/**
 * Create Test Event for Payment Flow Testing
 *
 * Creates a "TEST 2026" event with pricing template rules
 * so the full payment/checkout flow can be tested end-to-end.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$db = getDB();
$message = '';
$messageType = 'info';

// Handle creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_test') {
    checkCsrf();

    try {
        $pdo = $db->getPdo();

        // Check if test event already exists
        $existing = $db->getRow("SELECT id FROM events WHERE name = 'TEST 2026'");
        if ($existing) {
            $message = "Testevenemanget finns redan (ID: {$existing['id']}). <a href='/admin/event-edit.php?id={$existing['id']}'>Redigera</a>";
            $messageType = 'warning';
        } else {
            // Find a series to attach to (optional)
            $series = $db->getRow("SELECT id, name FROM series WHERE year = 2026 AND status != 'archived' ORDER BY id LIMIT 1");

            // Find default pricing template
            $pricingTemplate = $db->getRow("SELECT id, name FROM pricing_templates WHERE is_default = 1 LIMIT 1");
            if (!$pricingTemplate) {
                $pricingTemplate = $db->getRow("SELECT id, name FROM pricing_templates ORDER BY id LIMIT 1");
            }

            // Create the event
            $eventData = [
                'name' => 'TEST 2026',
                'date' => '2026-06-15',
                'location' => 'Testbanan, Sverige',
                'discipline' => 'ENDURO',
                'event_format' => 'ENDURO',
                'event_level' => 'national',
                'active' => 1,
                'status' => 'upcoming',
                'description' => 'Testevent for att verifiera betalningsflode (Stripe Checkout). Skapat automatiskt.',
                'max_participants' => 50,
                'registration_deadline' => '2026-06-14',
            ];

            if ($series) {
                $eventData['series_id'] = $series['id'];
            }
            if ($pricingTemplate) {
                $eventData['pricing_template_id'] = $pricingTemplate['id'];
            }

            $db->insert('events', $eventData);
            $eventId = $pdo->lastInsertId();

            // Link to series via series_events if series exists
            if ($series) {
                $maxSort = $db->getRow("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_sort FROM series_events WHERE series_id = ?", [$series['id']]);
                $db->insert('series_events', [
                    'series_id' => $series['id'],
                    'event_id' => $eventId,
                    'sort_order' => $maxSort['next_sort'] ?? 1
                ]);
            }

            // If no pricing template exists, create one with test prices
            if (!$pricingTemplate) {
                $db->insert('pricing_templates', [
                    'name' => 'Test - Standardpris',
                    'is_default' => 1,
                    'early_bird_percent' => 15,
                    'early_bird_days_before' => 21,
                    'late_fee_percent' => 25,
                    'late_fee_days_before' => 3
                ]);
                $templateId = $pdo->lastInsertId();

                // Update event with template
                $db->update('events', ['pricing_template_id' => $templateId], 'id = ?', [$eventId]);
            } else {
                $templateId = $pricingTemplate['id'];
            }

            // Create pricing rules for active classes (if not already existing)
            $classes = $db->getAll("SELECT id, name, display_name FROM classes WHERE active = 1 ORDER BY sort_order");
            $testPrices = [400, 350, 300, 250, 200]; // Different prices for variety
            foreach ($classes as $i => $class) {
                $existing = $db->getRow(
                    "SELECT id FROM pricing_template_rules WHERE template_id = ? AND class_id = ?",
                    [$templateId, $class['id']]
                );
                if (!$existing) {
                    $price = $testPrices[$i % count($testPrices)];
                    $db->insert('pricing_template_rules', [
                        'template_id' => $templateId,
                        'class_id' => $class['id'],
                        'base_price' => $price
                    ]);
                }
            }

            // Create event_pricing_rules for this specific event
            foreach ($classes as $i => $class) {
                $price = $testPrices[$i % count($testPrices)];
                try {
                    $db->insert('event_pricing_rules', [
                        'event_id' => $eventId,
                        'class_id' => $class['id'],
                        'base_price' => $price
                    ]);
                } catch (Exception $e) {
                    // May already exist
                }
            }

            $message = "Testevent 'TEST 2026' skapat (ID: $eventId)";
            if ($series) $message .= " | Serie: {$series['name']}";
            if ($pricingTemplate) $message .= " | Prismall: {$pricingTemplate['name']}";
            $message .= " | Klasser med priser: " . count($classes);
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = 'Fel: ' . $e->getMessage();
        $messageType = 'error';
        error_log("create-test-event error: " . $e->getMessage());
    }
}

// Check current state
$testEvent = $db->getRow("SELECT * FROM events WHERE name = 'TEST 2026'");
$stripeKey = env('STRIPE_SECRET_KEY', '');
$stripeConfigured = !empty($stripeKey);

$pageTitle = 'Skapa testevent';
include __DIR__ . '/../components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>">
    <?= $message ?>
</div>
<?php endif; ?>

<div class="card mb-lg">
    <div class="card-header">
        <h2><i data-lucide="flask-conical"></i> Testevent for betalningsflode</h2>
    </div>
    <div class="card-body">
        <?php if ($testEvent): ?>
            <div class="alert alert-success">
                <i data-lucide="check-circle"></i>
                <strong>TEST 2026</strong> finns redan (ID: <?= $testEvent['id'] ?>)
            </div>
            <div class="flex gap-md flex-wrap mt-md">
                <a href="/admin/event-edit.php?id=<?= $testEvent['id'] ?>" class="btn btn--primary">
                    <i data-lucide="pencil"></i> Redigera event
                </a>
                <a href="/event/<?= $testEvent['id'] ?>" class="btn btn--secondary" target="_blank">
                    <i data-lucide="external-link"></i> Visa publik sida
                </a>
                <a href="/admin/event-registrations.php?event_id=<?= $testEvent['id'] ?>" class="btn btn--secondary">
                    <i data-lucide="users"></i> Anmalningar
                </a>
            </div>
        <?php else: ?>
            <p class="text-secondary mb-md">
                Skapar ett testevent med namn "TEST 2026", prissattning per klass, och koppling till en serie.
                Du kan sedan testa hela anmalnings- och betalningsfldet.
            </p>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_test">
                <button type="submit" class="btn btn--primary">
                    <i data-lucide="plus"></i> Skapa TEST 2026
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Stripe Status -->
<div class="card mb-lg">
    <div class="card-header">
        <h2><i data-lucide="credit-card"></i> Stripe-status</h2>
    </div>
    <div class="card-body">
        <?php if ($stripeConfigured): ?>
            <div class="alert alert-success">
                <i data-lucide="check-circle"></i>
                Stripe ar konfigurerat (nyckel borjar med <?= substr($stripeKey, 0, 7) ?>...)
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i data-lucide="alert-triangle"></i>
                <strong>STRIPE_SECRET_KEY saknas!</strong>
                <p class="mt-sm">Lagg till foljande i din <code>.env</code>-fil:</p>
                <pre style="background:var(--color-bg-page);padding:var(--space-sm);border-radius:var(--radius-sm);margin-top:var(--space-xs);font-size:var(--text-sm);">STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...</pre>
                <p class="mt-sm text-sm">
                    Skapa nycklar pa <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard</a>
                </p>
            </div>
        <?php endif; ?>

        <h3 class="mt-lg mb-sm">Checklista for betalningstest</h3>
        <table class="table">
            <tbody>
                <tr>
                    <td><i data-lucide="<?= $stripeConfigured ? 'check-circle' : 'x-circle' ?>" style="color:var(--color-<?= $stripeConfigured ? 'success' : 'error' ?>);width:18px;height:18px;"></i></td>
                    <td>STRIPE_SECRET_KEY i .env</td>
                </tr>
                <tr>
                    <?php
                    $hasRecipient = false;
                    try {
                        $recipient = $db->getRow("SELECT id, name, stripe_account_id, stripe_account_status FROM payment_recipients WHERE active = 1 LIMIT 1");
                        $hasRecipient = !empty($recipient);
                    } catch (Exception $e) {}
                    ?>
                    <td><i data-lucide="<?= $hasRecipient ? 'check-circle' : 'x-circle' ?>" style="color:var(--color-<?= $hasRecipient ? 'success' : 'error' ?>);width:18px;height:18px;"></i></td>
                    <td>Betalningsmottagare skapad <?= $hasRecipient ? '(' . htmlspecialchars($recipient['name']) . ')' : '' ?></td>
                </tr>
                <tr>
                    <?php $hasStripeConnect = $hasRecipient && !empty($recipient['stripe_account_id']) && $recipient['stripe_account_status'] === 'active'; ?>
                    <td><i data-lucide="<?= $hasStripeConnect ? 'check-circle' : 'minus-circle' ?>" style="color:var(--color-<?= $hasStripeConnect ? 'success' : 'warning' ?>);width:18px;height:18px;"></i></td>
                    <td>Stripe Connect-konto kopplat <?= $hasStripeConnect ? '' : '(valfritt for test - betalning gar till plattformen)' ?></td>
                </tr>
                <tr>
                    <td><i data-lucide="<?= $testEvent ? 'check-circle' : 'x-circle' ?>" style="color:var(--color-<?= $testEvent ? 'success' : 'error' ?>);width:18px;height:18px;"></i></td>
                    <td>Testevent skapat</td>
                </tr>
                <tr>
                    <?php
                    $hasPricing = false;
                    if ($testEvent && !empty($testEvent['pricing_template_id'])) {
                        $rules = $db->getAll("SELECT id FROM pricing_template_rules WHERE template_id = ?", [$testEvent['pricing_template_id']]);
                        $hasPricing = !empty($rules);
                    }
                    ?>
                    <td><i data-lucide="<?= $hasPricing ? 'check-circle' : 'x-circle' ?>" style="color:var(--color-<?= $hasPricing ? 'success' : 'error' ?>);width:18px;height:18px;"></i></td>
                    <td>Priser konfigurerade per klass</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
