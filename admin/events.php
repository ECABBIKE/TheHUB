<?php
/**
 * Admin Events - V3 Design System
 */
require_once __DIR__ . '/../config.php';

global $pdo;

// Get filter parameters
$filterBrand = isset($_GET['brand']) ? trim($_GET['brand']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;
$filterDiscipline = isset($_GET['discipline']) ? trim($_GET['discipline']) : null;

// Check if series_events table exists
$seriesEventsTableExists = false;
try {
    $pdo->query("SELECT 1 FROM series_events LIMIT 1");
    $seriesEventsTableExists = true;
} catch (Exception $e) {
    // Table doesn't exist
}

// Build WHERE clause
$where = [];
$params = [];

if ($filterBrand) {
    // Filter by brand (series name without year)
    // Match series names that start with the brand name
    if ($seriesEventsTableExists) {
        $where[] = "(s.name COLLATE utf8mb4_unicode_ci LIKE CONCAT(?, '%') OR e.id IN (SELECT se.event_id FROM series_events se INNER JOIN series s2 ON se.series_id = s2.id WHERE s2.name COLLATE utf8mb4_unicode_ci LIKE CONCAT(?, '%')))";
        $params[] = $filterBrand;
        $params[] = $filterBrand;
    } else {
        $where[] = "s.name COLLATE utf8mb4_unicode_ci LIKE CONCAT(?, '%')";
        $params[] = $filterBrand;
    }
}

if ($filterYear) {
    $where[] = "YEAR(e.date) = ?";
    $params[] = $filterYear;
}

if ($filterDiscipline) {
    $where[] = "e.discipline = ?";
    $params[] = $filterDiscipline;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get events - include series name from both direct link and junction table
$seriesNameSelect = $seriesEventsTableExists
    ? "COALESCE(s.name, (SELECT s2.name FROM series s2 INNER JOIN series_events se ON s2.id = se.series_id WHERE se.event_id = e.id LIMIT 1))"
    : "s.name";

$seriesIdSelect = $seriesEventsTableExists
    ? "COALESCE(e.series_id, (SELECT se.series_id FROM series_events se WHERE se.event_id = e.id LIMIT 1))"
    : "e.series_id";

$sql = "SELECT
    e.id, e.name, e.date, e.location, e.discipline, e.status,
    e.event_level, e.event_format, e.pricing_template_id, e.advent_id,
    e.organizer_club_id, e.website, e.contact_email, e.contact_phone,
    e.registration_deadline, e.registration_deadline_time,
    v.name as venue_name,
    c.name as organizer_name,
    {$seriesNameSelect} as series_name,
    {$seriesIdSelect} as series_id
FROM events e
LEFT JOIN venues v ON e.venue_id = v.id
LEFT JOIN series s ON e.series_id = s.id
LEFT JOIN clubs c ON e.organizer_club_id = c.id
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

// Define valid disciplines with display names
$disciplineMapping = [
    'ENDURO' => 'Enduro',
    'DH' => 'Downhill',
    'XC' => 'XC',
    'XCO' => 'XCO',
    'XCM' => 'XCM',
    'DUAL_SLALOM' => 'Dual Slalom',
    'PUMPTRACK' => 'Pumptrack',
    'GRAVEL' => 'Gravel',
    'E-MTB' => 'E-MTB'
];

// Get which disciplines are actually used in events
try {
    $usedDisciplines = $pdo->query("SELECT DISTINCT discipline FROM events WHERE discipline IS NOT NULL AND discipline != '' ORDER BY discipline")->fetchAll(PDO::FETCH_COLUMN);
    // Only show disciplines that exist in the database
    $allDisciplines = [];
    foreach ($usedDisciplines as $disc) {
        if (isset($disciplineMapping[$disc])) {
            $allDisciplines[$disc] = $disciplineMapping[$disc];
        }
    }
} catch (Exception $e) {
    $allDisciplines = [];
}

// Get all active series for dropdown in table (with year for filtering)
try {
    $allSeriesForDropdown = $pdo->query("SELECT id, name, year FROM series WHERE active = 1 ORDER BY year DESC, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allSeriesForDropdown = [];
}

// Get all active clubs for organizer dropdown
try {
    $allClubs = $pdo->query("SELECT id, name FROM clubs WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allClubs = [];
}

// Get all active point scales for pricing template dropdown
try {
    $allPointScales = $pdo->query("SELECT id, name FROM point_scales WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allPointScales = [];
}

// Get unique brands (series names without year) for filter
try {
    if ($filterYear) {
        if ($seriesEventsTableExists) {
            // Get brands that have events in this year
            $stmt = $pdo->prepare("
                SELECT DISTINCT s.name
                FROM series s
                WHERE s.active = 1 AND (
                    s.id IN (SELECT DISTINCT series_id FROM events WHERE series_id IS NOT NULL AND YEAR(date) = ?)
                    OR s.id IN (SELECT DISTINCT se.series_id FROM series_events se INNER JOIN events e ON se.event_id = e.id WHERE YEAR(e.date) = ?)
                )
                ORDER BY s.name
            ");
            $stmt->execute([$filterYear, $filterYear]);
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT s.name
                FROM series s
                INNER JOIN events e ON s.id = e.series_id
                WHERE s.active = 1 AND YEAR(e.date) = ?
                ORDER BY s.name
            ");
            $stmt->execute([$filterYear]);
        }
        $seriesNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $seriesNames = $pdo->query("SELECT DISTINCT name FROM series WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }

    // Extract brands (remove year suffix like " (2025)")
    $allBrands = [];
    foreach ($seriesNames as $name) {
        $brand = preg_replace('/ \(\d{4}\)$/', '', $name);
        if (!in_array($brand, $allBrands)) {
            $allBrands[] = $brand;
        }
    }
    sort($allBrands);
} catch (Exception $e) {
    $allBrands = [];
}

// Page config
$page_title = 'Events';
$breadcrumbs = [
    ['label' => 'Events']
];
$page_actions = '
<button id="bulk-edit-toggle" class="btn-admin btn-admin-secondary" onclick="toggleBulkEdit(\'event\')" style="margin-right: var(--space-sm);">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4Z"/></svg>
    <span id="bulk-edit-label">Masseditering Tävling</span>
</button>
<button id="bulk-edit-organizer-toggle" class="btn-admin btn-admin-secondary" onclick="toggleBulkEdit(\'organizer\')" style="margin-right: var(--space-sm);">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    <span id="bulk-edit-organizer-label">Masseditering Arrangör</span>
</button>
<a href="/admin/events/create" class="btn-admin btn-admin-primary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
    Nytt Event
</a>';

// Include unified layout (uses same layout as public site)
include __DIR__ . '/components/unified-layout.php';
?>

<?php if (isset($error)): ?>
    <div class="alert alert-error">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Filter Section -->
<div class="admin-card">
    <div class="admin-card-body">
        <form method="GET" action="/admin/events" class="admin-form-row" id="filter-form">
            <!-- Brand Filter -->
            <div class="admin-form-group" style="margin-bottom: 0;">
                <label for="brand-filter" class="admin-form-label">Varumärke<?= $filterYear ? ' (' . $filterYear . ')' : '' ?></label>
                <select id="brand-filter" name="brand" class="admin-form-select" onchange="this.form.submit()">
                    <option value="">Alla varumärken</option>
                    <?php foreach ($allBrands as $brand): ?>
                        <option value="<?= htmlspecialchars($brand) ?>" <?= $filterBrand === $brand ? 'selected' : '' ?>>
                            <?= htmlspecialchars($brand) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

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

            <!-- Discipline Filter -->
            <div class="admin-form-group" style="margin-bottom: 0;">
                <label for="discipline-filter" class="admin-form-label">Format</label>
                <select id="discipline-filter" name="discipline" class="admin-form-select" onchange="this.form.submit()">
                    <option value="">Alla format</option>
                    <?php foreach ($allDisciplines as $code => $displayName): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $filterDiscipline === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($displayName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <!-- Active Filters Info -->
        <?php if ($filterBrand || $filterYear || $filterDiscipline): ?>
            <div style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border); display: flex; align-items: center; gap: var(--space-sm); flex-wrap: wrap;">
                <span style="font-size: var(--text-sm); color: var(--color-text-secondary);">Visar:</span>
                <?php if ($filterBrand): ?>
                    <span class="admin-badge admin-badge-info"><?= htmlspecialchars($filterBrand) ?></span>
                <?php endif; ?>
                <?php if ($filterYear): ?>
                    <span class="admin-badge admin-badge-warning"><?= $filterYear ?></span>
                <?php endif; ?>
                <?php if ($filterDiscipline): ?>
                    <span class="admin-badge admin-badge-success"><?= htmlspecialchars($disciplineMapping[$filterDiscipline] ?? $filterDiscipline) ?></span>
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
                            <th class="event-field">Serie</th>
                            <th>Plats</th>
                            <th class="event-field">Format</th>
                            <th class="event-field">Level</th>
                            <th class="event-field">Event Format</th>
                            <th class="event-field">Prismall</th>
                            <th class="event-field">Advent ID</th>
                            <th class="organizer-field">Arrangör</th>
                            <th class="organizer-field">Webbplats</th>
                            <th class="organizer-field">Kontakt e-post</th>
                            <th class="organizer-field">Kontakt telefon</th>
                            <th class="organizer-field">Anmälningsfrist</th>
                            <th class="organizer-field">Klockslag</th>
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
                                <td class="event-field">
                                    <?php
                                    // Filter series to only show those from the same year as the event
                                    $eventYear = $event['date'] ? date('Y', strtotime($event['date'])) : null;
                                    $filteredSeries = [];
                                    foreach ($allSeriesForDropdown as $series) {
                                        if (!$eventYear || $series['year'] == $eventYear) {
                                            $filteredSeries[] = $series;
                                        }
                                    }
                                    ?>
                                    <select class="admin-form-select" style="min-width: 200px; padding: var(--space-xs) var(--space-sm); font-size: 0.875rem;" onchange="updateSeries(<?= $event['id'] ?>, this.value)">
                                        <option value="">-</option>
                                        <?php foreach ($filteredSeries as $series): ?>
                                            <option value="<?= $series['id'] ?>" <?= ($event['series_id'] ?? '') == $series['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($series['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><?= htmlspecialchars($event['location'] ?? '-') ?></td>
                                <td class="event-field">
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
                                <td class="event-field">
                                    <select class="admin-form-select" style="min-width: 130px; padding: var(--space-xs) var(--space-sm);" onchange="updateEventLevel(<?= $event['id'] ?>, this.value)">
                                        <option value="">-</option>
                                        <option value="Nationell (100%)" <?= ($event['event_level'] ?? '') === 'Nationell (100%)' ? 'selected' : '' ?>>Nationell (100%)</option>
                                        <option value="Sportmotion (50%)" <?= ($event['event_level'] ?? '') === 'Sportmotion (50%)' ? 'selected' : '' ?>>Sportmotion (50%)</option>
                                    </select>
                                </td>
                                <td class="event-field">
                                    <select class="admin-form-select" style="min-width: 150px; padding: var(--space-xs) var(--space-sm);" onchange="updateEventFormat(<?= $event['id'] ?>, this.value)">
                                        <option value="">-</option>
                                        <option value="Enduro (en tid)" <?= ($event['event_format'] ?? '') === 'Enduro (en tid)' ? 'selected' : '' ?>>Enduro (en tid)</option>
                                        <option value="Downhill Standard" <?= ($event['event_format'] ?? '') === 'Downhill Standard' ? 'selected' : '' ?>>Downhill Standard</option>
                                        <option value="SweCUP Downhill" <?= ($event['event_format'] ?? '') === 'SweCUP Downhill' ? 'selected' : '' ?>>SweCUP Downhill</option>
                                        <option value="Dual Slalom" <?= ($event['event_format'] ?? '') === 'Dual Slalom' ? 'selected' : '' ?>>Dual Slalom</option>
                                    </select>
                                </td>
                                <td class="event-field">
                                    <select class="admin-form-select" style="min-width: 150px; padding: var(--space-xs) var(--space-sm); font-size: 0.875rem;" onchange="updatePricingTemplate(<?= $event['id'] ?>, this.value)">
                                        <option value="">-</option>
                                        <?php foreach ($allPointScales as $scale): ?>
                                            <option value="<?= $scale['id'] ?>" <?= ($event['pricing_template_id'] ?? '') == $scale['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($scale['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="event-field">
                                    <input type="text" class="admin-form-input" style="min-width: 80px; padding: var(--space-xs) var(--space-sm); font-size: 0.7rem;"
                                           value="<?= htmlspecialchars($event['advent_id'] ?? '') ?>"
                                           onblur="updateAdventId(<?= $event['id'] ?>, this.value)"
                                           placeholder="-">
                                </td>
                                <td class="organizer-field">
                                    <select class="admin-form-select" style="min-width: 150px; padding: var(--space-xs) var(--space-sm); font-size: 0.875rem;" onchange="updateOrganizerClub(<?= $event['id'] ?>, this.value)">
                                        <option value="">-</option>
                                        <?php foreach ($allClubs as $club): ?>
                                            <option value="<?= $club['id'] ?>" <?= ($event['organizer_club_id'] ?? '') == $club['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($club['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="organizer-field">
                                    <input type="text" class="admin-form-input organizer-input" style="min-width: 120px; padding: var(--space-xs) var(--space-sm); font-size: 0.875rem;"
                                           value="<?= htmlspecialchars($event['website'] ?? '') ?>"
                                           onblur="updateOrganizerField(<?= $event['id'] ?>, 'website', this.value)"
                                           placeholder="https://">
                                </td>
                                <td class="organizer-field">
                                    <input type="email" class="admin-form-input organizer-input" style="min-width: 150px; padding: var(--space-xs) var(--space-sm); font-size: 0.875rem;"
                                           value="<?= htmlspecialchars($event['contact_email'] ?? '') ?>"
                                           onblur="updateOrganizerField(<?= $event['id'] ?>, 'contact_email', this.value)"
                                           placeholder="mail@example.com">
                                </td>
                                <td class="organizer-field">
                                    <input type="tel" class="admin-form-input organizer-input" style="min-width: 120px; padding: var(--space-xs) var(--space-sm); font-size: 0.875rem;"
                                           value="<?= htmlspecialchars($event['contact_phone'] ?? '') ?>"
                                           onblur="updateOrganizerField(<?= $event['id'] ?>, 'contact_phone', this.value)"
                                           placeholder="070-123 45 67">
                                </td>
                                <td class="organizer-field">
                                    <input type="date" class="admin-form-input organizer-input" style="min-width: 130px; padding: var(--space-xs) var(--space-sm); font-size: 0.875rem;"
                                           value="<?= htmlspecialchars($event['registration_deadline'] ?? '') ?>"
                                           onblur="updateOrganizerField(<?= $event['id'] ?>, 'registration_deadline', this.value)">
                                </td>
                                <td class="organizer-field">
                                    <input type="time" class="admin-form-input organizer-input" style="min-width: 100px; padding: var(--space-xs) var(--space-sm); font-size: 0.875rem;"
                                           value="<?= htmlspecialchars($event['registration_deadline_time'] ?? '23:59') ?>"
                                           onblur="updateOrganizerField(<?= $event['id'] ?>, 'registration_deadline_time', this.value)"
                                           placeholder="23:59">
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

async function updateSeries(eventId, seriesId) {
    try {
        const response = await fetch('/admin/api/update-event-series.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                event_id: eventId,
                series_id: seriesId,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert('Fel: ' + (result.error || 'Kunde inte uppdatera serie'));
        } else {
            showToast('Serie uppdaterad', 'success');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid uppdatering av serie');
    }
}

async function updateEventLevel(eventId, eventLevel) {
    try {
        const response = await fetch('/admin/api/update-event-field.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                event_id: eventId,
                field: 'event_level',
                value: eventLevel,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert('Fel: ' + (result.error || 'Kunde inte uppdatera event level'));
        } else {
            showToast('Event Level uppdaterat', 'success');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid uppdatering av event level');
    }
}

async function updateEventFormat(eventId, eventFormat) {
    try {
        const response = await fetch('/admin/api/update-event-field.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                event_id: eventId,
                field: 'event_format',
                value: eventFormat,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert('Fel: ' + (result.error || 'Kunde inte uppdatera event format'));
        } else {
            showToast('Event Format uppdaterat', 'success');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid uppdatering av event format');
    }
}

async function updatePricingTemplate(eventId, pricingTemplateId) {
    try {
        const response = await fetch('/admin/api/update-event-field.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                event_id: eventId,
                field: 'pricing_template_id',
                value: pricingTemplateId,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert('Fel: ' + (result.error || 'Kunde inte uppdatera prismall'));
        } else {
            showToast('Prismall uppdaterad', 'success');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid uppdatering av prismall');
    }
}

async function updateAdventId(eventId, adventId) {
    try {
        const response = await fetch('/admin/api/update-event-field.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                event_id: eventId,
                field: 'advent_id',
                value: adventId,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert('Fel: ' + (result.error || 'Kunde inte uppdatera Advent ID'));
        } else {
            showToast('Advent ID uppdaterat', 'success');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid uppdatering av Advent ID');
    }
}

async function updateOrganizerClub(eventId, clubId) {
    try {
        const response = await fetch('/admin/api/update-organizer-field.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                event_id: eventId,
                field: 'organizer_club_id',
                value: clubId,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert('Fel: ' + (result.error || 'Kunde inte uppdatera arrangör'));
        } else {
            showToast('Arrangör uppdaterad', 'success');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid uppdatering av arrangör');
    }
}

async function updateOrganizerField(eventId, field, value) {
    try {
        const response = await fetch('/admin/api/update-organizer-field.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                event_id: eventId,
                field: field,
                value: value,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert('Fel: ' + (result.error || 'Kunde inte uppdatera fält'));
        } else {
            showToast('Fält uppdaterat', 'success');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid uppdatering av fält');
    }
}

// Bulk Edit Mode - 'event' or 'organizer' or null
let bulkEditMode = null;
let bulkChanges = {};

// Hide editable fields by default
document.addEventListener('DOMContentLoaded', function() {
    const style = document.createElement('style');
    style.textContent = `
        .event-field,
        .organizer-field {
            display: none;
        }
        .bulk-edit-event-mode .event-field {
            display: table-cell;
        }
        .bulk-edit-organizer-mode .organizer-field {
            display: table-cell;
        }
    `;
    document.head.appendChild(style);
});

function toggleBulkEdit(mode) {
    const eventBtn = document.getElementById('bulk-edit-toggle');
    const eventLabel = document.getElementById('bulk-edit-label');
    const organizerBtn = document.getElementById('bulk-edit-organizer-toggle');
    const organizerLabel = document.getElementById('bulk-edit-organizer-label');
    const table = document.querySelector('.admin-table');

    // If clicking the same mode again, turn it off
    if (bulkEditMode === mode) {
        bulkEditMode = null;

        // Reset both buttons
        eventBtn.classList.remove('btn-admin-primary');
        eventBtn.classList.add('btn-admin-secondary');
        eventLabel.textContent = 'Masseditering Tävling';

        organizerBtn.classList.remove('btn-admin-primary');
        organizerBtn.classList.add('btn-admin-secondary');
        organizerLabel.textContent = 'Masseditering Arrangör';

        // Remove mode classes
        table.classList.remove('bulk-edit-event-mode', 'bulk-edit-organizer-mode');

        disableBulkEdit();
        hideBulkSaveButton();
        bulkChanges = {};
    } else {
        // Switch to new mode
        bulkEditMode = mode;

        if (mode === 'event') {
            // Activate event mode
            eventBtn.classList.remove('btn-admin-secondary');
            eventBtn.classList.add('btn-admin-primary');
            eventLabel.textContent = 'Avsluta massredigering';

            // Deactivate organizer mode
            organizerBtn.classList.remove('btn-admin-primary');
            organizerBtn.classList.add('btn-admin-secondary');
            organizerLabel.textContent = 'Masseditering Arrangör';

            // Show event fields
            table.classList.remove('bulk-edit-organizer-mode');
            table.classList.add('bulk-edit-event-mode');
        } else if (mode === 'organizer') {
            // Activate organizer mode
            organizerBtn.classList.remove('btn-admin-secondary');
            organizerBtn.classList.add('btn-admin-primary');
            organizerLabel.textContent = 'Avsluta massredigering';

            // Deactivate event mode
            eventBtn.classList.remove('btn-admin-primary');
            eventBtn.classList.add('btn-admin-secondary');
            eventLabel.textContent = 'Masseditering Tävling';

            // Show organizer fields
            table.classList.remove('bulk-edit-event-mode');
            table.classList.add('bulk-edit-organizer-mode');
        }

        enableBulkEdit(mode);
        showBulkSaveButton();
        bulkChanges = {};
    }
}

function enableBulkEdit(mode) {
    // Get selector for fields based on mode
    const fieldSelector = mode === 'event' ? '.event-field' : '.organizer-field';

    // Disable individual onchange/onblur handlers and add bulk edit tracking for visible fields only
    document.querySelectorAll(`${fieldSelector} select`).forEach(select => {
        select.dataset.originalOnchange = select.getAttribute('onchange');
        select.removeAttribute('onchange');
        select.addEventListener('change', trackBulkChange);
        select.style.borderColor = 'var(--color-primary)';
    });

    document.querySelectorAll(`${fieldSelector} input[type="text"], ${fieldSelector} input[type="email"], ${fieldSelector} input[type="tel"], ${fieldSelector} input[type="date"], ${fieldSelector} input[type="time"]`).forEach(input => {
        input.dataset.originalOnblur = input.getAttribute('onblur');
        input.removeAttribute('onblur');
        input.addEventListener('input', trackBulkChange);
        input.style.borderColor = 'var(--color-primary)';
    });
}

function disableBulkEdit() {
    // Re-enable individual onchange/onblur handlers for all fields
    document.querySelectorAll('.admin-table select').forEach(select => {
        if (select.dataset.originalOnchange) {
            select.setAttribute('onchange', select.dataset.originalOnchange);
            delete select.dataset.originalOnchange;
        }
        select.removeEventListener('change', trackBulkChange);
        select.style.borderColor = '';
        select.style.backgroundColor = '';
    });

    document.querySelectorAll('.admin-table input').forEach(input => {
        if (input.dataset.originalOnblur) {
            input.setAttribute('onblur', input.dataset.originalOnblur);
            delete input.dataset.originalOnblur;
        }
        input.removeEventListener('input', trackBulkChange);
        input.style.borderColor = '';
        input.style.backgroundColor = '';
    });
}

function trackBulkChange(event) {
    const element = event.target;
    const row = element.closest('tr');
    const eventId = getEventIdFromRow(row);
    const fieldType = getFieldType(element);

    if (!bulkChanges[eventId]) {
        bulkChanges[eventId] = {};
    }

    bulkChanges[eventId][fieldType] = element.value;

    // Visual feedback
    element.style.backgroundColor = '#fff3cd';

    updateBulkSaveButton();
}

function getEventIdFromRow(row) {
    // Extract event ID from the edit button or delete button
    const editBtn = row.querySelector('a[href*="/admin/events/edit/"]');
    if (editBtn) {
        const match = editBtn.href.match(/\/edit\/(\d+)/);
        if (match) return match[1];
    }
    return null;
}

function getFieldType(element) {
    // Determine field type based on element attributes
    const onchange = element.dataset.originalOnchange || element.getAttribute('onchange') || '';
    const onblur = element.dataset.originalOnblur || element.getAttribute('onblur') || '';

    // Event fields
    if (onchange.includes('updateSeries') || element.style.minWidth === '200px') {
        return 'series_id';
    } else if (onchange.includes('updateDiscipline')) {
        return 'discipline';
    } else if (onchange.includes('updateEventLevel')) {
        return 'event_level';
    } else if (onchange.includes('updateEventFormat')) {
        return 'event_format';
    } else if (onchange.includes('updatePricingTemplate')) {
        return 'pricing_template_id';
    } else if (onblur.includes('updateAdventId')) {
        return 'advent_id';
    }
    // Organizer fields
    else if (onchange.includes('updateOrganizerClub')) {
        return 'organizer_club_id';
    } else if (onblur.includes("'website'")) {
        return 'website';
    } else if (onblur.includes("'contact_email'")) {
        return 'contact_email';
    } else if (onblur.includes("'contact_phone'")) {
        return 'contact_phone';
    } else if (onblur.includes("'registration_deadline'") && element.type === 'date') {
        return 'registration_deadline';
    } else if (onblur.includes("'registration_deadline_time'") || element.type === 'time') {
        return 'registration_deadline_time';
    }
    return 'unknown';
}

function showBulkSaveButton() {
    if (!document.getElementById('bulk-save-container')) {
        const container = document.createElement('div');
        container.id = 'bulk-save-container';
        container.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 1000; background: white; padding: var(--space-md); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); display: flex; gap: var(--space-sm); align-items: center;';

        container.innerHTML = `
            <span id="bulk-change-count" style="font-weight: 500; color: var(--color-text);">0 ändringar</span>
            <button onclick="saveBulkChanges()" class="btn-admin btn-admin-primary" id="bulk-save-btn" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Spara alla ändringar
            </button>
            <button onclick="cancelBulkEdit()" class="btn-admin btn-admin-secondary">Avbryt</button>
        `;

        document.body.appendChild(container);
    }
}

function hideBulkSaveButton() {
    const container = document.getElementById('bulk-save-container');
    if (container) {
        container.remove();
    }
}

function updateBulkSaveButton() {
    const count = Object.keys(bulkChanges).reduce((total, eventId) => {
        return total + Object.keys(bulkChanges[eventId]).length;
    }, 0);

    const countEl = document.getElementById('bulk-change-count');
    const saveBtn = document.getElementById('bulk-save-btn');

    if (countEl) {
        countEl.textContent = `${count} ändring${count !== 1 ? 'ar' : ''}`;
    }

    if (saveBtn) {
        saveBtn.disabled = count === 0;
    }
}

async function saveBulkChanges() {
    const count = Object.keys(bulkChanges).reduce((total, eventId) => {
        return total + Object.keys(bulkChanges[eventId]).length;
    }, 0);

    if (count === 0) return;

    const saveBtn = document.getElementById('bulk-save-btn');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span style="opacity: 0.7;">Sparar...</span>';

    try {
        const response = await fetch('/admin/api/bulk-update-events.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                changes: bulkChanges,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast(`${count} ändring${count !== 1 ? 'ar' : ''} sparade!`, 'success');
            bulkChanges = {};
            updateBulkSaveButton();

            // Reset visual feedback
            document.querySelectorAll('.admin-table select').forEach(select => {
                select.style.backgroundColor = '';
            });

            // Optionally reload page to show updated data
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Fel: ' + (result.error || 'Kunde inte spara ändringar'));
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Fel vid sparande av ändringar');
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

function cancelBulkEdit() {
    if (Object.keys(bulkChanges).length > 0) {
        if (!confirm('Du har osparade ändringar. Vill du verkligen avbryta?')) {
            return;
        }
    }
    toggleBulkEdit();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
