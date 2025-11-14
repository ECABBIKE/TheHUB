<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    // Validate required fields
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['date'] ?? '');

    if (empty($name) || empty($date)) {
        $message = 'Namn och datum är obligatoriska';
        $messageType = 'error';
    } else {
        // Prepare event data
        $eventData = [
            'name' => $name,
            'advent_id' => trim($_POST['advent_id'] ?? '') ?: null,
            'date' => $date,
            'location' => trim($_POST['location'] ?? ''),
            'venue_id' => !empty($_POST['venue_id']) ? intval($_POST['venue_id']) : null,
            'type' => trim($_POST['type'] ?? ''),
            'discipline' => trim($_POST['discipline'] ?? ''),
            'series_id' => !empty($_POST['series_id']) ? intval($_POST['series_id']) : null,
            'distance' => !empty($_POST['distance']) ? floatval($_POST['distance']) : null,
            'elevation_gain' => !empty($_POST['elevation_gain']) ? intval($_POST['elevation_gain']) : null,
            'status' => $_POST['status'] ?? 'upcoming',
            'description' => trim($_POST['description'] ?? ''),
            'organizer' => trim($_POST['organizer'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'registration_url' => trim($_POST['registration_url'] ?? ''),
            'registration_deadline' => !empty($_POST['registration_deadline']) ? trim($_POST['registration_deadline']) : null,
            'max_participants' => !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null,
            'entry_fee' => !empty($_POST['entry_fee']) ? floatval($_POST['entry_fee']) : null,
            'active' => isset($_POST['active']) ? 1 : 0,
        ];

        try {
            $db->insert('events', $eventData);
            $_SESSION['message'] = 'Event skapat!';
            $_SESSION['messageType'] = 'success';
            header('Location: /admin/events.php');
            exit;
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Fetch series and venues for dropdowns
$series = $db->getAll("SELECT id, name FROM series WHERE status = 'active' ORDER BY name");
$venues = $db->getAll("SELECT id, name, city FROM venues WHERE active = 1 ORDER BY name");

$pageTitle = 'Skapa Event';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container" style="max-width: 800px;">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="calendar"></i>
                Skapa Nytt Event
            </h1>
            <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                <i data-lucide="x"></i>
                Avbryt
            </a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="gs-card">
            <form method="POST" class="gs-card-content">
                <?= csrf_field() ?>

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
                            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                            placeholder="T.ex. GravitySeries Järvsö"
                        >
                    </div>

                    <!-- Advent ID -->
                    <div>
                        <label for="advent_id" class="gs-label">
                            <i data-lucide="hash"></i>
                            Advent ID
                        </label>
                        <input
                            type="text"
                            id="advent_id"
                            name="advent_id"
                            class="gs-input"
                            value="<?= htmlspecialchars($_POST['advent_id'] ?? '') ?>"
                            placeholder="T.ex. event-2024-001"
                        >
                        <small class="gs-text-muted">Externt ID för import av resultat</small>
                    </div>

                    <!-- Date (Required) -->
                    <div>
                        <label for="date" class="gs-label">
                            <i data-lucide="calendar-days"></i>
                            Datum <span class="gs-text-error">*</span>
                        </label>
                        <input
                            type="date"
                            id="date"
                            name="date"
                            class="gs-input"
                            required
                            value="<?= htmlspecialchars($_POST['date'] ?? '') ?>"
                        >
                    </div>

                    <!-- Location and Venue -->
                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
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
                                value="<?= htmlspecialchars($_POST['location'] ?? '') ?>"
                                placeholder="T.ex. Järvsö"
                            >
                        </div>
                        <div>
                            <label for="venue_id" class="gs-label">
                                <i data-lucide="mountain"></i>
                                Bana/Anläggning
                            </label>
                            <select id="venue_id" name="venue_id" class="gs-input">
                                <option value="">Ingen specifik bana</option>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?= $venue['id'] ?>">
                                        <?= htmlspecialchars($venue['name']) ?>
                                        <?php if ($venue['city']): ?>
                                            (<?= htmlspecialchars($venue['city']) ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Type, Discipline, and Series -->
                    <div class="gs-grid gs-grid-cols-3 gs-gap-md">
                        <div>
                            <label for="type" class="gs-label">
                                <i data-lucide="flag"></i>
                                Typ
                            </label>
                            <input
                                type="text"
                                id="type"
                                name="type"
                                class="gs-input"
                                value="<?= htmlspecialchars($_POST['type'] ?? '') ?>"
                                placeholder="T.ex. Enduro"
                            >
                        </div>
                        <div>
                            <label for="discipline" class="gs-label">
                                <i data-lucide="bike"></i>
                                Disciplin
                            </label>
                            <input
                                type="text"
                                id="discipline"
                                name="discipline"
                                class="gs-input"
                                value="<?= htmlspecialchars($_POST['discipline'] ?? '') ?>"
                                placeholder="T.ex. MTB"
                            >
                        </div>
                        <div>
                            <label for="series_id" class="gs-label">
                                <i data-lucide="trophy"></i>
                                Serie
                            </label>
                            <select id="series_id" name="series_id" class="gs-input">
                                <option value="">Ingen serie</option>
                                <?php foreach ($series as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
                                value="<?= htmlspecialchars($_POST['distance'] ?? '') ?>"
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
                                value="<?= htmlspecialchars($_POST['elevation_gain'] ?? '') ?>"
                                placeholder="T.ex. 1200"
                            >
                        </div>
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status" class="gs-label">
                            <i data-lucide="activity"></i>
                            Status
                        </label>
                        <select id="status" name="status" class="gs-input">
                            <option value="upcoming" selected>Kommande</option>
                            <option value="ongoing">Pågående</option>
                            <option value="completed">Avslutad</option>
                            <option value="cancelled">Inställd</option>
                        </select>
                    </div>

                    <!-- Organizer and Website -->
                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
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
                                value="<?= htmlspecialchars($_POST['organizer'] ?? '') ?>"
                                placeholder="T.ex. GravitySeries AB"
                            >
                        </div>
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
                                value="<?= htmlspecialchars($_POST['website'] ?? '') ?>"
                                placeholder="https://example.com"
                            >
                        </div>
                    </div>

                    <!-- Registration Details -->
                    <div class="gs-grid gs-grid-cols-3 gs-gap-md">
                        <div>
                            <label for="registration_url" class="gs-label">
                                <i data-lucide="link"></i>
                                Anmälningslänk
                            </label>
                            <input
                                type="url"
                                id="registration_url"
                                name="registration_url"
                                class="gs-input"
                                value="<?= htmlspecialchars($_POST['registration_url'] ?? '') ?>"
                                placeholder="https://..."
                            >
                        </div>
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
                                value="<?= htmlspecialchars($_POST['registration_deadline'] ?? '') ?>"
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
                                value="<?= htmlspecialchars($_POST['max_participants'] ?? '') ?>"
                                placeholder="T.ex. 500"
                            >
                        </div>
                    </div>

                    <!-- Entry Fee -->
                    <div>
                        <label for="entry_fee" class="gs-label">
                            <i data-lucide="dollar-sign"></i>
                            Startavgift (kr)
                        </label>
                        <input
                            type="number"
                            id="entry_fee"
                            name="entry_fee"
                            class="gs-input"
                            step="0.01"
                            min="0"
                            value="<?= htmlspecialchars($_POST['entry_fee'] ?? '') ?>"
                            placeholder="T.ex. 350.00"
                        >
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
                            placeholder="Beskriv eventet..."
                        ><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <!-- Active Status -->
                    <div>
                        <label class="gs-checkbox-label">
                            <input
                                type="checkbox"
                                name="active"
                                class="gs-checkbox"
                                checked
                            >
                            <span>
                                <i data-lucide="check-circle"></i>
                                Aktivt event
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Footer -->
                <div class="gs-flex gs-justify-end gs-gap-md gs-mt-lg">
                    <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                        Avbryt
                    </a>
                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="check"></i>
                        Skapa Event
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
