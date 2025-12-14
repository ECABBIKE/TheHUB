<?php
/**
 * Fix UCI-ID Format Tool
 * Scans database for incorrectly formatted UCI IDs and fixes them
 * Correct format: XXX XXX XXX XX (11 digits with spaces)
 */
require_once __DIR__ . '/../config.php';
require_admin();

require_once INCLUDES_PATH . '/helpers.php';

$db = getDB();
$message = '';
$messageType = '';

// Find all riders with UCI IDs that need fixing
function findMalformedUciIds($db) {
    // Get all riders with uci_id set
    $riders = $db->getAll("
        SELECT id, firstname, lastname, uci_id
        FROM riders
        WHERE uci_id IS NOT NULL
          AND uci_id != ''
          AND uci_id NOT REGEXP '^[0-9]{3} [0-9]{3} [0-9]{3} [0-9]{2}$'
        ORDER BY lastname, firstname
    ");

    $malformed = [];
    foreach ($riders as $rider) {
        $original = $rider['uci_id'];
        $normalized = normalizeUciId($original);

        // Check if normalization actually changes anything
        if ($original !== $normalized) {
            // Verify it's a valid 11-digit UCI ID after normalization
            $digits = preg_replace('/[^0-9]/', '', $normalized);
            $rider['normalized'] = $normalized;
            $rider['is_valid'] = strlen($digits) === 11;
            $malformed[] = $rider;
        }
    }

    return $malformed;
}

// Handle fix action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    checkCsrf();

    if ($_POST['action'] === 'fix_all') {
        $malformed = findMalformedUciIds($db);
        $fixed = 0;
        $skipped = 0;

        foreach ($malformed as $rider) {
            if ($rider['is_valid']) {
                try {
                    $db->update('riders', [
                        'uci_id' => $rider['normalized']
                    ], 'id = ?', [$rider['id']]);
                    $fixed++;
                } catch (Exception $e) {
                    $skipped++;
                }
            } else {
                $skipped++;
            }
        }

        $message = "Klart! $fixed UCI-ID har normaliserats till korrekt format.";
        if ($skipped > 0) {
            $message .= " $skipped kunde inte fixas (ogiltigt format).";
        }
        $messageType = 'success';

    } elseif ($_POST['action'] === 'fix_single' && isset($_POST['rider_id'])) {
        $riderId = intval($_POST['rider_id']);
        $rider = $db->getRow("SELECT id, uci_id FROM riders WHERE id = ?", [$riderId]);

        if ($rider) {
            $normalized = normalizeUciId($rider['uci_id']);
            $db->update('riders', ['uci_id' => $normalized], 'id = ?', [$riderId]);
            $message = "UCI-ID för deltagare #$riderId har normaliserats.";
            $messageType = 'success';
        }
    }
}

// Get current state
$malformed = findMalformedUciIds($db);
$totalRiders = $db->getRow("SELECT COUNT(*) as cnt FROM riders WHERE uci_id IS NOT NULL AND uci_id != ''")['cnt'] ?? 0;
$validCount = $totalRiders - count($malformed);

// Page config
$page_title = 'Fixa UCI-ID Format';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools'],
    ['label' => 'Fixa UCI-ID Format']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}

.stat-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    text-align: center;
}

.stat-card.success { border-color: var(--color-success); }
.stat-card.warning { border-color: var(--color-warning); }
.stat-card.error { border-color: var(--color-error); }

.stat-value {
    font-size: 2.5rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: var(--space-xs);
}

.stat-card.success .stat-value { color: var(--color-success); }
.stat-card.warning .stat-value { color: var(--color-warning); }
.stat-card.error .stat-value { color: var(--color-error); }

.stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.fix-table {
    width: 100%;
    border-collapse: collapse;
}

.fix-table th,
.fix-table td {
    padding: var(--space-sm) var(--space-md);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.fix-table th {
    background: var(--color-bg-sunken);
    font-weight: 600;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.fix-table tr:hover {
    background: var(--color-bg-hover);
}

.uci-original {
    font-family: var(--font-mono);
    color: var(--color-error);
    text-decoration: line-through;
    opacity: 0.7;
}

.uci-arrow {
    color: var(--color-text-muted);
    padding: 0 var(--space-sm);
}

.uci-fixed {
    font-family: var(--font-mono);
    color: var(--color-success);
    font-weight: 600;
}

.uci-invalid {
    font-family: var(--font-mono);
    color: var(--color-warning);
}

.badge-invalid {
    display: inline-block;
    padding: 2px 8px;
    background: var(--color-warning-light);
    color: var(--color-warning);
    border-radius: var(--radius-sm);
    font-size: var(--text-xs);
    font-weight: 600;
}

.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-md);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}

.all-good {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--color-success);
}

.all-good svg {
    width: 64px;
    height: 64px;
    margin-bottom: var(--space-md);
}

.all-good h3 {
    margin: 0 0 var(--space-sm);
    font-size: var(--text-xl);
}

.all-good p {
    margin: 0;
    color: var(--color-text-secondary);
}
</style>

<?php if ($message): ?>
<div class="alert alert--<?= $messageType ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-row">
    <div class="stat-card success">
        <div class="stat-value"><?= number_format($validCount) ?></div>
        <div class="stat-label">Korrekt formaterade</div>
    </div>
    <div class="stat-card <?= count($malformed) > 0 ? 'warning' : 'success' ?>">
        <div class="stat-value"><?= number_format(count($malformed)) ?></div>
        <div class="stat-label">Behöver fixas</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= number_format($totalRiders) ?></div>
        <div class="stat-label">Totalt med UCI-ID</div>
    </div>
</div>

<?php if (empty($malformed)): ?>
<!-- All Good State -->
<div class="card">
    <div class="all-good">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
        <h3>Alla UCI-ID är korrekt formaterade!</h3>
        <p>Det finns inga UCI-ID som behöver fixas. Format: XXX XXX XXX XX</p>
    </div>
</div>

<?php else: ?>
<!-- Fix Actions -->
<div class="card mb-lg">
    <div class="action-bar">
        <div>
            <strong><?= count($malformed) ?> UCI-ID</strong> behöver normaliseras till format: <code>XXX XXX XXX XX</code>
        </div>
        <form method="POST" style="margin: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="fix_all">
            <button type="submit" class="btn btn--primary" onclick="return confirm('Vill du normalisera alla <?= count($malformed) ?> UCI-ID till korrekt format?')">
                <i data-lucide="wand-2"></i>
                Fixa alla (<?= count($malformed) ?>)
            </button>
        </form>
    </div>
</div>

<!-- List of malformed UCI IDs -->
<div class="card">
    <div class="card-header">
        <h2>
            <i data-lucide="list"></i>
            UCI-ID som behöver fixas
        </h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="fix-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Namn</th>
                        <th>Nuvarande UCI-ID</th>
                        <th>Normaliserat</th>
                        <th style="width: 100px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($malformed as $rider): ?>
                    <tr>
                        <td><?= $rider['id'] ?></td>
                        <td>
                            <a href="/admin/riders/edit/<?= $rider['id'] ?>">
                                <?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="uci-original"><?= h($rider['uci_id']) ?></span>
                        </td>
                        <td>
                            <?php if ($rider['is_valid']): ?>
                            <span class="uci-fixed"><?= h($rider['normalized']) ?></span>
                            <?php else: ?>
                            <span class="uci-invalid"><?= h($rider['normalized']) ?></span>
                            <span class="badge-invalid">Ogiltigt</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rider['is_valid']): ?>
                            <form method="POST" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="fix_single">
                                <input type="hidden" name="rider_id" value="<?= $rider['id'] ?>">
                                <button type="submit" class="btn btn--sm btn--secondary">
                                    Fixa
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
