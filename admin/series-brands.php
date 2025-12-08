<?php
/**
 * Admin Series Brands - V3 Design System
 * Manage parent series (brands) like "Swecup", "GravitySeries Enduro" etc.
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Initialize message variables
$message = '';
$messageType = 'info';

// Check if series_brands table exists
$tableExists = false;
try {
    $check = $db->getAll("SHOW TABLES LIKE 'series_brands'");
    $tableExists = !empty($check);
} catch (Exception $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $page_title = 'Serie-varumärken';
    $page_group = 'standings';
    include __DIR__ . '/components/unified-layout.php';
    ?>
    <div class="alert alert-warning">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Tabellen <code>series_brands</code> finns inte. Kör migrationen först.
    </div>
    <div class="admin-card">
        <div class="admin-card-body">
            <p>Kör följande migration för att skapa tabellen:</p>
            <code>database/migrations/050_add_series_brands.sql</code>
        </div>
    </div>
    <?php
    include __DIR__ . '/components/unified-layout-footer.php';
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } else {
            // Handle logo upload
            $logoPath = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/series-brands/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileExtension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

                if (in_array($fileExtension, $allowedExtensions)) {
                    $fileName = uniqid('brand_') . '.' . $fileExtension;
                    $targetPath = $uploadDir . $fileName;

                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
                        $logoPath = '/uploads/series-brands/' . $fileName;
                    }
                }
            }

            // Generate slug from name
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            $slug = trim($slug, '-');

            // Prepare brand data
            $brandData = [
                'name' => $name,
                'slug' => $slug,
                'description' => trim($_POST['description'] ?? ''),
                'website' => trim($_POST['website'] ?? ''),
                'gradient_start' => $_POST['gradient_start'] ?? '#004A98',
                'gradient_end' => $_POST['gradient_end'] ?? '#002a5c',
                'accent_color' => $_POST['accent_color'] ?? '#61CE70',
                'active' => isset($_POST['active']) ? 1 : 0,
                'display_order' => intval($_POST['display_order'] ?? 0),
            ];

            if ($logoPath) {
                $brandData['logo'] = $logoPath;
            }

            try {
                if ($action === 'create') {
                    $db->insert('series_brands', $brandData);
                    $message = 'Varumärke skapat!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('series_brands', $brandData, 'id = ?', [$id]);
                    $message = 'Varumärke uppdaterat!';
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
            // First unlink any series using this brand
            $db->query("UPDATE series SET brand_id = NULL WHERE brand_id = ?", [$id]);
            $db->delete('series_brands', 'id = ?', [$id]);
            $message = 'Varumärke borttaget!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get all brands with series count
$brands = $db->getAll("
    SELECT sb.*,
           COUNT(s.id) as series_count
    FROM series_brands sb
    LEFT JOIN series s ON s.brand_id = sb.id
    GROUP BY sb.id
    ORDER BY sb.display_order ASC, sb.name ASC
");

// Get unassigned series count
$unassignedCount = $db->getRow("SELECT COUNT(*) as cnt FROM series WHERE brand_id IS NULL");
$unassignedSeries = $unassignedCount['cnt'] ?? 0;

// Page config
$page_title = 'Serie-varumärken';
$page_group = 'standings';
$page_actions = '<button onclick="openBrandModal()" class="btn-admin btn-admin-primary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
    Nytt varumärke
</button>';

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

<!-- Stats Grid -->
<div class="grid grid-stats grid-gap-md">
    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-info-light); color: var(--color-info);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= count($brands) ?></div>
            <div class="admin-stat-label">Varumärken</div>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-success-light); color: var(--color-success);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= count(array_filter($brands, fn($b) => $b['active'])) ?></div>
            <div class="admin-stat-label">Aktiva</div>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-warning-light); color: var(--color-warning);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $unassignedSeries ?></div>
            <div class="admin-stat-label">Otilldelade serier</div>
        </div>
    </div>
</div>

<!-- Brands Table -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><?= count($brands) ?> varumärken</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <?php if (empty($brands)): ?>
            <div class="admin-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                <h3>Inga varumärken hittades</h3>
                <p>Skapa ett varumärke för att gruppera serier.</p>
                <button onclick="openBrandModal()" class="btn-admin btn-admin-primary">Skapa varumärke</button>
            </div>
        <?php else: ?>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Logo</th>
                            <th>Namn</th>
                            <th>Slug</th>
                            <th>Serier</th>
                            <th>Status</th>
                            <th>Ordning</th>
                            <th style="width: 120px;">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($brands as $brand): ?>
                            <tr>
                                <td>
                                    <?php if ($brand['logo']): ?>
                                        <img src="<?= htmlspecialchars($brand['logo']) ?>" alt="" style="max-width: 40px; max-height: 40px; object-fit: contain;">
                                    <?php else: ?>
                                        <span style="color: var(--color-text-secondary);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($brand['name']) ?></strong>
                                </td>
                                <td>
                                    <code style="font-size: var(--text-xs);"><?= htmlspecialchars($brand['slug']) ?></code>
                                </td>
                                <td>
                                    <span class="admin-badge admin-badge-info"><?= $brand['series_count'] ?></span>
                                </td>
                                <td>
                                    <?php if ($brand['active']): ?>
                                        <span class="admin-badge admin-badge-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="admin-badge admin-badge-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $brand['display_order'] ?></td>
                                <td>
                                    <div class="table-actions">
                                        <button onclick='editBrand(<?= json_encode($brand) ?>)' class="btn-admin btn-admin-sm btn-admin-secondary" title="Redigera">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                        </button>
                                        <button onclick="deleteBrand(<?= $brand['id'] ?>, '<?= addslashes($brand['name']) ?>')" class="btn-admin btn-admin-sm btn-admin-danger" title="Ta bort">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                        </button>
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

<!-- Brand Modal -->
<div id="brandModal" class="admin-modal" style="display: none;">
    <div class="admin-modal-overlay" onclick="closeBrandModal()"></div>
    <div class="admin-modal-content">
        <div class="admin-modal-header">
            <h2 id="modalTitle">Nytt varumärke</h2>
            <button type="button" class="admin-modal-close" onclick="closeBrandModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="brandForm" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="brandId" value="">

            <div class="admin-modal-body">
                <div class="admin-form-group">
                    <label for="name" class="admin-form-label">Namn <span style="color: var(--color-error);">*</span></label>
                    <input type="text" id="name" name="name" class="admin-form-input" required placeholder="T.ex. Swecup, GravitySeries Enduro">
                </div>

                <div class="admin-form-group">
                    <label for="description" class="admin-form-label">Beskrivning</label>
                    <textarea id="description" name="description" class="admin-form-textarea" rows="3" placeholder="Kort beskrivning av serien..."></textarea>
                </div>

                <div class="admin-form-group">
                    <label for="website" class="admin-form-label">Webbsida</label>
                    <input type="url" id="website" name="website" class="admin-form-input" placeholder="https://...">
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label for="gradient_start" class="admin-form-label">Gradient start</label>
                        <input type="color" id="gradient_start" name="gradient_start" class="admin-form-input" value="#004A98" style="height: 40px; padding: 4px;">
                    </div>
                    <div class="admin-form-group">
                        <label for="gradient_end" class="admin-form-label">Gradient slut</label>
                        <input type="color" id="gradient_end" name="gradient_end" class="admin-form-input" value="#002a5c" style="height: 40px; padding: 4px;">
                    </div>
                    <div class="admin-form-group">
                        <label for="accent_color" class="admin-form-label">Accent</label>
                        <input type="color" id="accent_color" name="accent_color" class="admin-form-input" value="#61CE70" style="height: 40px; padding: 4px;">
                    </div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label for="display_order" class="admin-form-label">Visningsordning</label>
                        <input type="number" id="display_order" name="display_order" class="admin-form-input" value="0" min="0">
                    </div>
                    <div class="admin-form-group" style="display: flex; align-items: center; padding-top: 24px;">
                        <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                            <input type="checkbox" id="active" name="active" checked>
                            <span>Aktiv</span>
                        </label>
                    </div>
                </div>

                <div class="admin-form-group">
                    <label for="logo" class="admin-form-label">Logotyp</label>
                    <input type="file" id="logo" name="logo" class="admin-form-input" accept="image/*">
                    <div id="currentLogo" style="display: none; margin-top: var(--space-sm);">
                        <strong>Nuvarande:</strong><br>
                        <img id="currentLogoImg" src="" alt="Logo" style="max-width: 150px; max-height: 80px; margin-top: var(--space-xs);">
                    </div>
                </div>
            </div>

            <div class="admin-modal-footer">
                <button type="button" class="btn-admin btn-admin-secondary" onclick="closeBrandModal()">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary" id="submitButton">Skapa</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = '<?= htmlspecialchars(generate_csrf_token()) ?>';

function openBrandModal() {
    document.getElementById('brandModal').style.display = 'flex';
    document.getElementById('brandForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('brandId').value = '';
    document.getElementById('modalTitle').textContent = 'Nytt varumärke';
    document.getElementById('submitButton').textContent = 'Skapa';
    document.getElementById('currentLogo').style.display = 'none';
    document.getElementById('active').checked = true;
}

function closeBrandModal() {
    document.getElementById('brandModal').style.display = 'none';
}

function editBrand(brand) {
    document.getElementById('brandModal').style.display = 'flex';
    document.getElementById('formAction').value = 'update';
    document.getElementById('brandId').value = brand.id;
    document.getElementById('modalTitle').textContent = 'Redigera varumärke';
    document.getElementById('submitButton').textContent = 'Spara';

    document.getElementById('name').value = brand.name || '';
    document.getElementById('description').value = brand.description || '';
    document.getElementById('website').value = brand.website || '';
    document.getElementById('gradient_start').value = brand.gradient_start || '#004A98';
    document.getElementById('gradient_end').value = brand.gradient_end || '#002a5c';
    document.getElementById('accent_color').value = brand.accent_color || '#61CE70';
    document.getElementById('display_order').value = brand.display_order || 0;
    document.getElementById('active').checked = brand.active == 1;

    if (brand.logo) {
        document.getElementById('currentLogo').style.display = 'block';
        document.getElementById('currentLogoImg').src = brand.logo;
    } else {
        document.getElementById('currentLogo').style.display = 'none';
    }
}

function deleteBrand(id, name) {
    if (!confirm('Är du säker på att du vill ta bort "' + name + '"?\n\nAlla serier kopplade till detta varumärke blir otilldelade.')) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="delete">' +
                     '<input type="hidden" name="id" value="' + id + '">' +
                     '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBrandModal();
    }
});
</script>

<style>
.admin-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
}

.admin-modal-content {
    position: relative;
    background: var(--color-bg);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.admin-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.admin-modal-header h2 {
    margin: 0;
    font-size: var(--text-xl);
}

.admin-modal-close {
    background: none;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    color: var(--color-text-secondary);
    border-radius: var(--radius-sm);
}

.admin-modal-close:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text);
}

.admin-modal-close svg {
    width: 20px;
    height: 20px;
}

.admin-modal-body {
    padding: var(--space-lg);
    overflow-y: auto;
    flex: 1;
}

.admin-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-lg);
    border-top: 1px solid var(--color-border);
}

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
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
