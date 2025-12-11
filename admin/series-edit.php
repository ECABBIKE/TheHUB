<?php
/**
 * Admin Series Edit - V3 Unified Design System
 * Dedicated page for editing series (like event-edit.php)
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get series ID from URL (supports both /admin/series/edit/123 and ?id=123)
$id = 0;
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    // Check for pretty URL format
    $uri = $_SERVER['REQUEST_URI'];
    if (preg_match('#/admin/series/edit/(\d+)#', $uri, $matches)) {
        $id = intval($matches[1]);
    }
}

// Check if creating new series
$isNew = ($id === 0 && isset($_GET['new']));

if ($id <= 0 && !$isNew) {
    $_SESSION['message'] = 'Ogiltigt serie-ID';
    $_SESSION['messageType'] = 'error';
    header('Location: /admin/series');
    exit;
}

// Set default values for new series (must be before POST handler)
$series = [
    'id' => 0,
    'name' => '',
    'year' => date('Y'),
    'type' => '',
    'format' => 'Championship',
    'status' => 'planning',
    'start_date' => '',
    'end_date' => '',
    'description' => '',
    'organizer' => '',
    'logo' => '',
    'brand_id' => null,
    'swish_number' => '',
    'swish_name' => '',
];

// Fetch series data if editing
if (!$isNew) {
    $fetchedSeries = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);

    if (!$fetchedSeries) {
        $_SESSION['message'] = 'Serie hittades inte';
        $_SESSION['messageType'] = 'error';
        header('Location: /admin/series');
        exit;
    }
    $series = array_merge($series, $fetchedSeries);
}

// Check if year column exists
$yearColumnExists = false;
try {
    $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'year'");
    if (empty($columns)) {
        // Add year column if it doesn't exist
        $db->query("ALTER TABLE series ADD COLUMN year INT DEFAULT NULL AFTER name");
        $yearColumnExists = true;
    } else {
        $yearColumnExists = true;
    }
} catch (Exception $e) {
    error_log("SERIES EDIT: Error checking/adding year column: " . $e->getMessage());
}

// Check if format column exists
$formatColumnExists = false;
try {
    $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'format'");
    $formatColumnExists = !empty($columns);
} catch (Exception $e) {}

// Check if brand_id column exists and get brands
$brandColumnExists = false;
$brands = [];
try {
    $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'brand_id'");
    $brandColumnExists = !empty($columns);
    if ($brandColumnExists) {
        $tables = $db->getAll("SHOW TABLES LIKE 'series_brands'");
        if (!empty($tables)) {
            $brands = $db->getAll("SELECT id, name FROM series_brands WHERE active = 1 ORDER BY display_order ASC, name ASC");
        }
    }
} catch (Exception $e) {}

// Check if swish columns exist
$swishColumnsExist = false;
try {
    $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'swish_number'");
    $swishColumnsExist = !empty($columns);
} catch (Exception $e) {}

// Initialize message variables
$message = '';
$messageType = 'info';

// Check if we just saved
if (isset($_GET['saved']) && $_GET['saved'] == '1' && isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'] ?? 'success';
    unset($_SESSION['message'], $_SESSION['messageType']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $name = trim($_POST['name'] ?? '');

    $year = !empty($_POST['year']) ? intval($_POST['year']) : null;

    if (empty($name)) {
        $message = 'Namn är obligatoriskt';
        $messageType = 'error';
    } elseif (empty($year)) {
        $message = 'År är obligatoriskt - ange vilket år serien gäller';
        $messageType = 'error';
    } else {
        // Handle logo upload
        $logoPath = $series['logo'] ?? null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/series/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileExtension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

            if (in_array($fileExtension, $allowedExtensions)) {
                $fileName = uniqid('series_') . '.' . $fileExtension;
                $targetPath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
                    $logoPath = '/uploads/series/' . $fileName;
                }
            }
        }

        // Prepare series data
        $seriesData = [
            'name' => $name,
            'type' => trim($_POST['type'] ?? ''),
            'status' => $_POST['status'] ?? 'planning',
            'start_date' => !empty($_POST['start_date']) ? trim($_POST['start_date']) : null,
            'end_date' => !empty($_POST['end_date']) ? trim($_POST['end_date']) : null,
            'description' => trim($_POST['description'] ?? ''),
            'organizer' => trim($_POST['organizer'] ?? ''),
            'logo' => $logoPath,
        ];

        // Year is always required now
        if ($yearColumnExists) {
            $seriesData['year'] = $year;
        }

        // Add format if column exists
        if ($formatColumnExists) {
            $seriesData['format'] = $_POST['format'] ?? 'Championship';
        }

        // Add brand_id if column exists
        if ($brandColumnExists) {
            $seriesData['brand_id'] = !empty($_POST['brand_id']) ? intval($_POST['brand_id']) : null;
        }

        // Add Swish payment fields if columns exist
        if ($swishColumnsExist) {
            $seriesData['swish_number'] = trim($_POST['swish_number'] ?? '') ?: null;
            $seriesData['swish_name'] = trim($_POST['swish_name'] ?? '') ?: null;
        }

        try {
            // Check if we're marking as completed (and it wasn't before)
            $wasCompleted = !$isNew && ($series['status'] ?? '') === 'completed';
            $isNowCompleted = $seriesData['status'] === 'completed';
            $justCompleted = !$wasCompleted && $isNowCompleted;
            $calculateChampions = isset($_POST['calculate_champions']) && $_POST['calculate_champions'] === '1';

            if ($isNew) {
                $newId = $db->insert('series', $seriesData);
                $_SESSION['message'] = 'Serie skapad!';
                $_SESSION['messageType'] = 'success';
                header('Location: /admin/series/edit/' . $newId . '?saved=1');
                exit;
            } else {
                $db->update('series', $seriesData, 'id = ?', [$id]);

                // If just marked as completed and user confirmed to calculate champions
                if ($justCompleted && $calculateChampions) {
                    require_once __DIR__ . '/../includes/rebuild-rider-stats.php';
                    $pdo = $db->getPdo();

                    // Rebuild stats for all riders who have results in this series
                    $ridersStmt = $pdo->prepare("
                        SELECT DISTINCT r.cyclist_id
                        FROM results r
                        JOIN events e ON r.event_id = e.id
                        JOIN series_events se ON se.event_id = e.id
                        WHERE se.series_id = ? AND r.cyclist_id IS NOT NULL
                    ");
                    $ridersStmt->execute([$id]);
                    $riderIds = $ridersStmt->fetchAll(PDO::FETCH_COLUMN);

                    $championCount = 0;
                    foreach ($riderIds as $riderId) {
                        rebuildRiderStats($pdo, $riderId);
                        // Check if this rider got a championship for this series
                        $checkStmt = $pdo->prepare("
                            SELECT COUNT(*) FROM rider_achievements
                            WHERE rider_id = ? AND achievement_type = 'series_champion' AND series_id = ?
                        ");
                        $checkStmt->execute([$riderId, $id]);
                        if ($checkStmt->fetchColumn() > 0) {
                            $championCount++;
                        }
                    }

                    $_SESSION['message'] = "Serie avslutad! Beräknade {$championCount} seriemästare.";
                    $_SESSION['messageType'] = 'success';
                } else {
                    $_SESSION['message'] = 'Serie uppdaterad!';
                    $_SESSION['messageType'] = 'success';
                }

                header('Location: /admin/series/edit/' . $id . '?saved=1');
                exit;
            }
        } catch (Exception $e) {
            error_log("SERIES EDIT ERROR: " . $e->getMessage());
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Re-fetch series data after potential update (for display)
if (!$isNew && $id > 0) {
    $fetchedSeries = $db->getRow("SELECT * FROM series WHERE id = ?", [$id]);
    if ($fetchedSeries) {
        $series = array_merge($series, $fetchedSeries);
    }
}

// Get events count and results status for this series
$eventsCount = 0;
$eventsWithResults = 0;
$readyToComplete = false;
if (!$isNew) {
    // Check if series_events table exists
    $seriesEventsExists = false;
    try {
        $tables = $db->getAll("SHOW TABLES LIKE 'series_events'");
        $seriesEventsExists = !empty($tables);
    } catch (Exception $e) {}

    if ($seriesEventsExists) {
        // Count events via junction table
        $eventsCount = $db->getValue("SELECT COUNT(*) FROM series_events WHERE series_id = ?", [$id]) ?: 0;
        // Count events with at least one finished result
        $eventsWithResults = $db->getValue("
            SELECT COUNT(DISTINCT se.event_id)
            FROM series_events se
            INNER JOIN results r ON r.event_id = se.event_id
            WHERE se.series_id = ? AND r.status = 'finished'
        ", [$id]) ?: 0;
    } else {
        $eventsCount = $db->getValue("SELECT COUNT(*) FROM events WHERE series_id = ?", [$id]) ?: 0;
    }

    // Check if ready to complete (all events have results but not marked completed)
    $readyToComplete = $eventsCount > 0
        && $eventsWithResults >= $eventsCount
        && ($series['status'] ?? '') !== 'completed';
}

// Page config
$page_title = $isNew ? 'Ny Serie' : 'Redigera Serie: ' . htmlspecialchars($series['name']);
$breadcrumbs = [
    ['label' => 'Serier', 'url' => '/admin/series'],
    ['label' => $isNew ? 'Ny' : htmlspecialchars($series['name'])]
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?>">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <?php if ($messageType === 'success'): ?>
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        <?php elseif ($messageType === 'error'): ?>
            <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>
        <?php else: ?>
            <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/>
        <?php endif; ?>
    </svg>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($readyToComplete): ?>
<div class="alert alert-warning" style="display: flex; align-items: center; justify-content: space-between; gap: var(--space-md);">
    <div style="display: flex; align-items: center; gap: var(--space-sm);">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px; flex-shrink: 0;">
            <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>
        </svg>
        <div>
            <strong>Alla <?= $eventsCount ?> events har resultat!</strong>
            <span style="color: var(--color-text-secondary);">
                Ändra status till "Avslutad" för att beräkna seriemästare.
            </span>
        </div>
    </div>
    <button type="button" onclick="document.getElementById('status').value='completed'; document.getElementById('status').dispatchEvent(new Event('change'));"
            class="btn-admin btn-admin-warning" style="white-space: nowrap;">
        Markera avslutad
    </button>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="calculate_champions" id="calculate_champions" value="0">

    <!-- Basic Info -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px;"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                Grundläggande information
            </h2>
        </div>
        <div class="admin-card-body">
            <?php if ($brandColumnExists && !empty($brands)): ?>
            <div class="admin-form-group" style="background: var(--color-bg-secondary); padding: var(--space-md); border-radius: var(--radius-md); margin-bottom: var(--space-md);">
                <label for="brand_id" class="admin-form-label" style="font-weight: 600;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; display: inline; vertical-align: middle; margin-right: 4px;"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                    Huvudserie (Varumärke) <span style="color: var(--color-error);">*</span>
                </label>
                <select id="brand_id" name="brand_id" class="admin-form-select" required onchange="updateSeriesName()">
                    <option value="">-- Välj huvudserie --</option>
                    <?php foreach ($brands as $brand): ?>
                    <option value="<?= $brand['id'] ?>" data-name="<?= htmlspecialchars($brand['name']) ?>" <?= ($series['brand_id'] ?? '') == $brand['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($brand['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: var(--color-text-secondary); font-size: 0.75rem;">
                    Detta är den övergripande serien (t.ex. "Swecup", "GravitySeries Enduro").
                    <a href="/admin/series/brands" style="color: var(--color-accent);">Hantera huvudserier</a>
                </small>
            </div>
            <?php endif; ?>

            <div class="admin-form-row">
                <div class="admin-form-group" style="flex: 1;">
                    <label for="year" class="admin-form-label">
                        Säsong/År <span style="color: var(--color-error);">*</span>
                    </label>
                    <input type="number" id="year" name="year" class="admin-form-input" required
                           value="<?= htmlspecialchars($series['year'] ?? date('Y')) ?>"
                           min="2000" max="2100">
                    <small style="color: var(--color-text-secondary); font-size: 0.75rem;">
                        Vilket år gäller denna säsong? Avgör vilka event som tillhör serien.
                    </small>
                </div>
                <div class="admin-form-group" style="flex: 2;">
                    <label for="name" class="admin-form-label">Serienamn <span style="color: var(--color-error);">*</span></label>
                    <input type="text" id="name" name="name" class="admin-form-input" required
                           value="<?= htmlspecialchars($series['name'] ?? '') ?>"
                           placeholder="T.ex. Swecup">
                    <small style="color: var(--color-text-secondary); font-size: 0.75rem;">
                        År visas separat som badge - skriv ej år i namnet
                    </small>
                </div>
            </div>

            <div class="admin-form-row">
                <div class="admin-form-group">
                    <label for="type" class="admin-form-label">Typ</label>
                    <input type="text" id="type" name="type" class="admin-form-input"
                           value="<?= htmlspecialchars($series['type'] ?? '') ?>"
                           placeholder="T.ex. Enduro, DH, XC">
                </div>
                <?php if ($formatColumnExists): ?>
                <div class="admin-form-group">
                    <label for="format" class="admin-form-label">Format</label>
                    <select id="format" name="format" class="admin-form-select">
                        <option value="Championship" <?= ($series['format'] ?? '') === 'Championship' ? 'selected' : '' ?>>Championship</option>
                        <option value="Team" <?= ($series['format'] ?? '') === 'Team' ? 'selected' : '' ?>>Team</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="admin-form-group">
                    <label for="status" class="admin-form-label">Status</label>
                    <select id="status" name="status" class="admin-form-select">
                        <option value="planning" <?= ($series['status'] ?? '') === 'planning' ? 'selected' : '' ?>>Planering</option>
                        <option value="active" <?= ($series['status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktiv</option>
                        <option value="completed" <?= ($series['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Avslutad ✓</option>
                        <option value="cancelled" <?= ($series['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Inställd</option>
                    </select>
                    <small style="color: var(--color-text-secondary); font-size: 0.75rem;">
                        "Avslutad" beräknar seriemästare automatiskt
                    </small>
                </div>
            </div>

            <details style="margin-top: var(--space-sm);">
                <summary style="cursor: pointer; color: var(--color-text-secondary); font-size: 0.85rem;">
                    Valfritt: Specifika datum (normalt beräknas detta från events)
                </summary>
                <div class="admin-form-row" style="margin-top: var(--space-sm);">
                    <div class="admin-form-group">
                        <label for="start_date" class="admin-form-label">Startdatum</label>
                        <input type="date" id="start_date" name="start_date" class="admin-form-input"
                               value="<?= htmlspecialchars($series['start_date'] ?? '') ?>">
                        <small style="color: var(--color-text-secondary); font-size: 0.75rem;">
                            Lämna tomt för att använda första eventets datum
                        </small>
                    </div>
                    <div class="admin-form-group">
                        <label for="end_date" class="admin-form-label">Slutdatum</label>
                        <input type="date" id="end_date" name="end_date" class="admin-form-input"
                               value="<?= htmlspecialchars($series['end_date'] ?? '') ?>">
                        <small style="color: var(--color-text-secondary); font-size: 0.75rem;">
                            Lämna tomt för att använda sista eventets datum
                        </small>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- Organizer & Description -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Arrangör & Beskrivning
            </h2>
        </div>
        <div class="admin-card-body">
            <div class="admin-form-group">
                <label for="organizer" class="admin-form-label">Arrangör</label>
                <input type="text" id="organizer" name="organizer" class="admin-form-input"
                       value="<?= htmlspecialchars($series['organizer'] ?? '') ?>"
                       placeholder="T.ex. Svenska Cykelförbundet">
            </div>

            <div class="admin-form-group">
                <label for="description" class="admin-form-label">Beskrivning</label>
                <textarea id="description" name="description" class="admin-form-textarea" rows="4"
                          placeholder="Beskriv serien..."><?= htmlspecialchars($series['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <?php if ($swishColumnsExist): ?>
    <!-- Swish Payment -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px;"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>
                Betalning (Swish)
            </h2>
        </div>
        <div class="admin-card-body">
            <p style="color: var(--color-text-secondary); margin-bottom: var(--space-md);">
                Swish-uppgifter för serieanmälningar. Event kan välja att betalning går till serien.
            </p>
            <div class="admin-form-row">
                <div class="admin-form-group">
                    <label for="swish_number" class="admin-form-label">Swish-nummer</label>
                    <input type="text" id="swish_number" name="swish_number" class="admin-form-input"
                           value="<?= htmlspecialchars($series['swish_number'] ?? '') ?>"
                           placeholder="070-123 45 67 eller 123-456 78 90">
                    <small style="color: var(--color-text-secondary);">Mobilnummer eller Swish-företagsnummer</small>
                </div>
                <div class="admin-form-group">
                    <label for="swish_name" class="admin-form-label">Mottagarnamn</label>
                    <input type="text" id="swish_name" name="swish_name" class="admin-form-input"
                           value="<?= htmlspecialchars($series['swish_name'] ?? '') ?>"
                           placeholder="Seriens namn">
                    <small style="color: var(--color-text-secondary);">Visas för deltagare vid betalning</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Logo -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px;"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                Logotyp
            </h2>
        </div>
        <div class="admin-card-body">
            <div class="admin-form-group">
                <label for="logo" class="admin-form-label">Ladda upp logotyp</label>
                <input type="file" id="logo" name="logo" class="admin-form-input" accept="image/*">
                <?php if (!empty($series['logo'])): ?>
                <div style="margin-top: var(--space-md); padding: var(--space-md); background: var(--color-bg-secondary); border-radius: var(--radius-md);">
                    <strong style="display: block; margin-bottom: var(--space-sm);">Nuvarande logotyp:</strong>
                    <img src="<?= htmlspecialchars($series['logo']) ?>" alt="Logotyp" style="max-width: 200px; max-height: 100px; border-radius: var(--radius-sm);">
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stats (only for existing series) -->
    <?php if (!$isNew): ?>
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px;"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                Statistik
            </h2>
        </div>
        <div class="admin-card-body">
            <div class="grid grid-stats grid-gap-md">
                <div class="admin-stat-card">
                    <div class="admin-stat-content">
                        <div class="admin-stat-value"><?= $eventsCount ?></div>
                        <div class="admin-stat-label">Events</div>
                    </div>
                </div>
                <div class="admin-stat-card">
                    <div class="admin-stat-content">
                        <div class="admin-stat-value"><?= htmlspecialchars($series['year'] ?? '-') ?></div>
                        <div class="admin-stat-label">År</div>
                    </div>
                </div>
            </div>

            <div style="margin-top: var(--space-lg);">
                <a href="/admin/events?series_id=<?= $id ?>" class="btn-admin btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                    Visa events i denna serie
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="admin-card">
        <div class="admin-card-body">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-md);">
                <div style="display: flex; gap: var(--space-sm);">
                    <a href="/admin/series" class="btn-admin btn-admin-secondary">Avbryt</a>
                    <button type="submit" class="btn-admin btn-admin-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <?= $isNew ? 'Skapa Serie' : 'Spara Ändringar' ?>
                    </button>
                </div>

                <?php if (!$isNew): ?>
                <button type="button" onclick="deleteSeries(<?= $id ?>, '<?= addslashes($series['name']) ?>')"
                        class="btn-admin btn-admin-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                    Ta bort serie
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>
const csrfToken = '<?= htmlspecialchars(generate_csrf_token()) ?>';
const currentStatus = '<?= htmlspecialchars($series['status'] ?? 'planning') ?>';
const isNewSeries = <?= $isNew ? 'true' : 'false' ?>;

// Auto-generate series name from brand (without year - year shown as badge)
function updateSeriesName() {
    if (!isNewSeries) return; // Only auto-update for new series

    const brandSelect = document.getElementById('brand_id');
    const nameInput = document.getElementById('name');

    if (!brandSelect || !nameInput) return;

    const selectedOption = brandSelect.options[brandSelect.selectedIndex];
    const brandName = selectedOption?.dataset?.name || '';

    if (brandName) {
        nameInput.value = brandName;
    }
}

// Handle form submission - check if status changed to completed
document.querySelector('form').addEventListener('submit', function(e) {
    const statusSelect = document.getElementById('status');
    const newStatus = statusSelect.value;
    const calculateChampionsField = document.getElementById('calculate_champions');

    // Only show dialog if status is being changed TO completed (wasn't before)
    if (newStatus === 'completed' && currentStatus !== 'completed') {
        e.preventDefault();

        // Show confirmation dialog
        const confirmCalc = confirm(
            'Du markerar serien som AVSLUTAD.\n\n' +
            'Vill du räkna seriemästare nu?\n\n' +
            'OBS: Se till att ALLA resultat är importerade innan du bekräftar!\n\n' +
            'Klicka OK för att beräkna mästare, eller Avbryt för att bara spara status.'
        );

        calculateChampionsField.value = confirmCalc ? '1' : '0';

        // Submit the form
        this.submit();
    }
});

<?php if (!$isNew): ?>
function deleteSeries(id, name) {
    if (!confirm('Är du säker på att du vill ta bort "' + name + '"?\n\nDetta kan inte ångras.')) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/series';
    form.innerHTML = '<input type="hidden" name="action" value="delete">' +
                     '<input type="hidden" name="id" value="' + id + '">' +
                     '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
    document.body.appendChild(form);
    form.submit();
}
<?php endif; ?>
</script>

<style>
.admin-form-textarea {
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg);
    color: var(--color-text);
    font-family: inherit;
    font-size: var(--text-sm);
    resize: vertical;
}

.admin-form-textarea:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-alpha);
}

/* Mobile adjustments */
@media (max-width: 600px) {
    .admin-form-row {
        flex-direction: column;
    }

    .admin-card-body > div[style*="display: flex"] {
        flex-direction: column;
        align-items: stretch !important;
    }

    .admin-card-body > div[style*="display: flex"] > div {
        width: 100%;
    }
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
