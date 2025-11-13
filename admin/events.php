<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Demo mode check
$is_demo = ($db->getConnection() === null);

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_demo) {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        // Validate required fields
        $name = trim($_POST['name'] ?? '');
        $event_date = trim($_POST['event_date'] ?? '');

        if (empty($name) || empty($event_date)) {
            $message = 'Namn och datum är obligatoriska';
            $messageType = 'error';
        } else {
            // Prepare event data
            $eventData = [
                'name' => $name,
                'event_date' => $event_date,
                'location' => trim($_POST['location'] ?? ''),
                'event_type' => $_POST['event_type'] ?? 'road_race',
                'status' => $_POST['status'] ?? 'upcoming',
                'series_id' => !empty($_POST['series_id']) ? intval($_POST['series_id']) : null,
                'distance' => !empty($_POST['distance']) ? floatval($_POST['distance']) : null,
                'elevation_gain' => !empty($_POST['elevation_gain']) ? intval($_POST['elevation_gain']) : null,
                'description' => trim($_POST['description'] ?? ''),
                'organizer' => trim($_POST['organizer'] ?? ''),
                'website' => trim($_POST['website'] ?? ''),
                'registration_deadline' => !empty($_POST['registration_deadline']) ? trim($_POST['registration_deadline']) : null,
                'max_participants' => !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null,
            ];

            try {
                if ($action === 'create') {
                    $db->insert('events', $eventData);
                    $message = 'Tävling skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('events', $eventData, 'id = ?', [$id]);
                    $message = 'Tävling uppdaterad!';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        try {
            $db->delete('events', 'id = ?', [$id]);
            $message = 'Tävling borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Handle filters
$status = $_GET['status'] ?? '';
$year = $_GET['year'] ?? date('Y');
$location = $_GET['location'] ?? '';

// Fetch series for dropdown (if not in demo mode)
$series = [];
$editEvent = null;
if (!$is_demo) {
    $series = $db->getAll("SELECT id, name FROM series WHERE status = 'active' ORDER BY name");

    // Check if editing an event
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $editEvent = $db->getOne("SELECT * FROM events WHERE id = ?", [intval($_GET['edit'])]);
    }
}

if ($is_demo) {
    // Demo events
    $all_events = [
        ['id' => 1, 'name' => 'GravitySeries Järvsö XC', 'event_date' => '2025-06-15', 'location' => 'Järvsö', 'event_type' => 'XC', 'status' => 'upcoming', 'distance' => '45 km', 'participant_count' => 145],
        ['id' => 2, 'name' => 'SM Lindesberg', 'event_date' => '2025-07-01', 'location' => 'Lindesberg', 'event_type' => 'XC', 'status' => 'upcoming', 'distance' => '38 km', 'participant_count' => 220],
        ['id' => 3, 'name' => 'Cykelvasan 90', 'event_date' => '2025-08-10', 'location' => 'Mora', 'event_type' => 'Landsväg', 'status' => 'upcoming', 'distance' => '90 km', 'participant_count' => 890],
        ['id' => 4, 'name' => 'GravitySeries Åre', 'event_date' => '2024-08-20', 'location' => 'Åre', 'event_type' => 'XC', 'status' => 'completed', 'distance' => '42 km', 'participant_count' => 156],
        ['id' => 5, 'name' => 'Vätternrundan', 'event_date' => '2024-06-15', 'location' => 'Motala', 'event_type' => 'Landsväg', 'status' => 'completed', 'distance' => '300 km', 'participant_count' => 1200],
    ];

    // Filter by status
    if ($status) {
        $events = array_filter($all_events, fn($e) => $e['status'] === $status);
    } else {
        $events = $all_events;
    }

    // Filter by year
    $events = array_filter($events, fn($e) => date('Y', strtotime($e['event_date'])) == $year);

    // Filter by location
    if ($location) {
        $events = array_filter($events, fn($e) => $e['location'] === $location);
    }

    $events = array_values($events);

    // Available years
    $years = [
        ['year' => 2025],
        ['year' => 2024],
        ['year' => 2023],
    ];
} else {
    $where = ["YEAR(event_date) = ?"];
    $params = [$year];

    if ($status) {
        $where[] = "status = ?";
        $params[] = $status;
    }

    if ($location) {
        $where[] = "location = ?";
        $params[] = $location;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Get events with participant count
    $sql = "SELECT
                e.id,
                e.name,
                e.event_date,
                e.location,
                e.event_type,
                e.status,
                e.distance,
                COUNT(DISTINCT r.id) as participant_count
            FROM events e
            LEFT JOIN results r ON e.id = r.event_id
            $whereClause
            GROUP BY e.id
            ORDER BY e.event_date DESC";

    $events = $db->getAll($sql, $params);

    // Get available years
    $years = $db->getAll("SELECT DISTINCT YEAR(event_date) as year FROM events ORDER BY year DESC");
}

$pageTitle = 'Tävlingar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="calendar"></i>
                    Tävlingar
                </h1>
                <?php if (!$is_demo): ?>
                    <button type="button" class="gs-btn gs-btn-primary" onclick="openEventModal()">
                        <i data-lucide="plus"></i>
                        Ny Tävling
                    </button>
                <?php endif; ?>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Location Filter Badge -->
            <?php if ($location): ?>
                <div class="gs-alert gs-alert-info gs-mb-lg">
                    <i data-lucide="map-pin"></i>
                    <div class="gs-flex gs-items-center gs-gap-md">
                        <span>
                            Visar tävlingar för plats: <strong><?= h($location) ?></strong>
                        </span>
                        <a href="/admin/events.php" class="gs-btn gs-btn-sm gs-btn-outline">
                            <i data-lucide="x"></i>
                            Ta bort filter
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-flex gs-gap-md gs-items-end">
                        <div>
                            <label for="year" class="gs-label">
                                <i data-lucide="calendar"></i>
                                År
                            </label>
                            <select id="year" name="year" class="gs-input" style="max-width: 150px;">
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= $y['year'] ?>" <?= $y['year'] == $year ? 'selected' : '' ?>>
                                        <?= $y['year'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status" class="gs-label">
                                <i data-lucide="filter"></i>
                                Status
                            </label>
                            <select id="status" name="status" class="gs-input" style="max-width: 200px;">
                                <option value="">Alla</option>
                                <option value="upcoming" <?= $status === 'upcoming' ? 'selected' : '' ?>>Kommande</option>
                                <option value="ongoing" <?= $status === 'ongoing' ? 'selected' : '' ?>>Pågående</option>
                                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Avslutad</option>
                                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Inställd</option>
                            </select>
                        </div>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="filter"></i>
                            Filtrera
                        </button>
                        <?php if ($status || $year != date('Y') || $location): ?>
                            <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                                Rensa
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Event Modal -->
            <?php if (!$is_demo): ?>
                <div id="eventModal" class="gs-modal" style="display: none;">
                    <div class="gs-modal-overlay" onclick="closeEventModal()"></div>
                    <div class="gs-modal-content" style="max-width: 700px;">
                        <div class="gs-modal-header">
                            <h2 class="gs-modal-title" id="modalTitle">
                                <i data-lucide="calendar"></i>
                                <span id="modalTitleText">Ny Tävling</span>
                            </h2>
                            <button type="button" class="gs-modal-close" onclick="closeEventModal()">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                        <form method="POST" id="eventForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" id="formAction" value="create">
                            <input type="hidden" name="id" id="eventId" value="">

                            <div class="gs-modal-body">
                                <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                                    <!-- Name (Required) -->
                                    <div>
                                        <label for="name" class="gs-label">
                                            <i data-lucide="calendar"></i>
                                            Namn <span class="gs-text-error">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="name"
                                            name="name"
                                            class="gs-input"
                                            required
                                            placeholder="T.ex. GravitySeries Järvsö"
                                        >
                                    </div>

                                    <!-- Event Date (Required) -->
                                    <div>
                                        <label for="event_date" class="gs-label">
                                            <i data-lucide="calendar-days"></i>
                                            Datum <span class="gs-text-error">*</span>
                                        </label>
                                        <input
                                            type="date"
                                            id="event_date"
                                            name="event_date"
                                            class="gs-input"
                                            required
                                        >
                                    </div>

                                    <!-- Location -->
                                    <div>
                                        <label for="location" class="gs-label">
                                            <i data-lucide="map-pin"></i>
                                            Plats
                                        </label>
                                        <input
                                            type="text"
                                            id="location"
                                            name="location"
                                            class="gs-input"
                                            placeholder="T.ex. Järvsö"
                                        >
                                    </div>

                                    <!-- Event Type -->
                                    <div>
                                        <label for="event_type" class="gs-label">
                                            <i data-lucide="flag"></i>
                                            Tävlingstyp
                                        </label>
                                        <select id="event_type" name="event_type" class="gs-input">
                                            <option value="road_race">Road Race</option>
                                            <option value="time_trial">Time Trial</option>
                                            <option value="criterium">Criterium</option>
                                            <option value="stage_race">Stage Race</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>

                                    <!-- Status -->
                                    <div>
                                        <label for="status" class="gs-label">
                                            <i data-lucide="activity"></i>
                                            Status
                                        </label>
                                        <select id="status" name="status" class="gs-input">
                                            <option value="upcoming">Kommande</option>
                                            <option value="ongoing">Pågående</option>
                                            <option value="completed">Avslutad</option>
                                            <option value="cancelled">Inställd</option>
                                        </select>
                                    </div>

                                    <!-- Series -->
                                    <div>
                                        <label for="series_id" class="gs-label">
                                            <i data-lucide="trophy"></i>
                                            Serie
                                        </label>
                                        <select id="series_id" name="series_id" class="gs-input">
                                            <option value="">Ingen serie</option>
                                            <?php foreach ($series as $s): ?>
                                                <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Distance and Elevation -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="distance" class="gs-label">
                                                <i data-lucide="route"></i>
                                                Distans (km)
                                            </label>
                                            <input
                                                type="number"
                                                id="distance"
                                                name="distance"
                                                class="gs-input"
                                                step="0.01"
                                                min="0"
                                                placeholder="T.ex. 42.5"
                                            >
                                        </div>
                                        <div>
                                            <label for="elevation_gain" class="gs-label">
                                                <i data-lucide="mountain"></i>
                                                Höjdmeter (m)
                                            </label>
                                            <input
                                                type="number"
                                                id="elevation_gain"
                                                name="elevation_gain"
                                                class="gs-input"
                                                min="0"
                                                placeholder="T.ex. 1200"
                                            >
                                        </div>
                                    </div>

                                    <!-- Organizer -->
                                    <div>
                                        <label for="organizer" class="gs-label">
                                            <i data-lucide="user"></i>
                                            Arrangör
                                        </label>
                                        <input
                                            type="text"
                                            id="organizer"
                                            name="organizer"
                                            class="gs-input"
                                            placeholder="T.ex. GravitySeries AB"
                                        >
                                    </div>

                                    <!-- Website -->
                                    <div>
                                        <label for="website" class="gs-label">
                                            <i data-lucide="globe"></i>
                                            Webbplats
                                        </label>
                                        <input
                                            type="url"
                                            id="website"
                                            name="website"
                                            class="gs-input"
                                            placeholder="https://example.com"
                                        >
                                    </div>

                                    <!-- Registration Deadline and Max Participants -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="registration_deadline" class="gs-label">
                                                <i data-lucide="calendar-clock"></i>
                                                Anmälningsfrist
                                            </label>
                                            <input
                                                type="date"
                                                id="registration_deadline"
                                                name="registration_deadline"
                                                class="gs-input"
                                            >
                                        </div>
                                        <div>
                                            <label for="max_participants" class="gs-label">
                                                <i data-lucide="users"></i>
                                                Max deltagare
                                            </label>
                                            <input
                                                type="number"
                                                id="max_participants"
                                                name="max_participants"
                                                class="gs-input"
                                                min="0"
                                                placeholder="T.ex. 500"
                                            >
                                        </div>
                                    </div>

                                    <!-- Description -->
                                    <div>
                                        <label for="description" class="gs-label">
                                            <i data-lucide="file-text"></i>
                                            Beskrivning
                                        </label>
                                        <textarea
                                            id="description"
                                            name="description"
                                            class="gs-input"
                                            rows="4"
                                            placeholder="Beskriv tävlingen..."
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="gs-modal-footer">
                                <button type="button" class="gs-btn gs-btn-outline" onclick="closeEventModal()">
                                    Avbryt
                                </button>
                                <button type="submit" class="gs-btn gs-btn-primary" id="submitButton">
                                    <i data-lucide="check"></i>
                                    <span id="submitButtonText">Skapa</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-4 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($events) ?></div>
                    <div class="gs-stat-label">Totalt tävlingar</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="clock" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($events, fn($e) => $e['status'] === 'upcoming')) ?>
                    </div>
                    <div class="gs-stat-label">Kommande</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($events, fn($e) => $e['status'] === 'completed')) ?>
                    </div>
                    <div class="gs-stat-label">Avslutade</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($events, 'participant_count')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt deltagare</div>
                </div>
            </div>

            <!-- Events Table -->
            <?php if (empty($events)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="calendar-x" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga tävlingar hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="calendar"></i>
                                        Namn
                                    </th>
                                    <th>Datum</th>
                                    <th>
                                        <i data-lucide="map-pin"></i>
                                        Plats
                                    </th>
                                    <th>Disciplin</th>
                                    <th>Status</th>
                                    <th>
                                        <i data-lucide="users"></i>
                                        Deltagare
                                    </th>
                                    <th style="width: 150px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($event['name']) ?></strong>
                                            <?php if ($event['distance']): ?>
                                                <br>
                                                <span class="gs-text-secondary gs-text-xs">
                                                    <i data-lucide="route"></i>
                                                    <?= $event['distance'] ?> km
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="gs-text-secondary" style="font-family: monospace;">
                                                <?= formatDate($event['event_date'], 'd M Y') ?>
                                            </span>
                                        </td>
                                        <td><?= h($event['location']) ?></td>
                                        <td>
                                            <span class="gs-badge gs-badge-primary">
                                                <i data-lucide="flag"></i>
                                                <?= h(str_replace('_', ' ', $event['event_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $statusMap = [
                                                'upcoming' => ['badge' => 'warning', 'icon' => 'clock', 'text' => 'Kommande'],
                                                'ongoing' => ['badge' => 'primary', 'icon' => 'play', 'text' => 'Pågående'],
                                                'completed' => ['badge' => 'success', 'icon' => 'check-circle', 'text' => 'Avslutad'],
                                                'cancelled' => ['badge' => 'secondary', 'icon' => 'x-circle', 'text' => 'Inställd']
                                            ];
                                            $statusInfo = $statusMap[$event['status']] ?? ['badge' => 'secondary', 'icon' => 'help-circle', 'text' => $event['status']];
                                            ?>
                                            <span class="gs-badge gs-badge-<?= $statusInfo['badge'] ?>">
                                                <i data-lucide="<?= $statusInfo['icon'] ?>"></i>
                                                <?= $statusInfo['text'] ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-center">
                                            <strong class="gs-text-primary"><?= $event['participant_count'] ?></strong>
                                        </td>
                                        <td style="text-align: right;">
                                            <?php if ($is_demo): ?>
                                                <span class="gs-badge gs-badge-secondary">Demo</span>
                                            <?php else: ?>
                                                <div class="gs-flex gs-gap-sm gs-justify-end">
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        onclick="editEvent(<?= $event['id'] ?>)"
                                                        title="Redigera"
                                                    >
                                                        <i data-lucide="edit"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                        onclick="deleteEvent(<?= $event['id'] ?>, '<?= addslashes(h($event['name'])) ?>')"
                                                        title="Ta bort"
                                                    >
                                                        <i data-lucide="trash-2"></i>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$is_demo): ?>
        <script>
            // Open modal for creating new event
            function openEventModal() {
                document.getElementById('eventModal').style.display = 'flex';
                document.getElementById('eventForm').reset();
                document.getElementById('formAction').value = 'create';
                document.getElementById('eventId').value = '';
                document.getElementById('modalTitleText').textContent = 'Ny Tävling';
                document.getElementById('submitButtonText').textContent = 'Skapa';

                // Re-initialize Lucide icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }

            // Close modal
            function closeEventModal() {
                document.getElementById('eventModal').style.display = 'none';
            }

            // Edit event - reload page with edit parameter
            function editEvent(id) {
                window.location.href = `?edit=${id}`;
            }

            // Delete event
            function deleteEvent(id, name) {
                if (!confirm(`Är du säker på att du vill ta bort "${name}"?`)) {
                    return;
                }

                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }

            // Close modal when clicking outside
            document.addEventListener('DOMContentLoaded', function() {
                const modal = document.getElementById('eventModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeEventModal();
                        }
                    });
                }

                // Handle edit mode from URL parameter
                <?php if ($editEvent): ?>
                    // Populate form with event data
                    document.getElementById('formAction').value = 'update';
                    document.getElementById('eventId').value = '<?= $editEvent['id'] ?>';
                    document.getElementById('name').value = '<?= addslashes($editEvent['name']) ?>';
                    document.getElementById('event_date').value = '<?= $editEvent['event_date'] ?>';
                    document.getElementById('location').value = '<?= addslashes($editEvent['location'] ?? '') ?>';
                    document.getElementById('event_type').value = '<?= $editEvent['event_type'] ?? 'road_race' ?>';
                    document.getElementById('status').value = '<?= $editEvent['status'] ?? 'upcoming' ?>';
                    document.getElementById('series_id').value = '<?= $editEvent['series_id'] ?? '' ?>';
                    document.getElementById('distance').value = '<?= $editEvent['distance'] ?? '' ?>';
                    document.getElementById('elevation_gain').value = '<?= $editEvent['elevation_gain'] ?? '' ?>';
                    document.getElementById('description').value = '<?= addslashes($editEvent['description'] ?? '') ?>';
                    document.getElementById('organizer').value = '<?= addslashes($editEvent['organizer'] ?? '') ?>';
                    document.getElementById('website').value = '<?= addslashes($editEvent['website'] ?? '') ?>';
                    document.getElementById('registration_deadline').value = '<?= $editEvent['registration_deadline'] ?? '' ?>';
                    document.getElementById('max_participants').value = '<?= $editEvent['max_participants'] ?? '' ?>';

                    // Update modal title and button
                    document.getElementById('modalTitleText').textContent = 'Redigera Tävling';
                    document.getElementById('submitButtonText').textContent = 'Uppdatera';

                    // Open modal
                    document.getElementById('eventModal').style.display = 'flex';

                    // Re-initialize Lucide icons
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                <?php endif; ?>
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeEventModal();
                }
            });
        </script>
        <?php endif; ?>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
