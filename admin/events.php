<?php
/**
 * Admin Events - V3 Design System
 */
require_once __DIR__ . '/../config.php';

global $pdo;

// Get filter parameters
$filterSeries = isset($_GET['series_id']) && is_numeric($_GET['series_id']) ? intval($_GET['series_id']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

// Build WHERE clause
$where = [];
$params = [];

if ($filterSeries) {
    $where[] = "e.series_id = ?";
    $params[] = $filterSeries;
}

if ($filterYear) {
    $where[] = "YEAR(e.date) = ?";
    $params[] = $filterYear;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get events
$sql = "SELECT
    e.id, e.name, e.date, e.location, e.discipline, e.status,
    v.name as venue_name,
    s.name as series_name,
    s.id as series_id
FROM events e
LEFT JOIN venues v ON e.venue_id = v.id
LEFT JOIN series s ON e.series_id = s.id
{$whereClause}
ORDER BY e.date DESC
LIMIT 200";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $events = [];
    $error = $e->getMessage();
}

// Get all years from events
try {
    $allYears = $pdo->query("SELECT DISTINCT YEAR(date) as year FROM events WHERE date IS NOT NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allYears = [];
}

// Get series for filter
try {
    if ($filterYear) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.name
            FROM series s
            INNER JOIN events e ON s.id = e.series_id
            WHERE s.active = 1 AND YEAR(e.date) = ?
            ORDER BY s.name
        ");
        $stmt->execute([$filterYear]);
        $allSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $allSeries = $pdo->query("SELECT id, name FROM series WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $allSeries = [];
}

// Page config
$page_title = 'Events';
$breadcrumbs = [
    ['label' => 'Events']
];
$page_actions = '<a href="/admin/events/create" class="btn-admin btn-admin-primary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
    Nytt Event
</a>';

// Include unified layout (uses same layout as public site)
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Events Tabs -->
<div class="admin-tabs">
    <a href="/admin/events" class="admin-tab active">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
        Events
    </a>
    <a href="/admin/results.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
        Resultat
    </a>
    <a href="/admin/classes" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>
        Klasser
    </a>
    <a href="/admin/orders.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        Betalningar
    </a>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-error">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Filter Section -->
<div class="admin-card">
    <div class="admin-card-body">
        <form method="GET" action="/admin/events" class="admin-form-row">
            <!-- Year Filter -->
            <div class="admin-form-group" style="margin-bottom: 0;">
                <label for="year-filter" class="admin-form-label">År</label>
                <select id="year-filter" name="year" class="admin-form-select" onchange="this.form.submit()">
                    <option value="">Alla år</option>
                    <?php foreach ($allYears as $yearRow): ?>
                        <option value="<?= $yearRow['year'] ?>" <?= $filterYear == $yearRow['year'] ? 'selected' : '' ?>>
                            <?= $yearRow['year'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Series Filter -->
            <div class="admin-form-group" style="margin-bottom: 0;">
                <label for="series-filter" class="admin-form-label">Serie<?= $filterYear ? ' (' . $filterYear . ')' : '' ?></label>
                <select id="series-filter" name="series_id" class="admin-form-select" onchange="this.form.submit()">
                    <option value="">Alla serier</option>
                    <?php foreach ($allSeries as $series): ?>
                        <option value="<?= $series['id'] ?>" <?= $filterSeries == $series['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($series['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <!-- Active Filters Info -->
        <?php if ($filterSeries || $filterYear): ?>
            <div style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border); display: flex; align-items: center; gap: var(--space-sm); flex-wrap: wrap;">
                <span style="font-size: var(--text-sm); color: var(--color-text-secondary);">Visar:</span>
                <?php if ($filterSeries): ?>
                    <span class="admin-badge admin-badge-info">
                        <?php
                        $seriesName = array_filter($allSeries, function($s) use ($filterSeries) {
                            return $s['id'] == $filterSeries;
                        });
                        echo $seriesName ? htmlspecialchars(reset($seriesName)['name']) : 'Serie #' . $filterSeries;
                        ?>
                    </span>
                <?php endif; ?>
                <?php if ($filterYear): ?>
                    <span class="admin-badge admin-badge-warning"><?= $filterYear ?></span>
                <?php endif; ?>
                <a href="/admin/events" class="btn-admin btn-admin-sm btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 14px; height: 14px;"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    Visa alla
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Events Table -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><?= count($events) ?> events</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <?php if (empty($events)): ?>
            <div class="admin-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                <h3>Inga events hittades</h3>
                <p>Prova att ändra filtren eller skapa ett nytt event.</p>
                <a href="/admin/events/create" class="btn-admin btn-admin-primary">Skapa event</a>
            </div>
        <?php else: ?>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Namn</th>
                            <th>Serie</th>
                            <th>Plats</th>
                            <th>Format</th>
                            <th style="width: 100px;">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= htmlspecialchars($event['date'] ?? '-') ?></td>
                                <td>
                                    <a href="/admin/events/edit/<?= $event['id'] ?>" style="color: var(--color-accent); text-decoration: none; font-weight: 500;">
                                        <?= htmlspecialchars($event['name']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($event['series_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($event['location'] ?? '-') ?></td>
                                <td>
                                    <select class="admin-form-select" style="min-width: 120px; padding: var(--space-xs) var(--space-sm);" onchange="updateDiscipline(<?= $event['id'] ?>, this.value)">
                                        <option value="">-</option>
                                        <option value="ENDURO" <?= ($event['discipline'] ?? '') === 'ENDURO' ? 'selected' : '' ?>>Enduro</option>
                                        <option value="DH" <?= ($event['discipline'] ?? '') === 'DH' ? 'selected' : '' ?>>Downhill</option>
                                        <option value="XC" <?= ($event['discipline'] ?? '') === 'XC' ? 'selected' : '' ?>>XC</option>
                                        <option value="XCO" <?= ($event['discipline'] ?? '') === 'XCO' ? 'selected' : '' ?>>XCO</option>
                                        <option value="XCM" <?= ($event['discipline'] ?? '') === 'XCM' ? 'selected' : '' ?>>XCM</option>
                                        <option value="DUAL_SLALOM" <?= ($event['discipline'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>Dual Slalom</option>
                                        <option value="PUMPTRACK" <?= ($event['discipline'] ?? '') === 'PUMPTRACK' ? 'selected' : '' ?>>Pumptrack</option>
                                        <option value="GRAVEL" <?= ($event['discipline'] ?? '') === 'GRAVEL' ? 'selected' : '' ?>>Gravel</option>
                                        <option value="E-MTB" <?= ($event['discipline'] ?? '') === 'E-MTB' ? 'selected' : '' ?>>E-MTB</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="/admin/events/edit/<?= $event['id'] ?>" class="btn-admin btn-admin-sm btn-admin-secondary" title="Redigera">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                        </a>
                                        <button onclick="deleteEvent(<?= $event['id'] ?>, '<?= addslashes($event['name']) ?>')" class="btn-admin btn-admin-sm btn-admin-danger" title="Ta bort">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Store CSRF token from PHP session
const csrfToken = '<?= htmlspecialchars(generate_csrf_token()) ?>';

function deleteEvent(id, name) {
    if (!confirm('Är du säker på att du vill ta bort eventet "' + name + '"?')) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/event-delete.php';
    form.innerHTML = '<input type="hidden" name="id" value="' + id + '">' +
                     '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
    document.body.appendChild(form);
    form.submit();
}

async function updateDiscipline(eventId, discipline) {
    try {
        const response = await fetch('/admin/api/update-event-discipline.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                event_id: eventId,
                discipline: discipline,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert('Fel: ' + (result.error || 'Kunde inte uppdatera'));
        } else {
            showToast('Format uppdaterat', 'success');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid uppdatering av tävlingsformat');
    }
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
