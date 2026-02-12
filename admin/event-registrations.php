<?php
/**
 * Event Registrations - Complete participant list with all fields and CSV export
 * Uses Economy Tab System
 */

require_once __DIR__ . '/../config.php';

$db = getDB();

// Get event ID (supports both 'id' and 'event_id')
$eventId = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['event_id']) ? intval($_GET['event_id']) : 0);

if ($eventId <= 0) {
    $_SESSION['flash_message'] = 'Ogiltigt event-ID';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/events.php');
    exit;
}

// Fetch event
$event = $db->getRow("
    SELECT e.*, s.name as series_name
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

$message = '';
$messageType = 'info';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';
    $regId = intval($_POST['registration_id'] ?? 0);

    if ($action === 'confirm' && $regId) {
        $db->update('event_registrations', [
            'status' => 'confirmed',
            'confirmed_date' => date('Y-m-d H:i:s')
        ], 'id = ?', [$regId]);
        $message = 'Anmälan bekräftad!';
        $messageType = 'success';

    } elseif ($action === 'cancel' && $regId) {
        $db->update('event_registrations', ['status' => 'cancelled'], 'id = ?', [$regId]);
        $message = 'Anmälan avbruten!';
        $messageType = 'success';
    }
}

// Filter parameters
$filterStatus = $_GET['status'] ?? 'all';
$filterClass = $_GET['class'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query
$whereConditions = ["er.event_id = ?"];
$params = [$eventId];

if ($filterStatus && $filterStatus !== 'all') {
    $whereConditions[] = "er.status = ?";
    $params[] = $filterStatus;
}

if ($filterClass) {
    $whereConditions[] = "er.category = ?";
    $params[] = $filterClass;
}

if ($search) {
    $whereConditions[] = "(er.first_name LIKE ? OR er.last_name LIKE ? OR r.email LIKE ? OR r.phone LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Detect available rider columns (ICE, address fields may not exist yet)
$hasIceFields = false;
$hasAddressFields = false;
try {
    $cols = $db->getAll("SHOW COLUMNS FROM riders");
    $colNames = array_column($cols, 'Field');
    $hasIceFields = in_array('ice_name', $colNames) && in_array('ice_phone', $colNames);
    $hasAddressFields = in_array('address', $colNames) && in_array('postal_code', $colNames);
} catch (Exception $e) {}

// Build SELECT with all available fields
$selectFields = "er.*, r.email, r.phone, r.birth_year as rider_birth_year,
       r.gender as rider_gender, r.nationality, r.license_number,
       c.name as club_name,
       o.order_number, o.payment_status as order_payment_status,
       o.payment_method, o.customer_name as buyer_name, o.customer_email as buyer_email,
       o.total_amount as order_total, o.paid_at";

if ($hasIceFields) {
    $selectFields .= ", r.ice_name, r.ice_phone";
}
if ($hasAddressFields) {
    $selectFields .= ", r.address, r.postal_code, r.postal_city";
}

// Get registrations with all rider data
$registrations = $db->getAll("
    SELECT {$selectFields}
    FROM event_registrations er
    LEFT JOIN riders r ON er.rider_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    LEFT JOIN orders o ON er.order_id = o.id
    {$whereClause}
    ORDER BY er.category, er.last_name, er.first_name
    LIMIT 500
", $params);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $eventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event['name']);
    $filename = "anmalningar_{$eventName}_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // BOM for Excel UTF-8 support
    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'w');

    // Header row
    $headers = ['Klass', 'Förnamn', 'Efternamn', 'Födelseår', 'Kön', 'E-post', 'Telefon',
                'Klubb', 'Nationalitet', 'UCI ID', 'Startnr'];
    if ($hasIceFields) {
        $headers[] = 'Nödkontakt namn';
        $headers[] = 'Nödkontakt telefon';
    }
    if ($hasAddressFields) {
        $headers[] = 'Adress';
        $headers[] = 'Postnummer';
        $headers[] = 'Ort';
    }
    $headers = array_merge($headers, ['Order', 'Betalstatus', 'Betalmetod', 'Betald datum',
                'Köpare', 'Köpare e-post', 'Status', 'Anmäld']);
    fputcsv($fp, $headers, ';');

    // Data rows
    foreach ($registrations as $reg) {
        $row = [
            $reg['category'] ?? '',
            $reg['first_name'] ?? '',
            $reg['last_name'] ?? '',
            $reg['rider_birth_year'] ?? $reg['birth_year'] ?? '',
            $reg['rider_gender'] ?? $reg['gender'] ?? '',
            $reg['email'] ?? '',
            $reg['phone'] ?? '',
            $reg['club_name'] ?? $reg['club'] ?? '',
            $reg['nationality'] ?? '',
            $reg['license_number'] ?? '',
            $reg['bib_number'] ?? '',
        ];
        if ($hasIceFields) {
            $row[] = $reg['ice_name'] ?? '';
            $row[] = $reg['ice_phone'] ?? '';
        }
        if ($hasAddressFields) {
            $row[] = $reg['address'] ?? '';
            $row[] = $reg['postal_code'] ?? '';
            $row[] = $reg['postal_city'] ?? '';
        }
        $paymentStatusMap = ['paid' => 'Betald', 'pending' => 'Väntande', 'failed' => 'Misslyckad', 'expired' => 'Utgången'];
        $statusMap = ['pending' => 'Väntande', 'confirmed' => 'Bekräftad', 'cancelled' => 'Avbruten'];
        $row = array_merge($row, [
            $reg['order_number'] ?? '',
            $paymentStatusMap[$reg['order_payment_status'] ?? ''] ?? ($reg['order_payment_status'] ?? ''),
            $reg['payment_method'] ?? '',
            $reg['paid_at'] ? date('Y-m-d H:i', strtotime($reg['paid_at'])) : '',
            $reg['buyer_name'] ?? '',
            $reg['buyer_email'] ?? '',
            $statusMap[$reg['status'] ?? ''] ?? ($reg['status'] ?? ''),
            $reg['created_at'] ? date('Y-m-d H:i', strtotime($reg['created_at'])) : '',
        ]);
        fputcsv($fp, $row, ';');
    }

    fclose($fp);
    exit;
}

// Get stats
$stats = $db->getRow("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM event_registrations
    WHERE event_id = ?
", [$eventId]);

// Get available classes for filter
$classes = $db->getAll("
    SELECT DISTINCT category FROM event_registrations WHERE event_id = ? ORDER BY category
", [$eventId]);

// Build CSV export URL with current filters
$exportParams = ['id' => $eventId, 'export' => 'csv'];
if ($filterStatus !== 'all') $exportParams['status'] = $filterStatus;
if ($filterClass) $exportParams['class'] = $filterClass;
if ($search) $exportParams['search'] = $search;
$exportUrl = '/admin/event-registrations.php?' . http_build_query($exportParams);

// Set page variables for economy layout
$economy_page_title = 'Anmälningar';
$economy_page_actions = '<a href="' . htmlspecialchars($exportUrl) . '" class="btn btn--secondary btn--sm"><i data-lucide="download"></i> Exportera CSV</a>';

include __DIR__ . '/components/economy-layout.php';
?>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> mb-lg">
            <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="reg-stats-grid mb-lg">
            <div class="card">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold"><?= $stats['total'] ?? 0 ?></div>
                    <div class="text-sm text-secondary">Totalt</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold text-warning"><?= $stats['pending'] ?? 0 ?></div>
                    <div class="text-sm text-secondary">Väntande</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold text-success"><?= $stats['confirmed'] ?? 0 ?></div>
                    <div class="text-sm text-secondary">Bekräftade</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold text-secondary"><?= $stats['cancelled'] ?? 0 ?></div>
                    <div class="text-sm text-secondary">Avbrutna</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-lg">
            <div class="card-body">
                <form method="GET" class="reg-filter-form">
                    <input type="hidden" name="id" value="<?= $eventId ?>">

                    <div class="form-group" style="min-width: 130px;">
                        <label class="label">Status</label>
                        <select name="status" class="input" onchange="this.form.submit()">
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Alla</option>
                            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Väntande</option>
                            <option value="confirmed" <?= $filterStatus === 'confirmed' ? 'selected' : '' ?>>Bekräftade</option>
                            <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Avbrutna</option>
                        </select>
                    </div>

                    <?php if (!empty($classes)): ?>
                    <div class="form-group" style="min-width: 130px;">
                        <label class="label">Klass</label>
                        <select name="class" class="input" onchange="this.form.submit()">
                            <option value="">Alla klasser</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= htmlspecialchars($c['category']) ?>" <?= $filterClass === $c['category'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['category']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group" style="flex: 1; min-width: 180px;">
                        <label class="label">Sök</label>
                        <input type="text" name="search" class="input"
                               value="<?= htmlspecialchars($search) ?>"
                               placeholder="Namn, e-post, telefon...">
                    </div>

                    <button type="submit" class="btn btn--secondary">
                        <i data-lucide="search"></i>
                        Sök
                    </button>
                </form>
            </div>
        </div>

        <!-- Registrations - Desktop table -->
        <div class="card reg-table-desktop">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;">Deltagarlista (<?= count($registrations) ?>)</h3>
                <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn--secondary btn--sm">
                    <i data-lucide="download"></i> CSV
                </a>
            </div>
            <div class="card-body gs-p-0">
                <?php if (empty($registrations)): ?>
                <div class="p-xl text-center text-secondary">
                    <i data-lucide="users" class="icon-xl mb-md" style="opacity: 0.3;"></i>
                    <p>Inga anmälningar hittades</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table--compact">
                        <thead>
                            <tr>
                                <th>Namn</th>
                                <th>Klass</th>
                                <th>År</th>
                                <th>Klubb</th>
                                <th>E-post</th>
                                <th>Telefon</th>
                                <?php if ($hasIceFields): ?>
                                <th>Nödkontakt</th>
                                <?php endif; ?>
                                <th>UCI ID</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrations as $reg): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-secondary"><?= htmlspecialchars($reg['category'] ?? '') ?></span>
                                </td>
                                <td class="text-sm text-secondary"><?= htmlspecialchars($reg['rider_birth_year'] ?? $reg['birth_year'] ?? '-') ?></td>
                                <td class="text-sm"><?= htmlspecialchars($reg['club_name'] ?? $reg['club'] ?? '-') ?></td>
                                <td class="text-sm"><?= htmlspecialchars($reg['email'] ?? '-') ?></td>
                                <td class="text-sm"><?= htmlspecialchars($reg['phone'] ?? '-') ?></td>
                                <?php if ($hasIceFields): ?>
                                <td class="text-sm">
                                    <?php if (!empty($reg['ice_name'])): ?>
                                        <?= htmlspecialchars($reg['ice_name']) ?>
                                        <?php if (!empty($reg['ice_phone'])): ?>
                                            <div class="text-xs text-secondary"><?= htmlspecialchars($reg['ice_phone']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td class="text-sm">
                                    <?php if (!empty($reg['license_number'])): ?>
                                        <code class="text-xs"><?= htmlspecialchars($reg['license_number']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($reg['order_number']): ?>
                                    <code class="text-xs"><?= htmlspecialchars($reg['order_number']) ?></code>
                                    <?php if ($reg['order_payment_status'] === 'paid'): ?>
                                    <span class="badge badge-success badge-sm">Betald</span>
                                    <?php elseif ($reg['order_payment_status'] === 'pending'): ?>
                                    <span class="badge badge-warning badge-sm">Väntar</span>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $statusBadge = match($reg['status']) {
                                        'pending' => 'badge-warning',
                                        'confirmed' => 'badge-success',
                                        'cancelled' => 'badge-secondary',
                                        default => 'badge-secondary'
                                    };
                                    $statusLabel = match($reg['status']) {
                                        'pending' => 'Väntande',
                                        'confirmed' => 'Bekräftad',
                                        'cancelled' => 'Avbruten',
                                        default => $reg['status']
                                    };
                                    ?>
                                    <span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span>
                                </td>
                                <td class="text-right">
                                    <?php if ($reg['status'] === 'pending'): ?>
                                    <div class="flex gap-xs justify-end">
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="confirm">
                                            <input type="hidden" name="registration_id" value="<?= $reg['id'] ?>">
                                            <button type="submit" class="btn btn--sm btn--success" title="Bekräfta">
                                                <i data-lucide="check"></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="registration_id" value="<?= $reg['id'] ?>">
                                            <button type="submit" class="btn btn--sm btn--secondary" title="Avbryt"
                                                    onclick="return confirm('Avbryta denna anmälan?')">
                                                <i data-lucide="x"></i>
                                            </button>
                                        </form>
                                    </div>
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

        <!-- Registrations - Mobile card list -->
        <div class="reg-cards-mobile">
            <?php if (empty($registrations)): ?>
            <div class="card">
                <div class="card-body text-center text-secondary" style="padding:var(--space-xl);">
                    <i data-lucide="users" style="width:48px;height:48px;opacity:0.3;margin-bottom:var(--space-md);"></i>
                    <p>Inga anmälningar hittades</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;">Deltagare (<?= count($registrations) ?>)</h3>
                    <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn--secondary btn--sm">
                        <i data-lucide="download"></i> CSV
                    </a>
                </div>
            </div>
            <?php
            $currentCategory = null;
            foreach ($registrations as $reg):
                if ($reg['category'] !== $currentCategory):
                    $currentCategory = $reg['category'];
            ?>
            <div class="reg-mobile-category"><?= htmlspecialchars($currentCategory ?? 'Okänd klass') ?></div>
            <?php endif; ?>
            <div class="reg-mobile-card">
                <div class="reg-mobile-card__header">
                    <div class="reg-mobile-card__name">
                        <strong><?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?></strong>
                        <span class="text-sm text-secondary"><?= htmlspecialchars($reg['rider_birth_year'] ?? $reg['birth_year'] ?? '') ?></span>
                    </div>
                    <?php
                    $statusBadge = match($reg['status']) {
                        'pending' => 'badge-warning',
                        'confirmed' => 'badge-success',
                        'cancelled' => 'badge-secondary',
                        default => 'badge-secondary'
                    };
                    $statusLabel = match($reg['status']) {
                        'pending' => 'Väntande',
                        'confirmed' => 'Bekräftad',
                        'cancelled' => 'Avbruten',
                        default => $reg['status']
                    };
                    ?>
                    <span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span>
                </div>
                <div class="reg-mobile-card__details">
                    <?php if (!empty($reg['club_name'] ?? $reg['club'])): ?>
                    <div class="reg-mobile-detail">
                        <i data-lucide="shield" style="width:14px;height:14px;"></i>
                        <?= htmlspecialchars($reg['club_name'] ?? $reg['club']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($reg['email'])): ?>
                    <div class="reg-mobile-detail">
                        <i data-lucide="mail" style="width:14px;height:14px;"></i>
                        <a href="mailto:<?= htmlspecialchars($reg['email']) ?>"><?= htmlspecialchars($reg['email']) ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($reg['phone'])): ?>
                    <div class="reg-mobile-detail">
                        <i data-lucide="phone" style="width:14px;height:14px;"></i>
                        <a href="tel:<?= htmlspecialchars($reg['phone']) ?>"><?= htmlspecialchars($reg['phone']) ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($hasIceFields && !empty($reg['ice_name'])): ?>
                    <div class="reg-mobile-detail" style="color:var(--color-warning);">
                        <i data-lucide="heart-pulse" style="width:14px;height:14px;"></i>
                        <?= htmlspecialchars($reg['ice_name']) ?>
                        <?php if (!empty($reg['ice_phone'])): ?>
                            - <a href="tel:<?= htmlspecialchars($reg['ice_phone']) ?>"><?= htmlspecialchars($reg['ice_phone']) ?></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($reg['license_number'])): ?>
                    <div class="reg-mobile-detail">
                        <i data-lucide="id-card" style="width:14px;height:14px;"></i>
                        UCI: <?= htmlspecialchars($reg['license_number']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($reg['order_number'])): ?>
                    <div class="reg-mobile-detail">
                        <i data-lucide="receipt" style="width:14px;height:14px;"></i>
                        <?= htmlspecialchars($reg['order_number']) ?>
                        <?php if ($reg['order_payment_status'] === 'paid'): ?>
                            <span class="badge badge-success badge-sm">Betald</span>
                        <?php elseif ($reg['order_payment_status'] === 'pending'): ?>
                            <span class="badge badge-warning badge-sm">Väntar</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($reg['status'] === 'pending'): ?>
                <div class="reg-mobile-card__actions">
                    <form method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="registration_id" value="<?= $reg['id'] ?>">
                        <button type="submit" class="btn btn--sm btn--success">
                            <i data-lucide="check"></i> Bekräfta
                        </button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="registration_id" value="<?= $reg['id'] ?>">
                        <button type="submit" class="btn btn--sm btn--secondary"
                                onclick="return confirm('Avbryta denna anmälan?')">
                            <i data-lucide="x"></i> Avbryt
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

<style>
.icon-xl {
    width: 48px;
    height: 48px;
}
.badge-sm {
    font-size: var(--text-xs);
    padding: 2px 6px;
}
.reg-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-md);
}
.reg-filter-form {
    display: flex;
    gap: var(--space-md);
    flex-wrap: wrap;
    align-items: flex-end;
}
.reg-cards-mobile {
    display: none;
}
.reg-mobile-category {
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-surface);
    font-weight: 600;
    font-size: 0.9375rem;
    border-top: 1px solid var(--color-border);
    border-bottom: 1px solid var(--color-border);
    margin-top: var(--space-sm);
}
.reg-mobile-card {
    background: var(--color-bg-card);
    border-bottom: 1px solid var(--color-border);
    padding: var(--space-md);
}
.reg-mobile-card__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}
.reg-mobile-card__name {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.reg-mobile-card__name strong {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.reg-mobile-card__details {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}
.reg-mobile-detail {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}
.reg-mobile-detail a {
    color: var(--color-accent-text);
    text-decoration: none;
}
.reg-mobile-card__actions {
    display: flex;
    gap: var(--space-sm);
    margin-top: var(--space-sm);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
}
.reg-mobile-card__actions .btn {
    min-height: 44px;
}

@media (max-width: 767px) {
    .reg-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .reg-table-desktop {
        display: none;
    }
    .reg-cards-mobile {
        display: block;
    }
    .reg-mobile-category {
        margin-left: calc(-1 * var(--container-padding, 12px));
        margin-right: calc(-1 * var(--container-padding, 12px));
        padding-left: var(--container-padding, 12px);
        padding-right: var(--container-padding, 12px);
    }
    .reg-mobile-card {
        margin-left: calc(-1 * var(--container-padding, 12px));
        margin-right: calc(-1 * var(--container-padding, 12px));
        padding-left: var(--container-padding, 12px);
        padding-right: var(--container-padding, 12px);
    }
}
</style>

<?php include __DIR__ . '/components/economy-layout-footer.php'; ?>
