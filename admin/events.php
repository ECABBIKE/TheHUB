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
                <!-- Year Filter (First) -->
                <div class="gs-mb-md">
                    <label class="gs-label gs-mb-sm">
                        <i data-lucide="calendar"></i>
                        Filtrera på år:
                    </label>
                    <div class="gs-flex gs-gap-sm gs-flex-wrap">
                        <a href="/admin/events.php"
                           class="gs-btn gs-btn-sm <?= !$filterYear ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                            Alla år
                        </a>
                        <?php foreach ($allYears as $yearRow): ?>
                            <a href="/admin/events.php?year=<?= $yearRow['year'] ?>"
                               class="gs-btn gs-btn-sm <?= $filterYear == $yearRow['year'] ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                                <?= $yearRow['year'] ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Serie Filter (Second - only visible if year is selected or showing all) -->
                <?php if ($filterYear || !empty($allSeries)): ?>
                    <div>
                        <label class="gs-label gs-mb-sm">
                            <i data-lucide="trophy"></i>
                            Filtrera på serie<?= $filterYear ? ' (' . $filterYear . ')' : '' ?>:
                        </label>
                        <div class="gs-flex gs-gap-sm gs-flex-wrap">
                            <a href="/admin/events.php<?= $filterYear ? '?year=' . $filterYear : '' ?>"
                               class="gs-btn gs-btn-sm <?= !$filterSeries ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                                Alla serier
                            </a>
                            <?php foreach ($allSeries as $series): ?>
                                <a href="/admin/events.php?series_id=<?= $series['id'] ?><?= $filterYear ? '&year=' . $filterYear : '' ?>"
                                   class="gs-btn gs-btn-sm <?= $filterSeries == $series['id'] ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                                    <?= htmlspecialchars($series['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Active Filters Info -->
                <?php if ($filterSeries || $filterYear): ?>
                    <div class="gs-mt-md" style="padding-top: var(--gs-space-md); border-top: 1px solid var(--gs-border);">
                        <div class="gs-flex gs-items-center gs-gap-sm">
                            <span class="gs-text-sm gs-text-secondary">Aktiva filter:</span>
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
                            <a href="/admin/events.php" class="gs-btn gs-btn-sm gs-btn-outline gs-text-xs">
                                <i data-lucide="x"></i>
                                Rensa alla filter
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
                    <div style="overflow-x: auto;">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Namn</th>
                                    <th>Serie</th>
                                    <th>Plats</th>
                                    <th>Venue</th>
                                    <th>Disciplin</th>
                                    <th>Status</th>
                                    <th style="width: 120px;">Åtgärder</th>
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
                                        <td><?= htmlspecialchars($event['venue_name'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($event['discipline']): ?>
                                                <span class="gs-badge"><?= htmlspecialchars($event['discipline']) ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($event['status'] === 'published'): ?>
                                                <span class="gs-badge gs-badge-success">Publicerad</span>
                                            <?php elseif ($event['status'] === 'draft'): ?>
                                                <span class="gs-badge gs-badge-warning">Utkast</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary">Okänd</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="gs-flex gs-gap-sm">
                                                <a href="/admin/event-edit.php?id=<?= $event['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Redigera">
                                                    <i data-lucide="edit" style="width: 14px;"></i>
                                                </a>
                                                <button onclick="deleteEvent(<?= $event['id'] ?>, '<?= addslashes($event['name']) ?>')" class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger" title="Ta bort">
                                                    <i data-lucide="trash-2" style="width: 14px;"></i>
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
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
