<?php
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
        $message = 'Inställningar sparade!';
        $messageType = 'success';
    } else {
        $message = 'Kunde inte spara inställningar';
        $messageType = 'error';
    }
}

// Load current settings
$currentSettings = require __DIR__ . '/../config/public_settings.php';

$pageTitle = 'Inställningar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="settings"></i>
                    Inställningar
                </h1>
            </div>

            <!-- Message -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Public Display Settings -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="eye"></i>
                        Publik Visning
                    </h2>
                </div>
                <div class="gs-card-content">
                    <form method="POST">
                        <?= csrf_field() ?>

                        <div class="gs-form-group">
                            <label class="gs-label">
                                <i data-lucide="users"></i>
                                Visa deltagare publikt
                            </label>
                            <p class="gs-text-secondary gs-text-sm gs-mb-md">
                                Välj vilka deltagare som ska visas på den publika deltagarsidan (/riders.php)
                            </p>

                            <div class="gs-radio-group">
                                <label class="gs-radio-label">
                                    <input type="radio"
                                           name="public_riders_display"
                                           value="with_results"
                                           <?= ($currentSettings['public_riders_display'] ?? 'with_results') === 'with_results' ? 'checked' : '' ?>>
                                    <span class="gs-radio-text">
                                        <strong>Endast deltagare med resultat</strong>
                                        <span class="gs-text-secondary gs-text-sm" style="display: block; margin-top: 0.25rem;">
                                            Visar bara cyklister som har minst ett tävlingsresultat uppladdat
                                        </span>
                                    </span>
                                </label>

                                <label class="gs-radio-label gs-mt-md">
                                    <input type="radio"
                                           name="public_riders_display"
                                           value="all"
                                           <?= ($currentSettings['public_riders_display'] ?? 'with_results') === 'all' ? 'checked' : '' ?>>
                                    <span class="gs-radio-text">
                                        <strong>Alla aktiva deltagare</strong>
                                        <span class="gs-text-secondary gs-text-sm" style="display: block; margin-top: 0.25rem;">
                                            Visar alla cyklister i databasen, även de utan resultat
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="gs-flex gs-gap-md">
                            <button type="submit" class="gs-btn gs-btn-primary">
                                <i data-lucide="save"></i>
                                Spara Inställningar
                            </button>
                            <a href="/admin" class="gs-btn gs-btn-outline">
                                <i data-lucide="arrow-left"></i>
                                Tillbaka
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Info Box -->
            <div class="gs-alert gs-alert-info">
                <i data-lucide="info"></i>
                <div>
                    <strong>Information:</strong>
                    <ul style="margin: 0.5rem 0 0 1.5rem;">
                        <li>Ändringarna träder i kraft omedelbart på den publika sidan</li>
                        <li>Admin-sidan visar alltid alla deltagare oavsett denna inställning</li>
                        <li>Deltagare med SWE-ID (ingen licens) visas med röd badge</li>
                    </ul>
                </div>
            </div>
        </div>
    </main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
