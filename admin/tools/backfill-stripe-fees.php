<?php
/**
 * Backfill Stripe Fees
 * Fetches actual Stripe fees from balance_transaction for existing paid orders.
 *
 * Looks for PaymentIntent IDs in multiple columns:
 * - stripe_payment_intent_id (primary)
 * - payment_reference (often contains pi_xxx)
 * - gateway_transaction_id (may contain pi_xxx or cs_xxx)
 * - gateway_metadata JSON (may have payment_intent inside)
 *
 * For checkout sessions (cs_xxx), retrieves the session to find the PI first.
 */

require_once __DIR__ . '/../../config.php';
require_admin();

if (!hasRole('admin')) {
    set_flash('error', 'Admin-behörighet krävs');
    redirect('/admin');
}

$db = getDB();

// Check if stripe_fee column exists
$colCheck = $db->getAll("SHOW COLUMNS FROM orders LIKE 'stripe_fee'");
if (empty($colCheck)) {
    $page_title = 'Backfill Stripe-avgifter';
    include __DIR__ . '/../components/unified-layout.php';
    echo '<div class="alert alert-warning">Kolumnen <code>stripe_fee</code> finns inte. Kör migration 049 först.</div>';
    echo '<a href="/admin/migrations.php" class="btn-admin btn-admin-primary">Gå till migrationer</a>';
    include __DIR__ . '/../components/unified-layout-footer.php';
    exit;
}

// AJAX: Process a batch of orders
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_batch') {
    header('Content-Type: application/json');

    $batchSize = intval($_POST['batch_size'] ?? 10);

    $stripeApiKey = getenv('STRIPE_SECRET_KEY');
    if (!$stripeApiKey && function_exists('env')) {
        $stripeApiKey = env('STRIPE_SECRET_KEY', '');
    }

    if (!$stripeApiKey) {
        echo json_encode(['success' => false, 'error' => 'Stripe API-nyckel saknas i .env']);
        exit;
    }

    require_once __DIR__ . '/../../includes/payment/StripeClient.php';
    $stripe = new \TheHUB\Payment\StripeClient($stripeApiKey);

    // Get orders that need backfilling - look in ALL possible PI ID columns
    // Include payment_method = 'card' OR NULL with Stripe references
    $orders = $db->getAll("
        SELECT id, order_number, stripe_payment_intent_id, payment_reference,
               gateway_transaction_id, gateway_metadata, total_amount, payment_method
        FROM orders
        WHERE payment_status = 'paid'
          AND stripe_fee IS NULL
          AND (
              payment_method = 'card'
              OR (payment_method IS NULL AND (
                  stripe_payment_intent_id IS NOT NULL
                  OR gateway_transaction_id LIKE 'cs_%'
                  OR gateway_transaction_id LIKE 'pi_%'
              ))
          )
        ORDER BY id ASC
        LIMIT ?
    ", [$batchSize]);

    $processed = 0;
    $errors = 0;
    $skipped = 0;
    $results = [];

    foreach ($orders as $order) {
        $piId = null;

        // Strategy 1: stripe_payment_intent_id column
        if (!empty($order['stripe_payment_intent_id']) && str_starts_with($order['stripe_payment_intent_id'], 'pi_')) {
            $piId = $order['stripe_payment_intent_id'];
        }

        // Strategy 2: payment_reference (often set to PI ID by webhook)
        if (!$piId && !empty($order['payment_reference']) && str_starts_with($order['payment_reference'], 'pi_')) {
            $piId = $order['payment_reference'];
        }

        // Strategy 3: gateway_transaction_id might be PI ID
        if (!$piId && !empty($order['gateway_transaction_id']) && str_starts_with($order['gateway_transaction_id'], 'pi_')) {
            $piId = $order['gateway_transaction_id'];
        }

        // Strategy 4: gateway_transaction_id might be checkout session - retrieve PI from it
        if (!$piId && !empty($order['gateway_transaction_id']) && str_starts_with($order['gateway_transaction_id'], 'cs_')) {
            try {
                $sessionResp = $stripe->request('GET', '/checkout/sessions/' . $order['gateway_transaction_id']);
                if (!isset($sessionResp['error']) && !empty($sessionResp['payment_intent'])) {
                    $piId = $sessionResp['payment_intent'];
                    // Also store the PI ID for future use
                    try {
                        $db->execute("UPDATE orders SET stripe_payment_intent_id = ? WHERE id = ? AND (stripe_payment_intent_id IS NULL OR stripe_payment_intent_id = '')",
                            [$piId, $order['id']]);
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {
                // Session lookup failed
            }
            usleep(50000); // Rate limit
        }

        // Strategy 5: gateway_metadata JSON may contain payment_intent
        if (!$piId && !empty($order['gateway_metadata'])) {
            $meta = json_decode($order['gateway_metadata'], true);
            if (is_array($meta)) {
                $piId = $meta['checkout_session']['payment_intent']
                    ?? $meta['stripe_event']['payment_intent']
                    ?? $meta['payment_intent']
                    ?? null;
            }
        }

        if (!$piId) {
            $skipped++;
            $results[] = [
                'order' => $order['order_number'],
                'error' => 'Inget PaymentIntent-ID hittades',
                'status' => 'skip'
            ];
            // Mark as 0 fee to prevent re-processing (can't look up fee)
            // Don't do this - leave NULL so manual fix is possible
            continue;
        }

        // Fetch actual fee from Stripe
        try {
            $feeData = $stripe->getPaymentFee($piId);

            if ($feeData['success']) {
                $db->execute(
                    "UPDATE orders SET stripe_fee = ?, stripe_balance_transaction_id = ?, stripe_payment_intent_id = COALESCE(NULLIF(stripe_payment_intent_id, ''), ?) WHERE id = ?",
                    [$feeData['fee'], $feeData['balance_transaction_id'], $piId, $order['id']]
                );
                $processed++;
                $results[] = [
                    'order' => $order['order_number'],
                    'fee' => $feeData['fee'],
                    'status' => 'ok'
                ];
            } else {
                $errors++;
                $results[] = [
                    'order' => $order['order_number'],
                    'error' => $feeData['error'] ?? 'Okänt fel',
                    'status' => 'error'
                ];
            }
        } catch (\Throwable $e) {
            $errors++;
            $results[] = [
                'order' => $order['order_number'],
                'error' => $e->getMessage(),
                'status' => 'error'
            ];
        }

        usleep(50000); // Rate limiting
    }

    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'errors' => $errors,
        'skipped' => $skipped,
        'batch_count' => count($orders),
        'results' => $results
    ]);
    exit;
}

// Get stats
$stats = ['total_paid_card' => 0, 'missing_fee' => 0, 'has_fee' => 0];
$debug = ['total_paid' => 0, 'by_method' => []];

try {
    // Debug: show ALL paid orders by payment_method
    $methodRows = $db->getAll("
        SELECT COALESCE(payment_method, 'NULL') as method, COUNT(*) as cnt
        FROM orders
        WHERE payment_status = 'paid'
        GROUP BY payment_method
        ORDER BY cnt DESC
    ");
    foreach ($methodRows as $mr) {
        $debug['by_method'][$mr['method']] = (int)$mr['cnt'];
        $debug['total_paid'] += (int)$mr['cnt'];
    }
} catch (Exception $e) {
    $debug['error'] = $e->getMessage();
}

try {
    $row = $db->getRow("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN stripe_fee IS NULL THEN 1 ELSE 0 END) as missing_fee,
            SUM(CASE WHEN stripe_fee IS NOT NULL THEN 1 ELSE 0 END) as has_fee
        FROM orders
        WHERE payment_status = 'paid'
          AND (
              payment_method = 'card'
              OR (payment_method IS NULL AND (
                  stripe_payment_intent_id IS NOT NULL
                  OR gateway_transaction_id LIKE 'cs_%'
                  OR gateway_transaction_id LIKE 'pi_%'
              ))
          )
    ");
    $stats['total_paid_card'] = (int)($row['total'] ?? 0);
    $stats['missing_fee'] = (int)($row['missing_fee'] ?? 0);
    $stats['has_fee'] = (int)($row['has_fee'] ?? 0);
} catch (Exception $e) {
    $debug['stats_error'] = $e->getMessage();
}

$page_title = 'Backfill Stripe-avgifter';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Backfill Stripe-avgifter']
];
include __DIR__ . '/../components/unified-layout.php';
?>

<style>
.backfill-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl); }
.backfill-stat { background: var(--color-bg-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-lg); text-align: center; }
.backfill-stat-value { font-size: var(--text-2xl); font-weight: 700; }
.backfill-stat-label { font-size: var(--text-xs); color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-top: var(--space-xs); }
.backfill-progress { margin: var(--space-lg) 0; }
.backfill-progress-bar { height: 8px; background: var(--color-bg-sunken); border-radius: var(--radius-full); overflow: hidden; }
.backfill-progress-fill { height: 100%; background: var(--color-accent); border-radius: var(--radius-full); transition: width 0.3s ease; width: 0%; }
.backfill-log { max-height: 400px; overflow-y: auto; background: var(--color-bg-sunken); border-radius: var(--radius-md); padding: var(--space-md); font-family: monospace; font-size: var(--text-sm); margin-top: var(--space-lg); }
.log-entry { padding: 2px 0; }
.log-ok { color: var(--color-success); }
.log-error { color: var(--color-error); }
.log-skip { color: var(--color-warning); }
.log-info { color: var(--color-text-secondary); }
</style>

<div class="admin-card">
    <div class="admin-card-header">
        <h3>Hämta faktiska Stripe-avgifter</h3>
    </div>
    <div class="admin-card-body">
        <p style="color: var(--color-text-secondary); margin-bottom: var(--space-lg);">
            Hämtar riktiga avgifter från Stripes <code>balance_transaction</code> för befintliga betalda ordrar.
            Söker PaymentIntent-ID i flera kolumner (stripe_payment_intent_id, payment_reference, gateway_transaction_id, gateway_metadata).
            Checkout-sessions (cs_xxx) slås upp automatiskt för att hitta tillhörande PaymentIntent.
        </p>

        <?php if (!empty($debug['stats_error'])): ?>
        <div class="alert alert-danger" style="margin-bottom: var(--space-lg);">
            <strong>SQL-fel i stats-frågan:</strong> <?= htmlspecialchars($debug['stats_error']) ?>
        </div>
        <?php endif; ?>

        <?php if ($debug['total_paid'] > 0 && $stats['total_paid_card'] == 0): ?>
        <div class="alert alert-warning" style="margin-bottom: var(--space-lg);">
            <strong>Diagnostik:</strong> Det finns <?= $debug['total_paid'] ?> betalda ordrar i databasen, men inga matchar kortbetalningsfiltret.
            <br>Betalmetoder: <?php foreach ($debug['by_method'] as $m => $c) echo "<code>{$m}</code>: {$c} st &nbsp; "; ?>
        </div>
        <?php endif; ?>

        <div class="backfill-stats">
            <div class="backfill-stat">
                <div class="backfill-stat-value"><?= $stats['total_paid_card'] ?></div>
                <div class="backfill-stat-label">Kortbetalningar totalt</div>
            </div>
            <div class="backfill-stat">
                <div class="backfill-stat-value" style="color: var(--color-success);"><?= $stats['has_fee'] ?></div>
                <div class="backfill-stat-label">Har faktisk avgift</div>
            </div>
            <div class="backfill-stat">
                <div class="backfill-stat-value" style="color: var(--color-warning);" id="missingCount"><?= $stats['missing_fee'] ?></div>
                <div class="backfill-stat-label">Saknar avgift</div>
            </div>
        </div>

        <?php if ($stats['missing_fee'] > 0): ?>
        <div class="backfill-progress" id="progressSection" style="display: none;">
            <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-xs); font-size: var(--text-sm);">
                <span id="progressText">0 / <?= $stats['missing_fee'] ?></span>
                <span id="progressPct">0%</span>
            </div>
            <div class="backfill-progress-bar">
                <div class="backfill-progress-fill" id="progressFill"></div>
            </div>
        </div>

        <button type="button" class="btn-admin btn-admin-primary" id="startBtn" onclick="startBackfill()">
            <i data-lucide="download"></i>
            Starta backfill (<?= $stats['missing_fee'] ?> ordrar)
        </button>
        <button type="button" class="btn-admin btn-admin-secondary" id="stopBtn" onclick="stopBackfill()" style="display: none;">
            <i data-lucide="square"></i>
            Stoppa
        </button>
        <?php elseif ($stats['total_paid_card'] == 0): ?>
        <div class="alert alert-warning">
            Inga betalda kortordrar hittades.
            <?php if ($debug['total_paid'] > 0): ?>
                Totalt <?= $debug['total_paid'] ?> betalda ordrar finns, men alla har betalmetod:
                <?php foreach ($debug['by_method'] as $m => $c) echo "<code>{$m}</code> ({$c}) "; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-success">Alla kortbetalningar har redan faktiska avgifter lagrade!</div>
        <?php endif; ?>

        <div class="backfill-log" id="logContainer" style="display: none;"></div>
    </div>
</div>

<script>
let running = false;
let totalToProcess = <?= $stats['missing_fee'] ?>;
let processedTotal = 0;
let errorsTotal = 0;
let skippedTotal = 0;
let consecutiveEmpty = 0;

async function startBackfill() {
    running = true;
    document.getElementById('startBtn').style.display = 'none';
    document.getElementById('stopBtn').style.display = '';
    document.getElementById('progressSection').style.display = '';
    document.getElementById('logContainer').style.display = '';

    addLog('Startar backfill av ' + totalToProcess + ' ordrar...', 'info');

    while (running) {
        try {
            const formData = new FormData();
            formData.append('action', 'process_batch');
            formData.append('batch_size', '10');

            const response = await fetch('/admin/tools/backfill-stripe-fees.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (!result.success) {
                addLog('Fel: ' + (result.error || 'Okänt'), 'error');
                break;
            }

            if (result.batch_count === 0) {
                addLog('Klart! Alla ordrar har bearbetats.', 'info');
                break;
            }

            processedTotal += result.processed;
            errorsTotal += result.errors;
            skippedTotal += (result.skipped || 0);

            result.results.forEach(r => {
                if (r.status === 'ok') {
                    addLog(r.order + ': ' + r.fee.toFixed(2) + ' kr', 'ok');
                } else if (r.status === 'skip') {
                    addLog(r.order + ': ' + r.error, 'skip');
                } else {
                    addLog(r.order + ': ' + r.error, 'error');
                }
            });

            // If entire batch was skipped/errored, likely no more can be processed
            if (result.processed === 0 && result.batch_count > 0) {
                consecutiveEmpty++;
                if (consecutiveEmpty >= 3) {
                    addLog('Inga fler ordrar kan bearbetas (saknar Stripe-referens).', 'info');
                    break;
                }
            } else {
                consecutiveEmpty = 0;
            }

            const done = processedTotal + skippedTotal + errorsTotal;
            const pct = Math.min(100, Math.round((done / totalToProcess) * 100));
            document.getElementById('progressFill').style.width = pct + '%';
            document.getElementById('progressPct').textContent = pct + '%';
            document.getElementById('progressText').textContent =
                processedTotal + ' hämtade, ' + skippedTotal + ' utan referens, ' + errorsTotal + ' fel';
            document.getElementById('missingCount').textContent = Math.max(0, totalToProcess - processedTotal);

        } catch (error) {
            addLog('Nätverksfel: ' + error.message, 'error');
            break;
        }
    }

    running = false;
    document.getElementById('stopBtn').style.display = 'none';
    addLog('Färdig. ' + processedTotal + ' hämtade, ' + skippedTotal + ' utan referens, ' + errorsTotal + ' fel.', 'info');
}

function stopBackfill() {
    running = false;
    addLog('Stoppad av användaren.', 'info');
}

function addLog(message, type) {
    const container = document.getElementById('logContainer');
    const entry = document.createElement('div');
    entry.className = 'log-entry log-' + type;
    entry.textContent = new Date().toLocaleTimeString('sv-SE') + ' - ' + message;
    container.appendChild(entry);
    container.scrollTop = container.scrollHeight;
}
</script>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
