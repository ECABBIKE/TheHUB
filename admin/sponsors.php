<?php
/**
 * Sponsor Management - Admin Page
 * TheHUB V3 - Media & Sponsor System
 *
 * Manage sponsors, tiers, and assignments
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/sponsor-functions.php';
require_once __DIR__ . '/../includes/media-functions.php';

global $pdo;

// Get filter parameters
$filterTier = $_GET['tier'] ?? null;
$filterSeries = isset($_GET['series']) ? (int)$_GET['series'] : null;
$filterActive = isset($_GET['active']) ? $_GET['active'] === '1' : null;
$searchQuery = $_GET['search'] ?? '';

// Get all series for filter and form
$allSeries = [];
try {
    $seriesStmt = $pdo->query("SELECT id, name, short_name FROM series ORDER BY name");
    $allSeries = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Sponsors - could not load series: " . $e->getMessage());
}

// Get sponsors (with optional series filter)
if ($searchQuery) {
    $sponsors = search_sponsors($searchQuery, 100);
} elseif ($filterSeries) {
    // Filter by series
    $stmt = $pdo->prepare("
        SELECT s.*, ss.series_id
        FROM sponsors s
        INNER JOIN series_sponsors ss ON s.id = ss.sponsor_id
        WHERE ss.series_id = ?
        " . ($filterTier ? " AND s.tier = ?" : "") . "
        " . ($filterActive !== null ? " AND s.active = ?" : "") . "
        ORDER BY s.display_order, s.name
    ");
    $params = [$filterSeries];
    if ($filterTier) $params[] = $filterTier;
    if ($filterActive !== null) $params[] = $filterActive ? 1 : 0;
    $stmt->execute($params);
    $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Add logo_url
    foreach ($sponsors as &$sp) {
        $sp['logo_url'] = $sp['logo'] ? '/uploads/sponsors/' . $sp['logo'] : null;
    }
} else {
    $sponsors = get_sponsors($filterActive !== false, $filterTier);
}

// Get stats
$stats = get_sponsor_stats();
$totalSponsors = array_sum(array_column($stats, 'count'));
$activeSponsors = array_sum(array_column($stats, 'active_count'));

// Tier definitions
$tiers = [
    'title' => ['name' => 'Titelsponsor', 'color' => '#8B5CF6'],
    'gold' => ['name' => 'Guldsponsor', 'color' => '#F59E0B'],
    'silver' => ['name' => 'Silversponsor', 'color' => '#9CA3AF'],
    'bronze' => ['name' => 'Bronssponsor', 'color' => '#D97706']
];

// Page config
$page_title = 'Sponsorer';
$breadcrumbs = [
    ['label' => 'Sponsorer']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<style>
/* Sponsor page styles */
.sponsor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
    gap: var(--space-md);
}

.sponsor-stats {
    display: flex;
    gap: var(--space-lg);
}

.sponsor-stat {
    text-align: center;
}

.sponsor-stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-accent);
}

.sponsor-stat-label {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    text-transform: uppercase;
}

/* Filters */
.sponsor-filters {
    display: flex;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
    align-items: center;
}

.sponsor-filters form {
    display: flex;
    gap: var(--space-sm);
    flex: 1;
    min-width: 200px;
}

.sponsor-filters input,
.sponsor-filters select {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
}

.sponsor-filters input {
    flex: 1;
}

.tier-filter {
    display: flex;
    gap: var(--space-xs);
}

.tier-filter-btn {
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-secondary);
    cursor: pointer;
    font-size: 0.75rem;
    text-decoration: none;
}

.tier-filter-btn:hover {
    background: var(--color-bg-hover);
}

.tier-filter-btn.active {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

/* Sponsor grid */
.sponsor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-lg);
}

.sponsor-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.15s;
}

.sponsor-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.sponsor-card-header {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}

.sponsor-logo {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-sm);
    background: var(--color-bg-sunken);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}

.sponsor-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.sponsor-logo-placeholder {
    color: var(--color-text-secondary);
    font-size: 24px;
    font-weight: 700;
}

.sponsor-info {
    flex: 1;
    min-width: 0;
}

.sponsor-name {
    font-weight: 600;
    font-size: 1rem;
    margin: 0 0 var(--space-2xs);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sponsor-tier {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
}

.sponsor-status {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--color-error);
}

.sponsor-status.active {
    background: var(--color-success);
}

.sponsor-card-body {
    padding: var(--space-md);
}

.sponsor-meta {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.sponsor-meta-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.sponsor-meta-item svg {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
}

.sponsor-card-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken);
    border-top: 1px solid var(--color-border);
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.8);
    z-index: 1000;
    padding: var(--space-lg);
    overflow-y: auto;
}

.modal.active {
    display: flex;
    align-items: flex-start;
    justify-content: center;
}

.modal-content {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    max-width: 600px;
    width: 100%;
    margin-top: var(--space-xl);
}

/* Mobile: Fullscreen modal */
@media (max-width: 599px) {
    .modal {
        padding: 0;
    }
    .modal-content {
        max-width: 100%;
        height: 100%;
        margin: 0;
        border-radius: 0;
        display: flex;
        flex-direction: column;
    }
    .modal-header {
        padding-top: calc(var(--space-md) + env(safe-area-inset-top, 0px));
        flex-shrink: 0;
    }
    .modal-body {
        flex: 1;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .modal-footer {
        padding-bottom: calc(var(--space-md) + env(safe-area-inset-bottom, 0px));
        flex-shrink: 0;
    }
    .modal-close {
        min-width: 44px;
        min-height: 44px;
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.modal-close {
    background: none;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    color: var(--color-text-secondary);
}

.modal-body {
    padding: var(--space-lg);
}

.form-group {
    margin-bottom: var(--space-md);
}

.form-label {
    display: block;
    font-weight: 500;
    margin-bottom: var(--space-xs);
    font-size: 0.875rem;
}

.form-input,
.form-select,
.form-textarea {
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-sunken);
    color: var(--color-text-primary);
}

.form-textarea {
    resize: vertical;
    min-height: 100px;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-md);
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-sunken);
    border-top: 1px solid var(--color-border);
}

/* Logo picker */
.logo-picker {
    display: flex;
    align-items: center;
    gap: var(--space-md);
}

.logo-preview {
    width: 80px;
    height: 80px;
    background: var(--color-bg-sunken);
    border: 2px dashed var(--color-border);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.logo-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.logo-preview:hover {
    border-color: var(--color-accent);
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--color-text-secondary);
}

.empty-state-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto var(--space-md);
    opacity: 0.5;
}
</style>

<!-- Header -->
<div class="sponsor-header">
    <div class="sponsor-stats">
        <div class="sponsor-stat">
            <div class="sponsor-stat-value"><?= $totalSponsors ?></div>
            <div class="sponsor-stat-label">Totalt</div>
        </div>
        <div class="sponsor-stat">
            <div class="sponsor-stat-value"><?= $activeSponsors ?></div>
            <div class="sponsor-stat-label">Aktiva</div>
        </div>
    </div>

    <button class="btn btn-primary" onclick="openCreateModal()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
        Ny sponsor
    </button>
</div>

<!-- Filters -->
<div class="sponsor-filters">
    <form method="get" action="/admin/sponsors">
        <input type="text" name="search" placeholder="Sök sponsorer..." value="<?= htmlspecialchars($searchQuery) ?>">
        <button type="submit" class="btn btn-secondary">Sök</button>
    </form>

    <select class="form-select" onchange="filterBySeries(this.value)" style="min-width: 150px;">
        <option value="">Alla serier</option>
        <?php foreach ($allSeries as $series): ?>
        <option value="<?= $series['id'] ?>" <?= $filterSeries === (int)$series['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($series['short_name'] ?: $series['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>

    <div class="tier-filter">
        <a href="/admin/sponsors<?= $filterSeries ? "?series=$filterSeries" : '' ?>" class="tier-filter-btn <?= !$filterTier ? 'active' : '' ?>">Alla</a>
        <?php foreach ($tiers as $tierKey => $tier): ?>
        <a href="/admin/sponsors?tier=<?= $tierKey ?><?= $filterSeries ? "&series=$filterSeries" : '' ?>" class="tier-filter-btn <?= $filterTier === $tierKey ? 'active' : '' ?>" style="<?= $filterTier === $tierKey ? "background: {$tier['color']}; border-color: {$tier['color']};" : '' ?>">
            <?= $tier['name'] ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Sponsor Grid -->
<?php if (empty($sponsors)): ?>
<div class="empty-state">
    <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20.42 4.58a5.4 5.4 0 0 0-7.65 0l-.77.78-.77-.78a5.4 5.4 0 0 0-7.65 0C1.46 6.7 1.33 10.28 4 13l8 8 8-8c2.67-2.72 2.54-6.3.42-8.42z"/>
    </svg>
    <h3>Inga sponsorer</h3>
    <p><?= $searchQuery ? 'Inga sponsorer matchar din sökning' : 'Lägg till din första sponsor genom att klicka på "Ny sponsor"' ?></p>
</div>
<?php else: ?>
<div class="sponsor-grid">
    <?php foreach ($sponsors as $sponsor): ?>
    <div class="sponsor-card" data-id="<?= $sponsor['id'] ?>">
        <div class="sponsor-card-header">
            <div class="sponsor-logo">
                <?php if ($sponsor['logo_url']): ?>
                    <img src="<?= htmlspecialchars($sponsor['logo_url']) ?>" alt="<?= htmlspecialchars($sponsor['name']) ?>">
                <?php else: ?>
                    <span class="sponsor-logo-placeholder"><?= strtoupper(substr($sponsor['name'], 0, 2)) ?></span>
                <?php endif; ?>
            </div>
            <div class="sponsor-info">
                <h3 class="sponsor-name"><?= htmlspecialchars($sponsor['name']) ?></h3>
                <span class="sponsor-tier" style="background: <?= $tiers[$sponsor['tier']]['color'] ?>; color: white;">
                    <?= $tiers[$sponsor['tier']]['name'] ?>
                </span>
            </div>
            <div class="sponsor-status <?= $sponsor['active'] ? 'active' : '' ?>" title="<?= $sponsor['active'] ? 'Aktiv' : 'Inaktiv' ?>"></div>
        </div>

        <div class="sponsor-card-body">
            <div class="sponsor-meta">
                <?php if ($sponsor['website']): ?>
                <div class="sponsor-meta-item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" x2="22" y1="12" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    <a href="<?= htmlspecialchars($sponsor['website']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(parse_url($sponsor['website'], PHP_URL_HOST) ?: $sponsor['website']) ?></a>
                </div>
                <?php endif; ?>

                <?php if ($sponsor['contact_email']): ?>
                <div class="sponsor-meta-item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <?= htmlspecialchars($sponsor['contact_email']) ?>
                </div>
                <?php endif; ?>

                <?php if ($sponsor['contact_name']): ?>
                <div class="sponsor-meta-item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?= htmlspecialchars($sponsor['contact_name']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="sponsor-card-footer">
            <button class="btn btn-sm btn-ghost" onclick="editSponsor(<?= $sponsor['id'] ?>)">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-xs"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                Redigera
            </button>
            <button class="btn btn-sm btn-danger" onclick="deleteSponsor(<?= $sponsor['id'] ?>, '<?= htmlspecialchars(addslashes($sponsor['name'])) ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-xs"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                Radera
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Create/Edit Modal -->
<div class="modal" id="sponsorModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Ny sponsor</h3>
            <button class="modal-close" onclick="closeModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-lg"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        </div>
        <form id="sponsorForm" onsubmit="saveSponsor(event)" enctype="multipart/form-data">
            <input type="hidden" id="sponsorId" name="id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Namn *</label>
                    <input type="text" class="form-input" id="sponsorName" name="name" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nivå</label>
                        <select class="form-select" id="sponsorTier" name="tier">
                            <?php foreach ($tiers as $tierKey => $tier): ?>
                            <option value="<?= $tierKey ?>"><?= $tier['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="sponsorActive" name="active">
                            <option value="1">Aktiv</option>
                            <option value="0">Inaktiv</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Serier</label>
                    <div class="series-checkboxes" id="seriesCheckboxes" style="display: flex; flex-wrap: wrap; gap: var(--space-sm);">
                        <?php foreach ($allSeries as $series): ?>
                        <label style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: var(--color-bg-sunken); border-radius: var(--radius-sm); cursor: pointer; font-size: 0.875rem;">
                            <input type="checkbox" name="series[]" value="<?= $series['id'] ?>" class="series-checkbox">
                            <?= htmlspecialchars($series['short_name'] ?: $series['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Webbplats</label>
                    <input type="url" class="form-input" id="sponsorWebsite" name="website" placeholder="https://...">
                </div>

                <div class="form-group">
                    <label class="form-label">Beskrivning</label>
                    <textarea class="form-textarea" id="sponsorDescription" name="description" rows="3"></textarea>
                </div>

                <!-- Logo section with three sizes -->
                <div style="background: var(--color-bg-sunken); padding: var(--space-md); border-radius: var(--radius-md); margin-bottom: var(--space-md);">
                    <h4 style="margin: 0 0 var(--space-md) 0; font-size: 0.9rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm" style="display: inline; vertical-align: middle; margin-right: 4px;"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                        Logotyper
                    </h4>
                    <p style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: var(--space-md);">
                        Ladda upp logotyper i <a href="/admin/media.php?folder=sponsors" target="_blank" style="color: var(--color-accent);">Mediabiblioteket</a> först, välj sedan här.
                    </p>

                    <!-- Banner Logo (1200x150) -->
                    <div class="form-group">
                        <label class="form-label">
                            Banner-logo <code style="background: var(--color-bg); padding: 2px 6px; border-radius: 4px; font-size: 0.75rem;">1200×150px</code>
                        </label>
                        <div class="logo-picker">
                            <div class="logo-preview" id="logoBannerPreview" style="width: 120px; height: 40px;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-md"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                            </div>
                            <input type="hidden" id="logoBannerId" name="logo_banner_id">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openMediaPicker('banner')">Välj från media</button>
                            <button type="button" class="btn btn-sm btn-ghost" onclick="clearLogoField('banner')">Ta bort</button>
                        </div>
                        <small class="text-secondary">Stor banner högst upp på event-sidan</small>
                    </div>

                    <!-- Standard Logo (200x60) -->
                    <div class="form-group">
                        <label class="form-label">
                            Standard-logo <code style="background: var(--color-bg); padding: 2px 6px; border-radius: 4px; font-size: 0.75rem;">200×60px</code>
                        </label>
                        <div class="logo-picker">
                            <div class="logo-preview" id="logoStandardPreview">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-md"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                            </div>
                            <input type="hidden" id="logoStandardId" name="logo_standard_id">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openMediaPicker('standard')">Välj från media</button>
                            <button type="button" class="btn btn-sm btn-ghost" onclick="clearLogoField('standard')">Ta bort</button>
                        </div>
                        <small class="text-secondary">Logo-raden under event-info</small>
                    </div>

                    <!-- Small Logo (160x40) -->
                    <div class="form-group">
                        <label class="form-label">
                            Liten logo <code style="background: var(--color-bg); padding: 2px 6px; border-radius: 4px; font-size: 0.75rem;">160×40px</code>
                        </label>
                        <div class="logo-picker">
                            <div class="logo-preview" id="logoSmallPreview" style="width: 60px; height: 60px;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-md"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                            </div>
                            <input type="hidden" id="logoSmallId" name="logo_small_id">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openMediaPicker('small')">Välj från media</button>
                            <button type="button" class="btn btn-sm btn-ghost" onclick="clearLogoField('small')">Ta bort</button>
                        </div>
                        <small class="text-secondary">"Resultat sponsrat av" vid klasserna</small>
                    </div>
                </div>

                <hr style="margin: var(--space-lg) 0; border: none; border-top: 1px solid var(--color-border);">

                <h4 class="mb-md">Kontaktperson</h4>

                <div class="form-group">
                    <label class="form-label">Namn</label>
                    <input type="text" class="form-input" id="contactName" name="contact_name">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">E-post</label>
                        <input type="email" class="form-input" id="contactEmail" name="contact_email">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Telefon</label>
                        <input type="tel" class="form-input" id="contactPhone" name="contact_phone">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Sorteringsordning</label>
                    <input type="number" class="form-input" id="displayOrder" name="display_order" value="0" min="0">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Avbryt</button>
                <button type="submit" class="btn btn-primary">Spara</button>
            </div>
        </form>
    </div>
</div>

<!-- Media Picker Modal -->
<div class="modal" id="mediaPickerModal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Välj bild från Mediabiblioteket</h3>
            <button type="button" class="close-btn" onclick="closeMediaModal()">&times;</button>
        </div>
        <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
            <div id="mediaGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px;">
                <!-- Media items will be loaded here -->
            </div>
        </div>
        <div class="modal-footer">
            <a href="/admin/media.php?folder=sponsors" target="_blank" class="btn btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm" style="margin-right: 4px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>
                Öppna Mediabiblioteket
            </a>
            <button type="button" class="btn btn-ghost" onclick="closeMediaModal()">Avbryt</button>
        </div>
    </div>
</div>

<script>
let currentSponsorId = null;
let currentLogoField = null;
let mediaData = [];

function filterBySeries(seriesId) {
    const url = new URL(window.location.href);
    if (seriesId) {
        url.searchParams.set('series', seriesId);
    } else {
        url.searchParams.delete('series');
    }
    window.location.href = url.toString();
}

function openCreateModal() {
    currentSponsorId = null;
    document.getElementById('modalTitle').textContent = 'Ny sponsor';
    document.getElementById('sponsorForm').reset();
    document.getElementById('sponsorId').value = '';
    document.getElementById('sponsorTier').value = 'bronze';
    document.getElementById('sponsorActive').value = '1';
    document.querySelectorAll('.series-checkbox').forEach(cb => cb.checked = false);
    clearLogoField('banner');
    clearLogoField('standard');
    clearLogoField('small');
    document.getElementById('sponsorModal').classList.add('active');
}

async function editSponsor(id) {
    try {
        const response = await fetch(`/api/sponsors.php?action=get&id=${id}`);
        const result = await response.json();

        if (!result.success) {
            alert(result.error);
            return;
        }

        const sponsor = result.data;
        currentSponsorId = id;

        document.getElementById('modalTitle').textContent = 'Redigera sponsor';
        document.getElementById('sponsorId').value = id;
        document.getElementById('sponsorName').value = sponsor.name || '';
        document.getElementById('sponsorTier').value = sponsor.tier || 'bronze';
        document.getElementById('sponsorActive').value = sponsor.active ? '1' : '0';
        document.getElementById('sponsorWebsite').value = sponsor.website || '';
        document.getElementById('sponsorDescription').value = sponsor.description || '';
        document.getElementById('contactName').value = sponsor.contact_name || '';
        document.getElementById('contactEmail').value = sponsor.contact_email || '';
        document.getElementById('contactPhone').value = sponsor.contact_phone || '';
        document.getElementById('displayOrder').value = sponsor.display_order || 0;

        // Set logo fields
        clearLogoField('banner');
        clearLogoField('standard');
        clearLogoField('small');

        if (sponsor.logo_banner_id) {
            setLogoFieldById('banner', sponsor.logo_banner_id, sponsor.banner_logo_url);
        }
        if (sponsor.logo_standard_id) {
            setLogoFieldById('standard', sponsor.logo_standard_id, sponsor.standard_logo_url);
        }
        if (sponsor.logo_small_id) {
            setLogoFieldById('small', sponsor.logo_small_id, sponsor.small_logo_url);
        }

        // Fallback to legacy logo
        if (!sponsor.logo_banner_id && !sponsor.logo_standard_id && !sponsor.logo_small_id) {
            const legacyUrl = sponsor.logo_url || (sponsor.logo ? '/uploads/sponsors/' + sponsor.logo : null);
            if (legacyUrl) {
                setLogoFieldByUrl('standard', legacyUrl);
            }
        }

        document.querySelectorAll('.series-checkbox').forEach(cb => {
            cb.checked = sponsor.series_ids && sponsor.series_ids.includes(parseInt(cb.value));
        });

        document.getElementById('sponsorModal').classList.add('active');
    } catch (error) {
        console.error('Error loading sponsor:', error);
        alert('Kunde inte ladda sponsordata');
    }
}

function closeModal() {
    document.getElementById('sponsorModal').classList.remove('active');
    currentSponsorId = null;
}

function closeMediaModal() {
    document.getElementById('mediaPickerModal').classList.remove('active');
    currentLogoField = null;
}

async function saveSponsor(event) {
    event.preventDefault();

    const form = document.getElementById('sponsorForm');
    const formData = new FormData(form);

    const selectedSeries = [];
    document.querySelectorAll('.series-checkbox:checked').forEach(cb => {
        selectedSeries.push(parseInt(cb.value));
    });

    const data = {
        name: formData.get('name'),
        tier: formData.get('tier'),
        active: formData.get('active') === '1',
        website: formData.get('website') || null,
        description: formData.get('description') || null,
        contact_name: formData.get('contact_name') || null,
        contact_email: formData.get('contact_email') || null,
        contact_phone: formData.get('contact_phone') || null,
        display_order: parseInt(formData.get('display_order')) || 0,
        logo_banner_id: formData.get('logo_banner_id') || null,
        logo_standard_id: formData.get('logo_standard_id') || null,
        logo_small_id: formData.get('logo_small_id') || null,
        series_ids: selectedSeries
    };

    try {
        let response;
        if (currentSponsorId) {
            data.id = currentSponsorId;
            response = await fetch('/api/sponsors.php?action=update', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
        } else {
            response = await fetch('/api/sponsors.php?action=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
        }

        const result = await response.json();
        if (result.success) {
            closeModal();
            location.reload();
        } else {
            alert(result.error || 'Kunde inte spara');
        }
    } catch (error) {
        console.error('Error saving sponsor:', error);
        alert('Kunde inte spara');
    }
}

async function deleteSponsor(id, name) {
    if (!confirm(`Vill du radera sponsorn "${name}"?`)) return;

    try {
        const response = await fetch(`/api/sponsors.php?action=delete&id=${id}`, { method: 'DELETE' });
        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert(result.error);
        }
    } catch (error) {
        console.error('Error deleting sponsor:', error);
        alert('Kunde inte radera');
    }
}

// Media picker functions
async function openMediaPicker(field) {
    currentLogoField = field;
    const modal = document.getElementById('mediaPickerModal');
    const grid = document.getElementById('mediaGrid');

    grid.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--color-text-secondary);">Laddar media...</div>';
    modal.classList.add('active');

    try {
        const response = await fetch('/api/media.php?action=list&folder=sponsors&limit=100');
        const result = await response.json();

        if (result.success && result.data.length > 0) {
            mediaData = result.data;
            renderMediaGrid();
        } else {
            grid.innerHTML = `
                <div style="text-align: center; padding: 40px; color: var(--color-text-secondary);">
                    <p>Inga bilder i sponsormappen.</p>
                    <a href="/admin/media.php?folder=sponsors" target="_blank" class="btn btn-primary" style="margin-top: 16px;">
                        Gå till Mediabiblioteket
                    </a>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading media:', error);
        grid.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--color-danger);">Kunde inte ladda media</div>';
    }
}

function renderMediaGrid() {
    const grid = document.getElementById('mediaGrid');
    grid.innerHTML = mediaData.map(media => `
        <div class="media-item" onclick="selectMedia(${media.id}, '${media.filepath}')" style="cursor: pointer; border: 2px solid transparent; border-radius: 8px; padding: 8px; transition: border-color 0.2s;">
            <img src="/${media.filepath}" alt="${media.original_filename || ''}" style="width: 100%; height: 80px; object-fit: contain; background: var(--color-bg-sunken); border-radius: 4px;">
            <div style="font-size: 0.75rem; color: var(--color-text-secondary); margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                ${media.original_filename || 'Bild'}
            </div>
        </div>
    `).join('');
}

function selectMedia(mediaId, filepath) {
    if (!currentLogoField) return;

    const previewId = 'logo' + currentLogoField.charAt(0).toUpperCase() + currentLogoField.slice(1) + 'Preview';
    const inputId = 'logo' + currentLogoField.charAt(0).toUpperCase() + currentLogoField.slice(1) + 'Id';

    document.getElementById(inputId).value = mediaId;
    document.getElementById(previewId).innerHTML = `<img src="/${filepath}" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;

    closeMediaModal();
}

function setLogoFieldById(field, mediaId, url) {
    const previewId = 'logo' + field.charAt(0).toUpperCase() + field.slice(1) + 'Preview';
    const inputId = 'logo' + field.charAt(0).toUpperCase() + field.slice(1) + 'Id';

    document.getElementById(inputId).value = mediaId;
    if (url) {
        document.getElementById(previewId).innerHTML = `<img src="/${url.replace(/^\//, '')}" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;
    }
}

function setLogoFieldByUrl(field, url) {
    const previewId = 'logo' + field.charAt(0).toUpperCase() + field.slice(1) + 'Preview';
    if (url) {
        document.getElementById(previewId).innerHTML = `<img src="${url}" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;
    }
}

function clearLogoField(field) {
    const previewId = 'logo' + field.charAt(0).toUpperCase() + field.slice(1) + 'Preview';
    const inputId = 'logo' + field.charAt(0).toUpperCase() + field.slice(1) + 'Id';

    document.getElementById(inputId).value = '';
    document.getElementById(previewId).innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
    `;
}

// Close modals on escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeModal();
        closeMediaModal();
    }
});

document.getElementById('sponsorModal').addEventListener('click', (e) => {
    if (e.target === document.getElementById('sponsorModal')) closeModal();
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
