<?php
/**
 * Organizer App - Deltagarlista (DEMO)
 */

require_once __DIR__ . '/config.php';
requireOrganizer();

// Hämta event
$eventId = (int)($_GET['event'] ?? 0);
if (!$eventId) {
    header('Location: dashboard.php');
    exit;
}

$event = getEventWithClasses($eventId);
if (!$event) {
    die('Eventet hittades inte.');
}

// Filter
$filterSource = $_GET['source'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterClass = $_GET['class'] ?? '';

// Demo-registreringar
$registrations = [
    ['id' => 1, 'bib_number' => '101', 'first_name' => 'Erik', 'last_name' => 'Andersson', 'category' => 'Men Elite', 'club_name' => 'Cykelklubben', 'payment_status' => 'paid', 'registration_source' => 'online'],
    ['id' => 2, 'bib_number' => '102', 'first_name' => 'Anna', 'last_name' => 'Svensson', 'category' => 'Women Elite', 'club_name' => 'MTB Klubben', 'payment_status' => 'paid', 'registration_source' => 'online'],
    ['id' => 3, 'bib_number' => '201', 'first_name' => 'Johan', 'last_name' => 'Eriksson', 'category' => 'Men Sport', 'club_name' => '', 'payment_status' => 'unpaid', 'registration_source' => 'onsite'],
    ['id' => 4, 'bib_number' => '103', 'first_name' => 'Maria', 'last_name' => 'Johansson', 'category' => 'Women Sport', 'club_name' => 'Team Gravity', 'payment_status' => 'paid', 'registration_source' => 'online'],
    ['id' => 5, 'bib_number' => '202', 'first_name' => 'Anders', 'last_name' => 'Lindberg', 'category' => 'Men Master', 'club_name' => '', 'payment_status' => 'paid', 'registration_source' => 'onsite'],
];

// Filtrering
if ($filterSource !== 'all') {
    $registrations = array_filter($registrations, fn($r) => $r['registration_source'] === $filterSource);
}
if ($filterStatus === 'paid') {
    $registrations = array_filter($registrations, fn($r) => $r['payment_status'] === 'paid');
} elseif ($filterStatus === 'unpaid') {
    $registrations = array_filter($registrations, fn($r) => $r['payment_status'] !== 'paid');
}
if ($filterClass) {
    $registrations = array_filter($registrations, fn($r) => $r['category'] === $filterClass);
}

$counts = countEventRegistrations($eventId);
$classes = ['Men Elite', 'Women Elite', 'Men Sport', 'Women Sport', 'Men Master', 'Juniors'];

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
                <div class="org-participant-row">
                    <div class="org-participant__bib">
                        <?= htmlspecialchars($reg['bib_number']) ?>
                    </div>
                    <div>
                        <div class="org-participant__name">
                            <?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?>
                        </div>
                        <div class="org-participant__club">
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
                            <span class="org-status org-status--unpaid">Obetald</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="org-card org-mt-lg">
    <div class="org-card__body org-text-center" style="padding: 24px;">
        <p class="org-text-muted" style="font-size: 14px;">
            <i data-lucide="info" style="width: 16px; height: 16px; vertical-align: middle;"></i>
            Demo-version - visar exempeldata
        </p>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
