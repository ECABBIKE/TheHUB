<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();


// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST')) {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        // Validate required fields
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } else {
            // Prepare series data
            $seriesData = [
                'name' => $name,
                'type' => trim($_POST['type'] ?? ''),
                'status' => $_POST['status'] ?? 'planning',
                'start_date' => !empty($_POST['start_date']) ? trim($_POST['start_date']) : null,
                'end_date' => !empty($_POST['end_date']) ? trim($_POST['end_date']) : null,
                'description' => trim($_POST['description'] ?? ''),
            ];

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
    $editSeries = $db->getOne("SELECT * FROM series WHERE id = ?", [intval($_GET['edit'])]);
}

    // Get series from database
    $series = $db->getAll("SELECT id, name, type, status, start_date, end_date,
                          (SELECT COUNT(*) FROM events WHERE series_id = series.id) as events_count
                          FROM series
                          ORDER BY start_date DESC");
}

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
                <?php endif; ?>
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
                        <form method="POST" id="seriesForm">
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
            <?php endif; ?>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-4 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="trophy" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($series) ?></div>
                    <div class="gs-stat-label">Totalt serier</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($series, fn($s) => $s['status'] === 'active')) ?>
                    </div>
                    <div class="gs-stat-label">Aktiva</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($series, 'events_count')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt events</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                    <div class="gs-stat-number">~1,200</div>
                    <div class="gs-stat-label">Deltagare</div>
                </div>
            </div>

            <!-- Series Table -->
            <div class="gs-card">
                <div class="gs-table-responsive">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>
                                    <i data-lucide="trophy"></i>
                                    Namn
                                </th>
                                <th>Typ</th>
                                <th>Startdatum</th>
                                <th>Slutdatum</th>
                                <th>
                                    <i data-lucide="calendar"></i>
                                    Events
                                </th>
                                <th>Status</th>
                                <th style="width: 150px; text-align: right;">Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($series as $serie): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($serie['name']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="gs-badge gs-badge-primary">
                                            <i data-lucide="flag"></i>
                                            <?= h($serie['type']) ?>
                                        </span>
                                    </td>
                                    <td class="gs-text-secondary" style="font-family: monospace;">
                                        <?= date('d M Y', strtotime($serie['start_date'])) ?>
                                    </td>
                                    <td class="gs-text-secondary" style="font-family: monospace;">
                                        <?= date('d M Y', strtotime($serie['end_date'])) ?>
                                    </td>
                                    <td class="gs-text-center">
                                        <strong class="gs-text-primary"><?= $serie['events_count'] ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                        $statusMap = [
                                            'planning' => ['badge' => 'secondary', 'icon' => 'clock', 'text' => 'Planering'],
                                            'active' => ['badge' => 'success', 'icon' => 'check-circle', 'text' => 'Aktiv'],
                                            'completed' => ['badge' => 'primary', 'icon' => 'check-circle-2', 'text' => 'Avslutad'],
                                            'cancelled' => ['badge' => 'secondary', 'icon' => 'x-circle', 'text' => 'Inställd']
                                        ];
                                        $statusInfo = $statusMap[$serie['status']] ?? ['badge' => 'secondary', 'icon' => 'help-circle', 'text' => ucfirst($serie['status'])];
                                        ?>
                                        <span class="gs-badge gs-badge-<?= $statusInfo['badge'] ?>">
                                            <i data-lucide="<?= $statusInfo['icon'] ?>"></i>
                                            <?= $statusInfo['text'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        
                                            <span class="gs-badge gs-badge-secondary">Demo</span>
                                        <?php else: ?>
                                            <div class="gs-flex gs-gap-sm gs-justify-end">
                                                <a
                                                    href="/admin/events.php?series_id=<?= $serie['id'] ?>"
                                                    class="gs-btn gs-btn-sm gs-btn-outline"
                                                    title="Visa events"
                                                >
                                                    <i data-lucide="calendar"></i>
                                                    <?= $serie['events_count'] ?>
                                                </a>
                                                <button
                                                    type="button"
                                                    class="gs-btn gs-btn-sm gs-btn-outline"
                                                    onclick="editSeries(<?= $serie['id'] ?>)"
                                                    title="Redigera"
                                                >
                                                    <i data-lucide="edit"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                    onclick="deleteSeries(<?= $serie['id'] ?>, '<?= addslashes(h($serie['name'])) ?>')"
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
        <?php endif; ?>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
