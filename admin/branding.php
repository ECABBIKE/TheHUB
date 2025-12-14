<?php
/**
 * Branding / Design System Admin Page
 * View and customize site colors and typography
 * Version: v1.0.0 [2025-12-13]
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Branding settings file
$brandingFile = __DIR__ . '/../uploads/branding.json';

// Load current branding
function loadBranding($file) {
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
    return [];
}

// Save branding
function saveBranding($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

$branding = loadBranding($brandingFile);
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? 'save';

    if ($action === 'reset') {
        // Reset to defaults
        $branding = [];
        saveBranding($brandingFile, $branding);
        $message = 'Branding återställt till standard!';
        $messageType = 'success';
    } elseif ($action === 'save_series') {
        // Save series colors to database
        $seriesId = intval($_POST['series_id'] ?? 0);
        $gradientStart = $_POST['gradient_start'] ?? '';
        $gradientEnd = $_POST['gradient_end'] ?? '';
        $accentColor = $_POST['accent_color'] ?? '';

        if ($seriesId > 0) {
            try {
                $db->update('series', [
                    'gradient_start' => $gradientStart,
                    'gradient_end' => $gradientEnd,
                    'accent_color' => $accentColor
                ], 'id = ?', [$seriesId]);
                $message = 'Seriefärger sparade!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Kunde inte spara: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } else {
        // Save custom colors
        $customColors = [];

        foreach ($_POST as $key => $value) {
            if (strpos($key, 'color_') === 0 && !empty($value)) {
                $varName = str_replace('_', '-', substr($key, 6)); // color_bg_page -> bg-page
                $varName = '--color-' . $varName;

                // Validate it's a valid color
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) || preg_match('/^rgba?\(/', $value)) {
                    $customColors[$varName] = $value;
                }
            }
        }

        $branding['colors'] = $customColors;

        // Save responsive layout settings
        $branding['responsive'] = [
            'mobile_portrait' => [
                'padding' => $_POST['mobile_portrait_padding'] ?? '12',
                'radius' => $_POST['mobile_portrait_radius'] ?? '0'
            ],
            'tablet' => [
                'padding' => $_POST['tablet_padding'] ?? '24',
                'radius' => $_POST['tablet_radius'] ?? '8'
            ],
            'desktop' => [
                'padding' => $_POST['desktop_padding'] ?? '32',
                'radius' => $_POST['desktop_radius'] ?? '12'
            ]
        ];

        saveBranding($brandingFile, $branding);
        $message = 'Branding sparad!';
        $messageType = 'success';
    }
}

// Fetch active series for branding
$seriesList = [];
try {
    $seriesList = $db->getAll("
        SELECT id, name, slug,
               COALESCE(gradient_start, '#004A98') as gradient_start,
               COALESCE(gradient_end, '#002a5c') as gradient_end,
               COALESCE(accent_color, '#61CE70') as accent_color,
               logo_dark
        FROM series
        WHERE active = 1
        ORDER BY name ASC
    ");
} catch (Exception $e) {
    // Try without logo_dark column
    try {
        $seriesList = $db->getAll("
            SELECT id, name, slug,
                   COALESCE(gradient_start, '#004A98') as gradient_start,
                   COALESCE(gradient_end, '#002a5c') as gradient_end,
                   COALESCE(accent_color, '#61CE70') as accent_color,
                   NULL as logo_dark
            FROM series
            WHERE active = 1
            ORDER BY name ASC
        ");
    } catch (Exception $e2) {
        // Last resort - just get basic series info
        $seriesList = $db->getAll("SELECT id, name, slug FROM series WHERE active = 1 ORDER BY name ASC");
    }
}

// Define color groups for display
$colorGroups = [
    'Bakgrunder' => [
        'bg-page' => ['label' => 'Sidbakgrund', 'dark' => '#0A0C14', 'light' => '#F4F5F7'],
        'bg-surface' => ['label' => 'Ytor (kort, modals)', 'dark' => '#12141C', 'light' => '#FFFFFF'],
        'bg-card' => ['label' => 'Kort', 'dark' => '#1A1D28', 'light' => '#FFFFFF'],
        'bg-sunken' => ['label' => 'Nedsänkta ytor', 'dark' => '#06080E', 'light' => '#E9EBEE'],
    ],
    'Text' => [
        'text-primary' => ['label' => 'Primär text', 'dark' => '#F9FAFB', 'light' => '#171717'],
        'text-secondary' => ['label' => 'Sekundär text', 'dark' => '#D1D5DB', 'light' => '#4B5563'],
        'text-tertiary' => ['label' => 'Tertiär text', 'dark' => '#9CA3AF', 'light' => '#6B7280'],
        'text-muted' => ['label' => 'Dämpad text', 'dark' => '#6B7280', 'light' => '#9CA3AF'],
    ],
    'Accent & Knappar' => [
        'accent' => ['label' => 'Accentfärg', 'dark' => '#3B9EFF', 'light' => '#004A98'],
        'accent-hover' => ['label' => 'Accent hover', 'dark' => '#60B0FF', 'light' => '#003B7C'],
        'accent-light' => ['label' => 'Accent ljus', 'dark' => 'rgba(59,158,255,0.15)', 'light' => '#E8F0FB'],
    ],
    'Status' => [
        'success' => ['label' => 'Framgång', 'dark' => '#10B981', 'light' => '#059669'],
        'warning' => ['label' => 'Varning', 'dark' => '#FBBF24', 'light' => '#D97706'],
        'error' => ['label' => 'Fel', 'dark' => '#EF4444', 'light' => '#DC2626'],
        'info' => ['label' => 'Info', 'dark' => '#38BDF8', 'light' => '#0284C7'],
    ],
    'Kanter' => [
        'border' => ['label' => 'Kant', 'dark' => '#2D3139', 'light' => '#E5E7EB'],
        'border-strong' => ['label' => 'Stark kant', 'dark' => '#3F444D', 'light' => '#D1D5DB'],
    ],
];

// Typography settings
$fonts = [
    'heading' => ['label' => 'Rubriker (H1)', 'value' => 'Oswald', 'example' => 'THEHUB GRAVITY'],
    'heading-secondary' => ['label' => 'Underrubriker (H2-H3)', 'value' => 'Cabin Condensed', 'example' => 'Kalender & Event'],
    'body' => ['label' => 'Brödtext', 'value' => 'Manrope', 'example' => 'Detta är ett exempel på brödtext som visas på sidan.'],
    'link' => ['label' => 'Länkar', 'value' => 'Roboto', 'example' => 'Klicka här för mer info'],
];

$spacings = [
    '2xs' => '4px',
    'xs' => '8px',
    'sm' => '12px',
    'md' => '16px',
    'lg' => '24px',
    'xl' => '32px',
    '2xl' => '48px',
    '3xl' => '64px',
];

$radii = [
    'sm' => '6px',
    'md' => '10px',
    'lg' => '14px',
    'xl' => '20px',
    'full' => '9999px',
];

// Page config
$page_title = 'Branding';
$breadcrumbs = [
    ['label' => 'System', 'url' => '/admin/'],
    ['label' => 'Branding']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.branding-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-lg);
}

.color-group {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
}

.color-group h3 {
    margin: 0 0 var(--space-md);
    font-size: var(--text-lg);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.color-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) 0;
    border-bottom: 1px solid var(--color-border);
}

.color-item:last-child {
    border-bottom: none;
}

.color-swatch {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    border: 2px solid var(--color-border);
    flex-shrink: 0;
    cursor: pointer;
    position: relative;
}

.color-swatch input[type="color"] {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.color-info {
    flex: 1;
    min-width: 0;
}

.color-label {
    font-weight: var(--weight-medium);
    font-size: var(--text-sm);
}

.color-value {
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
}

.color-input {
    width: 90px;
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    padding: var(--space-xs);
    background: var(--color-bg-sunken);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    color: var(--color-text-primary);
}

/* Typography Section */
.font-preview {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}

.font-preview h4 {
    margin: 0 0 var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.font-sample {
    padding: var(--space-md);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    margin-top: var(--space-sm);
}

.font-sample.heading { font-family: var(--font-heading); font-size: var(--text-2xl); text-transform: uppercase; }
.font-sample.heading-secondary { font-family: var(--font-heading-secondary); font-size: var(--text-xl); }
.font-sample.body { font-family: var(--font-body); font-size: var(--text-base); }
.font-sample.link { font-family: var(--font-link); font-size: var(--text-base); color: var(--color-accent); }

/* Spacing Preview */
.spacing-grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
}

.spacing-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-sm);
}

.spacing-bar {
    height: 20px;
    background: var(--color-accent);
    border-radius: var(--radius-sm);
}

.spacing-label {
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    min-width: 60px;
}

/* Radius Preview */
.radius-grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
}

.radius-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-xs);
}

.radius-sample {
    width: 60px;
    height: 60px;
    background: var(--color-accent);
}

.radius-label {
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
}

/* Live Preview */
.live-preview {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-top: var(--space-lg);
}

.preview-buttons {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
}

.preview-alerts {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    margin-top: var(--space-md);
}

/* Series Colors */
.series-color-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-lg);
}

.series-color-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.series-color-preview {
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.series-color-preview .series-logo {
    max-height: 50px;
    max-width: 150px;
    object-fit: contain;
    filter: brightness(0) invert(1);
}

.series-color-preview .series-name-overlay {
    color: white;
    font-weight: var(--weight-bold);
    font-size: var(--text-lg);
    text-transform: uppercase;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.series-color-form {
    padding: var(--space-md);
}

.series-color-form h4 {
    margin: 0 0 var(--space-md);
    font-size: var(--text-base);
}

.series-color-row {
    display: flex;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
    flex-wrap: wrap;
}

.series-color-row label {
    display: flex;
    flex-direction: column;
    gap: var(--space-2xs);
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
}

.color-swatch-mini {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    border: 2px solid var(--color-border);
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.color-swatch-mini input[type="color"] {
    position: absolute;
    inset: -5px;
    width: calc(100% + 10px);
    height: calc(100% + 10px);
    opacity: 0;
    cursor: pointer;
}
</style>

<?php if ($message): ?>
<div class="alert alert--<?= $messageType ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<form method="POST" id="brandingForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <!-- Action Buttons -->
    <div class="flex gap-md mb-lg" style="justify-content: flex-end;">
        <button type="button" class="btn btn--secondary" onclick="if(confirm('Återställa alla färger till standard?')) { document.querySelector('[name=action]').value='reset'; document.getElementById('brandingForm').submit(); }">
            <i data-lucide="rotate-ccw"></i>
            Återställ standard
        </button>
        <button type="submit" class="btn btn--primary">
            <i data-lucide="save"></i>
            Spara ändringar
        </button>
    </div>

    <!-- Colors Section -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="palette"></i>
                Färger
            </h2>
            <span class="badge">Mörkt tema</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Klicka på en färgruta för att välja ny färg. Ändringar sparas och appliceras på hela sidan.
            </p>

            <div class="branding-grid">
                <?php foreach ($colorGroups as $groupName => $colors): ?>
                <div class="color-group">
                    <h3>
                        <i data-lucide="<?= $groupName === 'Bakgrunder' ? 'square' : ($groupName === 'Text' ? 'type' : ($groupName === 'Status' ? 'check-circle' : 'minus')) ?>"></i>
                        <?= h($groupName) ?>
                    </h3>
                    <?php foreach ($colors as $varKey => $colorInfo):
                        $fullVar = '--color-' . $varKey;
                        $currentValue = $branding['colors'][$fullVar] ?? $colorInfo['dark'];
                        $inputName = 'color_' . str_replace('-', '_', $varKey);
                    ?>
                    <div class="color-item">
                        <div class="color-swatch" style="background: <?= h($currentValue) ?>;">
                            <input type="color"
                                   name="<?= $inputName ?>"
                                   value="<?= h(preg_match('/^#/', $currentValue) ? $currentValue : '#000000') ?>"
                                   onchange="updateColorPreview(this, '<?= $varKey ?>')">
                        </div>
                        <div class="color-info">
                            <div class="color-label"><?= h($colorInfo['label']) ?></div>
                            <div class="color-value"><?= h($fullVar) ?></div>
                        </div>
                        <input type="text"
                               class="color-input"
                               value="<?= h($currentValue) ?>"
                               data-var="<?= $varKey ?>"
                               onchange="updateFromText(this)">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Responsive Layout Section -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="layout"></i>
                Responsiv Layout
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Justera padding och rundning för varje plattform. Samma värde appliceras på alla kort/element inom plattformen.
            </p>

            <?php
            $responsive = $branding['responsive'] ?? [];
            $mobilePortrait = $responsive['mobile_portrait'] ?? ['padding' => '12', 'radius' => '0'];
            $tablet = $responsive['tablet'] ?? ['padding' => '24', 'radius' => '8'];
            $desktop = $responsive['desktop'] ?? ['padding' => '32', 'radius' => '12'];
            ?>

            <!-- MOBILE PORTRAIT (0-767px) -->
            <div class="device-settings" style="margin-bottom: 1.5rem; padding: 1.5rem; background: var(--color-bg-surface); border-radius: var(--radius-lg); border-left: 4px solid #3B9EFF;">
                <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="smartphone"></i>
                    Mobile Portrait (0-767px)
                </h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Container Padding</label>
                        <select name="mobile_portrait_padding" class="form-control" style="width: 100%;" onchange="previewResponsive('portrait', 'padding', this.value)">
                            <option value="8" <?= $mobilePortrait['padding'] === '8' ? 'selected' : '' ?>>8px - Extra Tight</option>
                            <option value="10" <?= $mobilePortrait['padding'] === '10' ? 'selected' : '' ?>>10px - Tight</option>
                            <option value="12" <?= $mobilePortrait['padding'] === '12' ? 'selected' : '' ?>>12px - Compact</option>
                            <option value="16" <?= $mobilePortrait['padding'] === '16' ? 'selected' : '' ?>>16px - Normal</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Border Radius</label>
                        <select name="mobile_portrait_radius" class="form-control" style="width: 100%;" onchange="previewResponsive('portrait', 'radius', this.value)">
                            <option value="0" <?= $mobilePortrait['radius'] === '0' ? 'selected' : '' ?>>0px - Kantigt (Edge-to-edge)</option>
                            <option value="4" <?= $mobilePortrait['radius'] === '4' ? 'selected' : '' ?>>4px - Lätt rundat</option>
                            <option value="6" <?= $mobilePortrait['radius'] === '6' ? 'selected' : '' ?>>6px - Rundat</option>
                            <option value="8" <?= $mobilePortrait['radius'] === '8' ? 'selected' : '' ?>>8px - Mycket rundat</option>
                        </select>
                    </div>
                </div>
                <small style="display: block; margin-top: 0.75rem; opacity: 0.7; font-size: 0.75rem;">
                    Rekommenderat: 12px padding + 0px radius för edge-to-edge känsla
                </small>
            </div>

            <!-- TABLET / LANDSCAPE (768-1023px) -->
            <div class="device-settings" style="margin-bottom: 1.5rem; padding: 1.5rem; background: var(--color-bg-surface); border-radius: var(--radius-lg); border-left: 4px solid #10B981;">
                <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="tablet"></i>
                    Landscape / Tablet (768-1023px)
                </h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Container Padding</label>
                        <select name="tablet_padding" class="form-control" style="width: 100%;" onchange="previewResponsive('tablet', 'padding', this.value)">
                            <option value="16" <?= $tablet['padding'] === '16' ? 'selected' : '' ?>>16px - Compact</option>
                            <option value="20" <?= $tablet['padding'] === '20' ? 'selected' : '' ?>>20px - Normal</option>
                            <option value="24" <?= $tablet['padding'] === '24' ? 'selected' : '' ?>>24px - Luftigt</option>
                            <option value="32" <?= $tablet['padding'] === '32' ? 'selected' : '' ?>>32px - Extra luftigt</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Border Radius</label>
                        <select name="tablet_radius" class="form-control" style="width: 100%;" onchange="previewResponsive('tablet', 'radius', this.value)">
                            <option value="0" <?= $tablet['radius'] === '0' ? 'selected' : '' ?>>0px - Kantigt</option>
                            <option value="6" <?= $tablet['radius'] === '6' ? 'selected' : '' ?>>6px - Lätt rundat</option>
                            <option value="8" <?= $tablet['radius'] === '8' ? 'selected' : '' ?>>8px - Rundat</option>
                            <option value="12" <?= $tablet['radius'] === '12' ? 'selected' : '' ?>>12px - Mycket rundat</option>
                            <option value="16" <?= $tablet['radius'] === '16' ? 'selected' : '' ?>>16px - Extra rundat</option>
                        </select>
                    </div>
                </div>
                <small style="display: block; margin-top: 0.75rem; opacity: 0.7; font-size: 0.75rem;">
                    Rekommenderat: 24px padding + 8px radius för balanserad design
                </small>
            </div>

            <!-- DESKTOP (1024px+) -->
            <div class="device-settings" style="padding: 1.5rem; background: var(--color-bg-surface); border-radius: var(--radius-lg); border-left: 4px solid #8B5CF6;">
                <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="monitor"></i>
                    Desktop (1024px+)
                </h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Container Padding</label>
                        <select name="desktop_padding" class="form-control" style="width: 100%;" onchange="previewResponsive('desktop', 'padding', this.value)">
                            <option value="24" <?= $desktop['padding'] === '24' ? 'selected' : '' ?>>24px - Compact</option>
                            <option value="32" <?= $desktop['padding'] === '32' ? 'selected' : '' ?>>32px - Normal</option>
                            <option value="40" <?= $desktop['padding'] === '40' ? 'selected' : '' ?>>40px - Luftigt</option>
                            <option value="48" <?= $desktop['padding'] === '48' ? 'selected' : '' ?>>48px - Extra luftigt</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Border Radius</label>
                        <select name="desktop_radius" class="form-control" style="width: 100%;" onchange="previewResponsive('desktop', 'radius', this.value)">
                            <option value="6" <?= $desktop['radius'] === '6' ? 'selected' : '' ?>>6px - Lätt rundat</option>
                            <option value="8" <?= $desktop['radius'] === '8' ? 'selected' : '' ?>>8px - Rundat</option>
                            <option value="12" <?= $desktop['radius'] === '12' ? 'selected' : '' ?>>12px - Mycket rundat</option>
                            <option value="16" <?= $desktop['radius'] === '16' ? 'selected' : '' ?>>16px - Extra rundat</option>
                            <option value="20" <?= $desktop['radius'] === '20' ? 'selected' : '' ?>>20px - Max rundat</option>
                        </select>
                    </div>
                </div>
                <small style="display: block; margin-top: 0.75rem; opacity: 0.7; font-size: 0.75rem;">
                    Rekommenderat: 32px padding + 12px radius för professionell look
                </small>
            </div>

            <!-- Info Box -->
            <div style="margin-top: 1.5rem; padding: 1rem; background: var(--color-bg-sunken); border-radius: var(--radius-md); border: 1px solid var(--color-border);">
                <p style="margin: 0; font-size: 0.875rem; color: var(--color-text-secondary); display: flex; align-items: start; gap: 0.5rem;">
                    <i data-lucide="info" style="width: 16px; height: 16px; margin-top: 2px; flex-shrink: 0;"></i>
                    <span>
                        <strong>Så fungerar det:</strong> Samma radius appliceras på ALLA kort, knappar och containers inom varje plattform.
                        Spara och ladda om sidan för att se effekten, eller ändra din browser-storlek för att testa olika breakpoints.
                    </span>
                </p>
            </div>
        </div>
    </div>
</form>

<!-- Series Colors Section (separate forms for each series) -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <i data-lucide="trophy"></i>
            Seriefärger
        </h2>
        <span class="badge"><?= count($seriesList) ?> serier</span>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-lg">
            Varje serie har egna färger som används för datumboxar, topbar och andra serierelaterade element.
        </p>

        <?php if (empty($seriesList)): ?>
        <div class="alert alert--warning">
            <i data-lucide="alert-triangle"></i>
            Inga aktiva serier hittades. Se till att det finns serier med <code>active = 1</code> i databasen.
        </div>
        <?php else: ?>
        <div class="series-color-grid">
            <?php foreach ($seriesList as $series): ?>
            <div class="series-color-card">
                <div class="series-color-preview" style="background: linear-gradient(135deg, <?= h($series['gradient_start'] ?? '#004A98') ?>, <?= h($series['gradient_end'] ?? '#002a5c') ?>);">
                    <?php if ($series['logo_dark']): ?>
                        <img src="/uploads/series/<?= h($series['logo_dark']) ?>" alt="<?= h($series['name']) ?>" class="series-logo">
                    <?php else: ?>
                        <span class="series-name-overlay"><?= h($series['name']) ?></span>
                    <?php endif; ?>
                </div>
                <form method="POST" class="series-color-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_series">
                    <input type="hidden" name="series_id" value="<?= $series['id'] ?>">

                    <h4><?= h($series['name']) ?></h4>

                    <div class="series-color-row">
                        <label>
                            <span>Gradient start</span>
                            <div class="color-swatch-mini" style="background: <?= h($series['gradient_start'] ?? '#004A98') ?>;">
                                <input type="color" name="gradient_start" value="<?= h($series['gradient_start'] ?? '#004A98') ?>" onchange="updateSeriesPreview(this)">
                            </div>
                        </label>
                        <label>
                            <span>Gradient slut</span>
                            <div class="color-swatch-mini" style="background: <?= h($series['gradient_end'] ?? '#002a5c') ?>;">
                                <input type="color" name="gradient_end" value="<?= h($series['gradient_end'] ?? '#002a5c') ?>" onchange="updateSeriesPreview(this)">
                            </div>
                        </label>
                        <label>
                            <span>Accent</span>
                            <div class="color-swatch-mini" style="background: <?= h($series['accent_color'] ?? '#61CE70') ?>;">
                                <input type="color" name="accent_color" value="<?= h($series['accent_color'] ?? '#61CE70') ?>" onchange="updateSeriesPreview(this)">
                            </div>
                        </label>
                    </div>

                    <button type="submit" class="btn btn--sm btn--primary">
                        <i data-lucide="save"></i>
                        Spara
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Display-only Design System Reference Sections -->

<!-- Typography Section -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="type"></i>
                Typografi
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Förhandsvisning av de olika typsnitten som används på sidan.
            </p>

            <?php foreach ($fonts as $fontKey => $fontInfo): ?>
            <div class="font-preview">
                <h4><?= h($fontInfo['label']) ?> - <?= h($fontInfo['value']) ?></h4>
                <code class="text-secondary text-sm">var(--font-<?= $fontKey ?>)</code>
                <div class="font-sample <?= $fontKey ?>">
                    <?= h($fontInfo['example']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Spacing Section -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="ruler"></i>
                Spacing
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Standardiserade avstånd som används för marginaler och padding.
            </p>

            <div class="spacing-grid">
                <?php foreach ($spacings as $name => $value): ?>
                <div class="spacing-item">
                    <div class="spacing-bar" style="width: <?= $value ?>;"></div>
                    <div class="spacing-label">
                        --space-<?= $name ?><br>
                        <strong><?= $value ?></strong>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Radius Section -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="square"></i>
                Border Radius
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Standardiserade hörnradier för knappar, kort och andra element.
            </p>

            <div class="radius-grid">
                <?php foreach ($radii as $name => $value): ?>
                <div class="radius-item">
                    <div class="radius-sample" style="border-radius: <?= $value ?>;"></div>
                    <div class="radius-label">
                        --radius-<?= $name ?><br>
                        <?= $value ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Live Preview -->
    <div class="live-preview">
        <h3 class="mb-md">
            <i data-lucide="eye"></i>
            Live Preview
        </h3>

        <div class="preview-buttons">
            <button type="button" class="btn btn--primary">Primär knapp</button>
            <button type="button" class="btn btn--secondary">Sekundär knapp</button>
            <button type="button" class="btn btn--ghost">Ghost knapp</button>
            <span class="badge badge--primary">Badge</span>
            <span class="badge badge--success">Success</span>
            <span class="badge badge--warning">Warning</span>
            <span class="badge badge--error">Error</span>
        </div>

        <div class="preview-alerts">
            <div class="alert alert--success">
                <i data-lucide="check-circle"></i>
                Detta är ett framgångsmeddelande
            </div>
            <div class="alert alert--warning">
                <i data-lucide="alert-triangle"></i>
                Detta är en varning
            </div>
            <div class="alert alert--error">
                <i data-lucide="x-circle"></i>
                Detta är ett felmeddelande
            </div>
            <div class="alert alert--info">
                <i data-lucide="info"></i>
                Detta är ett informationsmeddelande
            </div>
        </div>
    </div>

<script>
function updateColorPreview(input, varKey) {
    const swatch = input.closest('.color-swatch');
    swatch.style.background = input.value;

    // Update text input
    const row = input.closest('.color-item');
    const textInput = row.querySelector('.color-input');
    textInput.value = input.value;

    // Apply live preview
    document.documentElement.style.setProperty('--color-' + varKey, input.value);
}

function updateFromText(input) {
    const varKey = input.dataset.var;
    const value = input.value;

    // Update swatch
    const row = input.closest('.color-item');
    const swatch = row.querySelector('.color-swatch');
    swatch.style.background = value;

    // Update color input if it's a hex color
    if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
        const colorInput = row.querySelector('input[type="color"]');
        colorInput.value = value;
    }

    // Apply live preview
    document.documentElement.style.setProperty('--color-' + varKey, value);
}

// Update series preview when changing colors
function updateSeriesPreview(input) {
    const card = input.closest('.series-color-card');
    const preview = card.querySelector('.series-color-preview');
    const form = input.closest('form');

    const gradientStart = form.querySelector('[name="gradient_start"]').value;
    const gradientEnd = form.querySelector('[name="gradient_end"]').value;

    // Update the preview gradient
    preview.style.background = `linear-gradient(135deg, ${gradientStart}, ${gradientEnd})`;

    // Update the mini swatch
    const swatch = input.closest('.color-swatch-mini');
    if (swatch) {
        swatch.style.background = input.value;
    }
}

// Live preview for responsive settings
function previewResponsive(device, type, value) {
    const width = window.innerWidth;

    // Determine if current viewport matches device
    let applies = false;
    if (device === 'portrait' && width <= 767) applies = true;
    if (device === 'tablet' && width >= 768 && width <= 1023) applies = true;
    if (device === 'desktop' && width >= 1024) applies = true;

    if (!applies) {
        console.log(`Preview skipped - current viewport (${width}px) doesn't match ${device}`);
        return;
    }

    // Apply preview
    if (type === 'padding') {
        document.documentElement.style.setProperty('--container-padding', value + 'px');
    } else if (type === 'radius') {
        document.documentElement.style.setProperty('--radius-sm', value + 'px');
        document.documentElement.style.setProperty('--radius-md', value + 'px');
        document.documentElement.style.setProperty('--radius-lg', value + 'px');
        document.documentElement.style.setProperty('--radius-xl', value + 'px');
    }
}

// Initialize Lucide icons
document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
