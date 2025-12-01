<?php
/**
 * Admin Settings - V3 Design System
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $publicRidersDisplay = $_POST['public_riders_display'] ?? 'with_results';

    // Update settings file
    $settingsContent = "<?php\n";
    $settingsContent .= "/**\n";
    $settingsContent .= " * Public Display Settings\n";
    $settingsContent .= " * Configure what data is visible on the public website\n";
    $settingsContent .= " */\n\n";
    $settingsContent .= "return [\n";
    $settingsContent .= "    // Show all riders publicly or only those with results\n";
    $settingsContent .= "    // Options: 'all' or 'with_results'\n";
    $settingsContent .= "    'public_riders_display' => '{$publicRidersDisplay}',\n\n";
    $settingsContent .= "    // Minimum number of results required to show rider (when 'with_results' is selected)\n";
    $settingsContent .= "    'min_results_to_show' => 1,\n";
    $settingsContent .= "];\n";

    if (file_put_contents(__DIR__ . '/../config/public_settings.php', $settingsContent)) {
        set_flash('success', 'Inställningar sparade!');
        redirect('/admin/settings');
    } else {
        $message = 'Kunde inte spara inställningar';
        $messageType = 'error';
    }
}

// Load current settings
$currentSettings = require __DIR__ . '/../config/public_settings.php';

// Page config for V3 admin layout
$page_title = 'Inställningar';
$breadcrumbs = [
    ['label' => 'Inställningar']
];

// Include unified layout (uses same layout as public site)
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Settings Tabs -->
<div class="admin-tabs">
    <a href="/admin/settings" class="admin-tab active">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Översikt
    </a>
    <a href="/admin/global-texts.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
        Texter
    </a>
    <a href="/admin/role-permissions.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Roller
    </a>
    <a href="/admin/pricing-templates.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Prismallar
    </a>
    <a href="/admin/tools.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        Verktyg
    </a>
    <a href="/admin/system-settings.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        System
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <?php if ($messageType === 'success'): ?>
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            <?php else: ?>
                <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>
            <?php endif; ?>
        </svg>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Public Display Settings -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>Publik Visning</h2>
    </div>
    <div class="admin-card-body">
        <form method="POST">
            <?= csrf_field() ?>

            <div class="admin-form-group">
                <label class="admin-form-label">
                    Visa deltagare publikt
                </label>
                <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-md);">
                    Välj vilka deltagare som ska visas på den publika deltagarsidan
                </p>

                <div style="display: flex; flex-direction: column; gap: var(--space-md);">
                    <label style="display: flex; align-items: flex-start; gap: var(--space-md); padding: var(--space-md); background: var(--color-bg-sunken); border-radius: var(--radius-md); cursor: pointer;">
                        <input type="radio"
                            name="public_riders_display"
                            value="with_results"
                            <?= ($currentSettings['public_riders_display'] ?? 'with_results') === 'with_results' ? 'checked' : '' ?>
                            style="margin-top: 4px;">
                        <span>
                            <strong style="display: block;">Endast deltagare med resultat</strong>
                            <span style="color: var(--color-text-secondary); font-size: var(--text-sm);">
                                Visar bara cyklister som har minst ett tävlingsresultat uppladdat
                            </span>
                        </span>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: var(--space-md); padding: var(--space-md); background: var(--color-bg-sunken); border-radius: var(--radius-md); cursor: pointer;">
                        <input type="radio"
                            name="public_riders_display"
                            value="all"
                            <?= ($currentSettings['public_riders_display'] ?? 'with_results') === 'all' ? 'checked' : '' ?>
                            style="margin-top: 4px;">
                        <span>
                            <strong style="display: block;">Alla aktiva deltagare</strong>
                            <span style="color: var(--color-text-secondary); font-size: var(--text-sm);">
                                Visar alla cyklister i databasen, även de utan resultat
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn-admin btn-admin-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Spara Inställningar
                </button>
                <a href="/admin/dashboard" class="btn-admin btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                    Tillbaka
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Info Box -->
<div class="alert alert-info">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/>
    </svg>
    <div>
        <strong>Information:</strong>
        <ul style="margin: var(--space-sm) 0 0 var(--space-lg); list-style: disc;">
            <li>Ändringarna träder i kraft omedelbart på den publika sidan</li>
            <li>Admin-sidan visar alltid alla deltagare oavsett denna inställning</li>
            <li>Deltagare med SWE-ID (ingen licens) visas med röd badge</li>
        </ul>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
