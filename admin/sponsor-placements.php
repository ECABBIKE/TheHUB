<?php
/**
 * Global Sponsor Placements - Admin Page (Super Admin Only)
 * TheHUB - Manage global sponsor placements across the site
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/sponsor-functions.php';
require_once __DIR__ . '/../includes/GlobalSponsorManager.php';

global $pdo;

// Require super admin
if (!hasRole('super_admin')) {
    header('Location: /admin/');
    exit;
}

$sponsorManager = new GlobalSponsorManager($pdo);

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_placement') {
        $result = $sponsorManager->createPlacement([
            'sponsor_id' => (int)$_POST['sponsor_id'],
            'custom_media_id' => $_POST['custom_media_id'] ?: null,
            'page_type' => $_POST['page_type'],
            'position' => $_POST['position'],
            'display_order' => (int)($_POST['display_order'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'start_date' => $_POST['start_date'] ?: null,
            'end_date' => $_POST['end_date'] ?: null,
            'impressions_target' => $_POST['impressions_target'] ?: null
        ]);

        if ($result['success']) {
            $message = 'Placering skapad!';
        } else {
            $error = 'Kunde inte skapa placering: ' . ($result['error'] ?? 'Okänt fel');
        }
    } elseif ($action === 'update_placement') {
        $result = $sponsorManager->updatePlacement((int)$_POST['placement_id'], [
            'sponsor_id' => (int)$_POST['sponsor_id'],
            'custom_media_id' => $_POST['custom_media_id'] ?: null,
            'page_type' => $_POST['page_type'],
            'position' => $_POST['position'],
            'display_order' => (int)($_POST['display_order'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'start_date' => $_POST['start_date'] ?: null,
            'end_date' => $_POST['end_date'] ?: null,
            'impressions_target' => $_POST['impressions_target'] ?: null
        ]);

        if ($result['success']) {
            $message = 'Placering uppdaterad!';
        } else {
            $error = 'Kunde inte uppdatera placering: ' . ($result['error'] ?? 'Okänt fel');
        }
    } elseif ($action === 'delete_placement') {
        $result = $sponsorManager->deletePlacement((int)$_POST['placement_id']);
        if ($result['success']) {
            $message = 'Placering borttagen!';
        } else {
            $error = 'Kunde inte ta bort placering.';
        }
    } elseif ($action === 'update_setting') {
        $key = $_POST['setting_key'];
        $value = $_POST['setting_value'];
        if ($sponsorManager->updateSetting($key, $value)) {
            $message = 'Inställning sparad!';
        } else {
            $error = 'Kunde inte spara inställning.';
        }
    }
}

// Get all placements
$placements = $sponsorManager->getAllPlacements();

// Get all active sponsors for dropdown
$sponsors = get_sponsors(true);

// Get settings
$settings = $sponsorManager->getAllSettings();
$publicEnabled = $sponsorManager->getSetting('public_enabled', '0');

// Get tier benefits
$tierBenefits = $sponsorManager->getTierBenefits();

// Page types and positions
$pageTypes = [
    'home' => 'Startsida',
    'results' => 'Resultat',
    'series_list' => 'Serieöversikt',
    'series_single' => 'Enskild serie',
    'database' => 'Databas',
    'ranking' => 'Ranking',
    'calendar' => 'Kalender',
    'rider' => 'Åkarsida',
    'club' => 'Klubbsida',
    'event' => 'Eventsida',
    'gallery' => 'Galleri (bildspel)',
    'blog' => 'Race Reports',
    'all' => 'Alla sidor'
];

// Note: sidebar positions only work on pages with sidebar layout
// Currently working positions: header_inline, header_banner, content_top, content_bottom, footer
$positions = [
    'header_inline' => 'Header (mitt i menyraden)',
    'header_banner' => 'Header Banner (under sidrubrik)',
    'content_top' => 'Innehåll Topp',
    'content_bottom' => 'Innehåll Botten',
    'footer' => 'Footer (via layout)'
];

// Format guidelines - RESPONSIVT SYSTEM
// Standardformat: Logo 600x150 (4:1), Banner 1200x150 (8:1)
// Skalas automatiskt: Mobil → Tablet → Desktop (max 1600px) → PWA
$positionFormats = [
    'header_inline' => [
        'logo' => '600 x 150 px',
        'banner' => '600 x 150 px',
        'desc' => 'Kompakt logo i menyraden. Skalas ner automatiskt. Transparent bakgrund rekommenderas.',
        'type' => 'Logo (PNG/SVG)'
    ],
    'header_banner' => [
        'logo' => '600 x 150 px',
        'banner' => '1200 x 150 px',
        'desc' => 'Fullbreddsbanner. Skalas responsivt från mobil till 1600px desktop.',
        'type' => 'Banner (PNG/JPG)'
    ],
    'content_top' => [
        'logo' => '600 x 150 px',
        'banner' => '600 x 150 px',
        'desc' => 'Responsivt grid (4:1). Desktop: 5/rad, Tablet: 4/rad, Mobil: 2/rad.',
        'type' => 'Logo (4:1)'
    ],
    'content_bottom' => [
        'logo' => '600 x 150 px',
        'banner' => '600 x 150 px',
        'desc' => 'Responsivt grid (4:1). Desktop: 5/rad, Tablet: 4/rad, Mobil: 2/rad.',
        'type' => 'Logo (4:1)'
    ],
    'footer' => [
        'logo' => '600 x 150 px',
        'banner' => '600 x 150 px',
        'desc' => 'Kompakt grid (4:1). Desktop: 6/rad, Tablet: 5/rad, Mobil: 3/rad.',
        'type' => 'Logo (PNG/SVG)'
    ]
];

$tierLabels = [
    'title_gravityseries' => ['name' => 'Titelsponsor GS', 'color' => '#8B5CF6'],
    'title_series' => ['name' => 'Titelsponsor Serie', 'color' => '#6366F1'],
    'gold' => ['name' => 'Guldsponsor', 'color' => '#F59E0B'],
    'silver' => ['name' => 'Silversponsor', 'color' => '#9CA3AF'],
    'branch' => ['name' => 'Branschsponsor', 'color' => '#D97706']
];

// Page config
$page_title = 'Globala Reklamplatser';
$breadcrumbs = [
    ['label' => 'Sponsorer', 'url' => '/admin/sponsors.php'],
    ['label' => 'Reklamplatser']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<link rel="stylesheet" href="/assets/css/sponsors-blog.css">

<style>
/* Page-specific styles */
.settings-section {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}

.settings-section h3 {
    margin: 0 0 var(--space-md);
    font-size: 1.125rem;
    color: var(--color-text-primary);
}

.setting-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-sm) 0;
    border-bottom: 1px solid var(--color-border);
}

.setting-row:last-child {
    border-bottom: none;
}

.setting-label {
    flex: 1;
}

.setting-label strong {
    display: block;
    color: var(--color-text-primary);
}

.setting-label small {
    color: var(--color-text-muted);
}

.toggle-switch {
    position: relative;
    width: 50px;
    height: 26px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--color-border);
    transition: .4s;
    border-radius: 26px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .toggle-slider {
    background-color: var(--color-accent);
}

input:checked + .toggle-slider:before {
    transform: translateX(24px);
}

.placement-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: var(--space-md);
}

.placement-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
}

.placement-card.inactive {
    opacity: 0.6;
}

.placement-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-sm);
}

.placement-sponsor {
    font-weight: 600;
    color: var(--color-text-primary);
}

.placement-tier {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    color: white;
}

.placement-location {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-sm);
}

.placement-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2xs);
    padding: 2px 8px;
    background: var(--color-bg-page);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.placement-stats {
    display: flex;
    gap: var(--space-md);
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-bottom: var(--space-sm);
}

.placement-actions {
    display: flex;
    gap: var(--space-xs);
}

.create-form {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2xs);
}

.form-group label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
}

.form-group input,
.form-group select {
    padding: var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-page);
    color: var(--color-text-primary);
}

.alert {
    padding: var(--space-md);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-md);
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid var(--color-success);
    color: var(--color-success);
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid var(--color-error);
    color: var(--color-error);
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Settings Section -->
<div class="settings-section">
    <h3><i data-lucide="settings"></i> Inställningar</h3>

    <div class="setting-row">
        <div class="setting-label">
            <strong>Publik synlighet</strong>
            <small>Visa globala sponsorer för vanliga besökare (inte bara admin)</small>
        </div>
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="public_enabled">
            <input type="hidden" name="setting_value" value="<?= $publicEnabled === '1' ? '0' : '1' ?>">
            <label class="toggle-switch">
                <input type="checkbox" <?= $publicEnabled === '1' ? 'checked' : '' ?> onchange="this.form.submit()">
                <span class="toggle-slider"></span>
            </label>
        </form>
    </div>
</div>

<!-- Format Guide -->
<div class="settings-section">
    <h3><i data-lucide="image"></i> Bildformat per position</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--space-md);">
        <?php foreach ($positionFormats as $posKey => $format): ?>
        <div style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-sm); border: 1px solid var(--color-border);">
            <strong style="color: var(--color-text-primary);"><?= $positions[$posKey] ?></strong>
            <div style="margin-top: var(--space-xs); font-size: 0.875rem;">
                <div style="display: flex; justify-content: space-between; color: var(--color-text-secondary);">
                    <span>Logo:</span>
                    <span style="font-family: monospace; color: var(--color-accent);"><?= $format['logo'] ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; color: var(--color-text-secondary);">
                    <span>Banner:</span>
                    <span style="font-family: monospace; color: var(--color-accent);"><?= $format['banner'] ?></span>
                </div>
                <div style="margin-top: var(--space-xs); font-size: 0.75rem; color: var(--color-text-muted);">
                    <?= $format['desc'] ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Preview Link -->
<div style="margin-bottom: var(--space-lg);">
    <a href="/admin/sponsor-placements-preview.php" class="btn btn-secondary">
        <i data-lucide="eye"></i> Förhandsvisa positioner
    </a>
</div>

<!-- Create New Placement -->
<div class="create-form">
    <h3><i data-lucide="plus"></i> Skapa ny placering</h3>

    <form method="POST">
        <input type="hidden" name="action" value="create_placement">

        <div class="form-row">
            <div class="form-group">
                <label>Sponsor</label>
                <select name="sponsor_id" required>
                    <option value="">Välj sponsor...</option>
                    <?php foreach ($sponsors as $sponsor): ?>
                        <option value="<?= $sponsor['id'] ?>">
                            <?= htmlspecialchars($sponsor['name']) ?>
                            (<?= $tierLabels[$sponsor['tier']]['name'] ?? $sponsor['tier'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Sidtyp</label>
                <select name="page_type" required>
                    <?php foreach ($pageTypes as $key => $label): ?>
                        <option value="<?= $key ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Position</label>
                <select name="position" id="position-select" required onchange="showFormatInfo()">
                    <?php foreach ($positions as $key => $label): ?>
                        <option value="<?= $key ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Dynamic format info -->
        <div id="format-info" class="alert" style="background: var(--color-accent-light); border: 1px solid var(--color-accent); margin-bottom: var(--space-md); display: none;">
            <strong>Rekommenderat format:</strong>
            <span id="format-text"></span>
        </div>

        <!-- Custom bild från mediaarkivet -->
        <div class="form-row">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Anpassad bild (valfritt - ersätter sponsorns standardbild)</label>
                <input type="hidden" name="custom_media_id" id="create-custom-media-id" value="">
                <div style="display: flex; align-items: center; gap: var(--space-sm); flex-wrap: wrap;">
                    <div id="create-custom-preview" style="display: none; background: var(--color-bg-page); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: var(--space-xs); max-width: 300px;">
                        <img id="create-custom-preview-img" src="" alt="Förhandsvisa" style="max-height: 80px; max-width: 100%; object-fit: contain; display: block; margin: 0 auto;">
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="openMediaPicker('create')">
                        <i data-lucide="image"></i> Välj från mediaarkivet
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" id="create-custom-remove" style="display: none;" onclick="removeCustomImage('create')">
                        <i data-lucide="x"></i> Ta bort
                    </button>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Sorteringsordning</label>
                <input type="number" name="display_order" value="0" min="0">
            </div>

            <div class="form-group">
                <label>Startdatum (valfritt)</label>
                <input type="date" name="start_date">
            </div>

            <div class="form-group">
                <label>Slutdatum (valfritt)</label>
                <input type="date" name="end_date">
            </div>

            <div class="form-group">
                <label>Max visningar (valfritt)</label>
                <input type="number" name="impressions_target" min="0" placeholder="Obegränsat">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" checked>
                    Aktiv
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i data-lucide="plus"></i> Skapa placering
        </button>
    </form>
</div>

<!-- Current Placements -->
<h3 style="margin-bottom: var(--space-md);">Aktiva placeringar (<?= count($placements) ?>)</h3>

<?php if (empty($placements)): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: var(--space-2xl);">
            <i data-lucide="layout-grid" style="width: 48px; height: 48px; color: var(--color-text-muted);"></i>
            <p style="margin-top: var(--space-md); color: var(--color-text-muted);">
                Inga placeringar än. Skapa din första placering ovan.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="placement-grid">
        <?php foreach ($placements as $placement): ?>
            <?php $tierInfo = $tierLabels[$placement['sponsor_tier']] ?? ['name' => $placement['sponsor_tier'], 'color' => '#666']; ?>
            <div class="placement-card <?= $placement['is_active'] ? '' : 'inactive' ?>">
                <div class="placement-header">
                    <span class="placement-sponsor"><?= htmlspecialchars($placement['sponsor_name']) ?></span>
                    <span class="placement-tier" style="background: <?= $tierInfo['color'] ?>">
                        <?= $tierInfo['name'] ?>
                    </span>
                </div>

                <div class="placement-location">
                    <span class="placement-badge">
                        <i data-lucide="file"></i>
                        <?= $pageTypes[$placement['page_type']] ?? $placement['page_type'] ?>
                    </span>
                    <span class="placement-badge">
                        <i data-lucide="layout"></i>
                        <?= $positions[$placement['position']] ?? $placement['position'] ?>
                    </span>
                </div>

                <?php if (!empty($placement['custom_image_path'])): ?>
                    <div style="margin-bottom: var(--space-sm); background: var(--color-bg-page); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: var(--space-xs); text-align: center;">
                        <img src="/<?= htmlspecialchars(ltrim($placement['custom_image_path'], '/')) ?>" alt="Anpassad bild" style="max-height: 60px; max-width: 100%; object-fit: contain; display: block; margin: 0 auto;">
                        <div style="font-size: 0.7rem; color: var(--color-text-muted); margin-top: var(--space-2xs);">Anpassad bild</div>
                    </div>
                <?php endif; ?>

                <div class="placement-stats">
                    <span><i data-lucide="eye"></i> <?= number_format($placement['impressions_current']) ?> visningar</span>
                    <span><i data-lucide="mouse-pointer"></i> <?= number_format($placement['clicks']) ?> klick</span>
                    <?php if ($placement['impressions_target']): ?>
                        <span><i data-lucide="target"></i> Mål: <?= number_format($placement['impressions_target']) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($placement['start_date'] || $placement['end_date']): ?>
                    <div class="placement-stats">
                        <?php if ($placement['start_date']): ?>
                            <span>Från: <?= date('Y-m-d', strtotime($placement['start_date'])) ?></span>
                        <?php endif; ?>
                        <?php if ($placement['end_date']): ?>
                            <span>Till: <?= date('Y-m-d', strtotime($placement['end_date'])) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="placement-actions">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="toggleEdit(<?= $placement['id'] ?>)">
                        <i data-lucide="pencil"></i> Redigera
                    </button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Ta bort denna placering?');">
                        <input type="hidden" name="action" value="delete_placement">
                        <input type="hidden" name="placement_id" value="<?= $placement['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">
                            <i data-lucide="trash-2"></i> Ta bort
                        </button>
                    </form>
                </div>

                <!-- Edit Form (hidden by default) -->
                <div class="edit-form" id="edit-form-<?= $placement['id'] ?>" style="display: none; margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_placement">
                        <input type="hidden" name="placement_id" value="<?= $placement['id'] ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label>Sponsor</label>
                                <select name="sponsor_id" required>
                                    <?php foreach ($sponsors as $sponsor): ?>
                                        <option value="<?= $sponsor['id'] ?>" <?= $placement['sponsor_id'] == $sponsor['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sponsor['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Sidtyp</label>
                                <select name="page_type" required>
                                    <?php foreach ($pageTypes as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= $placement['page_type'] == $key ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Position</label>
                                <select name="position" required>
                                    <?php foreach ($positions as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= $placement['position'] == $key ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Anpassad bild -->
                        <div class="form-row">
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label>Anpassad bild (ersätter sponsorns standardbild)</label>
                                <input type="hidden" name="custom_media_id" id="edit-custom-media-id-<?= $placement['id'] ?>" value="<?= $placement['custom_media_id'] ?? '' ?>">
                                <div style="display: flex; align-items: center; gap: var(--space-sm); flex-wrap: wrap;">
                                    <div id="edit-custom-preview-<?= $placement['id'] ?>" style="<?= empty($placement['custom_image_path']) ? 'display: none;' : '' ?> background: var(--color-bg-page); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: var(--space-xs); max-width: 300px;">
                                        <img id="edit-custom-preview-img-<?= $placement['id'] ?>" src="<?= !empty($placement['custom_image_path']) ? '/' . htmlspecialchars(ltrim($placement['custom_image_path'], '/')) : '' ?>" alt="Förhandsvisa" style="max-height: 80px; max-width: 100%; object-fit: contain; display: block; margin: 0 auto;">
                                    </div>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openMediaPicker('edit-<?= $placement['id'] ?>')">
                                        <i data-lucide="image"></i> Välj från mediaarkivet
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-sm" id="edit-custom-remove-<?= $placement['id'] ?>" style="<?= empty($placement['custom_media_id']) ? 'display: none;' : '' ?>" onclick="removeCustomImage('edit-<?= $placement['id'] ?>')">
                                        <i data-lucide="x"></i> Ta bort
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Sorteringsordning</label>
                                <input type="number" name="display_order" value="<?= $placement['display_order'] ?>" min="0">
                            </div>

                            <div class="form-group">
                                <label>Startdatum</label>
                                <input type="date" name="start_date" value="<?= $placement['start_date'] ? date('Y-m-d', strtotime($placement['start_date'])) : '' ?>">
                            </div>

                            <div class="form-group">
                                <label>Slutdatum</label>
                                <input type="date" name="end_date" value="<?= $placement['end_date'] ? date('Y-m-d', strtotime($placement['end_date'])) : '' ?>">
                            </div>

                            <div class="form-group">
                                <label>Max visningar</label>
                                <input type="number" name="impressions_target" value="<?= $placement['impressions_target'] ?>" min="0" placeholder="Obegränsat">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="is_active" value="1" <?= $placement['is_active'] ? 'checked' : '' ?>>
                                    Aktiv
                                </label>
                            </div>
                        </div>

                        <div style="display: flex; gap: var(--space-sm);">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i data-lucide="save"></i> Spara
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="toggleEdit(<?= $placement['id'] ?>)">
                                Avbryt
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function toggleEdit(placementId) {
    const form = document.getElementById('edit-form-' + placementId);
    if (form) {
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        lucide.createIcons();
    }
}

// Position format data
const positionFormats = <?= json_encode($positionFormats) ?>;

function showFormatInfo() {
    const select = document.getElementById('position-select');
    const formatInfo = document.getElementById('format-info');
    const formatText = document.getElementById('format-text');

    if (select && formatInfo && formatText) {
        const pos = select.value;
        if (positionFormats[pos]) {
            const f = positionFormats[pos];
            formatText.innerHTML = `Logo: <code>${f.logo}</code> | Banner: <code>${f.banner}</code> - ${f.type}`;
            formatInfo.style.display = 'block';
        } else {
            formatInfo.style.display = 'none';
        }
    }
}

// Show format info on page load
document.addEventListener('DOMContentLoaded', showFormatInfo);
</script>

<!-- Tier Benefits -->
<h3 style="margin: var(--space-xl) 0 var(--space-md);">Sponsornivåer och förmåner</h3>

<div class="sponsor-tier-benefits">
    <?php foreach ($tierLabels as $tierKey => $tierInfo): ?>
        <div class="sponsor-tier-card sponsor-tier-card-<?= $tierKey ?>">
            <h4 class="sponsor-tier-title" style="color: <?= $tierInfo['color'] ?>">
                <?= $tierInfo['name'] ?>
            </h4>
            <ul class="sponsor-tier-benefits-list">
                <?php if (isset($tierBenefits[$tierKey])): ?>
                    <?php foreach ($tierBenefits[$tierKey] as $benefit): ?>
                        <li>
                            <i data-lucide="check"></i>
                            <?= htmlspecialchars($benefit['benefit_value']) ?>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li><i data-lucide="check"></i>Konfigurera förmåner i databasen</li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>

<!-- Media Picker Modal -->
<div id="mediaPickerModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--color-bg-surface); border-radius: var(--radius-md); max-width: 900px; width: 95%; max-height: 85vh; display: flex; flex-direction: column; border: 1px solid var(--color-border);">
        <!-- Header -->
        <div style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-md); border-bottom: 1px solid var(--color-border);">
            <h3 style="margin: 0; font-size: 1.125rem;">Välj bild från mediaarkivet</h3>
            <button type="button" onclick="closeMediaPicker()" style="background: none; border: none; cursor: pointer; color: var(--color-text-muted); padding: var(--space-xs);">
                <i data-lucide="x"></i>
            </button>
        </div>
        <!-- Folder nav + search -->
        <div style="display: flex; align-items: center; gap: var(--space-sm); padding: var(--space-sm) var(--space-md); border-bottom: 1px solid var(--color-border); flex-wrap: wrap;">
            <select id="mediaFolderSelect" onchange="loadMediaFolder()" style="padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-page); color: var(--color-text-primary); min-width: 180px;">
                <option value="">Alla mappar</option>
                <option value="sponsors">Sponsorer</option>
                <option value="ads">Annonser</option>
                <option value="branding">Branding</option>
                <option value="general">Allmänt</option>
                <option value="series">Serier</option>
                <option value="events">Event</option>
                <option value="clubs">Klubbar</option>
            </select>
            <input type="text" id="mediaSearchInput" placeholder="Sök..." onkeyup="debounceSearch()" style="flex: 1; min-width: 150px; padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-page); color: var(--color-text-primary);">
        </div>
        <!-- Grid -->
        <div id="mediaPickerGrid" style="flex: 1; overflow-y: auto; padding: var(--space-md); display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: var(--space-sm); align-content: start;">
            <div style="grid-column: 1 / -1; text-align: center; color: var(--color-text-muted); padding: var(--space-xl);">
                Välj en mapp eller sök...
            </div>
        </div>
    </div>
</div>

<script>
let currentPickerField = null;
let searchTimeout = null;

function openMediaPicker(fieldPrefix) {
    currentPickerField = fieldPrefix;
    document.getElementById('mediaPickerModal').style.display = 'flex';
    loadMediaFolder();
}

function closeMediaPicker() {
    document.getElementById('mediaPickerModal').style.display = 'none';
    currentPickerField = null;
}

// Stäng modal med Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeMediaPicker();
});

// Stäng modal vid klick utanför
document.getElementById('mediaPickerModal').addEventListener('click', function(e) {
    if (e.target === this) closeMediaPicker();
});

function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadMediaFolder, 300);
}

function loadMediaFolder() {
    const folder = document.getElementById('mediaFolderSelect').value;
    const search = document.getElementById('mediaSearchInput').value.trim();
    const grid = document.getElementById('mediaPickerGrid');

    grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: var(--color-text-muted); padding: var(--space-xl);">Laddar...</div>';

    let url = '/api/media.php?action=list&limit=100';
    if (search) {
        url += '&search=' + encodeURIComponent(search);
        if (folder) url += '&folder=' + encodeURIComponent(folder);
    } else if (folder) {
        url += '&folder=' + encodeURIComponent(folder);
    }

    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data.length) {
                grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: var(--color-text-muted); padding: var(--space-xl);">Inga bilder hittades</div>';
                return;
            }

            // Filtrera bara bilder
            const images = data.data.filter(f => f.mime_type && f.mime_type.startsWith('image/'));
            if (!images.length) {
                grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: var(--color-text-muted); padding: var(--space-xl);">Inga bilder i denna mapp</div>';
                return;
            }

            grid.innerHTML = '';
            images.forEach(file => {
                const item = document.createElement('div');
                item.style.cssText = 'cursor: pointer; border: 2px solid var(--color-border); border-radius: var(--radius-sm); overflow: hidden; transition: border-color 0.2s; aspect-ratio: 4/3; display: flex; flex-direction: column;';
                item.onmouseenter = () => item.style.borderColor = 'var(--color-accent)';
                item.onmouseleave = () => item.style.borderColor = 'var(--color-border)';
                item.onclick = () => selectMediaImage(file.id, file.url, file.filename);

                const imgWrap = document.createElement('div');
                imgWrap.style.cssText = 'flex: 1; display: flex; align-items: center; justify-content: center; background: var(--color-bg-page); overflow: hidden;';

                const img = document.createElement('img');
                img.src = file.url;
                img.alt = file.filename || '';
                img.loading = 'lazy';
                img.style.cssText = 'max-width: 100%; max-height: 100%; object-fit: contain;';
                imgWrap.appendChild(img);

                const label = document.createElement('div');
                label.style.cssText = 'padding: 2px 4px; font-size: 0.65rem; color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; background: var(--color-bg-surface);';
                label.textContent = file.filename || 'Namnlös';

                item.appendChild(imgWrap);
                item.appendChild(label);
                grid.appendChild(item);
            });
        })
        .catch(err => {
            grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: var(--color-error); padding: var(--space-xl);">Kunde inte ladda bilder</div>';
            console.error(err);
        });
}

function selectMediaImage(mediaId, url, filename) {
    if (!currentPickerField) return;

    // Sätt hidden input
    const hiddenInput = document.getElementById(currentPickerField + '-custom-media-id');
    if (hiddenInput) hiddenInput.value = mediaId;

    // Visa preview
    const preview = document.getElementById(currentPickerField + '-custom-preview');
    const previewImg = document.getElementById(currentPickerField + '-custom-preview-img');
    if (preview && previewImg) {
        previewImg.src = url;
        preview.style.display = '';
    }

    // Visa ta-bort-knapp
    const removeBtn = document.getElementById(currentPickerField + '-custom-remove');
    if (removeBtn) removeBtn.style.display = '';

    closeMediaPicker();
    lucide.createIcons();
}

function removeCustomImage(fieldPrefix) {
    const hiddenInput = document.getElementById(fieldPrefix + '-custom-media-id');
    if (hiddenInput) hiddenInput.value = '';

    const preview = document.getElementById(fieldPrefix + '-custom-preview');
    if (preview) preview.style.display = 'none';

    const previewImg = document.getElementById(fieldPrefix + '-custom-preview-img');
    if (previewImg) previewImg.src = '';

    const removeBtn = document.getElementById(fieldPrefix + '-custom-remove');
    if (removeBtn) removeBtn.style.display = 'none';
}

lucide.createIcons();
</script>
