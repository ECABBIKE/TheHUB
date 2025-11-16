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
                'format' => $_POST['format'] ?? 'Championship',
                'status' => $_POST['status'] ?? 'planning',
                'start_date' => !empty($_POST['start_date']) ? trim($_POST['start_date']) : null,
                'end_date' => !empty($_POST['end_date']) ? trim($_POST['end_date']) : null,
                'description' => trim($_POST['description'] ?? ''),
                'organizer' => trim($_POST['organizer'] ?? ''),
            ];

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

// Get series from database
$series = $db->getAll("SELECT id, name, type, format, status, start_date, end_date, logo, organizer,
                      (SELECT COUNT(*) FROM events WHERE series_id = series.id) as events_count
                      FROM series
                      ORDER BY start_date DESC");

$pageTitle = 'Serier';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="trophy"></i>
                    Serier
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

            <!-- Series Modal -->
                <div id="seriesModal" class="gs-modal" style="display: none;">
                    <div class="gs-modal-overlay" onclick="closeSeriesModal()"></div>
                    <div class="gs-modal-content" style="max-width: 700px;">
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
                                        <div id="currentLogo" style="margin-top: 10px; display: none;">
                                            <strong>Nuvarande logotyp:</strong><br>
                                            <img id="currentLogoImg" src="" alt="Logotyp" style="max-width: 200px; max-height: 100px; margin-top: 5px;">
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
                <div class="gs-stat-card" style="padding: var(--gs-space-md);">
                    <i data-lucide="trophy" style="width: 32px; height: 32px; color: var(--gs-primary); margin-bottom: var(--gs-space-sm);"></i>
                    <div class="gs-stat-number" style="font-size: 1.75rem;"><?= count($series) ?></div>
                    <div class="gs-stat-label" style="font-size: 0.813rem;">Totalt serier</div>
                </div>
                <div class="gs-stat-card" style="padding: var(--gs-space-md);">
                    <i data-lucide="check-circle" style="width: 32px; height: 32px; color: var(--gs-success); margin-bottom: var(--gs-space-sm);"></i>
                    <div class="gs-stat-number" style="font-size: 1.75rem;">
                        <?= count(array_filter($series, fn($s) => $s['status'] === 'active')) ?>
                    </div>
                    <div class="gs-stat-label" style="font-size: 0.813rem;">Aktiva</div>
                </div>
                <div class="gs-stat-card" style="padding: var(--gs-space-md);">
                    <i data-lucide="calendar" style="width: 32px; height: 32px; color: var(--gs-accent); margin-bottom: var(--gs-space-sm);"></i>
                    <div class="gs-stat-number" style="font-size: 1.75rem;">
                        <?= array_sum(array_column($series, 'events_count')) ?>
                    </div>
                    <div class="gs-stat-label" style="font-size: 0.813rem;">Totalt events</div>
                </div>
                <div class="gs-stat-card" style="padding: var(--gs-space-md);">
                    <i data-lucide="users" style="width: 32px; height: 32px; color: var(--gs-warning); margin-bottom: var(--gs-space-sm);"></i>
                    <div class="gs-stat-number" style="font-size: 1.75rem;">~1,200</div>
                    <div class="gs-stat-label" style="font-size: 0.813rem;">Deltagare</div>
                </div>
            </div>

            <!-- Series Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                <?php foreach ($series as $serie): ?>
                    <?php
                    $statusMap = [
                        'planning' => ['badge' => 'secondary', 'icon' => 'clock', 'text' => 'Planering'],
                        'active' => ['badge' => 'success', 'icon' => 'check-circle', 'text' => 'Aktiv'],
                        'completed' => ['badge' => 'primary', 'icon' => 'check-circle-2', 'text' => 'Avslutad'],
                        'cancelled' => ['badge' => 'secondary', 'icon' => 'x-circle', 'text' => 'Inställd']
                    ];
                    $statusInfo = $statusMap[$serie['status']] ?? ['badge' => 'secondary', 'icon' => 'help-circle', 'text' => ucfirst($serie['status'])];
                    ?>
                    <div class="gs-card">
                        <div class="gs-card-content" style="padding: var(--gs-space-md);">
                            <!-- Header -->
                            <div class="gs-flex gs-items-center gs-justify-between gs-mb-sm">
                                <h3 class="gs-h5 gs-text-primary" style="margin: 0;">
                                    <?= h($serie['name']) ?>
                                </h3>
                                <span class="gs-badge gs-badge-<?= $statusInfo['badge'] ?> gs-badge-sm">
                                    <i data-lucide="<?= $statusInfo['icon'] ?>" style="width: 12px; height: 12px;"></i>
                                    <?= $statusInfo['text'] ?>
                                </span>
                            </div>

                            <!-- Logo and Organizer -->
                            <?php if (!empty($serie['logo']) || !empty($serie['organizer'])): ?>
                                <div class="gs-flex gs-items-center gs-gap-sm gs-mb-sm">
                                    <?php if (!empty($serie['logo'])): ?>
                                        <img src="<?= h($serie['logo']) ?>" alt="<?= h($serie['name']) ?>" style="max-height: 30px; max-width: 60px; object-fit: contain;">
                                    <?php endif; ?>
                                    <?php if (!empty($serie['organizer'])): ?>
                                        <span class="gs-text-xs gs-text-secondary">
                                            <i data-lucide="users" style="width: 12px; height: 12px;"></i>
                                            <?= h($serie['organizer']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Info -->
                            <div class="gs-flex gs-items-center gs-gap-md gs-mb-sm" style="padding: var(--gs-space-sm) 0; border-top: 1px solid var(--gs-border); border-bottom: 1px solid var(--gs-border);">
                                <div class="gs-flex gs-items-center gs-gap-xs">
                                    <i data-lucide="flag" style="width: 14px; height: 14px; color: var(--gs-text-secondary);"></i>
                                    <span class="gs-text-xs gs-text-secondary"><?= h($serie['type']) ?></span>
                                </div>
                                <div class="gs-flex gs-items-center gs-gap-xs">
                                    <i data-lucide="trophy" style="width: 14px; height: 14px; color: var(--gs-text-secondary);"></i>
                                    <span class="gs-text-xs gs-text-secondary"><?= h($serie['format'] ?? 'Championship') ?></span>
                                </div>
                                <div class="gs-flex gs-items-center gs-gap-xs">
                                    <i data-lucide="calendar" style="width: 14px; height: 14px; color: var(--gs-primary);"></i>
                                    <span class="gs-text-xs gs-text-primary"><?= $serie['events_count'] ?></span>
                                    <span class="gs-text-xs gs-text-secondary">events</span>
                                </div>
                            </div>

                            <!-- Dates -->
                            <div class="gs-flex gs-items-center gs-gap-sm gs-mb-sm gs-text-xs gs-text-secondary">
                                <span><?= date('d M Y', strtotime($serie['start_date'])) ?></span>
                                <span>→</span>
                                <span><?= date('d M Y', strtotime($serie['end_date'])) ?></span>
                            </div>

                            <!-- Actions -->
                            <div class="gs-flex gs-gap-xs">
                                <a
                                    href="/admin/events.php?series_id=<?= $serie['id'] ?>"
                                    class="gs-btn gs-btn-xs gs-btn-primary gs-flex-1"
                                    title="Visa events"
                                >
                                    <i data-lucide="calendar" style="width: 12px; height: 12px;"></i>
                                    Events
                                </a>
                                <button
                                    type="button"
                                    class="gs-btn gs-btn-xs gs-btn-outline"
                                    onclick="editSeries(<?= $serie['id'] ?>)"
                                    title="Redigera"
                                >
                                    <i data-lucide="edit" style="width: 12px; height: 12px;"></i>
                                </button>
                                <button
                                    type="button"
                                    class="gs-btn gs-btn-xs gs-btn-outline gs-btn-danger"
                                    onclick="deleteSeries(<?= $serie['id'] ?>, '<?= addslashes(h($serie['name'])) ?>')"
                                    title="Ta bort"
                                >
                                    <i data-lucide="trash-2" style="width: 12px; height: 12px;"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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
