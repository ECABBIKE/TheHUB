<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Initialize message variables
$message = '';
$messageType = 'info';

// Load current settings
$settingsFile = __DIR__ . '/../config/public_settings.php';
$currentSettings = require $settingsFile;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $public_riders_display = $_POST['public_riders_display'] ?? 'with_results';
        $min_results_to_show = intval($_POST['min_results_to_show'] ?? 1);

        // Validate
        if (!in_array($public_riders_display, ['all', 'with_results'])) {
            $message = 'Ogiltigt värde för visningsläge';
            $messageType = 'error';
        } elseif ($min_results_to_show < 1) {
            $message = 'Minsta antal resultat måste vara minst 1';
            $messageType = 'error';
        } else {
            // Create new settings array
            $newSettings = [
                'public_riders_display' => $public_riders_display,
                'min_results_to_show' => $min_results_to_show,
            ];

            // Generate PHP code for the settings file
            $phpCode = "<?php\n";
            $phpCode .= "/**\n";
            $phpCode .= " * Public Display Settings\n";
            $phpCode .= " * Configure what data is visible on the public website\n";
            $phpCode .= " */\n\n";
            $phpCode .= "return [\n";
            $phpCode .= "    // Show all riders publicly or only those with results\n";
            $phpCode .= "    // Options: 'all' or 'with_results'\n";
            $phpCode .= "    'public_riders_display' => '{$newSettings['public_riders_display']}',\n\n";
            $phpCode .= "    // Minimum number of results required to show rider (when 'with_results' is selected)\n";
            $phpCode .= "    'min_results_to_show' => {$newSettings['min_results_to_show']},\n";
            $phpCode .= "];\n";

            // Write to file
            if (file_put_contents($settingsFile, $phpCode) !== false) {
                $currentSettings = $newSettings;
                $message = 'Inställningar sparade!';
                $messageType = 'success';
            } else {
                $message = 'Kunde inte spara inställningar. Kontrollera filrättigheter.';
                $messageType = 'error';
            }
        }
    }
}

// Get statistics
$total_riders = $db->getRow("SELECT COUNT(*) as count FROM riders WHERE active = 1")['count'] ?? 0;
$riders_with_results = $db->getRow("
    SELECT COUNT(DISTINCT c.id) as count
    FROM riders c
    INNER JOIN results r ON c.id = r.cyclist_id
    WHERE c.active = 1
")['count'] ?? 0;
$riders_without_results = $total_riders - $riders_with_results;

$pageTitle = 'Publika Inställningar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container" style="max-width: 900px;">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
            <h1 class="gs-h1 gs-text-primary">
                <i data-lucide="settings"></i>
                Publika Inställningar
            </h1>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="bar-chart"></i>
                    Statistik
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-md">
                    <div class="gs-stat-card">
                        <i data-lucide="users" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                        <div class="gs-stat-number"><?= $total_riders ?></div>
                        <div class="gs-stat-label">Totalt aktiva deltagare</div>
                    </div>
                    <div class="gs-stat-card">
                        <i data-lucide="trophy" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                        <div class="gs-stat-number"><?= $riders_with_results ?></div>
                        <div class="gs-stat-label">Med resultat</div>
                    </div>
                    <div class="gs-stat-card">
                        <i data-lucide="user-x" class="gs-icon-lg gs-text-secondary gs-mb-md"></i>
                        <div class="gs-stat-number"><?= $riders_without_results ?></div>
                        <div class="gs-stat-label">Utan resultat</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Form -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="eye"></i>
                    Synlighet för Deltagare
                </h2>
            </div>
            <form method="POST" class="gs-card-content">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">

                <div class="gs-mb-lg">
                    <p class="gs-text-secondary gs-mb-md">
                        Välj vilka deltagare som ska visas på den publika deltagarsidan (<code>/riders.php</code>).
                    </p>

                    <div class="gs-alert gs-alert-info gs-mb-md">
                        <i data-lucide="info"></i>
                        <strong>Nuvarande inställning:</strong>
                        <?php if ($currentSettings['public_riders_display'] === 'all'): ?>
                            Visar alla <?= $total_riders ?> aktiva deltagare
                        <?php else: ?>
                            Visar bara deltagare med resultat (<?= $riders_with_results ?> deltagare)
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Display Mode -->
                <div class="gs-mb-lg">
                    <label class="gs-label gs-mb-sm">
                        <i data-lucide="users"></i>
                        Visningsläge
                    </label>

                    <div class="gs-flex gs-flex-col gs-gap-md">
                        <label class="gs-radio-card <?= $currentSettings['public_riders_display'] === 'with_results' ? 'active' : '' ?>">
                            <input
                                type="radio"
                                name="public_riders_display"
                                value="with_results"
                                class="gs-radio"
                                <?= $currentSettings['public_riders_display'] === 'with_results' ? 'checked' : '' ?>
                            >
                            <div class="gs-radio-card-content">
                                <div class="gs-radio-card-title">
                                    <i data-lucide="trophy"></i>
                                    Endast deltagare med resultat
                                </div>
                                <div class="gs-radio-card-description">
                                    Visar bara de <?= $riders_with_results ?> deltagare som har tävlat och har minst ett resultat registrerat.
                                    <strong>Rekommenderat för offentlig visning.</strong>
                                </div>
                            </div>
                        </label>

                        <label class="gs-radio-card <?= $currentSettings['public_riders_display'] === 'all' ? 'active' : '' ?>">
                            <input
                                type="radio"
                                name="public_riders_display"
                                value="all"
                                class="gs-radio"
                                <?= $currentSettings['public_riders_display'] === 'all' ? 'checked' : '' ?>
                            >
                            <div class="gs-radio-card-content">
                                <div class="gs-radio-card-title">
                                    <i data-lucide="users"></i>
                                    Alla aktiva deltagare
                                </div>
                                <div class="gs-radio-card-description">
                                    Visar alla <?= $total_riders ?> aktiva deltagare, även de utan resultat.
                                    Inkluderar <?= $riders_without_results ?> deltagare som inte har några registrerade resultat än.
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Minimum Results (shown only when 'with_results' is selected) -->
                <div class="gs-mb-lg" id="minResultsSection" style="<?= $currentSettings['public_riders_display'] === 'with_results' ? '' : 'display: none;' ?>">
                    <label for="min_results_to_show" class="gs-label">
                        <i data-lucide="hash"></i>
                        Minsta antal resultat
                    </label>
                    <input
                        type="number"
                        id="min_results_to_show"
                        name="min_results_to_show"
                        class="gs-input"
                        min="1"
                        value="<?= $currentSettings['min_results_to_show'] ?>"
                        style="max-width: 200px;"
                    >
                    <small class="gs-text-muted">
                        Deltagare måste ha minst detta antal registrerade resultat för att visas.
                    </small>
                </div>

                <!-- Save Button -->
                <div class="gs-flex gs-justify-end gs-gap-md gs-pt-md" style="border-top: 1px solid var(--gs-border);">
                    <a href="/admin/dashboard.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="x"></i>
                        Avbryt
                    </a>
                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="save"></i>
                        Spara Inställningar
                    </button>
                </div>
            </form>
        </div>

        <!-- Preview -->
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="external-link"></i>
                    Förhandsgranskning
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-secondary gs-mb-md">
                    Se hur den publika deltagarsidan ser ut med nuvarande inställningar.
                </p>
                <a href="/riders.php" target="_blank" class="gs-btn gs-btn-outline">
                    <i data-lucide="eye"></i>
                    Öppna Publika Deltagarsidan
                </a>
            </div>
        </div>
    </div>
</main>

<style>
.gs-radio-card {
    border: 2px solid var(--gs-border);
    border-radius: var(--gs-border-radius);
    padding: var(--gs-space-md);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    gap: var(--gs-space-md);
}

.gs-radio-card:hover {
    border-color: var(--gs-primary);
    background: var(--gs-background-secondary);
}

.gs-radio-card.active {
    border-color: var(--gs-primary);
    background: rgba(var(--gs-primary-rgb), 0.05);
}

.gs-radio-card-content {
    flex: 1;
}

.gs-radio-card-title {
    font-weight: 600;
    margin-bottom: var(--gs-space-xs);
    display: flex;
    align-items: center;
    gap: var(--gs-space-xs);
}

.gs-radio-card-description {
    font-size: 0.875rem;
    color: var(--gs-text-secondary);
}

.gs-radio {
    margin-top: 4px;
}
</style>

<script>
// Show/hide minimum results section based on selected display mode
document.addEventListener('DOMContentLoaded', function() {
    const radios = document.querySelectorAll('input[name="public_riders_display"]');
    const minResultsSection = document.getElementById('minResultsSection');

    radios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'with_results') {
                minResultsSection.style.display = 'block';
            } else {
                minResultsSection.style.display = 'none';
            }

            // Update active class on radio cards
            document.querySelectorAll('.gs-radio-card').forEach(card => {
                card.classList.remove('active');
            });
            this.closest('.gs-radio-card').classList.add('active');
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
