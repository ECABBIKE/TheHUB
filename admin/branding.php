<?php
/**
 * Branding / Design System Admin Page
 * View and customize site colors and typography
 * Version: v1.0.0 [2025-12-13]
 */
require_once __DIR__ . '/../config.php';
require_admin();

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

    // Encode to JSON first to catch any issues
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log('saveBranding: JSON encode failed - ' . json_last_error_msg());
        return false;
    }

    // Write to file
    $result = file_put_contents($file, $json);
    if ($result === false) {
        error_log('saveBranding: file_put_contents failed for ' . $file);
    }

    return $result;
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
            'mobile_landscape' => [
                'sidebar_gap' => $_POST['mobile_landscape_sidebar_gap'] ?? '4'
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

        // Save layout settings
        $branding['layout'] = [
            'content_max_width' => $_POST['content_max_width'] ?? '1400',
            'sidebar_width' => $_POST['sidebar_width'] ?? '72',
            'header_height' => $_POST['header_height'] ?? '60'
        ];

        // Save gradient settings (auto-generate from base color)
        $gradientEnabled = !empty($_POST['gradient_enabled']);
        $gradientBase = trim($_POST['gradient_base'] ?? '');

        if ($gradientEnabled && $gradientBase && preg_match('/^#[0-9A-Fa-f]{6}$/', $gradientBase)) {
            // Auto-generate gradient colors from base
            list($r, $g, $b) = sscanf($gradientBase, "#%02x%02x%02x");

            // Generate lighter version (start) - lighten by 8%
            $startR = min(255, $r + round($r * 0.08));
            $startG = min(255, $g + round($g * 0.08));
            $startB = min(255, $b + round($b * 0.08));
            $gradientStart = sprintf('#%02X%02X%02X', $startR, $startG, $startB);

            // Generate darker version (end) - darken by 12%
            $endR = max(0, $r - round($r * 0.12));
            $endG = max(0, $g - round($g * 0.12));
            $endB = max(0, $b - round($b * 0.12));
            $gradientEnd = sprintf('#%02X%02X%02X', $endR, $endG, $endB);

            $branding['gradient'] = [
                'enabled' => true,
                'base' => $gradientBase,
                'angle' => 135,
                'start' => $gradientStart,
                'end' => $gradientEnd
            ];
        } else {
            $branding['gradient'] = [
                'enabled' => false
            ];
        }

        // Save dark mode colors (bike app colors)
        $darkColors = [];
        if (!empty($_POST['dark_bg_page']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['dark_bg_page'])) {
            $darkColors['bg_page'] = $_POST['dark_bg_page'];
        }
        if (!empty($_POST['dark_bg_card']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['dark_bg_card'])) {
            $darkColors['bg_card'] = $_POST['dark_bg_card'];
        }
        if (!empty($_POST['dark_accent']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['dark_accent'])) {
            $darkColors['accent'] = $_POST['dark_accent'];
        }

        if (!empty($darkColors)) {
            $branding['dark_colors'] = $darkColors;
        } else {
            unset($branding['dark_colors']);
        }

        // Save logo settings
        $branding['logos'] = [
            'sidebar' => trim($_POST['logo_sidebar'] ?? ''),
            'homepage' => trim($_POST['logo_homepage'] ?? ''),
            'favicon' => trim($_POST['logo_favicon'] ?? '')
        ];

        // Debug: Log what we're saving
        error_log('Branding save - logos POST data: ' . json_encode([
            'logo_sidebar' => $_POST['logo_sidebar'] ?? '(not set)',
            'logo_homepage' => $_POST['logo_homepage'] ?? '(not set)',
            'logo_favicon' => $_POST['logo_favicon'] ?? '(not set)'
        ]));
        error_log('Branding save - full data: ' . json_encode($branding));

        $saveResult = saveBranding($brandingFile, $branding);
        error_log('Branding save result: ' . ($saveResult !== false ? 'success (' . $saveResult . ' bytes)' : 'FAILED'));

        if ($saveResult !== false) {
            $message = 'Branding sparad!';
            $messageType = 'success';

            // Show more details if logos were set
            $logoCount = count(array_filter($branding['logos']));
            if ($logoCount > 0) {
                $message .= " ({$logoCount} logotyp(er) sparade)";
            }
        } else {
            $message = 'Fel: Kunde inte spara branding. Kontrollera filrättigheter.';
            $messageType = 'error';
        }
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

    <!-- Logos Section -->
    <?php
    $logos = $branding['logos'] ?? [];
    $sidebarLogo = $logos['sidebar'] ?? '';
    $homepageLogo = $logos['homepage'] ?? '';
    $faviconLogo = $logos['favicon'] ?? '';
    ?>
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="image"></i>
                Logotyper
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Välj logotyper från <a href="/admin/media?folder=branding">Mediabiblioteket</a> eller ladda upp nya där först.
            </p>

            <div class="branding-grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
                <!-- Sidebar Logo -->
                <div class="color-group">
                    <h3>
                        <i data-lucide="sidebar"></i>
                        Sidebar-logga
                    </h3>
                    <p class="text-secondary text-sm mb-md">Visas i navigationens övre del på alla sidor.</p>
                    <input type="hidden" name="logo_sidebar" id="logoSidebarInput" value="<?= h($sidebarLogo) ?>">
                    <div id="logoSidebarPreview" class="logo-preview-box" onclick="openMediaPicker('sidebar')" style="width: 100%; height: 80px; background: var(--color-bg-sunken); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: pointer; border: 2px dashed var(--color-border); transition: border-color 0.2s;">
                        <?php if ($sidebarLogo): ?>
                            <img src="<?= h($sidebarLogo) ?>" alt="Sidebar logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                        <?php else: ?>
                            <span style="color: var(--color-text-muted); font-size: var(--text-sm);">Klicka för att välja bild</span>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-sm);">
                        <button type="button" class="btn btn--secondary btn--sm" onclick="openMediaPicker('sidebar')">
                            <i data-lucide="image"></i> Välj bild
                        </button>
                        <button type="button" class="btn btn--ghost btn--sm" onclick="clearLogo('sidebar')" <?= $sidebarLogo ? '' : 'disabled' ?>>
                            <i data-lucide="x"></i> Ta bort
                        </button>
                    </div>
                </div>

                <!-- Homepage Logo -->
                <div class="color-group">
                    <h3>
                        <i data-lucide="home"></i>
                        Startsida-logga
                    </h3>
                    <p class="text-secondary text-sm mb-md">Visas endast på startsidan (stor logga).</p>
                    <input type="hidden" name="logo_homepage" id="logoHomepageInput" value="<?= h($homepageLogo) ?>">
                    <div id="logoHomepagePreview" class="logo-preview-box" onclick="openMediaPicker('homepage')" style="width: 100%; height: 80px; background: var(--color-bg-sunken); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: pointer; border: 2px dashed var(--color-border); transition: border-color 0.2s;">
                        <?php if ($homepageLogo): ?>
                            <img src="<?= h($homepageLogo) ?>" alt="Homepage logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                        <?php else: ?>
                            <span style="color: var(--color-text-muted); font-size: var(--text-sm);">Klicka för att välja bild</span>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-sm);">
                        <button type="button" class="btn btn--secondary btn--sm" onclick="openMediaPicker('homepage')">
                            <i data-lucide="image"></i> Välj bild
                        </button>
                        <button type="button" class="btn btn--ghost btn--sm" onclick="clearLogo('homepage')" <?= $homepageLogo ? '' : 'disabled' ?>>
                            <i data-lucide="x"></i> Ta bort
                        </button>
                    </div>
                </div>

                <!-- Favicon -->
                <div class="color-group">
                    <h3>
                        <i data-lucide="star"></i>
                        Favicon
                    </h3>
                    <p class="text-secondary text-sm mb-md">Ikon som visas i webbläsarfliken (SVG rekommenderas).</p>
                    <input type="hidden" name="logo_favicon" id="logoFaviconInput" value="<?= h($faviconLogo) ?>">
                    <div id="logoFaviconPreview" class="logo-preview-box" onclick="openMediaPicker('favicon')" style="width: 100%; height: 80px; background: var(--color-bg-sunken); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: pointer; border: 2px dashed var(--color-border); transition: border-color 0.2s;">
                        <?php if ($faviconLogo): ?>
                            <img src="<?= h($faviconLogo) ?>" alt="Favicon" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                        <?php else: ?>
                            <span style="color: var(--color-text-muted); font-size: var(--text-sm);">Klicka för att välja bild</span>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-sm);">
                        <button type="button" class="btn btn--secondary btn--sm" onclick="openMediaPicker('favicon')">
                            <i data-lucide="image"></i> Välj bild
                        </button>
                        <button type="button" class="btn btn--ghost btn--sm" onclick="clearLogo('favicon')" <?= $faviconLogo ? '' : 'disabled' ?>>
                            <i data-lucide="x"></i> Ta bort
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Colors Section -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="palette"></i>
                Färger
            </h2>
            <span class="badge" id="theme-badge">
                <span class="theme-light-text" style="display:none;">Ljust tema</span>
                <span class="theme-dark-text" style="display:none;">Mörkt tema</span>
            </span>
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
            $mobileLandscape = $responsive['mobile_landscape'] ?? ['sidebar_gap' => '4'];
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

            <!-- MOBILE LANDSCAPE (0-1023px landscape) -->
            <div class="device-settings" style="margin-bottom: 1.5rem; padding: 1.5rem; background: var(--color-bg-surface); border-radius: var(--radius-lg); border-left: 4px solid #F59E0B;">
                <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="smartphone" style="transform: rotate(90deg);"></i>
                    Mobile Landscape (0-1023px liggande)
                </h4>

                <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Avstånd mellan sidebar och innehåll</label>
                        <select name="mobile_landscape_sidebar_gap" class="form-control" style="width: 100%;">
                            <option value="0" <?= $mobileLandscape['sidebar_gap'] === '0' ? 'selected' : '' ?>>0px - Ingen marginal</option>
                            <option value="4" <?= $mobileLandscape['sidebar_gap'] === '4' ? 'selected' : '' ?>>4px - Minimal</option>
                            <option value="8" <?= $mobileLandscape['sidebar_gap'] === '8' ? 'selected' : '' ?>>8px - Liten</option>
                            <option value="12" <?= $mobileLandscape['sidebar_gap'] === '12' ? 'selected' : '' ?>>12px - Normal</option>
                            <option value="16" <?= $mobileLandscape['sidebar_gap'] === '16' ? 'selected' : '' ?>>16px - Luftig</option>
                        </select>
                    </div>
                </div>
                <small style="display: block; margin-top: 0.75rem; opacity: 0.7; font-size: 0.75rem;">
                    I liggande läge visas en kompakt sidebar istället för botten-navigation
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

    <!-- Layout Section -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="maximize-2"></i>
                Layout-dimensioner
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Styr bredden pa huvudinnehallet och andra globala layout-dimensioner.
            </p>

            <?php
            $layout = $branding['layout'] ?? [];
            $contentMaxWidth = $layout['content_max_width'] ?? '1400';
            $sidebarWidth = $layout['sidebar_width'] ?? '72';
            $headerHeight = $layout['header_height'] ?? '60';
            ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--space-lg);">
                <!-- Content Max Width -->
                <div class="device-settings" style="padding: 1.5rem; background: var(--color-bg-surface); border-radius: var(--radius-lg); border-left: 4px solid #F59E0B;">
                    <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="monitor"></i>
                        Max innehallsbredd
                    </h4>
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Desktop (1024px+)</label>
                        <select name="content_max_width" class="form-control" style="width: 100%;">
                            <option value="1200" <?= $contentMaxWidth === '1200' ? 'selected' : '' ?>>1200px - Kompakt</option>
                            <option value="1400" <?= $contentMaxWidth === '1400' ? 'selected' : '' ?>>1400px - Standard</option>
                            <option value="1600" <?= $contentMaxWidth === '1600' ? 'selected' : '' ?>>1600px - Bred</option>
                            <option value="1800" <?= $contentMaxWidth === '1800' ? 'selected' : '' ?>>1800px - Extra bred</option>
                            <option value="none" <?= $contentMaxWidth === 'none' ? 'selected' : '' ?>>Ingen grans (full bredd)</option>
                        </select>
                    </div>
                    <small style="display: block; margin-top: 0.75rem; opacity: 0.7; font-size: 0.75rem;">
                        Begransar bredden pa huvudinnehallet. Paverkar inte Event-sidor.
                    </small>
                </div>

                <!-- Sidebar Width -->
                <div class="device-settings" style="padding: 1.5rem; background: var(--color-bg-surface); border-radius: var(--radius-lg); border-left: 4px solid #10B981;">
                    <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="sidebar"></i>
                        Sidopanelens bredd
                    </h4>
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Desktop sidebar</label>
                        <select name="sidebar_width" class="form-control" style="width: 100%;">
                            <option value="56" <?= $sidebarWidth === '56' ? 'selected' : '' ?>>56px - Kompakt</option>
                            <option value="72" <?= $sidebarWidth === '72' ? 'selected' : '' ?>>72px - Standard</option>
                            <option value="88" <?= $sidebarWidth === '88' ? 'selected' : '' ?>>88px - Bred</option>
                        </select>
                    </div>
                    <small style="display: block; margin-top: 0.75rem; opacity: 0.7; font-size: 0.75rem;">
                        Endast synlig pa desktop (900px+)
                    </small>
                </div>

                <!-- Header Height -->
                <div class="device-settings" style="padding: 1.5rem; background: var(--color-bg-surface); border-radius: var(--radius-lg); border-left: 4px solid #8B5CF6;">
                    <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="panel-top"></i>
                        Headerns hojd
                    </h4>
                    <div>
                        <label style="display: block; font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.5rem;">Global header</label>
                        <select name="header_height" class="form-control" style="width: 100%;">
                            <option value="48" <?= $headerHeight === '48' ? 'selected' : '' ?>>48px - Kompakt</option>
                            <option value="56" <?= $headerHeight === '56' ? 'selected' : '' ?>>56px - Lag</option>
                            <option value="60" <?= $headerHeight === '60' ? 'selected' : '' ?>>60px - Standard</option>
                            <option value="64" <?= $headerHeight === '64' ? 'selected' : '' ?>>64px - Hog</option>
                        </select>
                    </div>
                    <small style="display: block; margin-top: 0.75rem; opacity: 0.7; font-size: 0.75rem;">
                        Paverkar toppbaren pa alla sidor
                    </small>
                </div>
            </div>

            <!-- Info Box -->
            <div style="margin-top: 1.5rem; padding: 1rem; background: var(--color-bg-sunken); border-radius: var(--radius-md); border: 1px solid var(--color-border);">
                <p style="margin: 0; font-size: 0.875rem; color: var(--color-text-secondary); display: flex; align-items: start; gap: 0.5rem;">
                    <i data-lucide="info" style="width: 16px; height: 16px; margin-top: 2px; flex-shrink: 0;"></i>
                    <span>
                        <strong>CSS-variabler:</strong> Dessa varden satter <code>--content-max-width</code>, <code>--sidebar-width</code> och <code>--header-height</code>.
                    </span>
                </p>
            </div>
        </div>
    </div>

    <!-- Gradient Section -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2>
                <i data-lucide="palette"></i>
                Bakgrundsgradient
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Välj en basfärg så genererar systemet automatiskt en snygg gradient.
            </p>

            <?php
            $gradient = $branding['gradient'] ?? [];
            $gradientEnabled = !empty($gradient['enabled']);
            $gradientBase = $gradient['base'] ?? '#E8ECF1';
            $gradientStart = $gradient['start'] ?? '#E8ECF1';
            $gradientEnd = $gradient['end'] ?? '#F8F9FB';
            ?>

            <div style="display: flex; flex-direction: column; gap: var(--space-lg);">
                <!-- Enable Toggle -->
                <div style="display: flex; align-items: center; gap: var(--space-md); padding: var(--space-md); background: var(--color-bg-surface); border-radius: var(--radius-md);">
                    <input type="checkbox"
                           id="gradient_enabled"
                           name="gradient_enabled"
                           <?= $gradientEnabled ? 'checked' : '' ?>
                           style="width: 20px; height: 20px; cursor: pointer;">
                    <label for="gradient_enabled" style="font-weight: var(--weight-medium); cursor: pointer; margin: 0;">
                        Aktivera bakgrundsgradient
                    </label>
                </div>

                <!-- Color Picker -->
                <div style="padding: var(--space-lg); background: var(--color-bg-surface); border-radius: var(--radius-lg); border-left: 4px solid #F59E0B;">
                    <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="droplet"></i>
                        Basfärg
                    </h4>
                    <div style="display: flex; align-items: center; gap: var(--space-md);">
                        <input type="color"
                               name="gradient_base"
                               id="gradient_base"
                               value="<?= htmlspecialchars($gradientBase) ?>"
                               onchange="updateGradientPreview(this.value)"
                               style="width: 60px; height: 60px; border: 2px solid var(--color-border); border-radius: var(--radius-md); cursor: pointer;">
                        <div style="flex: 1;">
                            <div style="font-weight: var(--weight-medium); margin-bottom: 0.25rem;">
                                Vald färg
                            </div>
                            <div style="font-family: var(--font-mono); font-size: var(--text-sm); color: var(--color-text-secondary);" id="gradient-base-value">
                                <?= htmlspecialchars($gradientBase) ?>
                            </div>
                        </div>
                    </div>
                    <small style="display: block; margin-top: 0.75rem; opacity: 0.7; font-size: 0.75rem;">
                        Systemet genererar automatiskt ljusare (start) och mörkare (slut) färger
                    </small>
                </div>

                <!-- Preview -->
                <div style="padding: var(--space-lg); background: var(--color-bg-surface); border-radius: var(--radius-lg);">
                    <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="eye"></i>
                        Förhandsvisning
                    </h4>
                    <div id="gradient-preview" style="height: 120px; border-radius: var(--radius-md); background: linear-gradient(135deg, <?= htmlspecialchars($gradientStart) ?>, <?= htmlspecialchars($gradientEnd) ?>); border: 1px solid var(--color-border);"></div>
                    <div style="display: flex; justify-content: space-between; margin-top: var(--space-sm); font-size: var(--text-xs); font-family: var(--font-mono); color: var(--color-text-secondary);">
                        <span id="gradient-start-value">Start: <?= htmlspecialchars($gradientStart) ?></span>
                        <span id="gradient-end-value">Slut: <?= htmlspecialchars($gradientEnd) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TEMPORARILY HIDDEN - Dark Mode Colors Section (Bike App Style) -->
    <?php
    // DISABLED: Hiding this section until functionality is verified
    if (false): // Change to true to re-enable
    $darkColors = $branding['dark_colors'] ?? [];
    $darkBgPage = $darkColors['bg_page'] ?? '#242C3B';
    $darkBgCard = $darkColors['bg_card'] ?? '#353F54';
    $darkAccent = $darkColors['accent'] ?? '#37B6E9';
    ?>
    <div class="card mb-lg" style="display:none;">
        <div class="card-header">
            <h2>
                <i data-lucide="moon"></i>
                Mörkt tema
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-lg">
                Anpassa färger för mörkt läge. Baserat på bike app-designen.
            </p>

            <div class="branding-grid" style="grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));">
                <!-- Huvudbakgrund -->
                <div class="color-group">
                    <h3><i data-lucide="square"></i> Huvudbakgrund</h3>
                    <div style="display: flex; align-items: center; gap: var(--space-md); padding: var(--space-md); background: var(--color-bg-surface); border-radius: var(--radius-md);">
                        <input type="color"
                               name="dark_bg_page"
                               id="dark_bg_page"
                               value="<?= htmlspecialchars($darkBgPage) ?>"
                               style="width: 50px; height: 50px; border: 2px solid var(--color-border); border-radius: var(--radius-md); cursor: pointer;">
                        <div style="flex: 1;">
                            <div style="font-weight: var(--weight-medium); margin-bottom: 0.25rem;">
                                Sidbakgrund
                            </div>
                            <div style="font-family: var(--font-mono); font-size: var(--text-sm); color: var(--color-text-secondary);">
                                <?= htmlspecialchars($darkBgPage) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kortbakgrund -->
                <div class="color-group">
                    <h3><i data-lucide="layers"></i> Kort & Paneler</h3>
                    <div style="display: flex; align-items: center; gap: var(--space-md); padding: var(--space-md); background: var(--color-bg-surface); border-radius: var(--radius-md);">
                        <input type="color"
                               name="dark_bg_card"
                               id="dark_bg_card"
                               value="<?= htmlspecialchars($darkBgCard) ?>"
                               style="width: 50px; height: 50px; border: 2px solid var(--color-border); border-radius: var(--radius-md); cursor: pointer;">
                        <div style="flex: 1;">
                            <div style="font-weight: var(--weight-medium); margin-bottom: 0.25rem;">
                                Kortytor
                            </div>
                            <div style="font-family: var(--font-mono); font-size: var(--text-sm); color: var(--color-text-secondary);">
                                <?= htmlspecialchars($darkBgCard) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Accent -->
                <div class="color-group">
                    <h3><i data-lucide="zap"></i> Accentfärg</h3>
                    <div style="display: flex; align-items: center; gap: var(--space-md); padding: var(--space-md); background: var(--color-bg-surface); border-radius: var(--radius-md);">
                        <input type="color"
                               name="dark_accent"
                               id="dark_accent"
                               value="<?= htmlspecialchars($darkAccent) ?>"
                               style="width: 50px; height: 50px; border: 2px solid var(--color-border); border-radius: var(--radius-md); cursor: pointer;">
                        <div style="flex: 1;">
                            <div style="font-weight: var(--weight-medium); margin-bottom: 0.25rem;">
                                Länkar & Knappar
                            </div>
                            <div style="font-family: var(--font-mono); font-size: var(--text-sm); color: var(--color-text-secondary);">
                                <?= htmlspecialchars($darkAccent) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top: var(--space-lg); padding: var(--space-md); background: var(--color-bg-surface); border-radius: var(--radius-md); border-left: 4px solid #37B6E9;">
                <p style="margin: 0; font-size: 0.875rem; color: var(--color-text-secondary); display: flex; align-items: start; gap: 0.5rem;">
                    <i data-lucide="info" style="width: 16px; height: 16px; margin-top: 2px; flex-shrink: 0;"></i>
                    <span>
                        Övriga färger (gradient, text, kanter) genereras automatiskt från dessa basfärger.
                    </span>
                </p>
            </div>
        </div>
    </div>
    <?php endif; // End of dark mode section (disabled) ?>
</form>

<script>
// Live gradient preview
function updateGradientPreview(baseColor) {
    // Update base color display
    document.getElementById('gradient-base-value').textContent = baseColor;

    // Parse RGB
    const r = parseInt(baseColor.substr(1, 2), 16);
    const g = parseInt(baseColor.substr(3, 2), 16);
    const b = parseInt(baseColor.substr(5, 2), 16);

    // Generate lighter (start) - lighten by 8%
    const startR = Math.min(255, Math.round(r + r * 0.08));
    const startG = Math.min(255, Math.round(g + g * 0.08));
    const startB = Math.min(255, Math.round(b + b * 0.08));
    const startColor = `#${startR.toString(16).padStart(2, '0')}${startG.toString(16).padStart(2, '0')}${startB.toString(16).padStart(2, '0')}`.toUpperCase();

    // Generate darker (end) - darken by 12%
    const endR = Math.max(0, Math.round(r - r * 0.12));
    const endG = Math.max(0, Math.round(g - g * 0.12));
    const endB = Math.max(0, Math.round(b - b * 0.12));
    const endColor = `#${endR.toString(16).padStart(2, '0')}${endG.toString(16).padStart(2, '0')}${endB.toString(16).padStart(2, '0')}`.toUpperCase();

    // Update preview
    document.getElementById('gradient-preview').style.background = `linear-gradient(135deg, ${startColor}, ${endColor})`;
    document.getElementById('gradient-start-value').textContent = `Start: ${startColor}`;
    document.getElementById('gradient-end-value').textContent = `Slut: ${endColor}`;
}
</script>

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

// ============================================================================
// MEDIA PICKER FUNCTIONS
// ============================================================================

let currentLogoField = null;
let mediaData = [];

async function openMediaPicker(field) {
    currentLogoField = field;
    const modal = document.getElementById('mediaPickerModal');
    const grid = document.getElementById('mediaGrid');

    grid.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--color-text-secondary);">Laddar bilder...</div>';
    modal.classList.add('active');

    try {
        const response = await fetch('/api/media.php?action=list&folder=branding&limit=100');
        const result = await response.json();

        if (result.success && result.data.length > 0) {
            mediaData = result.data;
            renderMediaGrid();
        } else {
            grid.innerHTML = `
                <div style="text-align: center; padding: 40px; color: var(--color-text-secondary); grid-column: 1/-1;">
                    <p style="margin-bottom: 16px;">Inga bilder i branding-mappen.</p>
                    <a href="/admin/media?folder=branding" target="_blank" class="btn btn--primary">
                        <i data-lucide="upload"></i>
                        Ladda upp bilder
                    </a>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (error) {
        console.error('Error loading media:', error);
        grid.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--color-danger);">Kunde inte ladda bilder</div>';
    }
}

function renderMediaGrid() {
    const grid = document.getElementById('mediaGrid');
    grid.innerHTML = mediaData.map(media => `
        <div class="media-item" onclick="selectMedia('${media.filepath}')" style="cursor: pointer; border: 2px solid transparent; border-radius: 8px; padding: 8px; transition: border-color 0.2s; background: var(--color-bg-surface);">
            <img src="/${media.filepath}" alt="${media.original_filename || ''}" style="width: 100%; height: 80px; object-fit: contain; background: var(--color-bg-sunken); border-radius: 4px;">
            <div style="font-size: 0.75rem; color: var(--color-text-secondary); margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center;">
                ${media.original_filename || 'Bild'}
            </div>
        </div>
    `).join('');
}

function selectMedia(filepath) {
    if (!currentLogoField) return;

    const capitalizedField = currentLogoField.charAt(0).toUpperCase() + currentLogoField.slice(1);
    const inputId = 'logo' + capitalizedField + 'Input';
    const previewId = 'logo' + capitalizedField + 'Preview';
    const url = '/' + filepath.replace(/^\//, '');

    document.getElementById(inputId).value = url;
    document.getElementById(previewId).innerHTML = `<img src="${url}" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;

    // Enable remove button
    const colorGroup = document.getElementById(previewId).closest('.color-group');
    const removeBtn = colorGroup.querySelector('button[onclick*="clearLogo"]');
    if (removeBtn) removeBtn.disabled = false;

    closeMediaModal();
}

function clearLogo(field) {
    const capitalizedField = field.charAt(0).toUpperCase() + field.slice(1);
    const inputId = 'logo' + capitalizedField + 'Input';
    const previewId = 'logo' + capitalizedField + 'Preview';

    document.getElementById(inputId).value = '';
    document.getElementById(previewId).innerHTML = `<span style="color: var(--color-text-muted); font-size: var(--text-sm);">Klicka för att välja bild</span>`;

    // Disable remove button
    const colorGroup = document.getElementById(previewId).closest('.color-group');
    const removeBtn = colorGroup.querySelector('button[onclick*="clearLogo"]');
    if (removeBtn) removeBtn.disabled = true;
}

function closeMediaModal() {
    document.getElementById('mediaPickerModal').classList.remove('active');
    currentLogoField = null;
}

// Close modal on escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeMediaModal();
    }
});

// Close modal on backdrop click
document.getElementById('mediaPickerModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'mediaPickerModal') closeMediaModal();
});

// Hover effect for preview boxes
document.querySelectorAll('.logo-preview-box').forEach(box => {
    box.addEventListener('mouseenter', () => box.style.borderColor = 'var(--color-accent)');
    box.addEventListener('mouseleave', () => box.style.borderColor = 'var(--color-border)');
});

// Update theme badge to show actual active theme
function updateThemeBadge() {
    const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
    const lightText = document.querySelector('.theme-light-text');
    const darkText = document.querySelector('.theme-dark-text');

    if (currentTheme === 'light') {
        lightText.style.display = 'inline';
        darkText.style.display = 'none';
    } else {
        lightText.style.display = 'none';
        darkText.style.display = 'inline';
    }
}

// Run on load and watch for theme changes
updateThemeBadge();
new MutationObserver(updateThemeBadge).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme']
});
</script>

<!-- Media Picker Modal -->
<div class="modal" id="mediaPickerModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: var(--color-bg-surface); border-radius: var(--radius-lg); max-width: 700px; width: 90%; max-height: 80vh; display: flex; flex-direction: column; box-shadow: var(--shadow-lg);">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-lg); border-bottom: 1px solid var(--color-border);">
            <h3 style="margin: 0; font-size: var(--text-lg);">Välj bild från Mediabiblioteket</h3>
            <button type="button" onclick="closeMediaModal()" style="background: none; border: none; cursor: pointer; padding: 8px; color: var(--color-text-secondary);">
                <i data-lucide="x" style="width: 20px; height: 20px;"></i>
            </button>
        </div>
        <div class="modal-body" style="padding: var(--space-lg); overflow-y: auto; flex: 1;">
            <div id="mediaGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px;">
                <!-- Media items will be loaded here -->
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-md) var(--space-lg); border-top: 1px solid var(--color-border); background: var(--color-bg-sunken);">
            <a href="/admin/media?folder=branding" target="_blank" class="btn btn--secondary btn--sm">
                <i data-lucide="external-link"></i>
                Öppna Mediabiblioteket
            </a>
            <button type="button" class="btn btn--ghost btn--sm" onclick="closeMediaModal()">Avbryt</button>
        </div>
    </div>
</div>

<style>
.modal.active {
    display: flex !important;
}
.media-item:hover {
    border-color: var(--color-accent) !important;
    background: var(--color-bg-elevated) !important;
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
