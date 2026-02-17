<?php
/**
 * Backfill Stripe Fees
 * Fetches actual Stripe fees from balance_transaction for existing paid orders
 * that have a stripe_payment_intent_id but no stored stripe_fee.
 *
 * Run this after migration 049 to populate historical fee data.
 */

require_once __DIR__ . '/../../config.php';
require_admin();

// Only super admins
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
    $offset = intval($_POST['offset'] ?? 0);

    // Get Stripe API key
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

    // Get orders that need backfilling
    $orders = $db->getAll("
        SELECT id, order_number, stripe_payment_intent_id, total_amount
        FROM orders
        WHERE payment_status = 'paid'
          AND payment_method = 'card'
          AND stripe_payment_intent_id IS NOT NULL
          AND stripe_payment_intent_id != ''
          AND stripe_fee IS NULL
        ORDER BY id ASC
        LIMIT ? OFFSET ?
    ", [$batchSize, $offset]);

    $processed = 0;
    $errors = 0;
    $results = [];

    foreach ($orders as $order) {
        try {
            $feeData = $stripe->getPaymentFee($order['stripe_payment_intent_id']);

            if ($feeData['success']) {
                $db->execute(
                    "UPDATE orders SET stripe_fee = ?, stripe_balance_transaction_id = ? WHERE id = ?",
                    [$feeData['fee'], $feeData['balance_transaction_id'], $order['id']]
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

        // Rate limiting: ~50ms between API calls to stay within Stripe limits
        usleep(50000);
    }

    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'errors' => $errors,
        'batch_count' => count($orders),
        'results' => $results
    ]);
    exit;
}

// Get stats for the page
$stats = [
    'total_paid_card' => 0,
    'missing_fee' => 0,
    'has_fee' => 0,
    'no_pi_id' => 0
];

try {
    $row = $db->getOne("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN stripe_fee IS NULL AND stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id != '' THEN 1 ELSE 0 END) as missing_fee,
            SUM(CASE WHEN stripe_fee IS NOT NULL THEN 1 ELSE 0 END) as has_fee,
            SUM(CASE WHEN stripe_payment_intent_id IS NULL OR stripe_payment_intent_id = '' THEN 1 ELSE 0 END) as no_pi_id
        FROM orders
        WHERE payment_status = 'paid' AND payment_method = 'card'
    ");
    $stats['total_paid_card'] = (int)($row['total'] ?? 0);
    $stats['missing_fee'] = (int)($row['missing_fee'] ?? 0);
    $stats['has_fee'] = (int)($row['has_fee'] ?? 0);
    $stats['no_pi_id'] = (int)($row['no_pi_id'] ?? 0);
} catch (Exception $e) {}

$page_title = 'Backfill Stripe-avgifter';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Backfill Stripe-avgifter']
];
include __DIR__ . '/../components/unified-layout.php';
?>

<style>
.backfill-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.backfill-stat {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}
.backfill-stat-value {
    font-size: var(--text-2xl);
    font-weight: 700;
}
.backfill-stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: var(--space-xs);
}
.backfill-progress {
    margin: var(--space-lg) 0;
}
.backfill-progress-bar {
    height: 8px;
    background: var(--color-bg-sunken);
    border-radius: var(--radius-full);
    overflow: hidden;
}
.backfill-progress-fill {
    height: 100%;
    background: var(--color-accent);
    border-radius: var(--radius-full);
    transition: width 0.3s ease;
    width: 0%;
}
.backfill-log {
    max-height: 400px;
    overflow-y: auto;
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    font-family: monospace;
    font-size: var(--text-sm);
    margin-top: var(--space-lg);
}
.log-entry { padding: 2px 0; }
.log-ok { color: var(--color-success); }
.log-error { color: var(--color-error); }
.log-info { color: var(--color-text-secondary); }
</style>

<div class="admin-card">
    <div class="admin-card-header">
        <h3>Hämta faktiska Stripe-avgifter</h3>
    </div>
    <div class="admin-card-body">
        <p style="color: var(--color-text-secondary); margin-bottom: var(--space-lg);">
            Hämtar riktiga avgifter från Stripes <code>balance_transaction</code> för befintliga betalda ordrar.
            Ersätter uppskattade avgifter (1,5% + 2 kr) med faktiska belopp.
        </p>

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
            <div class="backfill-stat">
                <div class="backfill-stat-value" style="color: var(--color-text-muted);"><?= $stats['no_pi_id'] ?></div>
                <div class="backfill-stat-label">Saknar PI-ID</div>
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
        <?php else: ?>
        <div class="alert alert-success">
            <i data-lucide="check-circle"></i>
            Alla kortbetalningar har redan faktiska avgifter lagrade!
        </div>
        <?php endif; ?>

        <div class="backfill-log" id="logContainer" style="display: none;"></div>
    </div>
</div>

<script>
let running = false;
let totalToProcess = <?= $stats['missing_fee'] ?>;
let processedTotal = 0;
let errorsTotal = 0;

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
            formData.append('offset', '0'); // Always 0 since processed orders drop out of query

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

            // Log results
            result.results.forEach(r => {
                if (r.status === 'ok') {
                    addLog(`${r.order}: ${r.fee.toFixed(2)} kr`, 'ok');
                } else {
                    addLog(`${r.order}: ${r.error}`, 'error');
                }
            });

            // Update progress
            const pct = Math.min(100, Math.round((processedTotal / totalToProcess) * 100));
            document.getElementById('progressFill').style.width = pct + '%';
            document.getElementById('progressPct').textContent = pct + '%';
            document.getElementById('progressText').textContent =
                processedTotal + ' / ' + totalToProcess +
                (errorsTotal > 0 ? ' (' + errorsTotal + ' fel)' : '');
            document.getElementById('missingCount').textContent = totalToProcess - processedTotal;

        } catch (error) {
            addLog('Nätverksfel: ' + error.message, 'error');
            break;
        }
    }

    running = false;
    document.getElementById('stopBtn').style.display = 'none';
    addLog(`Färdig. ${processedTotal} bearbetade, ${errorsTotal} fel.`, 'info');
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
