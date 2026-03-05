<?php
/**
 * TheHUB - Destination-admin
 * Manage venues/destinations where user is admin
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();
$adminVenues = hub_get_admin_venues($currentUser['id']);

if (empty($adminVenues)) {
    header('Location: /profile');
    exit;
}

// Get selected venue (default to first)
$selectedVenueId = intval($_GET['venue'] ?? $adminVenues[0]['id']);
$selectedVenue = null;
foreach ($adminVenues as $venue) {
    if ($venue['id'] == $selectedVenueId) {
        $selectedVenue = $venue;
        break;
    }
}

if (!$selectedVenue) {
    header('Location: /profile');
    exit;
}

// Handle form submission (edit venue)
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hub_can_edit_venue($selectedVenueId)) {
        $message = 'Ingen behörighet att redigera denna destination.';
        $messageType = 'error';
    } else {
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $coordinates = trim($_POST['coordinates'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $website = trim($_POST['website'] ?? '');

        if (empty($name)) {
            $message = 'Destinationsnamn krävs.';
            $messageType = 'error';
        } else {
            try {
                $updateStmt = $pdo->prepare("
                    UPDATE venues SET name = ?, city = ?, region = ?, address = ?,
                    coordinates = ?, description = ?, website = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$name, $city, $region, $address, $coordinates, $description, $website, $selectedVenueId]);

                $message = 'Destinationsinformationen har sparats.';
                $messageType = 'success';

                // Refresh venue data
                $refreshStmt = $pdo->prepare("SELECT * FROM venues WHERE id = ?");
                $refreshStmt->execute([$selectedVenueId]);
                $selectedVenue = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $message = 'Kunde inte spara ändringar.';
                $messageType = 'error';
            }
        }
    }
}

// Get upcoming events at this venue
$eventsStmt = $pdo->prepare("
    SELECT e.id, e.name, e.date, e.location
    FROM events e
    WHERE e.venue_id = ? AND e.date >= CURDATE()
    ORDER BY e.date ASC
    LIMIT 10
");
$eventsStmt->execute([$selectedVenueId]);
$upcomingEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get past events count
$pastStmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE venue_id = ? AND date < CURDATE()");
$pastStmt->execute([$selectedVenueId]);
$pastEventsCount = (int) $pastStmt->fetchColumn();
?>

<link rel="stylesheet" href="/assets/css/forms.css?v=<?= filemtime(HUB_ROOT . '/assets/css/forms.css') ?>">

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Destination-admin</span>
    </nav>
    <h1 class="page-title">
        <i data-lucide="map-pin" class="page-icon"></i>
        Destination-admin
    </h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Venue Selector (if multiple) -->
<?php if (count($adminVenues) > 1): ?>
    <div class="club-selector">
        <?php foreach ($adminVenues as $venue): ?>
            <a href="?venue=<?= $venue['id'] ?>"
               class="club-tab<?= $venue['id'] == $selectedVenueId ? ' active' : '' ?>">
                <?= htmlspecialchars($venue['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Venue Header -->
<div class="club-header card">
    <div class="club-avatar">
        <i data-lucide="map-pin" style="width: 24px; height: 24px;"></i>
    </div>
    <div class="club-info">
        <h2><?= htmlspecialchars($selectedVenue['name']) ?></h2>
        <p style="color: var(--color-text-secondary); font-size: 0.875rem;">
            <?= htmlspecialchars(implode(', ', array_filter([$selectedVenue['city'] ?? '', $selectedVenue['region'] ?? '']))) ?>
        </p>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= count($upcomingEvents) ?></span>
        <span class="stat-label">Kommande event</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $pastEventsCount ?></span>
        <span class="stat-label">Genomförda event</span>
    </div>
</div>

<!-- Edit Form -->
<div class="card">
    <div class="card-header">
        <h3>Redigera destination</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">Namn *</label>
                <input type="text" name="name" class="form-input" required
                       value="<?= htmlspecialchars($selectedVenue['name'] ?? '') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Ort</label>
                    <input type="text" name="city" class="form-input"
                           value="<?= htmlspecialchars($selectedVenue['city'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Region</label>
                    <input type="text" name="region" class="form-input"
                           value="<?= htmlspecialchars($selectedVenue['region'] ?? '') ?>"
                           placeholder="t.ex. Halland">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Adress</label>
                <input type="text" name="address" class="form-input"
                       value="<?= htmlspecialchars($selectedVenue['address'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">GPS-koordinater</label>
                <input type="text" name="coordinates" class="form-input"
                       value="<?= htmlspecialchars($selectedVenue['coordinates'] ?? '') ?>"
                       placeholder="57.123, 12.456">
            </div>

            <div class="form-group">
                <label class="form-label">Webbplats</label>
                <input type="url" name="website" class="form-input"
                       value="<?= htmlspecialchars($selectedVenue['website'] ?? '') ?>"
                       placeholder="https://...">
            </div>

            <div class="form-group">
                <label class="form-label">Beskrivning</label>
                <textarea name="description" class="form-textarea" rows="5"
                          placeholder="Beskriv destinationen..."><?= htmlspecialchars($selectedVenue['description'] ?? '') ?></textarea>
            </div>

            <div class="form-actions" style="display: flex; gap: var(--space-sm); margin-top: var(--space-lg);">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="save"></i> Spara ändringar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Upcoming Events -->
<?php if (!empty($upcomingEvents)): ?>
<div class="section">
    <div class="section-header">
        <h2>Kommande event</h2>
    </div>
    <div class="upcoming-list">
        <?php foreach ($upcomingEvents as $event): ?>
            <a href="/calendar/<?= $event['id'] ?>" class="upcoming-item">
                <div class="upcoming-date">
                    <span class="upcoming-day"><?= date('j', strtotime($event['date'])) ?></span>
                    <span class="upcoming-month"><?= hub_month_short($event['date']) ?></span>
                </div>
                <div class="upcoming-info">
                    <span class="upcoming-name"><?= htmlspecialchars($event['name']) ?></span>
                    <span class="upcoming-class"><?= htmlspecialchars($event['location'] ?? '') ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- CSS reuses profile-club-admin styles -->
<link rel="stylesheet" href="/assets/css/pages/profile-club-admin.css?v=<?= @filemtime(HUB_ROOT . '/assets/css/pages/profile-club-admin.css') ?: time() ?>">
