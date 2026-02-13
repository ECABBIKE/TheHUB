<?php
/**
 * Event Startlist - Admin & Promotor view
 *
 * Features:
 * - Event selector dropdown
 * - Basic view (compact) / Extended view (full data, horizontal scroll)
 * - Grouped by class
 * - Bib number assignment (auto / manual)
 * - CSV export
 * - Mobile-first design
 * - Accessible to both admin and promotor roles
 */

require_once __DIR__ . '/../config.php';

// Require at least promotor role
require_admin();
if (!hasRole('promotor') && !hasRole('admin')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/');
}

$db = getDB();
$currentUser = getCurrentAdmin();
$userId = $currentUser['id'] ?? 0;
$isAdmin = hasRole('admin');

// ─── Get available events ───
$events = [];
try {
    if ($isAdmin) {
        $events = $db->getAll("
            SELECT e.id, e.name, e.date, e.location, s.name as series_name,
                   (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id AND er.status != 'cancelled') as reg_count
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            WHERE e.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY e.date ASC
        ");
    } else {
        $events = $db->getAll("
            SELECT e.id, e.name, e.date, e.location, s.name as series_name,
                   (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id AND er.status != 'cancelled') as reg_count
            FROM events e
            JOIN promotor_events pe ON pe.event_id = e.id
            LEFT JOIN series s ON e.series_id = s.id
            WHERE pe.user_id = ?
            ORDER BY e.date ASC
        ", [$userId]);
    }
} catch (Exception $e) {
    error_log("Startlist events error: " . $e->getMessage());
}

// ─── Selected event ───
$eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Auto-select first upcoming event if none selected
if ($eventId <= 0 && !empty($events)) {
    foreach ($events as $ev) {
        if (strtotime($ev['date']) >= strtotime('today')) {
            $eventId = (int)$ev['id'];
            break;
        }
    }
    if ($eventId <= 0) {
        $eventId = (int)$events[0]['id'];
    }
}

$event = null;
$registrations = [];
$classes = [];
$stats = ['total' => 0, 'confirmed' => 0, 'pending' => 0];

if ($eventId > 0) {
    // Verify access for promotors
    if (!$isAdmin) {
        $hasAccess = $db->getRow("SELECT 1 FROM promotor_events WHERE user_id = ? AND event_id = ?", [$userId, $eventId]);
        if (!$hasAccess) {
            set_flash('error', 'Du har inte behörighet till detta event');
            redirect('/admin/promotor.php');
        }
    }

    $event = $db->getRow("
        SELECT e.*, s.name as series_name
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        WHERE e.id = ?
    ", [$eventId]);

    if ($event) {
        // ─── Handle bib assignment ───
        $message = '';
        $messageType = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            checkCsrf();
            $action = $_POST['action'] ?? '';

            if ($action === 'assign_bibs') {
                // Auto-assign bib numbers per class
                $classRegs = $db->getAll("
                    SELECT id, category FROM event_registrations
                    WHERE event_id = ? AND status != 'cancelled'
                    ORDER BY category, last_name, first_name
                ", [$eventId]);

                $currentClass = null;
                $bib = 0;
                $startNumber = intval($_POST['start_number'] ?? 1);

                foreach ($classRegs as $reg) {
                    if ($reg['category'] !== $currentClass) {
                        $currentClass = $reg['category'];
                        $bib = $startNumber;
                    }
                    $db->update('event_registrations', ['bib_number' => $bib], 'id = ?', [$reg['id']]);
                    $bib++;
                }
                $message = 'Startnummer tilldelade!';
                $messageType = 'success';

            } elseif ($action === 'save_bib') {
                $regId = intval($_POST['registration_id'] ?? 0);
                $bibNumber = intval($_POST['bib_number'] ?? 0);
                if ($regId > 0) {
                    $db->update('event_registrations', ['bib_number' => $bibNumber ?: null], 'id = ?', [$regId]);
                    $message = 'Startnummer uppdaterat!';
                    $messageType = 'success';
                }

            } elseif ($action === 'clear_bibs') {
                $db->query("UPDATE event_registrations SET bib_number = NULL WHERE event_id = ?", [$eventId]);
                $message = 'Alla startnummer rensade!';
                $messageType = 'success';
            }
        }

        // ─── Detect available columns ───
        $hasIceFields = false;
        try {
            $cols = $db->getAll("SHOW COLUMNS FROM riders");
            $colNames = array_column($cols, 'Field');
            $hasIceFields = in_array('ice_name', $colNames) && in_array('ice_phone', $colNames);
        } catch (Exception $e) {}

        // ─── Filter ───
        $filterClass = $_GET['class'] ?? '';
        $filterStatus = $_GET['status'] ?? 'active';
        $search = trim($_GET['search'] ?? '');
        $viewMode = $_GET['view'] ?? 'basic';

        $whereConditions = ["er.event_id = ?"];
        $params = [$eventId];

        if ($filterStatus === 'active') {
            $whereConditions[] = "er.status != 'cancelled'";
        } elseif ($filterStatus && $filterStatus !== 'all') {
            $whereConditions[] = "er.status = ?";
            $params[] = $filterStatus;
        }

        if ($filterClass) {
            $whereConditions[] = "er.category = ?";
            $params[] = $filterClass;
        }

        if ($search) {
            $whereConditions[] = "(er.first_name LIKE ? OR er.last_name LIKE ? OR er.bib_number LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

        // Build SELECT
        $selectFields = "er.*, r.email, r.phone, r.birth_year as rider_birth_year,
               r.gender as rider_gender, r.nationality, r.license_number,
               c.name as club_name,
               o.order_number, o.payment_status as order_payment_status";

        if ($hasIceFields) {
            $selectFields .= ", r.ice_name, r.ice_phone";
        }

        $registrations = $db->getAll("
            SELECT {$selectFields}
            FROM event_registrations er
            LEFT JOIN riders r ON er.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN orders o ON er.order_id = o.id
            {$whereClause}
            ORDER BY er.category, er.bib_number, er.last_name, er.first_name
        ", $params);

        // Stats
        $stats = $db->getRow("
            SELECT
                COUNT(CASE WHEN status != 'cancelled' THEN 1 END) as total,
                COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending
            FROM event_registrations
            WHERE event_id = ?
        ", [$eventId]);

        // Available classes
        $classes = $db->getAll("
            SELECT DISTINCT category FROM event_registrations
            WHERE event_id = ? AND status != 'cancelled'
            ORDER BY category
        ", [$eventId]);

        // ─── CSV Export ───
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $eventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event['name']);
            $filename = "startlista_{$eventName}_" . date('Y-m-d') . ".csv";

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF";

            $fp = fopen('php://output', 'w');
            $headers = ['Startnr', 'Klass', 'Efternamn', 'Fornamn', 'Fodelsear', 'Kon', 'Klubb', 'Nationalitet', 'UCI ID', 'E-post', 'Telefon'];
            if ($hasIceFields) {
                $headers[] = 'Nodkontakt namn';
                $headers[] = 'Nodkontakt telefon';
            }
            $headers[] = 'Status';
            $headers[] = 'Betalning';
            fputcsv($fp, $headers, ';');

            foreach ($registrations as $reg) {
                $statusMap = ['pending' => 'Vantande', 'confirmed' => 'Bekraftad', 'cancelled' => 'Avbruten'];
                $paymentMap = ['paid' => 'Betald', 'pending' => 'Vantande'];
                $row = [
                    $reg['bib_number'] ?? '',
                    $reg['category'] ?? '',
                    $reg['last_name'] ?? '',
                    $reg['first_name'] ?? '',
                    $reg['rider_birth_year'] ?? $reg['birth_year'] ?? '',
                    $reg['rider_gender'] ?? $reg['gender'] ?? '',
                    $reg['club_name'] ?? $reg['club'] ?? '',
                    $reg['nationality'] ?? '',
                    $reg['license_number'] ?? '',
                    $reg['email'] ?? '',
                    $reg['phone'] ?? '',
                ];
                if ($hasIceFields) {
                    $row[] = $reg['ice_name'] ?? '';
                    $row[] = $reg['ice_phone'] ?? '';
                }
                $row[] = $statusMap[$reg['status'] ?? ''] ?? ($reg['status'] ?? '');
                $row[] = $paymentMap[$reg['order_payment_status'] ?? ''] ?? ($reg['order_payment_status'] ?? '');
                fputcsv($fp, $row, ';');
            }

            fclose($fp);
            exit;
        }
    }
}

// Build export URL
$exportParams = ['event_id' => $eventId, 'export' => 'csv'];
if ($filterClass ?? '') $exportParams['class'] = $filterClass;
if (($filterStatus ?? '') && $filterStatus !== 'active') $exportParams['status'] = $filterStatus;
if ($search ?? '') $exportParams['search'] = $search;
$exportUrl = '/admin/event-startlist.php?' . http_build_query($exportParams);

// ─── Page config ───
$page_title = 'Startlista';
$current_admin_page = 'events';
$page_actions = '';
if ($event) {
    $page_actions = '<a href="' . htmlspecialchars($exportUrl) . '" class="btn btn-secondary btn--sm"><i data-lucide="download"></i> Exportera CSV</a>';
}

include __DIR__ . '/components/unified-layout.php';
?>

<?php if (!empty($message)): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : 'warning' ?>">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<!-- Event Selector -->
<div class="sl-event-selector">
    <form method="GET" class="sl-event-form">
        <div class="sl-event-select-wrap">
            <i data-lucide="calendar" class="sl-event-icon"></i>
            <select name="event_id" class="sl-event-dropdown" onchange="this.form.submit()">
                <option value="">-- Valj event --</option>
                <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>"
                    <?= $eventId == $ev['id'] ? 'selected' : '' ?>
                    <?= strtotime($ev['date']) < strtotime('today') ? 'class="sl-past"' : '' ?>>
                    <?= date('j M', strtotime($ev['date'])) ?> - <?= h($ev['name']) ?>
                    (<?= (int)$ev['reg_count'] ?>)
                    <?php if ($ev['series_name']): ?>[<?= h($ev['series_name']) ?>]<?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$event): ?>
<div class="sl-empty">
    <i data-lucide="clipboard-list"></i>
    <h3>Valj ett event</h3>
    <p>Valj ett event i listan ovan for att se startlistan.</p>
</div>
<?php else: ?>

<!-- Stats Bar -->
<div class="sl-stats">
    <div class="sl-stat">
        <span class="sl-stat-value"><?= (int)($stats['total'] ?? 0) ?></span>
        <span class="sl-stat-label">Anmalda</span>
    </div>
    <div class="sl-stat sl-stat--success">
        <span class="sl-stat-value"><?= (int)($stats['confirmed'] ?? 0) ?></span>
        <span class="sl-stat-label">Bekraftade</span>
    </div>
    <div class="sl-stat sl-stat--warning">
        <span class="sl-stat-value"><?= (int)($stats['pending'] ?? 0) ?></span>
        <span class="sl-stat-label">Vantande</span>
    </div>
</div>

<!-- Filters + View Toggle -->
<div class="sl-toolbar">
    <form method="GET" class="sl-filters">
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="hidden" name="view" value="<?= h($viewMode ?? 'basic') ?>">

        <?php if (!empty($classes)): ?>
        <select name="class" class="sl-filter-select" onchange="this.form.submit()">
            <option value="">Alla klasser</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?= h($c['category']) ?>" <?= ($filterClass ?? '') === $c['category'] ? 'selected' : '' ?>>
                <?= h($c['category']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select name="status" class="sl-filter-select" onchange="this.form.submit()">
            <option value="active" <?= ($filterStatus ?? 'active') === 'active' ? 'selected' : '' ?>>Aktiva</option>
            <option value="all" <?= ($filterStatus ?? '') === 'all' ? 'selected' : '' ?>>Alla</option>
            <option value="confirmed" <?= ($filterStatus ?? '') === 'confirmed' ? 'selected' : '' ?>>Bekraftade</option>
            <option value="pending" <?= ($filterStatus ?? '') === 'pending' ? 'selected' : '' ?>>Vantande</option>
        </select>

        <div class="sl-search-wrap">
            <i data-lucide="search" class="sl-search-icon"></i>
            <input type="text" name="search" value="<?= h($search ?? '') ?>"
                   placeholder="Sok namn / startnr..."
                   class="sl-search-input">
        </div>

        <button type="submit" class="btn btn-secondary btn--sm sl-search-btn">
            <i data-lucide="search"></i>
        </button>
    </form>

    <div class="sl-view-toggle">
        <a href="?event_id=<?= $eventId ?>&view=basic<?= $filterClass ? '&class=' . urlencode($filterClass) : '' ?><?= $filterStatus !== 'active' ? '&status=' . urlencode($filterStatus) : '' ?>"
           class="sl-view-btn <?= ($viewMode ?? 'basic') === 'basic' ? 'active' : '' ?>"
           title="Basisk vy">
            <i data-lucide="list"></i>
        </a>
        <a href="?event_id=<?= $eventId ?>&view=extended<?= $filterClass ? '&class=' . urlencode($filterClass) : '' ?><?= $filterStatus !== 'active' ? '&status=' . urlencode($filterStatus) : '' ?>"
           class="sl-view-btn <?= ($viewMode ?? 'basic') === 'extended' ? 'active' : '' ?>"
           title="Utokad vy">
            <i data-lucide="columns-3"></i>
        </a>
    </div>
</div>

<!-- Bib Assignment Controls -->
<?php if ($isAdmin): ?>
<div class="sl-bib-controls">
    <form method="POST" class="sl-bib-form" onsubmit="return confirm('Tilldela startnummer till alla deltagare?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_bibs">
        <label class="sl-bib-label">Starta fran:</label>
        <input type="number" name="start_number" value="1" min="1" class="sl-bib-input">
        <button type="submit" class="btn btn-primary btn--sm">
            <i data-lucide="hash"></i>
            Tilldela startnr
        </button>
    </form>
    <form method="POST" onsubmit="return confirm('Rensa alla startnummer?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_bibs">
        <button type="submit" class="btn btn-secondary btn--sm">
            <i data-lucide="eraser"></i>
            Rensa
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Startlist Table -->
<?php if (empty($registrations)): ?>
<div class="sl-empty">
    <i data-lucide="users"></i>
    <h3>Inga deltagare</h3>
    <p>Inga anmalningar matchar dina filter.</p>
</div>
<?php else: ?>

<?php
// Group by class
$grouped = [];
foreach ($registrations as $reg) {
    $cat = $reg['category'] ?? 'Okand klass';
    $grouped[$cat][] = $reg;
}
?>

<?php foreach ($grouped as $category => $classRegs): ?>
<div class="sl-class-section">
    <div class="sl-class-header">
        <h3 class="sl-class-name"><?= h($category) ?></h3>
        <span class="sl-class-count"><?= count($classRegs) ?> deltagare</span>
    </div>

    <!-- Desktop/Tablet: Table view -->
    <div class="sl-table-wrap">
        <div class="table-wrapper">
            <table class="table table--compact sl-table">
                <thead>
                    <tr>
                        <th class="sl-col-bib">Nr</th>
                        <th class="sl-col-name">Namn</th>
                        <th class="sl-col-year">Ar</th>
                        <th class="sl-col-club">Klubb</th>
                        <th class="sl-col-status">Status</th>
                        <th class="sl-col-payment">Betalning</th>
                        <?php if (($viewMode ?? 'basic') === 'extended'): ?>
                        <th class="sl-col-ext">Kon</th>
                        <th class="sl-col-ext">Nation</th>
                        <th class="sl-col-ext">UCI ID</th>
                        <th class="sl-col-ext">E-post</th>
                        <th class="sl-col-ext">Telefon</th>
                        <?php if ($hasIceFields): ?>
                        <th class="sl-col-ext sl-col-ice">Nodkontakt</th>
                        <?php endif; ?>
                        <th class="sl-col-ext">Order</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classRegs as $reg): ?>
                    <tr>
                        <td class="sl-col-bib">
                            <?php if ($isAdmin): ?>
                            <form method="POST" class="sl-bib-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_bib">
                                <input type="hidden" name="registration_id" value="<?= $reg['id'] ?>">
                                <input type="number" name="bib_number"
                                       value="<?= (int)($reg['bib_number'] ?? 0) ?: '' ?>"
                                       class="sl-bib-cell-input"
                                       onchange="this.form.submit()"
                                       min="0" placeholder="-">
                            </form>
                            <?php else: ?>
                            <span class="sl-bib-number"><?= $reg['bib_number'] ? (int)$reg['bib_number'] : '-' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="sl-col-name col-rider">
                            <strong><?= h($reg['last_name']) ?></strong>, <?= h($reg['first_name']) ?>
                        </td>
                        <td class="sl-col-year"><?= h($reg['rider_birth_year'] ?? $reg['birth_year'] ?? '-') ?></td>
                        <td class="sl-col-club col-club"><?= h($reg['club_name'] ?? $reg['club'] ?? '-') ?></td>
                        <td class="sl-col-status">
                            <?php
                            $statusBadge = match($reg['status']) {
                                'confirmed' => 'badge-success',
                                'pending' => 'badge-warning',
                                default => 'badge-secondary'
                            };
                            $statusLabel = match($reg['status']) {
                                'confirmed' => 'OK',
                                'pending' => 'Vantar',
                                'cancelled' => 'Avbruten',
                                default => $reg['status']
                            };
                            ?>
                            <span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span>
                        </td>
                        <td class="sl-col-payment">
                            <?php if (($reg['order_payment_status'] ?? '') === 'paid'): ?>
                            <span class="badge badge-success">Betald</span>
                            <?php elseif (($reg['order_payment_status'] ?? '') === 'pending'): ?>
                            <span class="badge badge-warning">Vantar</span>
                            <?php else: ?>
                            <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <?php if (($viewMode ?? 'basic') === 'extended'): ?>
                        <td class="sl-col-ext"><?= h($reg['rider_gender'] ?? $reg['gender'] ?? '-') ?></td>
                        <td class="sl-col-ext"><?= h($reg['nationality'] ?? '-') ?></td>
                        <td class="sl-col-ext">
                            <?php if (!empty($reg['license_number'])): ?>
                            <code class="text-xs"><?= h($reg['license_number']) ?></code>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="sl-col-ext"><?= h($reg['email'] ?? '-') ?></td>
                        <td class="sl-col-ext">
                            <?php if (!empty($reg['phone'])): ?>
                            <a href="tel:<?= h($reg['phone']) ?>"><?= h($reg['phone']) ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <?php if ($hasIceFields): ?>
                        <td class="sl-col-ext sl-col-ice">
                            <?php if (!empty($reg['ice_name'])): ?>
                            <?= h($reg['ice_name']) ?>
                            <?php if (!empty($reg['ice_phone'])): ?>
                            <div class="text-xs"><a href="tel:<?= h($reg['ice_phone']) ?>"><?= h($reg['ice_phone']) ?></a></div>
                            <?php endif; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="sl-col-ext">
                            <?php if (!empty($reg['order_number'])): ?>
                            <code class="text-xs"><?= h($reg['order_number']) ?></code>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Mobile Portrait: Card view -->
    <div class="sl-cards">
        <?php foreach ($classRegs as $reg): ?>
        <div class="sl-card">
            <div class="sl-card-bib"><?= $reg['bib_number'] ? (int)$reg['bib_number'] : '-' ?></div>
            <div class="sl-card-info">
                <div class="sl-card-name">
                    <strong><?= h($reg['last_name']) ?></strong>, <?= h($reg['first_name']) ?>
                </div>
                <div class="sl-card-meta">
                    <?php if (!empty($reg['club_name'] ?? $reg['club'])): ?>
                    <span><?= h($reg['club_name'] ?? $reg['club']) ?></span>
                    <?php endif; ?>
                    <span><?= h($reg['rider_birth_year'] ?? $reg['birth_year'] ?? '') ?></span>
                </div>
            </div>
            <div class="sl-card-badges">
                <?php
                $statusBadge = match($reg['status']) {
                    'confirmed' => 'badge-success',
                    'pending' => 'badge-warning',
                    default => 'badge-secondary'
                };
                ?>
                <span class="badge <?= $statusBadge ?> badge-sm"><?= $reg['status'] === 'confirmed' ? 'OK' : 'Vantar' ?></span>
                <?php if (($reg['order_payment_status'] ?? '') === 'paid'): ?>
                <span class="badge badge-success badge-sm">Betald</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php endif; // empty registrations ?>
<?php endif; // event selected ?>

<style>
/* ===== STARTLIST STYLES ===== */
/* Mobile-first: base styles are for mobile */

/* Event Selector */
.sl-event-selector {
    margin-bottom: var(--space-md);
}
.sl-event-form {
    display: flex;
}
.sl-event-select-wrap {
    position: relative;
    flex: 1;
}
.sl-event-icon {
    position: absolute;
    left: var(--space-sm);
    top: 50%;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    color: var(--color-text-muted);
    pointer-events: none;
}
.sl-event-dropdown {
    width: 100%;
    padding: var(--space-sm) var(--space-md) var(--space-sm) calc(var(--space-sm) + 26px);
    font-size: var(--text-base);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text-primary);
    min-height: 44px;
}
.sl-event-dropdown:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-light);
}
.sl-past {
    color: var(--color-text-muted);
}

/* Stats Bar */
.sl-stats {
    display: flex;
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
}
.sl-stat {
    flex: 1;
    text-align: center;
    padding: var(--space-sm);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}
.sl-stat-value {
    display: block;
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--color-text-primary);
}
.sl-stat--success .sl-stat-value { color: var(--color-success); }
.sl-stat--warning .sl-stat-value { color: var(--color-warning); }
.sl-stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* Toolbar */
.sl-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
    align-items: center;
}
.sl-filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
    flex: 1;
    align-items: center;
}
.sl-filter-select {
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    min-height: 38px;
}
.sl-search-wrap {
    position: relative;
    flex: 1;
    min-width: 140px;
}
.sl-search-icon {
    position: absolute;
    left: var(--space-xs);
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: var(--color-text-muted);
    pointer-events: none;
}
.sl-search-input {
    width: 100%;
    padding: var(--space-xs) var(--space-sm) var(--space-xs) calc(var(--space-xs) + 22px);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    min-height: 38px;
}
.sl-search-input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 2px var(--color-accent-light);
}
.sl-search-btn {
    min-height: 38px;
}

/* View Toggle */
.sl-view-toggle {
    display: flex;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
.sl-view-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    background: var(--color-bg-surface);
    color: var(--color-text-secondary);
    text-decoration: none;
    transition: all 0.15s ease;
}
.sl-view-btn + .sl-view-btn {
    border-left: 1px solid var(--color-border);
}
.sl-view-btn.active {
    background: var(--color-accent);
    color: white;
}
.sl-view-btn i {
    width: 18px;
    height: 18px;
}

/* Bib Controls */
.sl-bib-controls {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    align-items: center;
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
}
.sl-bib-form {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.sl-bib-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    white-space: nowrap;
}
.sl-bib-input {
    width: 64px;
    padding: var(--space-xs);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    text-align: center;
    font-size: var(--text-sm);
    min-height: 36px;
}

/* Class Sections */
.sl-class-section {
    margin-bottom: var(--space-lg);
}
.sl-class-header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken);
    border: 1px solid var(--color-border);
    border-bottom: none;
    border-radius: var(--radius-md) var(--radius-md) 0 0;
}
.sl-class-name {
    margin: 0;
    font-size: var(--text-base);
    font-weight: 600;
}
.sl-class-count {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

/* Table Container */
.sl-table-wrap {
    border: 1px solid var(--color-border);
    border-radius: 0 0 var(--radius-md) var(--radius-md);
    overflow: hidden;
}
.sl-table-wrap .table-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.sl-table {
    margin: 0;
}
.sl-table th {
    position: sticky;
    top: 0;
    z-index: 1;
}

/* Table Columns */
.sl-col-bib {
    width: 56px;
    min-width: 56px;
    text-align: center;
    font-weight: 600;
}
.sl-col-year {
    width: 50px;
    text-align: center;
    color: var(--color-text-secondary);
}
.sl-col-status, .sl-col-payment {
    width: 80px;
    text-align: center;
}
.sl-col-ext {
    white-space: nowrap;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.sl-col-ice {
    min-width: 150px;
}

/* Bib inline edit */
.sl-bib-inline {
    display: inline;
}
.sl-bib-cell-input {
    width: 48px;
    padding: 2px 4px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    text-align: center;
    font-weight: 600;
    font-size: var(--text-sm);
    background: transparent;
    color: var(--color-text-primary);
}
.sl-bib-cell-input:hover {
    border-color: var(--color-border);
    background: var(--color-bg-surface);
}
.sl-bib-cell-input:focus {
    outline: none;
    border-color: var(--color-accent);
    background: var(--color-bg-surface);
    box-shadow: 0 0 0 2px var(--color-accent-light);
}
.sl-bib-number {
    font-weight: 600;
}

/* Mobile Card View - hidden by default on desktop */
.sl-cards {
    display: none;
}
.sl-card {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    border-bottom: 1px solid var(--color-border);
    background: var(--color-bg-surface);
}
.sl-card-bib {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-sunken);
    border-radius: var(--radius-sm);
    font-weight: 700;
    font-size: var(--text-sm);
    flex-shrink: 0;
}
.sl-card-info {
    flex: 1;
    min-width: 0;
}
.sl-card-name {
    font-size: var(--text-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sl-card-meta {
    display: flex;
    gap: var(--space-sm);
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}
.sl-card-badges {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex-shrink: 0;
}
.badge-sm {
    font-size: 10px;
    padding: 1px 6px;
}

/* Empty State */
.sl-empty {
    text-align: center;
    padding: var(--space-2xl) var(--space-lg);
    color: var(--color-text-secondary);
}
.sl-empty i {
    width: 48px;
    height: 48px;
    margin-bottom: var(--space-md);
    opacity: 0.4;
}
.sl-empty h3 {
    margin: 0 0 var(--space-xs) 0;
    color: var(--color-text-primary);
}
.sl-empty p {
    margin: 0;
}

/* ===== MOBILE BREAKPOINTS ===== */

/* Tablet portrait */
@media (max-width: 767px) {
    .sl-stats {
        gap: var(--space-xs);
    }
    .sl-stat {
        padding: var(--space-xs);
    }
    .sl-stat-value {
        font-size: var(--text-lg);
    }
    .sl-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .sl-filters {
        flex-wrap: wrap;
    }
    .sl-filter-select {
        flex: 1;
        min-width: 0;
    }
    .sl-view-toggle {
        align-self: flex-end;
    }
    .sl-bib-controls {
        flex-direction: column;
        align-items: stretch;
    }
    .sl-bib-form {
        flex-wrap: wrap;
    }

    /* Edge-to-edge for class sections */
    .sl-class-section {
        margin-left: calc(-1 * var(--container-padding, 16px));
        margin-right: calc(-1 * var(--container-padding, 16px));
        width: auto;
        margin-bottom: var(--space-md);
    }
    .sl-class-header {
        border-radius: 0;
        border-left: none;
        border-right: none;
        padding-left: var(--container-padding, 16px);
        padding-right: var(--container-padding, 16px);
    }
    .sl-table-wrap {
        border-radius: 0;
        border-left: none;
        border-right: none;
    }
    .sl-event-dropdown {
        font-size: 16px; /* Prevent iOS zoom */
    }
    .sl-search-input,
    .sl-filter-select {
        font-size: 16px; /* Prevent iOS zoom */
    }
}

/* Mobile portrait: switch to card view */
@media (max-width: 599px) and (orientation: portrait) {
    .sl-table-wrap {
        display: none;
    }
    .sl-cards {
        display: block;
        border: 1px solid var(--color-border);
        border-top: none;
    }
    .sl-class-section {
        margin-bottom: 0;
    }
    .sl-class-header {
        border-bottom: 1px solid var(--color-border);
        position: sticky;
        top: 0;
        z-index: 2;
    }
    .sl-card {
        padding-left: var(--container-padding, 16px);
        padding-right: var(--container-padding, 16px);
    }
    /* Hide search button on mobile - search on enter */
    .sl-search-btn {
        display: none;
    }
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
