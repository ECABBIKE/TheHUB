<?php
/**
 * Create Events - Multi-row event creation tool
 * TheHUB V3 - Quick event creation with up to 10 events at once
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

$message = '';
$messageType = 'info';
$createdCount = 0;

// Get all active venues
$venues = $db->getAll("SELECT id, name, city FROM venues WHERE active = 1 ORDER BY name");

// Get all active series (current year and next year)
$currentYear = date('Y');
$series = $db->getAll("
    SELECT id, name, year
    FROM series
    WHERE active = 1 AND year >= ? - 1
    ORDER BY year DESC, name
", [$currentYear]);

// Get all active clubs with RF registration (prioritize RF registered)
$clubs = $db->getAll("
    SELECT id, name, city, rf_registered
    FROM clubs
    WHERE active = 1
    ORDER BY rf_registered DESC, name
");

// Define disciplines
$disciplines = [
    'ENDURO' => 'Enduro',
    'DH' => 'Downhill',
    'XC' => 'XC',
    'XCO' => 'XCO',
    'XCC' => 'XCC',
    'XCE' => 'XCE',
    'DUAL_SLALOM' => 'Dual Slalom',
    'PUMPTRACK' => 'Pumptrack'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    // Create new venue
    if ($action === 'create_venue') {
        $venueName = trim($_POST['venue_name'] ?? '');
        $venueCity = trim($_POST['venue_city'] ?? '');

        if (empty($venueName)) {
            $message = 'Ange ett namn för banan';
            $messageType = 'error';
        } else {
            try {
                $db->insert('venues', [
                    'name' => $venueName,
                    'city' => $venueCity ?: null,
                    'active' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $newVenueId = $db->lastInsertId();
                $message = "Bana \"{$venueName}\" skapad med ID {$newVenueId}";
                $messageType = 'success';

                // Refresh venues list
                $venues = $db->getAll("SELECT id, name, city FROM venues WHERE active = 1 ORDER BY name");
            } catch (Exception $e) {
                $message = 'Fel vid skapande av bana: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }

    // Create events
    if ($action === 'create_events') {
        $events = $_POST['events'] ?? [];
        $errors = [];
        $createdCount = 0;

        foreach ($events as $index => $event) {
            $name = trim($event['name'] ?? '');
            $date = trim($event['date'] ?? '');

            // Skip empty rows
            if (empty($name) && empty($date)) {
                continue;
            }

            // Validate required fields
            if (empty($name)) {
                $errors[] = "Rad " . ($index + 1) . ": Namn saknas";
                continue;
            }

            if (empty($date)) {
                $errors[] = "Rad " . ($index + 1) . ": Datum saknas";
                continue;
            }

            // Parse date
            $parsedDate = strtotime($date);
            if ($parsedDate === false) {
                $errors[] = "Rad " . ($index + 1) . ": Ogiltigt datumformat";
                continue;
            }

            $venueId = !empty($event['venue_id']) ? intval($event['venue_id']) : null;
            $seriesId = !empty($event['series_id']) ? intval($event['series_id']) : null;
            $discipline = !empty($event['discipline']) ? $event['discipline'] : null;
            $organizerId = !empty($event['organizer_id']) ? intval($event['organizer_id']) : null;
            $active = isset($event['active']) && $event['active'] === '1' ? 1 : 0;

            // Generate advent_id
            $adventId = 'EVT-' . date('Ymd', $parsedDate) . '-' . substr(md5($name . $date . microtime()), 0, 6);

            // Check for duplicate (same name and date)
            $existing = $db->getRow(
                "SELECT id FROM events WHERE name = ? AND date = ?",
                [$name, date('Y-m-d', $parsedDate)]
            );

            if ($existing) {
                $errors[] = "Rad " . ($index + 1) . ": Event \"{$name}\" på detta datum finns redan";
                continue;
            }

            // Get venue name for location
            $location = null;
            if ($venueId) {
                $venueRow = $db->getRow("SELECT name, city FROM venues WHERE id = ?", [$venueId]);
                if ($venueRow) {
                    $location = $venueRow['city'] ?: $venueRow['name'];
                }
            }

            try {
                $db->insert('events', [
                    'name' => $name,
                    'advent_id' => $adventId,
                    'date' => date('Y-m-d', $parsedDate),
                    'location' => $location,
                    'venue_id' => $venueId,
                    'series_id' => $seriesId,
                    'discipline' => $discipline,
                    'organizer_club_id' => $organizerId,
                    'active' => $active,
                    'status' => 'upcoming',
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $eventId = $db->lastInsertId();

                // If series_id is set, also add to series_events junction table
                if ($seriesId) {
                    try {
                        $db->insert('series_events', [
                            'series_id' => $seriesId,
                            'event_id' => $eventId,
                            'sort_order' => 0
                        ]);
                    } catch (Exception $e) {
                        // Ignore if already exists or table doesn't exist
                    }
                }

                $createdCount++;
            } catch (Exception $e) {
                $errors[] = "Rad " . ($index + 1) . ": Fel vid skapande - " . $e->getMessage();
            }
        }

        if ($createdCount > 0) {
            $message = "{$createdCount} event skapades!";
            $messageType = 'success';
        }

        if (!empty($errors)) {
            if ($createdCount > 0) {
                $message .= '<br><br>';
            }
            $message .= 'Fel:<br>' . implode('<br>', $errors);
            $messageType = $createdCount > 0 ? 'warning' : 'error';
        }

        if ($createdCount === 0 && empty($errors)) {
            $message = 'Inga events att skapa. Fyll i minst en rad.';
            $messageType = 'warning';
        }
    }
}

// Page config
$page_title = 'Skapa Events';
$breadcrumbs = [
    ['label' => 'Events', 'url' => '/admin/events.php'],
    ['label' => 'Skapa Events']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.event-form-table {
    width: 100%;
    border-collapse: collapse;
}
.event-form-table th {
    text-align: left;
    padding: var(--space-sm);
    background: var(--color-bg-hover);
    font-weight: 600;
    font-size: var(--text-sm);
    white-space: nowrap;
}
.event-form-table td {
    padding: var(--space-xs);
    vertical-align: top;
}
.event-form-table tr:nth-child(even) {
    background: var(--color-bg-hover);
}
.event-form-table input[type="text"],
.event-form-table input[type="date"],
.event-form-table select {
    width: 100%;
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    font-size: var(--text-sm);
}
.event-form-table input[type="text"]:focus,
.event-form-table input[type="date"]:focus,
.event-form-table select:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 2px var(--color-accent-light);
}
.row-number {
    width: 40px;
    text-align: center;
    color: var(--color-text-muted);
    font-weight: 500;
}
.col-name { min-width: 180px; }
.col-date { width: 140px; }
.col-venue { min-width: 150px; }
.col-series { min-width: 150px; }
.col-discipline { width: 130px; }
.col-organizer { min-width: 180px; }
.col-active { width: 80px; text-align: center; }

.active-toggle {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100%;
}
.active-toggle input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.venue-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}
.venue-modal.active {
    display: flex;
}
.venue-modal-content {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    max-width: 400px;
    width: 90%;
}
.venue-modal-content h3 {
    margin: 0 0 var(--space-md);
}
.form-group {
    margin-bottom: var(--space-md);
}
.form-group label {
    display: block;
    margin-bottom: var(--space-xs);
    font-weight: 500;
}
.form-group input {
    width: 100%;
    padding: var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
}

@media (max-width: 1200px) {
    .table-responsive {
        overflow-x: auto;
    }
    .event-form-table {
        min-width: 1000px;
    }
}
</style>

<!-- Messages -->
<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-md">
    <?= $message ?>
</div>
<?php endif; ?>

<!-- Quick Stats -->
<div class="grid grid-cols-1 gs-md-grid-cols-3 gap-md mb-lg">
    <div class="card">
        <div class="card-body text-center">
            <div class="text-3xl font-bold text-accent"><?= count($venues) ?></div>
            <div class="text-sm text-secondary">Banor</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body text-center">
            <div class="text-3xl font-bold text-accent"><?= count($series) ?></div>
            <div class="text-sm text-secondary">Serier</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body text-center">
            <div class="text-3xl font-bold text-accent"><?= count($clubs) ?></div>
            <div class="text-sm text-secondary">Klubbar</div>
        </div>
    </div>
</div>

<!-- Main Form -->
<div class="card">
    <div class="card-header flex justify-between items-center">
        <h2>Lägg till events</h2>
        <button type="button" class="btn btn--secondary btn--sm" onclick="openVenueModal()">
            <i data-lucide="plus"></i>
            Ny bana
        </button>
    </div>
    <div class="card-body gs-p-0">
        <form method="POST" id="events-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_events">

            <div class="table-responsive">
                <table class="event-form-table">
                    <thead>
                        <tr>
                            <th class="row-number">#</th>
                            <th class="col-name">Namn *</th>
                            <th class="col-date">Datum *</th>
                            <th class="col-venue">Bana</th>
                            <th class="col-series">Serie</th>
                            <th class="col-discipline">Disciplin</th>
                            <th class="col-organizer">Arrangör</th>
                            <th class="col-active">Aktiv</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < 10; $i++): ?>
                        <tr>
                            <td class="row-number"><?= $i + 1 ?></td>
                            <td class="col-name">
                                <input type="text" name="events[<?= $i ?>][name]" placeholder="Eventnamn">
                            </td>
                            <td class="col-date">
                                <input type="date" name="events[<?= $i ?>][date]">
                            </td>
                            <td class="col-venue">
                                <select name="events[<?= $i ?>][venue_id]">
                                    <option value="">-- Välj bana --</option>
                                    <?php foreach ($venues as $venue): ?>
                                    <option value="<?= $venue['id'] ?>">
                                        <?= htmlspecialchars($venue['name']) ?>
                                        <?php if ($venue['city']): ?>
                                        (<?= htmlspecialchars($venue['city']) ?>)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="col-series">
                                <select name="events[<?= $i ?>][series_id]">
                                    <option value="">-- Välj serie --</option>
                                    <?php
                                    $currentSeriesYear = null;
                                    foreach ($series as $s):
                                        if ($s['year'] !== $currentSeriesYear) {
                                            if ($currentSeriesYear !== null) {
                                                echo '</optgroup>';
                                            }
                                            echo '<optgroup label="' . $s['year'] . '">';
                                            $currentSeriesYear = $s['year'];
                                        }
                                    ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($currentSeriesYear !== null): ?>
                                    </optgroup>
                                    <?php endif; ?>
                                </select>
                            </td>
                            <td class="col-discipline">
                                <select name="events[<?= $i ?>][discipline]">
                                    <option value="">-- Välj --</option>
                                    <?php foreach ($disciplines as $key => $label): ?>
                                    <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="col-organizer">
                                <select name="events[<?= $i ?>][organizer_id]" class="organizer-select">
                                    <option value="">-- Välj klubb --</option>
                                    <?php foreach ($clubs as $club): ?>
                                    <option value="<?= $club['id'] ?>">
                                        <?= htmlspecialchars($club['name']) ?>
                                        <?php if (!empty($club['rf_registered'])): ?> (RF)<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="col-active">
                                <div class="active-toggle">
                                    <input type="hidden" name="events[<?= $i ?>][active]" value="0">
                                    <input type="checkbox" name="events[<?= $i ?>][active]" value="1" checked>
                                </div>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer flex justify-between items-center">
                <div class="text-sm text-secondary">
                    <i data-lucide="info" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                    Fyll i namn och datum för att skapa event. Övriga fält är valfria.
                </div>
                <button type="submit" class="btn btn--primary">
                    <i data-lucide="save"></i>
                    Skapa events
                </button>
            </div>
        </form>
    </div>
</div>

<!-- New Venue Modal -->
<div class="venue-modal" id="venue-modal">
    <div class="venue-modal-content">
        <h3>Skapa ny bana</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_venue">

            <div class="form-group">
                <label for="venue_name">Namn *</label>
                <input type="text" id="venue_name" name="venue_name" required placeholder="t.ex. Hammarbybacken">
            </div>

            <div class="form-group">
                <label for="venue_city">Stad</label>
                <input type="text" id="venue_city" name="venue_city" placeholder="t.ex. Stockholm">
            </div>

            <div class="flex gap-sm">
                <button type="button" class="btn btn--secondary" onclick="closeVenueModal()">Avbryt</button>
                <button type="submit" class="btn btn--primary">Skapa bana</button>
            </div>
        </form>
    </div>
</div>

<script>
function openVenueModal() {
    document.getElementById('venue-modal').classList.add('active');
}

function closeVenueModal() {
    document.getElementById('venue-modal').classList.remove('active');
}

// Close modal on outside click
document.getElementById('venue-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeVenueModal();
    }
});

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeVenueModal();
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
