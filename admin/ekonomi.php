<?php
/**
 * Ekonomi Dashboard - Payment Administration
 *
 * Central panel för ekonomisk administration:
 * - Stripe (kortbetalningar)
 * - Ordrar och kvitton
 * - Prissättning
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$currentAdmin = getCurrentAdmin();
$isSuperAdmin = hasRole('super_admin');

// Check Stripe configuration
$stripeConfigured = !empty(env('STRIPE_SECRET_KEY', ''));

// Hämta statistik
$stats = [];

// Väntande betalningar
$stats['pending_orders'] = $db->getRow("SELECT COUNT(*) as cnt FROM orders WHERE payment_status = 'pending'")['cnt'] ?? 0;

// Bekräftade betalningar (senaste 30 dagarna)
$stats['confirmed_30d'] = $db->getRow("
    SELECT COUNT(*) as cnt FROM orders
    WHERE payment_status = 'paid'
    AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")['cnt'] ?? 0;

// Total omsättning (senaste 30 dagarna)
$stats['revenue_30d'] = $db->getRow("
    SELECT COALESCE(SUM(total_amount), 0) as total FROM orders
    WHERE payment_status = 'paid'
    AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")['total'] ?? 0;

// Väntande återbetalningar
$stats['pending_refunds'] = $db->getRow("
    SELECT COUNT(*) as cnt FROM refund_requests WHERE status = 'pending'
")['cnt'] ?? 0;

// Senaste ordrar
$recentOrders = $db->getAll("
    SELECT o.*, e.name as event_name,
           r.firstname, r.lastname
    FROM orders o
    LEFT JOIN events e ON o.event_id = e.id
    LEFT JOIN riders r ON o.rider_id = r.id
    ORDER BY o.created_at DESC
    LIMIT 10
");

// Page config
$page_title = 'Ekonomi';
$breadcrumbs = [
    ['label' => 'Ekonomi']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}

.stat-box {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    text-align: center;
}

.stat-box.highlight {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-text-primary);
    line-height: 1;
}

.stat-value.warning { color: var(--color-warning); }
.stat-value.success { color: var(--color-success); }
.stat-value.accent { color: var(--color-accent); }

.stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-xs);
}

.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
}

.action-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    display: flex;
    flex-direction: column;
}

.action-card:hover {
    border-color: var(--color-accent);
}

.action-card-header {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}

.action-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.action-icon svg {
    width: 24px;
    height: 24px;
}

.action-icon.stripe {
    background: linear-gradient(135deg, #635bff, #5851db);
    color: white;
}

.action-icon.orders {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.action-icon.pricing {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.action-icon.reports {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
}

.action-title {
    font-size: var(--text-lg);
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}

.action-subtitle {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    margin: 0;
}

.action-desc {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    margin-bottom: var(--space-md);
    flex: 1;
}

.action-links {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.action-link {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-hover);
    border-radius: var(--radius-sm);
    color: var(--color-text-primary);
    text-decoration: none;
    font-size: var(--text-sm);
    transition: all 0.15s;
}

.action-link:hover {
    background: var(--color-accent-light);
    color: var(--color-accent);
}

.action-link svg {
    width: 16px;
    height: 16px;
    color: var(--color-text-muted);
}

.action-link:hover svg {
    color: var(--color-accent);
}

.action-link.primary {
    background: var(--color-accent);
    color: white;
}

.action-link.primary:hover {
    background: var(--color-accent-hover);
    color: white;
}

.action-link.primary svg {
    color: white;
}

.orders-table {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.orders-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
    background: var(--color-bg-surface);
}

.orders-header h3 {
    margin: 0;
    font-size: var(--text-lg);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.order-row {
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    gap: var(--space-md);
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.order-row:last-child {
    border-bottom: none;
}

.order-row:hover {
    background: var(--color-bg-hover);
}

.order-status {
    padding: 4px 10px;
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    font-weight: 600;
    text-transform: uppercase;
}

.order-status.pending {
    background: rgba(251, 191, 36, 0.15);
    color: #d97706;
}

.order-status.paid {
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
}

.order-status.failed, .order-status.cancelled {
    background: rgba(239, 68, 68, 0.15);
    color: #dc2626;
}

.order-info {
    min-width: 0;
}

.order-customer {
    font-weight: 500;
    color: var(--color-text-primary);
}

.order-event {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.order-recipient {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}

.order-amount {
    font-weight: 600;
    color: var(--color-text-primary);
    text-align: right;
}

.order-date {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    text-align: right;
}

.quick-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-md);
    text-decoration: none;
    font-weight: 500;
    font-size: var(--text-sm);
}

.quick-btn:hover {
    background: var(--color-accent-hover);
}

.quick-btn svg {
    width: 16px;
    height: 16px;
}

@media (max-width: 767px) {
    .order-row {
        grid-template-columns: 1fr auto;
        gap: var(--space-sm);
    }

    .order-status {
        grid-column: span 2;
        justify-self: start;
    }
}
</style>

<!-- Statistik -->
<div class="stats-row">
    <div class="stat-box <?= $stats['pending_orders'] > 0 ? 'highlight' : '' ?>">
        <div class="stat-value <?= $stats['pending_orders'] > 0 ? 'warning' : '' ?>">
            <?= number_format($stats['pending_orders']) ?>
        </div>
        <div class="stat-label">Väntar på betalning</div>
    </div>
    <div class="stat-box">
        <div class="stat-value success"><?= number_format($stats['confirmed_30d']) ?></div>
        <div class="stat-label">Betalda (30 dagar)</div>
    </div>
    <div class="stat-box">
        <div class="stat-value accent"><?= number_format($stats['revenue_30d'], 0, ',', ' ') ?> kr</div>
        <div class="stat-label">Omsättning (30 dagar)</div>
    </div>
</div>

<!-- Snabblänkar -->
<?php if ($stats['pending_orders'] > 0): ?>
<div style="margin-bottom: var(--space-xl);">
    <a href="/admin/orders?status=pending" class="quick-btn">
        <i data-lucide="clock"></i>
        Hantera <?= $stats['pending_orders'] ?> väntande betalningar
    </a>
</div>
<?php endif; ?>

<!-- Huvudåtgärder -->
<div class="action-grid">
    <!-- Ordrar -->
    <div class="action-card">
        <div class="action-card-header">
            <div class="action-icon orders">
                <i data-lucide="receipt"></i>
            </div>
            <div>
                <h3 class="action-title">Ordrar</h3>
                <p class="action-subtitle"><?= $stats['pending_orders'] ?> väntande</p>
            </div>
        </div>
        <p class="action-desc">
            Se alla ordrar, bekräfta manuella betalningar, hantera återbetalningar.
        </p>
        <div class="action-links">
            <a href="/admin/orders" class="action-link primary">
                <i data-lucide="list"></i>
                Alla ordrar
            </a>
            <a href="/admin/orders?status=pending" class="action-link">
                <i data-lucide="clock"></i>
                Väntande
            </a>
            <?php if ($stats['pending_refunds'] > 0): ?>
            <a href="/admin/refund-requests" class="action-link">
                <i data-lucide="rotate-ccw"></i>
                Återbetalningar (<?= $stats['pending_refunds'] ?>)
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Prissättning -->
    <div class="action-card">
        <div class="action-card-header">
            <div class="action-icon pricing">
                <i data-lucide="tag"></i>
            </div>
            <div>
                <h3 class="action-title">Prissättning</h3>
                <p class="action-subtitle">Mallar & regler</p>
            </div>
        </div>
        <p class="action-desc">
            Skapa prismallar för events. Konfigurera early bird, sena anmälningar och klassspecifika priser.
        </p>
        <div class="action-links">
            <a href="/admin/pricing-templates" class="action-link primary">
                <i data-lucide="file-text"></i>
                Prismallar
            </a>
            <a href="/admin/classes" class="action-link">
                <i data-lucide="layers"></i>
                Klasser
            </a>
        </div>
    </div>

    <!-- Rapporter -->
    <div class="action-card">
        <div class="action-card-header">
            <div class="action-icon reports">
                <i data-lucide="bar-chart-3"></i>
            </div>
            <div>
                <h3 class="action-title">Rapporter</h3>
                <p class="action-subtitle">Export & statistik</p>
            </div>
        </div>
        <p class="action-desc">
            Exportera ordrar, se momsrapporter och arrangörsutbetalningar.
        </p>
        <div class="action-links">
            <a href="/admin/orders?export=csv" class="action-link">
                <i data-lucide="download"></i>
                Exportera ordrar (CSV)
            </a>
            <?php if ($isSuperAdmin): ?>
            <a href="/admin/promotor-payments" class="action-link">
                <i data-lucide="wallet"></i>
                Arrangörsbetalningar
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Senaste ordrar -->
<?php if (!empty($recentOrders)): ?>
<div class="orders-table">
    <div class="orders-header">
        <h3>
            <i data-lucide="clock"></i>
            Senaste ordrar
        </h3>
        <a href="/admin/orders" class="btn btn--secondary btn--sm">Visa alla</a>
    </div>
    <?php foreach ($recentOrders as $order): ?>
    <div class="order-row">
        <span class="order-status <?= $order['payment_status'] ?>">
            <?= $order['payment_status'] === 'paid' ? 'Betald' : ($order['payment_status'] === 'pending' ? 'Väntar' : 'Avbruten') ?>
        </span>
        <div class="order-info">
            <div class="order-customer"><?= h($order['firstname'] . ' ' . $order['lastname']) ?></div>
            <div class="order-event"><?= h($order['event_name'] ?? 'Serie-pass') ?></div>
            <?php if ($order['recipient_name']): ?>
            <div class="order-recipient"><?= h($order['recipient_name']) ?></div>
            <?php endif; ?>
        </div>
        <div class="order-amount"><?= number_format($order['total_amount'] ?? 0, 0, ',', ' ') ?> kr</div>
        <div class="order-date"><?= date('j M H:i', strtotime($order['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
