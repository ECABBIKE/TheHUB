<?php
/**
 * Organizer App - Deltagarlista
 * Visa alla registrerade deltagare för ett event
 */

require_once __DIR__ . '/config.php';
requireOrganizer();

// Hämta event
$eventId = (int)($_GET['event'] ?? 0);
if (!$eventId) {
    header('Location: dashboard.php');
    exit;
}

requireEventAccess($eventId);

$event = getEventWithClasses($eventId);
if (!$event) {
    die('Eventet hittades inte.');
}

// Filter
$filterSource = $_GET['source'] ?? 'all'; // all, onsite, online
$filterStatus = $_GET['status'] ?? 'all'; // all, paid, unpaid
$filterClass = $_GET['class'] ?? '';

// Hämta registreringar
global $pdo;

// Kolla om registration_source-kolumnen finns
$hasSourceColumn = false;
try {
    $check = $pdo->query("SHOW COLUMNS FROM event_registrations LIKE 'registration_source'");
    $hasSourceColumn = $check->rowCount() > 0;
} catch (Exception $e) {}

$sql = "
    SELECT er.*,
           r.firstname as rider_firstname, r.lastname as rider_lastname
    FROM event_registrations er
    LEFT JOIN riders r ON er.rider_id = r.id
    WHERE er.event_id = ? AND er.status != 'cancelled'
";
$params = [$eventId];

if ($hasSourceColumn) {
    if ($filterSource === 'onsite') {
        $sql .= " AND er.registration_source = 'onsite'";
    } elseif ($filterSource === 'online') {
        $sql .= " AND (er.registration_source = 'online' OR er.registration_source IS NULL)";
    }
}

if ($filterStatus === 'paid') {
    $sql .= " AND er.payment_status = 'paid'";
} elseif ($filterStatus === 'unpaid') {
    $sql .= " AND (er.payment_status != 'paid' OR er.payment_status IS NULL)";
}

if ($filterClass) {
    $sql .= " AND er.category = ?";
    $params[] = $filterClass;
}

$sql .= " ORDER BY er.bib_number ASC, er.registration_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Räkna
$counts = countEventRegistrations($eventId);

// Unika klasser för filter
$classes = array_unique(array_column($registrations, 'category'));
sort($classes);

$pageTitle = 'Deltagare';
$showHeader = true;
$headerTitle = 'Deltagare';
$headerSubtitle = $event['name'];
$showBackButton = true;
$backUrl = 'register.php?event=' . $eventId;
$showLogout = true;

include __DIR__ . '/includes/header.php';
?>

<!-- Statistik -->
<div class="org-stats">
    <div class="org-stat">
        <div class="org-stat__value"><?= (int)$counts['total'] ?></div>
        <div class="org-stat__label">Totalt</div>
    </div>
    <div class="org-stat">
        <div class="org-stat__value"><?= (int)$counts['onsite'] ?></div>
        <div class="org-stat__label">Plats</div>
    </div>
    <div class="org-stat">
        <div class="org-stat__value"><?= (int)$counts['paid'] ?></div>
        <div class="org-stat__label">Betalda</div>
    </div>
    <div class="org-stat">
        <div class="org-stat__value"><?= (int)$counts['unpaid'] ?></div>
        <div class="org-stat__label">Obetalda</div>
    </div>
</div>

<!-- Filter -->
<div class="org-card org-mb-lg">
    <div class="org-card__body">
        <form method="GET" style="display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end;">
            <input type="hidden" name="event" value="<?= $eventId ?>">

            <div class="org-form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
                <label class="org-label">Källa</label>
                <select name="source" class="org-select" onchange="this.form.submit()">
                    <option value="all" <?= $filterSource === 'all' ? 'selected' : '' ?>>Alla</option>
                    <option value="onsite" <?= $filterSource === 'onsite' ? 'selected' : '' ?>>Plats</option>
                    <option value="online" <?= $filterSource === 'online' ? 'selected' : '' ?>>Online</option>
                </select>
            </div>

            <div class="org-form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
                <label class="org-label">Betalning</label>
                <select name="status" class="org-select" onchange="this.form.submit()">
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Alla</option>
                    <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Betalda</option>
                    <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>Obetalda</option>
                </select>
            </div>

            <div class="org-form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
                <label class="org-label">Klass</label>
                <select name="class" class="org-select" onchange="this.form.submit()">
                    <option value="">Alla klasser</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= htmlspecialchars($class) ?>" <?= $filterClass === $class ? 'selected' : '' ?>>
                            <?= htmlspecialchars($class) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Lista -->
<div class="org-card">
    <?php if (empty($registrations)): ?>
        <div class="org-card__body org-text-center" style="padding: 48px;">
            <i data-lucide="users" style="width: 48px; height: 48px; color: var(--color-text); margin-bottom: 16px;"></i>
            <p class="org-text-muted">Inga deltagare matchar filtret.</p>
        </div>
    <?php else: ?>
        <div class="org-participant-list">
            <div class="org-participant-row org-participant-row--header">
                <div>#</div>
                <div>Namn</div>
                <div>Klass</div>
                <div>Status</div>
            </div>

            <?php foreach ($registrations as $reg): ?>
                <div class="org-participant-row" data-id="<?= $reg['id'] ?>">
                    <div class="org-participant__bib">
                        <?= htmlspecialchars($reg['bib_number'] ?: '-') ?>
                    </div>
                    <div>
                        <div class="org-participant__name">
                            <?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?>
                        </div>
                        <div class="org-participant__class">
                            <?= htmlspecialchars($reg['club_name'] ?: '') ?>
                            <?php if ($reg['registration_source'] === 'onsite'): ?>
                                <span style="color: var(--color-accent);">&bull; Plats</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div><?= htmlspecialchars($reg['category']) ?></div>
                    <div>
                        <?php if ($reg['payment_status'] === 'paid'): ?>
                            <span class="org-status org-status--paid">Betald</span>
                        <?php else: ?>
                            <button type="button"
                                    class="org-status org-status--unpaid btn-mark-paid"
                                    data-id="<?= $reg['id'] ?>"
                                    style="cursor: pointer; border: none;">
                                Obetald
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$pageScripts = <<<'SCRIPT'
<script>
document.querySelectorAll('.btn-mark-paid').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;

        if (!confirm('Markera som betald?')) return;

        try {
            const data = await OrgApp.api('confirm-payment.php', {
                registration_id: id
            });

            if (data.success) {
                // Uppdatera UI
                this.className = 'org-status org-status--paid';
                this.textContent = 'Betald';
                this.style.cursor = 'default';
                this.disabled = true;
            } else {
                OrgApp.showAlert(data.error || 'Kunde inte uppdatera');
            }
        } catch (err) {
            OrgApp.showAlert('Nätverksfel');
        }
    });
});
</script>
SCRIPT;

include __DIR__ . '/includes/footer.php';
?>
