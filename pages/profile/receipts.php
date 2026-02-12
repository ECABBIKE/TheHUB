<?php
/**
 * TheHUB - Mina Köp (Combined Registrations & Receipts)
 *
 * Visar användarens:
 * - Kommande anmälningar
 * - Köphistorik med kvitton och säljarinformation
 *
 * @package TheHUB
 * @since 2026-01-29
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();

// Inkludera receipt manager om den finns
if (file_exists(__DIR__ . '/../../includes/receipt-manager.php')) {
    require_once __DIR__ . '/../../includes/receipt-manager.php';
}

// Helper function
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// Hämta alla rider IDs (användaren + barn)
$childIds = function_exists('hub_get_linked_children')
    ? array_column(hub_get_linked_children($currentUser['id']), 'id')
    : [];
$allRiderIds = array_merge([$currentUser['id']], $childIds);
$placeholders = implode(',', array_fill(0, count($allRiderIds), '?'));

// Buyer email - used to also match orders by email (catches orders where rider_id was NULL)
$buyerEmail = $currentUser['email'] ?? '';

// =============================================================================
// HÄMTA KOMMANDE ANMÄLNINGAR (egna + barn + via orders som köparen gjort)
// =============================================================================
$upcomingRegistrations = [];
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'event_registrations'");
    if ($tableCheck->rowCount() > 0) {
        // Match registrations: either rider is current user/child, OR the order was placed by current user
        $stmt = $pdo->prepare("
            SELECT r.*, ri.firstname, ri.lastname,
                   e.name AS event_name, e.date AS event_date, e.location,
                   r.category AS class_name, s.name AS series_name, s.logo AS series_logo
            FROM event_registrations r
            JOIN riders ri ON r.rider_id = ri.id
            JOIN events e ON r.event_id = e.id
            LEFT JOIN series s ON e.series_id = s.id
            LEFT JOIN orders o ON r.order_id = o.id
            WHERE (r.rider_id IN ($placeholders) OR (o.rider_id IN ($placeholders)) OR (o.customer_email = ? AND o.customer_email != ''))
            AND r.status != 'cancelled'
            AND e.date >= CURDATE()
            GROUP BY r.id
            ORDER BY e.date ASC
            LIMIT 20
        ");
        $regParams = array_merge($allRiderIds, $allRiderIds, [$buyerEmail]);
        $stmt->execute($regParams);
        $upcomingRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $upcomingRegistrations = [];
}

// =============================================================================
// HÄMTA KÖPHISTORIK (ORDERS) - matcha på rider_id ELLER customer_email
// =============================================================================
$purchases = [];
$totalSpent = 0;
$purchaseCount = 0;

try {
    // Try full query with receipts and payment_recipients
    // Match by rider_id (buyer) OR by customer_email (catches guest/unlinked orders)
    $stmt = $pdo->prepare("
        SELECT o.*,
               e.name AS event_name,
               e.date AS event_date,
               s.name AS series_name,
               s.logo AS series_logo,
               pr.name AS seller_name,
               pr.org_number AS seller_org_number,
               r.receipt_number,
               r.id AS receipt_id
        FROM orders o
        LEFT JOIN events e ON o.event_id = e.id
        LEFT JOIN series s ON o.series_id = s.id OR e.series_id = s.id
        LEFT JOIN payment_recipients pr ON o.payment_recipient_id = pr.id
        LEFT JOIN receipts r ON r.order_id = o.id AND r.status = 'issued'
        WHERE (o.rider_id IN ($placeholders) OR (o.customer_email = ? AND o.customer_email != ''))
        ORDER BY o.created_at DESC
        LIMIT 50
    ");
    $orderParams = array_merge($allRiderIds, [$buyerEmail]);
    $stmt->execute($orderParams);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback: query without receipts/payment_recipients tables
    try {
        $stmt = $pdo->prepare("
            SELECT o.*,
                   e.name AS event_name,
                   e.date AS event_date,
                   s.name AS series_name,
                   s.logo AS series_logo,
                   NULL AS seller_name,
                   NULL AS seller_org_number,
                   NULL AS receipt_number,
                   NULL AS receipt_id
            FROM orders o
            LEFT JOIN events e ON o.event_id = e.id
            LEFT JOIN series s ON o.series_id = s.id OR e.series_id = s.id
            WHERE (o.rider_id IN ($placeholders) OR (o.customer_email = ? AND o.customer_email != ''))
            ORDER BY o.created_at DESC
            LIMIT 50
        ");
        $orderParams = array_merge($allRiderIds, [$buyerEmail]);
        $stmt->execute($orderParams);
        $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $purchases = [];
    }
}

// Hämta items och generate missing receipts for paid orders
foreach ($purchases as $key => $purchase) {
    try {
        $itemStmt = $pdo->prepare("
            SELECT oi.*, oi.description, oi.unit_price, oi.quantity
            FROM order_items oi
            WHERE oi.order_id = ?
        ");
        $itemStmt->execute([$purchase['id']]);
        $purchases[$key]['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $purchases[$key]['items'] = [];
    }

    if ($purchase['payment_status'] === 'paid') {
        $totalSpent += $purchase['total_amount'];
        $purchaseCount++;

        // Auto-generate receipt if missing for paid orders
        if (empty($purchase['receipt_id']) && function_exists('createReceiptForOrder')) {
            try {
                $receiptResult = createReceiptForOrder($pdo, $purchase['id']);
                if ($receiptResult['success'] && !empty($receiptResult['receipt_id'])) {
                    $purchases[$key]['receipt_id'] = $receiptResult['receipt_id'];
                    $purchases[$key]['receipt_number'] = $receiptResult['receipt_number'];
                }
            } catch (\Throwable $e) {
                // Receipt tables may not exist - skip silently
            }
        }
    }
}

// =============================================================================
// VISA ENSKILT KVITTO
// =============================================================================
$viewReceiptId = intval($_GET['view'] ?? 0);
$viewReceipt = null;

if ($viewReceiptId && function_exists('getReceipt')) {
    try {
        $viewReceipt = getReceipt($pdo, $viewReceiptId);
        // Verifiera att kvittot tillhör användaren
        if ($viewReceipt &&
            !in_array($viewReceipt['rider_id'], $allRiderIds) &&
            $viewReceipt['user_id'] != ($currentUser['user_id'] ?? 0)) {
            $viewReceipt = null;
        }
    } catch (Exception $e) {
        $viewReceipt = null;
    }
}

// Om view-param men inget kvitto finns, försök visa order istället
$viewOrderId = 0;
$viewOrder = null;
if (!$viewReceipt && isset($_GET['order'])) {
    $viewOrderId = intval($_GET['order']);
    foreach ($purchases as $p) {
        if ($p['id'] == $viewOrderId && in_array($p['rider_id'], $allRiderIds)) {
            $viewOrder = $p;
            break;
        }
    }
}
?>

<style>
.page-tabs {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.page-tab {
    padding: var(--space-sm) var(--space-md);
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--color-text-secondary);
    cursor: pointer;
    font-size: var(--text-sm);
    font-weight: 500;
    transition: all 0.2s;
}

.page-tab:hover {
    color: var(--color-text-primary);
}

.page-tab.active {
    color: var(--color-accent);
    border-bottom-color: var(--color-accent);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}

.summary-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    text-align: center;
}

.summary-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
    display: block;
}

.summary-label {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    margin-top: var(--space-2xs);
    display: block;
}

/* Upcoming registrations */
.upcoming-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.upcoming-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-md);
    text-decoration: none;
    color: inherit;
    transition: border-color 0.2s;
}

.upcoming-card:hover {
    border-color: var(--color-accent);
}

.upcoming-date {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 50px;
    padding: var(--space-xs) var(--space-sm);
    background: var(--color-accent-light);
    border-radius: var(--radius-md);
}

.upcoming-day {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-accent);
    line-height: 1;
}

.upcoming-month {
    font-size: var(--text-xs);
    color: var(--color-accent);
    text-transform: uppercase;
}

.upcoming-info {
    flex: 1;
    min-width: 0;
}

.upcoming-event {
    font-weight: 600;
    color: var(--color-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.upcoming-details {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.upcoming-status {
    font-size: var(--text-xs);
    padding: var(--space-2xs) var(--space-xs);
    border-radius: var(--radius-sm);
}

.status-confirmed {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-success);
}

.status-pending {
    background: rgba(251, 191, 36, 0.15);
    color: var(--color-warning);
}

/* Purchase list */
.purchase-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.purchase-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.purchase-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: var(--space-md);
    gap: var(--space-md);
}

.purchase-info {
    flex: 1;
    min-width: 0;
}

.purchase-title {
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: var(--space-2xs);
}

.purchase-seller {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}

.purchase-meta {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    margin-top: var(--space-xs);
}

.purchase-amount {
    text-align: right;
}

.purchase-price {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.purchase-status {
    font-size: var(--text-xs);
    margin-top: var(--space-2xs);
}

.purchase-status.paid {
    color: var(--color-success);
}

.purchase-status.pending {
    color: var(--color-warning);
}

.purchase-status.failed {
    color: var(--color-error);
}

.purchase-items {
    background: var(--color-bg-surface);
    padding: var(--space-sm) var(--space-md);
    border-top: 1px solid var(--color-border);
}

.purchase-item {
    display: flex;
    justify-content: space-between;
    font-size: var(--text-sm);
    padding: var(--space-2xs) 0;
}

.purchase-item-name {
    color: var(--color-text-secondary);
}

.purchase-item-price {
    color: var(--color-text-muted);
}

.purchase-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-sm) var(--space-md);
    border-top: 1px solid var(--color-border);
}

.purchase-method {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.purchase-actions {
    display: flex;
    gap: var(--space-sm);
}

.btn-action {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-sm);
    border-radius: var(--radius-sm);
    background: var(--color-bg-hover);
    color: var(--color-text-secondary);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    text-decoration: none;
    transition: background 0.2s, color 0.2s;
}

.btn-action:hover {
    background: var(--color-accent-light);
    color: var(--color-accent);
}

/* Receipt detail */
.receipt-detail {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-xl);
    margin-bottom: var(--space-lg);
}

.receipt-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: var(--space-xl);
    padding-bottom: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.receipt-logo {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-accent);
}

.receipt-title {
    text-align: right;
}

.receipt-title h2 {
    margin: 0 0 var(--space-xs) 0;
    font-size: 1.25rem;
}

.receipt-number {
    font-family: monospace;
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.receipt-date {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.receipt-parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-xl);
    margin-bottom: var(--space-xl);
}

.receipt-party h4 {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    margin-bottom: var(--space-xs);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.receipt-party p {
    margin: 0;
    color: var(--color-text-primary);
    line-height: 1.5;
}

.receipt-items-table {
    width: 100%;
    margin-bottom: var(--space-xl);
    border-collapse: collapse;
}

.receipt-items-table th {
    text-align: left;
    padding: var(--space-sm);
    border-bottom: 2px solid var(--color-border);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    font-weight: 500;
}

.receipt-items-table td {
    padding: var(--space-sm);
    border-bottom: 1px solid var(--color-border);
}

.receipt-items-table .amount {
    text-align: right;
}

.receipt-totals {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: var(--space-xs);
}

.receipt-total-row {
    display: flex;
    justify-content: space-between;
    gap: var(--space-xl);
    font-size: var(--text-sm);
    min-width: 250px;
}

.receipt-total-row.grand-total {
    font-size: 1.1rem;
    font-weight: 600;
    padding-top: var(--space-sm);
    border-top: 2px solid var(--color-border);
    margin-top: var(--space-sm);
}

.vat-breakdown {
    background: var(--color-bg-surface);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-top: var(--space-lg);
}

.vat-breakdown h4 {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    margin-bottom: var(--space-sm);
}

.empty-state {
    text-align: center;
    padding: var(--space-3xl);
    color: var(--color-text-muted);
}

.empty-icon {
    margin-bottom: var(--space-md);
    opacity: 0.5;
}

@media (max-width: 767px) {
    .receipt-parties {
        grid-template-columns: 1fr;
        gap: var(--space-lg);
    }

    .receipt-detail {
        padding: var(--space-md);
    }

    .purchase-header {
        flex-direction: column;
        gap: var(--space-sm);
    }

    .purchase-amount {
        text-align: left;
    }

    .summary-cards {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">/</span>
        <?php if ($viewReceipt): ?>
            <a href="/profile/receipts">Mina köp</a>
            <span class="breadcrumb-sep">/</span>
            <span><?= h($viewReceipt['receipt_number']) ?></span>
        <?php elseif ($viewOrder): ?>
            <a href="/profile/receipts">Mina köp</a>
            <span class="breadcrumb-sep">/</span>
            <span><?= h($viewOrder['order_number']) ?></span>
        <?php else: ?>
            <span>Mina köp</span>
        <?php endif; ?>
    </nav>
    <h1 class="page-title">
        <i data-lucide="shopping-bag" class="page-icon"></i>
        <?php if ($viewReceipt): ?>
            Kvitto
        <?php elseif ($viewOrder): ?>
            Order
        <?php else: ?>
            Mina köp
        <?php endif; ?>
    </h1>
</div>

<?php if ($viewReceipt): ?>
    <!-- ================================================================== -->
    <!-- VISA ENSKILT KVITTO -->
    <!-- ================================================================== -->
    <a href="/profile/receipts" class="btn btn-ghost" style="margin-bottom: var(--space-lg); display: inline-flex; align-items: center; gap: var(--space-xs);">
        <i data-lucide="arrow-left"></i> Tillbaka
    </a>

    <div class="receipt-detail">
        <div class="receipt-header">
            <div class="receipt-logo">
                <?= h($viewReceipt['seller_name'] ?? 'TheHUB') ?>
            </div>
            <div class="receipt-title">
                <h2>Kvitto</h2>
                <p class="receipt-number"><?= h($viewReceipt['receipt_number']) ?></p>
                <p class="receipt-date"><?= date('Y-m-d H:i', strtotime($viewReceipt['issued_at'])) ?></p>
            </div>
        </div>

        <div class="receipt-parties">
            <div class="receipt-party">
                <h4>Säljare</h4>
                <p><strong><?= h($viewReceipt['seller_name'] ?? 'TheHUB') ?></strong></p>
                <?php if (!empty($viewReceipt['seller_org_number'])): ?>
                    <p>Org.nr: <?= h($viewReceipt['seller_org_number']) ?></p>
                <?php endif; ?>
            </div>
            <div class="receipt-party">
                <h4>Köpare</h4>
                <p><strong><?= h($viewReceipt['customer_name']) ?></strong></p>
                <p><?= h($viewReceipt['customer_email']) ?></p>
            </div>
        </div>

        <?php if (!empty($viewReceipt['items'])): ?>
            <table class="receipt-items-table">
                <thead>
                    <tr>
                        <th>Beskrivning</th>
                        <th class="amount">Antal</th>
                        <th class="amount">Pris</th>
                        <th class="amount">Moms</th>
                        <th class="amount">Totalt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($viewReceipt['items'] as $item): ?>
                        <tr>
                            <td><?= h($item['description']) ?></td>
                            <td class="amount"><?= $item['quantity'] ?></td>
                            <td class="amount"><?= number_format($item['unit_price'], 2, ',', ' ') ?> kr</td>
                            <td class="amount"><?= $item['vat_rate'] ?>%</td>
                            <td class="amount"><?= number_format($item['total_price'], 2, ',', ' ') ?> kr</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="receipt-totals">
            <div class="receipt-total-row">
                <span>Summa exkl. moms:</span>
                <span><?= number_format($viewReceipt['subtotal'], 2, ',', ' ') ?> kr</span>
            </div>
            <div class="receipt-total-row">
                <span>Moms:</span>
                <span><?= number_format($viewReceipt['vat_amount'], 2, ',', ' ') ?> kr</span>
            </div>
            <?php if ($viewReceipt['discount'] > 0): ?>
                <div class="receipt-total-row">
                    <span>Rabatt:</span>
                    <span>-<?= number_format($viewReceipt['discount'], 2, ',', ' ') ?> kr</span>
                </div>
            <?php endif; ?>
            <div class="receipt-total-row grand-total">
                <span>Totalt:</span>
                <span><?= number_format($viewReceipt['total_amount'], 2, ',', ' ') ?> kr</span>
            </div>
        </div>

        <?php if (!empty($viewReceipt['vat_breakdown'])): ?>
            <div class="vat-breakdown">
                <h4>Momsspecifikation</h4>
                <?php foreach ($viewReceipt['vat_breakdown'] as $vat): ?>
                    <div class="receipt-total-row">
                        <span>Moms <?= $vat['rate'] ?>% (underlag: <?= number_format($vat['base'], 2, ',', ' ') ?> kr)</span>
                        <span><?= number_format($vat['vat'], 2, ',', ' ') ?> kr</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($viewOrder): ?>
    <!-- ================================================================== -->
    <!-- VISA ENSKILD ORDER -->
    <!-- ================================================================== -->
    <a href="/profile/receipts" class="btn btn-ghost" style="margin-bottom: var(--space-lg); display: inline-flex; align-items: center; gap: var(--space-xs);">
        <i data-lucide="arrow-left"></i> Tillbaka
    </a>

    <div class="receipt-detail">
        <div class="receipt-header">
            <div class="receipt-logo">
                <?= h($viewOrder['seller_name'] ?? 'TheHUB') ?>
            </div>
            <div class="receipt-title">
                <h2>Order</h2>
                <p class="receipt-number"><?= h($viewOrder['order_number']) ?></p>
                <p class="receipt-date"><?= date('Y-m-d H:i', strtotime($viewOrder['created_at'])) ?></p>
            </div>
        </div>

        <div class="receipt-parties">
            <div class="receipt-party">
                <h4>Säljare</h4>
                <p><strong><?= h($viewOrder['seller_name'] ?? 'TheHUB') ?></strong></p>
                <?php if (!empty($viewOrder['seller_org_number'])): ?>
                    <p>Org.nr: <?= h($viewOrder['seller_org_number']) ?></p>
                <?php endif; ?>
            </div>
            <div class="receipt-party">
                <h4>Köpare</h4>
                <p><strong><?= h($viewOrder['customer_name']) ?></strong></p>
                <p><?= h($viewOrder['customer_email']) ?></p>
            </div>
        </div>

        <?php if (!empty($viewOrder['items'])): ?>
            <table class="receipt-items-table">
                <thead>
                    <tr>
                        <th>Beskrivning</th>
                        <th class="amount">Antal</th>
                        <th class="amount">Pris</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($viewOrder['items'] as $item): ?>
                        <tr>
                            <td><?= h($item['description']) ?></td>
                            <td class="amount"><?= $item['quantity'] ?></td>
                            <td class="amount"><?= number_format($item['total_price'], 2, ',', ' ') ?> kr</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="receipt-totals">
            <?php if ($viewOrder['discount'] > 0): ?>
                <div class="receipt-total-row">
                    <span>Delsumma:</span>
                    <span><?= number_format($viewOrder['subtotal'], 2, ',', ' ') ?> kr</span>
                </div>
                <div class="receipt-total-row">
                    <span>Rabatt:</span>
                    <span>-<?= number_format($viewOrder['discount'], 2, ',', ' ') ?> kr</span>
                </div>
            <?php endif; ?>
            <div class="receipt-total-row grand-total">
                <span>Totalt:</span>
                <span><?= number_format($viewOrder['total_amount'], 2, ',', ' ') ?> kr</span>
            </div>
        </div>

        <div style="margin-top: var(--space-xl); padding-top: var(--space-lg); border-top: 1px solid var(--color-border);">
            <p style="color: var(--color-text-muted); font-size: var(--text-sm);">
                <strong>Status:</strong>
                <?php
                $statusText = [
                    'paid' => 'Betald',
                    'pending' => 'Väntar på betalning',
                    'failed' => 'Misslyckades',
                    'refunded' => 'Återbetald',
                    'cancelled' => 'Avbruten'
                ];
                echo $statusText[$viewOrder['payment_status']] ?? $viewOrder['payment_status'];
                ?>
                <?php if ($viewOrder['paid_at']): ?>
                    (<?= date('Y-m-d', strtotime($viewOrder['paid_at'])) ?>)
                <?php endif; ?>
            </p>
            <?php if ($viewOrder['payment_method']): ?>
                <p style="color: var(--color-text-muted); font-size: var(--text-sm);">
                    <strong>Betalmetod:</strong> <?= ucfirst(h($viewOrder['payment_method'])) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ================================================================== -->
    <!-- HUVUDVY: SAMMANFATTNING + TABS -->
    <!-- ================================================================== -->

    <!-- Sammanfattning -->
    <div class="summary-cards">
        <div class="summary-card">
            <span class="summary-value"><?= count($upcomingRegistrations) ?></span>
            <span class="summary-label">Kommande</span>
        </div>
        <div class="summary-card">
            <span class="summary-value"><?= $purchaseCount ?></span>
            <span class="summary-label">Köp</span>
        </div>
        <div class="summary-card">
            <span class="summary-value"><?= number_format($totalSpent, 0, ',', ' ') ?> kr</span>
            <span class="summary-label">Totalt betalt</span>
        </div>
    </div>

    <!-- Tabs -->
    <div class="page-tabs">
        <button class="page-tab active" data-tab="upcoming">
            Kommande (<?= count($upcomingRegistrations) ?>)
        </button>
        <button class="page-tab" data-tab="history">
            Köphistorik (<?= count($purchases) ?>)
        </button>
    </div>

    <!-- Tab: Kommande anmälningar -->
    <div class="tab-content active" id="tab-upcoming">
        <?php if (empty($upcomingRegistrations)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i data-lucide="calendar-x" style="width: 48px; height: 48px;"></i>
                </div>
                <h3>Inga kommande anmälningar</h3>
                <p>När du anmäler dig till tävlingar visas de här.</p>
                <a href="/calendar" class="btn btn-primary" style="margin-top: var(--space-md);">
                    Se tävlingskalender
                </a>
            </div>
        <?php else: ?>
            <div class="upcoming-list">
                <?php foreach ($upcomingRegistrations as $reg): ?>
                    <a href="/calendar/<?= $reg['event_id'] ?>" class="upcoming-card">
                        <div class="upcoming-date">
                            <span class="upcoming-day"><?= date('j', strtotime($reg['event_date'])) ?></span>
                            <span class="upcoming-month"><?= strtoupper(date('M', strtotime($reg['event_date']))) ?></span>
                        </div>
                        <div class="upcoming-info">
                            <div class="upcoming-event"><?= h($reg['event_name']) ?></div>
                            <div class="upcoming-details">
                                <?= h($reg['firstname'] . ' ' . $reg['lastname']) ?>
                                <?php if ($reg['class_name']): ?>
                                    &bull; <?= h($reg['class_name']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="upcoming-status status-<?= $reg['status'] ?>">
                            <?= $reg['status'] === 'confirmed' ? 'Bekräftad' : 'Väntar' ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Köphistorik -->
    <div class="tab-content" id="tab-history">
        <?php if (empty($purchases)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i data-lucide="receipt" style="width: 48px; height: 48px;"></i>
                </div>
                <h3>Ingen köphistorik ännu</h3>
                <p>Dina köp och kvitton kommer visas här.</p>
            </div>
        <?php else: ?>
            <div class="purchase-list">
                <?php foreach ($purchases as $purchase): ?>
                    <div class="purchase-card">
                        <div class="purchase-header">
                            <div class="purchase-info">
                                <div class="purchase-title">
                                    <?php if ($purchase['event_name']): ?>
                                        <?= h($purchase['event_name']) ?>
                                    <?php elseif ($purchase['series_name']): ?>
                                        <?= h($purchase['series_name']) ?> - Serie-pass
                                    <?php else: ?>
                                        Order <?= h($purchase['order_number']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="purchase-seller">
                                    <i data-lucide="store" style="width: 14px; height: 14px;"></i>
                                    <?= h($purchase['seller_name'] ?? 'TheHUB') ?>
                                    <?php if ($purchase['seller_org_number']): ?>
                                        <span style="color: var(--color-text-muted);">(<?= h($purchase['seller_org_number']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="purchase-meta">
                                    <?= h($purchase['order_number']) ?> &bull;
                                    <?= date('Y-m-d', strtotime($purchase['created_at'])) ?>
                                </div>
                            </div>
                            <div class="purchase-amount">
                                <div class="purchase-price"><?= number_format($purchase['total_amount'], 0, ',', ' ') ?> kr</div>
                                <div class="purchase-status <?= $purchase['payment_status'] ?>">
                                    <?php
                                    $statusText = [
                                        'paid' => 'Betald',
                                        'pending' => 'Väntar',
                                        'failed' => 'Misslyckad',
                                        'refunded' => 'Återbetald'
                                    ];
                                    echo $statusText[$purchase['payment_status']] ?? $purchase['payment_status'];
                                    ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($purchase['items'])): ?>
                            <div class="purchase-items">
                                <?php foreach ($purchase['items'] as $item): ?>
                                    <div class="purchase-item">
                                        <span class="purchase-item-name"><?= h($item['description']) ?></span>
                                        <span class="purchase-item-price"><?= number_format($item['total_price'], 0, ',', ' ') ?> kr</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="purchase-footer">
                            <div class="purchase-method">
                                <?php if ($purchase['payment_method'] === 'card'): ?>
                                    <i data-lucide="credit-card" style="width: 14px; height: 14px;"></i> Kort
                                <?php elseif ($purchase['payment_method'] === 'swish'): ?>
                                    <i data-lucide="smartphone" style="width: 14px; height: 14px;"></i> Swish
                                <?php elseif ($purchase['payment_method']): ?>
                                    <i data-lucide="wallet" style="width: 14px; height: 14px;"></i> <?= ucfirst(h($purchase['payment_method'])) ?>
                                <?php endif; ?>
                            </div>
                            <div class="purchase-actions">
                                <?php if ($purchase['receipt_id'] && $purchase['payment_status'] === 'paid'): ?>
                                    <a href="/profile/receipts?view=<?= $purchase['receipt_id'] ?>" class="btn-action">
                                        <i data-lucide="file-text" style="width: 14px; height: 14px;"></i>
                                        Visa kvitto
                                    </a>
                                <?php elseif ($purchase['payment_status'] === 'paid'): ?>
                                    <a href="/profile/receipts?order=<?= $purchase['id'] ?>" class="btn-action">
                                        <i data-lucide="eye" style="width: 14px; height: 14px;"></i>
                                        Visa detaljer
                                    </a>
                                <?php elseif ($purchase['payment_status'] === 'pending'): ?>
                                    <a href="/checkout/<?= $purchase['id'] ?>" class="btn-action">
                                        <i data-lucide="credit-card" style="width: 14px; height: 14px;"></i>
                                        Betala
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<script>
// Tab switching
document.querySelectorAll('.page-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        var tabId = this.dataset.tab;

        // Update active tab
        document.querySelectorAll('.page-tab').forEach(function(t) {
            t.classList.remove('active');
        });
        this.classList.add('active');

        // Show correct content
        document.querySelectorAll('.tab-content').forEach(function(content) {
            content.classList.remove('active');
        });
        document.getElementById('tab-' + tabId).classList.add('active');
    });
});

// Init Lucide icons
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>
