<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();


// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        // Validate required fields
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } else {
            // Handle logo upload
            $logoPath = null;
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
            ];

            // Only add format if column exists
            $formatColumnExists = false;
            try {
                $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'format'");
                $formatColumnExists = !empty($columns);
            } catch (Exception $e) {}

            if ($formatColumnExists) {
                $seriesData['format'] = $_POST['format'] ?? 'Championship';
            }

            // Add logo path if uploaded
            if ($logoPath) {
                $seriesData['logo'] = $logoPath;
            }

            try {
                if ($action === 'create') {
                    $db->insert('series', $seriesData);
                    $message = 'Serie skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('series', $seriesData, 'id = ?', [$id]);
                    $message = 'Serie uppdaterad!';
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
            $db->delete('series', 'id = ?', [$id]);
            $message = 'Serie borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Check if editing a series
$editSeries = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editSeries = $db->getRow("SELECT * FROM series WHERE id = ?", [intval($_GET['edit'])]);
}

// Get filter parameters
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

// Build WHERE clause
$where = [];
$params = [];

if ($filterYear) {
    $where[] = "YEAR(start_date) = ?";
    $params[] = $filterYear;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Check if format column exists
$formatColumnExists = false;
try {
    $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'format'");
    $formatColumnExists = !empty($columns);
} catch (Exception $e) {
    // Column doesn't exist, that's ok
}

// Check if series_events table exists
$seriesEventsTableExists = false;
try {
    $tables = $db->getAll("SHOW TABLES LIKE 'series_events'");
    $seriesEventsTableExists = !empty($tables);
} catch (Exception $e) {
    // Table doesn't exist, that's ok
}

// Get series from database
$formatSelect = $formatColumnExists ? ', format' : ', "Championship" as format';
$eventsCountSelect = $seriesEventsTableExists
    ? '(SELECT COUNT(*) FROM series_events WHERE series_id = series.id)'
    : '0';

$sql = "SELECT id, name, type{$formatSelect}, status, start_date, end_date, logo, organizer,
        {$eventsCountSelect} as events_count
        FROM series
        {$whereClause}
        ORDER BY start_date DESC";

$series = $db->getAll($sql, $params);

// Get all years from series
$allYears = $db->getAll("SELECT DISTINCT YEAR(start_date) as year FROM series WHERE start_date IS NOT NULL ORDER BY year DESC");

// Count unique participants in active series
$uniqueParticipants = 0;
if ($seriesEventsTableExists) {
    // Count unique riders from results where event is in an active series
    $participantCount = $db->getRow("
        SELECT COUNT(DISTINCT r.cyclist_id) as unique_riders
        FROM results r
        INNER JOIN series_events se ON r.event_id = se.event_id
        INNER JOIN series s ON se.series_id = s.id
        WHERE s.status = 'active'
    ");
    $uniqueParticipants = $participantCount['unique_riders'] ?? 0;
}

$pageTitle = 'Serier';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
                <h1 class="gs-h2">
                    <i data-lucide="trophy"></i>
                    Serier (<?= count($series) ?>)
                </h1>
                <button type="button" class="gs-btn gs-btn-primary" onclick="openSeriesModal()">
                    <i data-lucide="plus"></i>
                    Ny Serie
                </button>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                    <?= h($message) ?>
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
                    </form>

                    <!-- Active Filters Info -->
                    <?php if ($filterYear): ?>
                        <div class="gs-mt-md gs-filter-active">
                            <div class="gs-flex gs-items-center gs-gap-sm gs-flex-wrap">
                                <span class="gs-text-sm gs-text-secondary">Visar:</span>
                                <span class="gs-badge gs-badge-accent"><?= $filterYear ?></span>
                                <a href="/admin/series.php" class="gs-btn gs-btn-sm gs-btn-outline">
                                    <i data-lucide="x"></i>
                                    Visa alla
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Series Modal -->
                <div id="seriesModal" class="gs-modal gs-hidden">
                    <div class="gs-modal-overlay" onclick="closeSeriesModal()"></div>
                    <div class="gs-modal-content gs-modal-md">
                        <div class="gs-modal-header">
                            <h2 class="gs-modal-title" id="modalTitle">
                                <i data-lucide="trophy"></i>
                                <span id="modalTitleText">Ny Serie</span>
                            </h2>
                            <button type="button" class="gs-modal-close" onclick="closeSeriesModal()">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                        <form method="POST" id="seriesForm" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" id="formAction" value="create">
                            <input type="hidden" name="id" id="seriesId" value="">

                            <div class="gs-modal-body">
                                <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                                    <!-- Name (Required) -->
                                    <div>
                                        <label for="name" class="gs-label">
                                            <i data-lucide="trophy"></i>
                                            Namn <span class="gs-text-error">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="name"
                                            name="name"
                                            class="gs-input"
                                            required
                                            placeholder="T.ex. GravitySeries 2025"
                                        >
                                    </div>

                                    <!-- Type -->
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
                                            placeholder="T.ex. XC, Landsväg, MTB"
                                        >
                                    </div>

                                    <!-- Format -->
                                    <div>
                                        <label for="format" class="gs-label">
                                            <i data-lucide="trophy"></i>
                                            Format (Kvalpoäng)
                                        </label>
                                        <select id="format" name="format" class="gs-input">
                                            <option value="Championship">Championship (Individuellt)</option>
                                            <option value="Team">Team</option>
                                        </select>
                                        <p class="gs-text-xs gs-text-secondary gs-mt-xs">
                                            Hur kvalificeringspoäng räknas för denna serie
                                        </p>
                                    </div>

                                    <!-- Status -->
                                    <div>
                                        <label for="status" class="gs-label">
                                            <i data-lucide="activity"></i>
                                            Status
                                        </label>
                                        <select id="status" name="status" class="gs-input">
                                            <option value="planning">Planering</option>
                                            <option value="active">Aktiv</option>
                                            <option value="completed">Avslutad</option>
                                            <option value="cancelled">Inställd</option>
                                        </select>
                                    </div>

                                    <!-- Start and End Dates -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="start_date" class="gs-label">
                                                <i data-lucide="calendar"></i>
                                                Startdatum
                                            </label>
                                            <input
                                                type="date"
                                                id="start_date"
                                                name="start_date"
                                                class="gs-input"
                                            >
                                        </div>
                                        <div>
                                            <label for="end_date" class="gs-label">
                                                <i data-lucide="calendar"></i>
                                                Slutdatum
                                            </label>
                                            <input
                                                type="date"
                                                id="end_date"
                                                name="end_date"
                                                class="gs-input"
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
                                            placeholder="Beskriv serien..."
                                        ></textarea>
                                    </div>

                                    <!-- Organizer -->
                                    <div>
                                        <label for="organizer" class="gs-label">
                                            <i data-lucide="users"></i>
                                            Arrangör/Delegat
                                        </label>
                                        <input
                                            type="text"
                                            id="organizer"
                                            name="organizer"
                                            class="gs-input"
                                            placeholder="T.ex. Svenska Cykelförbundet, Lokala klubben"
                                        >
                                    </div>

                                    <!-- Logo Upload -->
                                    <div>
                                        <label for="logo" class="gs-label">
                                            <i data-lucide="image"></i>
                                            Logotyp
                                        </label>
                                        <input
                                            type="file"
                                            id="logo"
                                            name="logo"
                                            class="gs-input"
                                            accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,image/svg+xml"
                                        >
                                        <small class="gs-text-secondary">
                                            Godkända format: JPG, PNG, GIF, WebP, SVG. Max 5MB.
                                        </small>
                                        <div id="currentLogo" class="gs-logo-preview">
                                            <strong>Nuvarande logotyp:</strong><br>
                                            <img id="currentLogoImg" src="" alt="Logotyp">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="gs-modal-footer">
                                <button type="button" class="gs-btn gs-btn-outline" onclick="closeSeriesModal()">
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

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-md gs-mb-md">
                <div class="gs-stat-card gs-stat-card-content">
                    <i data-lucide="trophy" class="gs-stat-icon-primary"></i>
                    <div class="gs-stat-number gs-stat-number-xl"><?= count($series) ?></div>
                    <div class="gs-stat-label gs-stat-label-sm">Totalt serier</div>
                </div>
                <div class="gs-stat-card gs-stat-card-content">
                    <i data-lucide="check-circle" class="gs-stat-icon-success"></i>
                    <div class="gs-stat-number gs-stat-number-xl">
                        <?= count(array_filter($series, fn($s) => $s['status'] === 'active')) ?>
                    </div>
                    <div class="gs-stat-label gs-stat-label-sm">Aktiva</div>
                </div>
                <div class="gs-stat-card gs-stat-card-content">
                    <i data-lucide="calendar" class="gs-stat-icon-accent"></i>
                    <div class="gs-stat-number gs-stat-number-xl">
                        <?= array_sum(array_column($series, 'events_count')) ?>
                    </div>
                    <div class="gs-stat-label gs-stat-label-sm">Totalt events</div>
                </div>
                <div class="gs-stat-card gs-stat-card-content">
                    <i data-lucide="users" class="gs-stat-icon-warning"></i>
                    <div class="gs-stat-number gs-stat-number-xl">
                        <?= number_format($uniqueParticipants, 0, ',', ' ') ?>
                    </div>
                    <div class="gs-stat-label gs-stat-label-sm">Unika deltagare</div>
                </div>
            </div>

            <!-- Series List -->
            <div class="gs-card">
                <div class="gs-card-content">
                    <?php if (empty($series)): ?>
                        <div class="gs-alert gs-alert-warning">
                            <p>Inga serier hittades.</p>
                        </div>
                    <?php else: ?>
                        <div class="gs-table-scrollable">
                            <table class="gs-table">
                                <thead>
                                    <tr>
                                        <th>Namn</th>
                                        <th>Typ</th>
                                        <th>Format</th>
                                        <th>Status</th>
                                        <th>Startdatum</th>
                                        <th>Slutdatum</th>
                                        <th>Arrangör</th>
                                        <th>Events</th>
                                        <th class="gs-table-col-100">Åtgärder</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($series as $serie): ?>
                                        <?php
                                        $statusMap = [
                                            'planning' => ['badge' => 'secondary', 'text' => 'Planering'],
                                            'active' => ['badge' => 'success', 'text' => 'Aktiv'],
                                            'completed' => ['badge' => 'primary', 'text' => 'Avslutad'],
                                            'cancelled' => ['badge' => 'secondary', 'text' => 'Inställd']
                                        ];
                                        $statusInfo = $statusMap[$serie['status']] ?? ['badge' => 'secondary', 'text' => ucfirst($serie['status'])];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($serie['name']) ?></strong>
                                            </td>
                                            <td><?= h($serie['type'] ?? '-') ?></td>
                                            <td>
                                                <span class="gs-badge"><?= h($serie['format'] ?? 'Championship') ?></span>
                                            </td>
                                            <td>
                                                <span class="gs-badge gs-badge-<?= $statusInfo['badge'] ?>">
                                                    <?= $statusInfo['text'] ?>
                                                </span>
                                            </td>
                                            <td><?= $serie['start_date'] ? date('d M Y', strtotime($serie['start_date'])) : '-' ?></td>
                                            <td><?= $serie['end_date'] ? date('d M Y', strtotime($serie['end_date'])) : '-' ?></td>
                                            <td><?= h($serie['organizer'] ?? '-') ?></td>
                                            <td>
                                                <span class="gs-text-sm"><?= $serie['events_count'] ?></span>
                                            </td>
                                            <td>
                                                <div class="gs-flex gs-gap-sm">
                                                    <?php if ($seriesEventsTableExists): ?>
                                                    <a
                                                        href="/admin/series-events.php?series_id=<?= $serie['id'] ?>"
                                                        class="gs-btn gs-btn-sm gs-btn-primary"
                                                        title="Hantera events"
                                                    >
                                                        <i data-lucide="calendar" class="gs-icon-14"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    <a
                                                        href="?edit=<?= $serie['id'] ?>"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        title="Redigera"
                                                    >
                                                        <i data-lucide="edit" class="gs-icon-14"></i>
                                                    </a>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Är du säker på att du vill ta bort &quot;<?= addslashes(h($serie['name'])) ?>&quot;?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= $serie['id'] ?>">
                                                        <button
                                                            type="submit"
                                                            class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                            title="Ta bort"
                                                        >
                                                            <i data-lucide="trash-2" class="gs-icon-14"></i>
                                                        </button>
                                                    </form>
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

        <script>
            // Open modal for creating new series
            function openSeriesModal() {
                document.getElementById('seriesModal').style.display = 'flex';
                document.getElementById('seriesForm').reset();
                document.getElementById('formAction').value = 'create';
                document.getElementById('seriesId').value = '';
                document.getElementById('modalTitleText').textContent = 'Ny Serie';
                document.getElementById('submitButtonText').textContent = 'Skapa';

                // Re-initialize Lucide icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }

            // Close modal
            function closeSeriesModal() {
                document.getElementById('seriesModal').style.display = 'none';
            }

            // Edit series - reload page with edit parameter
            function editSeries(id) {
                window.location.href = `?edit=${id}`;
            }

            // Delete series
            function deleteSeries(id, name) {
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
                const modal = document.getElementById('seriesModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeSeriesModal();
                        }
                    });
                }

                // Handle edit mode from URL parameter
                <?php if ($editSeries): ?>
                    // Populate form with series data
                    document.getElementById('formAction').value = 'update';
                    document.getElementById('seriesId').value = '<?= $editSeries['id'] ?>';
                    document.getElementById('name').value = '<?= addslashes($editSeries['name']) ?>';
                    document.getElementById('type').value = '<?= addslashes($editSeries['type'] ?? '') ?>';
                    document.getElementById('format').value = '<?= $editSeries['format'] ?? 'Championship' ?>';
                    document.getElementById('status').value = '<?= $editSeries['status'] ?? 'planning' ?>';
                    document.getElementById('start_date').value = '<?= $editSeries['start_date'] ?? '' ?>';
                    document.getElementById('end_date').value = '<?= $editSeries['end_date'] ?? '' ?>';
                    document.getElementById('description').value = '<?= addslashes($editSeries['description'] ?? '') ?>';
                    document.getElementById('organizer').value = '<?= addslashes($editSeries['organizer'] ?? '') ?>';

                    // Show current logo if exists
                    <?php if (!empty($editSeries['logo'])): ?>
                        document.getElementById('currentLogo').style.display = 'block';
                        document.getElementById('currentLogoImg').src = '<?= $editSeries['logo'] ?>';
                    <?php endif; ?>

                    // Update modal title and button
                    document.getElementById('modalTitleText').textContent = 'Redigera Serie';
                    document.getElementById('submitButtonText').textContent = 'Uppdatera';

                    // Open modal
                    document.getElementById('seriesModal').style.display = 'flex';

                    // Re-initialize Lucide icons
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                <?php endif; ?>
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeSeriesModal();
                }
            });
        </script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
