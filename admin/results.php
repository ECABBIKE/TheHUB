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
        $event_id = !empty($_POST['event_id']) ? intval($_POST['event_id']) : null;
        $cyclist_id = !empty($_POST['cyclist_id']) ? intval($_POST['cyclist_id']) : null;

        if (empty($event_id) || empty($cyclist_id)) {
            $message = 'Event och deltagare är obligatoriska';
            $messageType = 'error';
        } else {
            // Prepare result data
            $resultData = [
                'event_id' => $event_id,
                'cyclist_id' => $cyclist_id,
                'category_id' => !empty($_POST['category_id']) ? intval($_POST['category_id']) : null,
                'position' => !empty($_POST['position']) ? intval($_POST['position']) : null,
                'finish_time' => !empty($_POST['finish_time']) ? trim($_POST['finish_time']) : null,
                'bib_number' => trim($_POST['bib_number'] ?? ''),
                'status' => $_POST['status'] ?? 'finished',
                'points' => !empty($_POST['points']) ? intval($_POST['points']) : 0,
                'time_behind' => !empty($_POST['time_behind']) ? trim($_POST['time_behind']) : null,
                'average_speed' => !empty($_POST['average_speed']) ? floatval($_POST['average_speed']) : null,
                'notes' => trim($_POST['notes'] ?? ''),
            ];

            try {
                if ($action === 'create') {
                    $db->insert('results', $resultData);
                    $message = 'Resultat skapat!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('results', $resultData, 'id = ?', [$id]);
                    $message = 'Resultat uppdaterat!';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                // Check for unique constraint violation
                if (strpos($e->getMessage(), 'unique_event_cyclist') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $message = 'Detta resultat finns redan (en deltagare kan bara ha ett resultat per tävling)';
                } else {
                    $message = 'Ett fel uppstod: ' . $e->getMessage();
                }
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        try {
            $db->delete('results', 'id = ?', [$id]);
            $message = 'Resultat borttaget!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Handle filters
$event_id = $_GET['event_id'] ?? '';
$category_id = $_GET['category_id'] ?? '';
$search = $_GET['search'] ?? '';

// Fetch dropdown data and check for edit mode (if not in demo mode)
$all_events = [];
$all_riders = [];
$all_categories = [];
$editResult = null;
    $all_events = $db->getAll("SELECT id, name, event_date FROM events ORDER BY event_date DESC LIMIT 100");
    $all_riders = $db->getAll("SELECT id, CONCAT(firstname, ' ', lastname) as name, license_number FROM riders ORDER BY lastname, firstname LIMIT 500");
    $all_categories = $db->getAll("SELECT id, name FROM categories ORDER BY name");

    // Check if editing a result
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $editResult = $db->getOne("SELECT * FROM results WHERE id = ?", [intval($_GET['edit'])]);
    }
}

    $where = [];
    $params = [];

    if ($event_id) {
        $where[] = "r.event_id = ?";
        $params[] = $event_id;
    }

    if ($category_id) {
        $where[] = "r.category_id = ?";
        $params[] = $category_id;
    }

    if ($search) {
        $where[] = "(CONCAT(c.firstname, ' ', c.lastname) LIKE ? OR c.license_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Get results with all related data
    $sql = "SELECT
                r.id,
                r.position,
                r.bib_number,
                r.finish_time,
                r.status,
                r.points,
                e.name as event_name,
                e.event_date,
                e.id as event_id,
                CONCAT(c.firstname, ' ', c.lastname) as rider_name,
                c.id as rider_id,
                c.birth_year,
                cl.name as club_name,
                cat.name as category_name,
                cat.id as category_id
            FROM results r
            JOIN events e ON r.event_id = e.id
            JOIN riders c ON r.cyclist_id = c.id
            LEFT JOIN clubs cl ON c.club_id = cl.id
            LEFT JOIN categories cat ON r.category_id = cat.id
            $whereClause
            ORDER BY e.event_date DESC, r.position ASC
            LIMIT 200";

    $results = $db->getAll($sql, $params);

    // Get events for filter
    $events = $db->getAll("SELECT id, name, event_date FROM events ORDER BY event_date DESC LIMIT 50");

    // Get categories for filter
    $categories = $db->getAll("SELECT id, name FROM categories ORDER BY name");
}

$pageTitle = 'Resultat';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="trophy"></i>
                    Resultat
                </h1>
                    <button type="button" class="gs-btn gs-btn-primary" onclick="openResultModal()">
                        <i data-lucide="plus"></i>
                        Nytt Resultat
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

            <!-- Result Modal -->
                <div id="resultModal" class="gs-modal" style="display: none;">
                    <div class="gs-modal-overlay" onclick="closeResultModal()"></div>
                    <div class="gs-modal-content" style="max-width: 700px;">
                        <div class="gs-modal-header">
                            <h2 class="gs-modal-title" id="modalTitle">
                                <i data-lucide="trophy"></i>
                                <span id="modalTitleText">Nytt Resultat</span>
                            </h2>
                            <button type="button" class="gs-modal-close" onclick="closeResultModal()">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                        <form method="POST" id="resultForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" id="formAction" value="create">
                            <input type="hidden" name="id" id="resultId" value="">

                            <div class="gs-modal-body">
                                <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                                    <!-- Event & Rider (Required) -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="event_id" class="gs-label">
                                                <i data-lucide="calendar"></i>
                                                Tävling <span class="gs-text-error">*</span>
                                            </label>
                                            <select id="event_id" name="event_id" class="gs-input" required>
                                                <option value="">Välj tävling...</option>
                                                <?php foreach ($all_events as $evt): ?>
                                                    <option value="<?= $evt['id'] ?>">
                                                        <?= h($evt['name']) ?> (<?= formatDate($evt['event_date'], 'Y-m-d') ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="cyclist_id" class="gs-label">
                                                <i data-lucide="user"></i>
                                                Deltagare <span class="gs-text-error">*</span>
                                            </label>
                                            <select id="cyclist_id" name="cyclist_id" class="gs-input" required>
                                                <option value="">Välj deltagare...</option>
                                                <?php foreach ($all_riders as $rider): ?>
                                                    <option value="<?= $rider['id'] ?>">
                                                        <?= h($rider['name']) ?>
                                                        <?php if ($rider['license_number']): ?>
                                                            (<?= h($rider['license_number']) ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Category -->
                                    <div>
                                        <label for="category_id" class="gs-label">
                                            <i data-lucide="tag"></i>
                                            Kategori
                                        </label>
                                        <select id="category_id" name="category_id" class="gs-input">
                                            <option value="">Ingen kategori</option>
                                            <?php foreach ($all_categories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>">
                                                    <?= h($cat['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Race Results -->
                                    <div class="gs-grid gs-grid-cols-3 gs-gap-md">
                                        <div>
                                            <label for="position" class="gs-label">
                                                <i data-lucide="hash"></i>
                                                Placering
                                            </label>
                                            <input
                                                type="number"
                                                id="position"
                                                name="position"
                                                class="gs-input"
                                                min="1"
                                                placeholder="T.ex. 1"
                                            >
                                        </div>
                                        <div>
                                            <label for="bib_number" class="gs-label">
                                                <i data-lucide="ticket"></i>
                                                Startnummer
                                            </label>
                                            <input
                                                type="text"
                                                id="bib_number"
                                                name="bib_number"
                                                class="gs-input"
                                                placeholder="T.ex. 101"
                                            >
                                        </div>
                                        <div>
                                            <label for="status" class="gs-label">
                                                <i data-lucide="flag"></i>
                                                Status
                                            </label>
                                            <select id="status" name="status" class="gs-input">
                                                <option value="finished">Finished</option>
                                                <option value="dnf">DNF</option>
                                                <option value="dns">DNS</option>
                                                <option value="dq">DQ</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Timing -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="finish_time" class="gs-label">
                                                <i data-lucide="clock"></i>
                                                Sluttid (HH:MM:SS)
                                            </label>
                                            <input
                                                type="text"
                                                id="finish_time"
                                                name="finish_time"
                                                class="gs-input"
                                                placeholder="T.ex. 01:45:23"
                                                pattern="[0-9]{2}:[0-9]{2}:[0-9]{2}"
                                            >
                                        </div>
                                        <div>
                                            <label for="time_behind" class="gs-label">
                                                <i data-lucide="timer"></i>
                                                Tid efter (HH:MM:SS)
                                            </label>
                                            <input
                                                type="text"
                                                id="time_behind"
                                                name="time_behind"
                                                class="gs-input"
                                                placeholder="T.ex. 00:01:22"
                                                pattern="[0-9]{2}:[0-9]{2}:[0-9]{2}"
                                            >
                                        </div>
                                    </div>

                                    <!-- Additional Data -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="points" class="gs-label">
                                                <i data-lucide="star"></i>
                                                Poäng
                                            </label>
                                            <input
                                                type="number"
                                                id="points"
                                                name="points"
                                                class="gs-input"
                                                min="0"
                                                placeholder="T.ex. 100"
                                                value="0"
                                            >
                                        </div>
                                        <div>
                                            <label for="average_speed" class="gs-label">
                                                <i data-lucide="gauge"></i>
                                                Medelhastighet (km/h)
                                            </label>
                                            <input
                                                type="number"
                                                id="average_speed"
                                                name="average_speed"
                                                class="gs-input"
                                                step="0.01"
                                                min="0"
                                                placeholder="T.ex. 42.5"
                                            >
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div>
                                        <label for="notes" class="gs-label">
                                            <i data-lucide="file-text"></i>
                                            Anteckningar
                                        </label>
                                        <textarea
                                            id="notes"
                                            name="notes"
                                            class="gs-input"
                                            rows="3"
                                            placeholder="Eventuella anteckningar..."
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="gs-modal-footer">
                                <button type="button" class="gs-btn gs-btn-outline" onclick="closeResultModal()">
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

            <!-- Filters -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-grid gs-grid-cols-1 gs-md-grid-cols-4 gs-gap-md">
                        <div>
                            <label for="event_id" class="gs-label">
                                <i data-lucide="calendar"></i>
                                Tävling
                            </label>
                            <select id="event_id" name="event_id" class="gs-input">
                                <option value="">Alla tävlingar</option>
                                <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['id'] ?>" <?= $event_id == $event['id'] ? 'selected' : '' ?>>
                                        <?= h($event['name']) ?> (<?= formatDate($event['event_date'], 'Y-m-d') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="category_id" class="gs-label">
                                <i data-lucide="tag"></i>
                                Kategori
                            </label>
                            <select id="category_id" name="category_id" class="gs-input">
                                <option value="">Alla kategorier</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>" <?= $category_id == $category['id'] ? 'selected' : '' ?>>
                                        <?= h($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="search" class="gs-label">
                                <i data-lucide="search"></i>
                                Sök deltagare
                            </label>
                            <input
                                type="text"
                                id="search"
                                name="search"
                                class="gs-input"
                                placeholder="Namn eller licens..."
                                value="<?= h($search) ?>"
                            >
                        </div>
                        <div style="display: flex; align-items: flex-end; gap: var(--gs-space-sm);">
                            <button type="submit" class="gs-btn gs-btn-primary gs-flex-1">
                                <i data-lucide="filter"></i>
                                Filtrera
                            </button>
                            <?php if ($event_id || $category_id || $search): ?>
                                <a href="/admin/results.php" class="gs-btn gs-btn-outline">
                                    Rensa
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-4 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="list" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($results) ?></div>
                    <div class="gs-stat-label">Resultat</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_unique(array_column($results, 'event_id'))) ?>
                    </div>
                    <div class="gs-stat-label">Tävlingar</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_unique(array_column($results, 'rider_id'))) ?>
                    </div>
                    <div class="gs-stat-label">Unika deltagare</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="award" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($results, fn($r) => $r['position'] >= 1 && $r['position'] <= 3)) ?>
                    </div>
                    <div class="gs-stat-label">Pallplatser</div>
                </div>
            </div>

            <!-- Results Table -->
            <?php if (empty($results)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="trophy" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga resultat hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">
                                        <i data-lucide="hash"></i>
                                        Plac
                                    </th>
                                    <th>
                                        <i data-lucide="calendar"></i>
                                        Tävling
                                    </th>
                                    <th>
                                        <i data-lucide="user"></i>
                                        Deltagare
                                    </th>
                                    <th>
                                        <i data-lucide="building"></i>
                                        Klubb
                                    </th>
                                    <th>
                                        <i data-lucide="tag"></i>
                                        Kategori
                                    </th>
                                    <th>
                                        <i data-lucide="clock"></i>
                                        Tid
                                    </th>
                                    <th>
                                        <i data-lucide="star"></i>
                                        Poäng
                                    </th>
                                    <th style="width: 120px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr class="<?= $result['position'] >= 1 && $result['position'] <= 3 ? 'gs-podium-' . $result['position'] : '' ?>">
                                        <td style="font-weight: 700;">
                                            <?php if ($result['position']): ?>
                                                <span class="gs-text-primary"><?= $result['position'] ?></span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= h($result['event_name']) ?></strong><br>
                                            <span class="gs-text-secondary gs-text-xs">
                                                <?= formatDate($result['event_date'], 'd M Y') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= h($result['rider_name']) ?></strong>
                                            <?php if ($result['birth_year']): ?>
                                                <span class="gs-text-secondary gs-text-xs">
                                                    (<?= $result['birth_year'] ?>)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-secondary gs-text-sm">
                                            <?= h($result['club_name'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <span class="gs-badge gs-badge-primary gs-text-xs">
                                                <?= h($result['category_name'] ?? '-') ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-secondary" style="font-family: monospace;">
                                            <?= formatTime($result['finish_time']) ?>
                                        </td>
                                        <td class="gs-text-center">
                                            <?php if ($result['points']): ?>
                                                <strong class="gs-text-primary"><?= $result['points'] ?></strong>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            
                                                <span class="gs-badge gs-badge-secondary">Demo</span>
                                            <?php else: ?>
                                                <div class="gs-flex gs-gap-sm gs-justify-end">
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        onclick="editResult(<?= $result['id'] ?>)"
                                                        title="Redigera"
                                                    >
                                                        <i data-lucide="edit"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                        onclick="deleteResult(<?= $result['id'] ?>, '<?= addslashes(h($result['rider_name'])) ?>')"
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

        <script>
            // Open modal for creating new result
            function openResultModal() {
                document.getElementById('resultModal').style.display = 'flex';
                document.getElementById('resultForm').reset();
                document.getElementById('formAction').value = 'create';
                document.getElementById('resultId').value = '';
                document.getElementById('modalTitleText').textContent = 'Nytt Resultat';
                document.getElementById('submitButtonText').textContent = 'Skapa';

                // Re-initialize Lucide icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }

            // Close modal
            function closeResultModal() {
                document.getElementById('resultModal').style.display = 'none';
            }

            // Edit result - reload page with edit parameter
            function editResult(id) {
                window.location.href = `?edit=${id}`;
            }

            // Delete result
            function deleteResult(id, riderName) {
                if (!confirm(`Är du säker på att du vill ta bort resultatet för "${riderName}"?`)) {
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
                const modal = document.getElementById('resultModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeResultModal();
                        }
                    });
                }

                // Handle edit mode from URL parameter
                <?php if ($editResult): ?>
                    // Populate form with result data
                    document.getElementById('formAction').value = 'update';
                    document.getElementById('resultId').value = '<?= $editResult['id'] ?>';
                    document.getElementById('event_id').value = '<?= $editResult['event_id'] ?? '' ?>';
                    document.getElementById('cyclist_id').value = '<?= $editResult['cyclist_id'] ?? '' ?>';
                    document.getElementById('category_id').value = '<?= $editResult['category_id'] ?? '' ?>';
                    document.getElementById('position').value = '<?= $editResult['position'] ?? '' ?>';
                    document.getElementById('finish_time').value = '<?= $editResult['finish_time'] ?? '' ?>';
                    document.getElementById('bib_number').value = '<?= addslashes($editResult['bib_number'] ?? '') ?>';
                    document.getElementById('status').value = '<?= $editResult['status'] ?? 'finished' ?>';
                    document.getElementById('points').value = '<?= $editResult['points'] ?? 0 ?>';
                    document.getElementById('time_behind').value = '<?= $editResult['time_behind'] ?? '' ?>';
                    document.getElementById('average_speed').value = '<?= $editResult['average_speed'] ?? '' ?>';
                    document.getElementById('notes').value = '<?= addslashes($editResult['notes'] ?? '') ?>';

                    // Update modal title and button
                    document.getElementById('modalTitleText').textContent = 'Redigera Resultat';
                    document.getElementById('submitButtonText').textContent = 'Uppdatera';

                    // Open modal
                    document.getElementById('resultModal').style.display = 'flex';

                    // Re-initialize Lucide icons
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                <?php endif; ?>
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeResultModal();
                }
            });
        </script>
        <?php endif; ?>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
