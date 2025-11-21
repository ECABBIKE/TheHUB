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
            $message = 'Klubbnamn är obligatoriskt';
            $messageType = 'error';
        } else {
            // Prepare club data
            $clubData = [
                'name' => $name,
                'short_name' => trim($_POST['short_name'] ?? ''),
                'region' => trim($_POST['region'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
                'country' => trim($_POST['country'] ?? 'Sverige'),
                'website' => trim($_POST['website'] ?? ''),
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            try {
                if ($action === 'create') {
                    $db->insert('clubs', $clubData);
                    $message = 'Klubb skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('clubs', $clubData, 'id = ?', [$id]);
                    $message = 'Klubb uppdaterad!';
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
            $db->delete('clubs', 'id = ?', [$id]);
            $message = 'Klubb borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Handle search
$search = $_GET['search'] ?? '';

// Check if editing a club
$editClub = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editClub = $db->getRow("SELECT * FROM clubs WHERE id = ?", [intval($_GET['edit'])]);
}

    $where = [];
    $params = [];

    if ($search) {
        $where[] = "name LIKE ?";
        $params[] = "%$search%";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Get clubs with rider count
    $sql = "SELECT
                cl.id,
                cl.name,
                cl.short_name,
                cl.city,
                cl.country,
                cl.active,
                COUNT(DISTINCT c.id) as rider_count
            FROM clubs cl
            LEFT JOIN riders c ON cl.id = c.club_id AND c.active = 1
            $whereClause
            GROUP BY cl.id
            ORDER BY cl.name";

    $clubs = $db->getAll($sql, $params);

$pageTitle = 'Klubbar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="building"></i>
                    Klubbar
                </h1>
                <div class="gs-flex gs-gap-sm">
                    <a href="/admin/import-clubs.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="upload"></i>
                        Importera
                    </a>
                    <button type="button" class="gs-btn gs-btn-primary" onclick="openClubModal()">
                        <i data-lucide="plus"></i>
                        Ny Klubb
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Club Modal -->
                <div id="clubModal" class="gs-modal gs-modal-hidden">
                    <div class="gs-modal-overlay" onclick="closeClubModal()"></div>
                    <div class="gs-modal-content gs-modal-content-md">
                        <div class="gs-modal-header">
                            <h2 class="gs-modal-title" id="modalTitle">
                                <i data-lucide="building"></i>
                                <span id="modalTitleText">Ny Klubb</span>
                            </h2>
                            <button type="button" class="gs-modal-close" onclick="closeClubModal()">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                        <form method="POST" id="clubForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" id="formAction" value="create">
                            <input type="hidden" name="id" id="clubId" value="">

                            <div class="gs-modal-body">
                                <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                                    <!-- Name (Required) -->
                                    <div>
                                        <label for="name" class="gs-label">
                                            <i data-lucide="building"></i>
                                            Namn <span class="gs-text-error">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="name"
                                            name="name"
                                            class="gs-input"
                                            required
                                            placeholder="T.ex. Team GravitySeries"
                                        >
                                    </div>

                                    <!-- Short Name -->
                                    <div>
                                        <label for="short_name" class="gs-label">
                                            <i data-lucide="tag"></i>
                                            Förkortning
                                        </label>
                                        <input
                                            type="text"
                                            id="short_name"
                                            name="short_name"
                                            class="gs-input"
                                            placeholder="T.ex. TGS, UCK"
                                        >
                                    </div>

                                    <!-- Region -->
                                    <div>
                                        <label for="region" class="gs-label">
                                            <i data-lucide="map"></i>
                                            Region
                                        </label>
                                        <input
                                            type="text"
                                            id="region"
                                            name="region"
                                            class="gs-input"
                                            placeholder="T.ex. Västsverige, Mellansverige"
                                        >
                                    </div>

                                    <!-- City and Country -->
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                        <div>
                                            <label for="city" class="gs-label">
                                                <i data-lucide="map-pin"></i>
                                                Stad
                                            </label>
                                            <input
                                                type="text"
                                                id="city"
                                                name="city"
                                                class="gs-input"
                                                placeholder="T.ex. Stockholm"
                                            >
                                        </div>

                                        <div>
                                            <label for="country" class="gs-label">
                                                <i data-lucide="globe"></i>
                                                Land
                                            </label>
                                            <input
                                                type="text"
                                                id="country"
                                                name="country"
                                                class="gs-input"
                                                value="Sverige"
                                                placeholder="Sverige"
                                            >
                                        </div>
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

                                    <!-- Active Status -->
                                    <div>
                                        <label class="gs-checkbox-label">
                                            <input
                                                type="checkbox"
                                                id="active"
                                                name="active"
                                                class="gs-checkbox"
                                                checked
                                            >
                                            <span>
                                                <i data-lucide="check-circle"></i>
                                                Aktiv
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="gs-modal-footer">
                                <button type="button" class="gs-btn gs-btn-outline" onclick="closeClubModal()">
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

            <!-- Search -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" id="searchForm" class="gs-flex gs-gap-md gs-items-center">
                        <div class="gs-flex-1">
                            <div class="gs-input-group">
                                <i data-lucide="search"></i>
                                <input
                                    type="text"
                                    name="search"
                                    id="searchInput"
                                    class="gs-input"
                                    placeholder="Sök efter klubbnamn..."
                                    value="<?= h($search) ?>"
                                    autocomplete="off"
                                >
                            </div>
                        </div>
                        <?php if ($search): ?>
                            <a href="/admin/clubs.php" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="x"></i>
                                Rensa
                            </a>
                        <?php endif; ?>
                        <span id="searchStatus" class="gs-text-xs gs-text-secondary" style="display: none;">Söker...</span>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="building" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($clubs) ?></div>
                    <div class="gs-stat-label">Totalt klubbar</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?php
                        $activeCount = 0;
                        foreach ($clubs as $c) {
                            if ($c['active'] == 1) $activeCount++;
                        }
                        echo $activeCount;
                        ?>
                    </div>
                    <div class="gs-stat-label">Aktiva</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($clubs, 'rider_count')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt medlemmar</div>
                </div>
            </div>

            <!-- Clubs Table -->
            <?php if (empty($clubs)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="building-2" class="gs-icon-xl gs-text-secondary gs-mb-md"></i>
                        <p class="gs-text-secondary">Inga klubbar hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="building"></i>
                                        Namn
                                    </th>
                                    <th>Förkortning</th>
                                    <th>
                                        <i data-lucide="map-pin"></i>
                                        Stad
                                    </th>
                                    <th>
                                        <i data-lucide="globe"></i>
                                        Land
                                    </th>
                                    <th>
                                        <i data-lucide="users"></i>
                                        Medlemmar
                                    </th>
                                    <th class="gs-table-col-actions-lg">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clubs as $club): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($club['name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="gs-badge gs-badge-primary">
                                                <?= h($club['short_name'] ?? substr($club['name'], 0, 3)) ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-secondary"><?= h($club['city'] ?? '-') ?></td>
                                        <td>
                                            <span class="gs-text-secondary">
                                                <?= h($club['country'] ?? 'Sverige') ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-center">
                                            <strong class="gs-text-primary"><?= $club['rider_count'] ?></strong>
                                        </td>
                                        <td class="gs-text-right">
                                                <div class="gs-flex gs-gap-sm gs-justify-end">
                                                    <a
                                                        href="/admin/riders.php?club_id=<?= $club['id'] ?>"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        title="Visa medlemmar"
                                                    >
                                                        <i data-lucide="users"></i>
                                                        <?= $club['rider_count'] ?>
                                                    </a>
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        onclick="editClub(<?= $club['id'] ?>)"
                                                        title="Redigera"
                                                    >
                                                        <i data-lucide="edit"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                        onclick="deleteClub(<?= $club['id'] ?>, '<?= addslashes(h($club['name'])) ?>')"
                                                        title="Ta bort"
                                                    >
                                                        <i data-lucide="trash-2"></i>
                                                    </button>
                                                </div>
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
            // Open modal for creating new club
            function openClubModal() {
                document.getElementById('clubModal').style.display = 'flex';
                document.getElementById('clubForm').reset();
                document.getElementById('formAction').value = 'create';
                document.getElementById('clubId').value = '';
                document.getElementById('modalTitleText').textContent = 'Ny Klubb';
                document.getElementById('submitButtonText').textContent = 'Skapa';
                // Set active checkbox to checked by default
                document.getElementById('active').checked = true;
                // Set default country
                document.getElementById('country').value = 'Sverige';

                // Re-initialize Lucide icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }

            // Close modal
            function closeClubModal() {
                document.getElementById('clubModal').style.display = 'none';
            }

            // Edit club - go to edit page
            function editClub(id) {
                window.location.href = `/admin/club-edit.php?id=${id}`;
            }

            // Delete club
            function deleteClub(id, name) {
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
                const modal = document.getElementById('clubModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeClubModal();
                        }
                    });
                }

                // Handle edit mode from URL parameter
                <?php if ($editClub): ?>
                    // Populate form with club data
                    document.getElementById('formAction').value = 'update';
                    document.getElementById('clubId').value = '<?= $editClub['id'] ?>';
                    document.getElementById('name').value = '<?= addslashes($editClub['name']) ?>';
                    document.getElementById('short_name').value = '<?= addslashes($editClub['short_name'] ?? '') ?>';
                    document.getElementById('region').value = '<?= addslashes($editClub['region'] ?? '') ?>';
                    document.getElementById('city').value = '<?= addslashes($editClub['city'] ?? '') ?>';
                    document.getElementById('country').value = '<?= addslashes($editClub['country'] ?? 'Sverige') ?>';
                    document.getElementById('website').value = '<?= addslashes($editClub['website'] ?? '') ?>';
                    document.getElementById('active').checked = <?= $editClub['active'] ? 'true' : 'false' ?>;

                    // Update modal title and button
                    document.getElementById('modalTitleText').textContent = 'Redigera Klubb';
                    document.getElementById('submitButtonText').textContent = 'Uppdatera';

                    // Open modal
                    document.getElementById('clubModal').style.display = 'flex';

                    // Re-initialize Lucide icons
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                <?php endif; ?>
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeClubModal();
                }
            });

            // Live search with debouncing
            (function() {
                const searchInput = document.getElementById('searchInput');
                const searchForm = document.getElementById('searchForm');
                const searchStatus = document.getElementById('searchStatus');
                let debounceTimer;
                let lastSearch = searchInput.value;

                searchInput.addEventListener('input', function() {
                    const query = this.value.trim();

                    // Clear previous timer
                    clearTimeout(debounceTimer);

                    // Don't search if query hasn't changed
                    if (query === lastSearch) return;

                    // Show status indicator
                    if (query.length >= 2 || query.length === 0) {
                        searchStatus.style.display = 'inline';
                    }

                    // Debounce: wait 300ms after user stops typing
                    debounceTimer = setTimeout(function() {
                        // Only search if at least 2 characters or empty (to clear)
                        if (query.length >= 2 || query.length === 0) {
                            lastSearch = query;
                            searchForm.submit();
                        } else {
                            searchStatus.style.display = 'none';
                        }
                    }, 300);
                });

                // Also handle Enter key
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        clearTimeout(debounceTimer);
                        searchForm.submit();
                    }
                });
            })();
        </script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
