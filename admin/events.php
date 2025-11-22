<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

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
    $events = $db->getAll($sql, $params);
} catch (Exception $e) {
    $events = [];
    $error = $e->getMessage();
}

// Get all years from events
$allYears = $db->getAll("SELECT DISTINCT YEAR(date) as year FROM events WHERE date IS NOT NULL ORDER BY year DESC");

// Get series for filter buttons - only series with events in the selected year
if ($filterYear) {
    $allSeries = $db->getAll("
        SELECT DISTINCT s.id, s.name
        FROM series s
        INNER JOIN events e ON s.id = e.series_id
        WHERE s.active = 1 AND YEAR(e.date) = ?
        ORDER BY s.name
    ", [$filterYear]);
} else {
    $allSeries = $db->getAll("SELECT id, name FROM series WHERE active = 1 ORDER BY name");
}

$pageTitle = 'Events';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="calendar"></i>
                Events (<?= count($events) ?>)
            </h1>
            <a href="/admin/event-create.php" class="gs-btn gs-btn-primary">
                <i data-lucide="plus"></i>
                Nytt Event
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="gs-alert gs-alert-danger gs-mb-lg">
                <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <form method="GET" class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                    <!-- Year Filter -->
                    <div>
                        <label for="year-filter" class="gs-label">
                            <i data-lucide="calendar"></i>
                            År
                        </label>
                        <select id="year-filter" name="year" class="gs-input" onchange="this.form.submit()">
                            <option value="">Alla år</option>
                            <?php foreach ($allYears as $yearRow): ?>
                                <option value="<?= $yearRow['year'] ?>" <?= $filterYear == $yearRow['year'] ? 'selected' : '' ?>>
                                    <?= $yearRow['year'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Series Filter -->
                    <div>
                        <label for="series-filter" class="gs-label">
                            <i data-lucide="trophy"></i>
                            Serie<?= $filterYear ? ' (' . $filterYear . ')' : '' ?>
                        </label>
                        <select id="series-filter" name="series_id" class="gs-input" onchange="this.form.submit()">
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
                    <div class="gs-mt-md gs-section-divider">
                        <div class="gs-flex gs-items-center gs-gap-sm gs-flex-wrap">
                            <span class="gs-text-sm gs-text-secondary">Visar:</span>
                            <?php if ($filterSeries): ?>
                                <span class="gs-badge gs-badge-primary">
                                    <?php
                                    $seriesName = array_filter($allSeries, function($s) use ($filterSeries) {
                                        return $s['id'] == $filterSeries;
                                    });
                                    echo $seriesName ? htmlspecialchars(reset($seriesName)['name']) : 'Serie #' . $filterSeries;
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($filterYear): ?>
                                <span class="gs-badge gs-badge-accent"><?= $filterYear ?></span>
                            <?php endif; ?>
                            <a href="/admin/events.php" class="gs-btn gs-btn-sm gs-btn-outline">
                                <i data-lucide="x"></i>
                                Visa alla
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="gs-card">
            <div class="gs-card-content">
                <?php if (empty($events)): ?>
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga events hittades.</p>
                    </div>
                <?php else: ?>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Namn</th>
                                    <th>Serie</th>
                                    <th>Plats</th>
                                    <th>Tävlingsformat</th>
                                    <th class="gs-table-col-actions">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($event['date'] ?? '-') ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($event['name']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($event['series_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($event['location'] ?? '-') ?></td>
                                        <td>
                                            <select class="gs-input gs-input-sm" style="min-width: 120px;" onchange="updateDiscipline(<?= $event['id'] ?>, this.value)">
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
                                            <div class="gs-flex gs-gap-sm">
                                                <a href="/admin/event-edit.php?id=<?= $event['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Redigera">
                                                    <i data-lucide="edit" class="gs-icon-14"></i>
                                                </a>
                                                <button onclick="deleteEvent(<?= $event['id'] ?>, '<?= addslashes($event['name']) ?>')" class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger" title="Ta bort">
                                                    <i data-lucide="trash-2" class="gs-icon-14"></i>
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
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();

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
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Fel vid uppdatering av tävlingsformat');
        }
    }
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
