<?php
/**
 * Reset Results and Import History Script
 * Deletes all results and clears import history
 *
 * IMPORTANT: Run this only once to clear all results!
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$stats = [
    'results_deleted' => 0,
    'import_history_deleted' => 0,
    'import_records_deleted' => 0,
    'errors' => []
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    checkCsrf();

    try {
        // Start transaction
        $db->query("START TRANSACTION");

        // Count existing records before deletion
        $resultsCount = $db->getOne("SELECT COUNT(*) FROM results");
        $importHistoryCount = $db->getOne("SELECT COUNT(*) FROM import_history");
        $importRecordsCount = $db->getOne("SELECT COUNT(*) FROM import_records");

        // Delete all results
        $db->query("DELETE FROM results");
        $stats['results_deleted'] = $resultsCount;
        error_log("Deleted {$resultsCount} results");

        // Delete all import records (tracking data)
        $db->query("DELETE FROM import_records");
        $stats['import_records_deleted'] = $importRecordsCount;
        error_log("Deleted {$importRecordsCount} import records");

        // Delete all import history
        $db->query("DELETE FROM import_history");
        $stats['import_history_deleted'] = $importHistoryCount;
        error_log("Deleted {$importHistoryCount} import history entries");

        // Commit transaction
        $db->query("COMMIT");

        $message = "Alla resultat och importhistorik raderade! {$stats['results_deleted']} resultat, {$stats['import_history_deleted']} importhistorik.";
        $messageType = 'success';

    } catch (Exception $e) {
        $db->query("ROLLBACK");
        $message = 'Fel vid radering: ' . $e->getMessage();
        $messageType = 'error';
        $stats['errors'][] = $e->getMessage();
    }
}

// Get current counts
$currentResultsCount = $db->getOne("SELECT COUNT(*) FROM results");
$currentImportHistoryCount = $db->getOne("SELECT COUNT(*) FROM import_history");
$currentImportRecordsCount = $db->getOne("SELECT COUNT(*) FROM import_records");

$pageTitle = 'Återställ Resultat & Importhistorik';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
            <div>
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="trash-2"></i>
                    Återställ Resultat & Importhistorik
                </h1>
                <p class="gs-text-secondary gs-mt-sm">
                    Radera alla resultat och importhistorik
                </p>
            </div>
            <a href="/admin/results.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <!-- Message -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <?php if ($stats['results_deleted'] > 0 || $stats['import_history_deleted'] > 0): ?>
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h4">Statistik</h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-grid gs-grid-cols-3 gs-gap-md">
                        <div>
                            <div class="gs-text-sm gs-text-secondary">Raderade resultat</div>
                            <div class="gs-h3 gs-text-danger"><?= number_format($stats['results_deleted']) ?></div>
                        </div>
                        <div>
                            <div class="gs-text-sm gs-text-secondary">Raderad importhistorik</div>
                            <div class="gs-h3 gs-text-danger"><?= number_format($stats['import_history_deleted']) ?></div>
                        </div>
                        <div>
                            <div class="gs-text-sm gs-text-secondary">Raderade spårningsposter</div>
                            <div class="gs-h3 gs-text-danger"><?= number_format($stats['import_records_deleted']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Current Status -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">Nuvarande status</h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-3 gs-gap-md">
                    <div>
                        <div class="gs-text-sm gs-text-secondary">Resultat i databasen</div>
                        <div class="gs-h3 <?= $currentResultsCount > 0 ? 'gs-text-accent' : 'gs-text-secondary' ?>">
                            <?= number_format($currentResultsCount) ?>
                        </div>
                    </div>
                    <div>
                        <div class="gs-text-sm gs-text-secondary">Importhistorik</div>
                        <div class="gs-h3 <?= $currentImportHistoryCount > 0 ? 'gs-text-accent' : 'gs-text-secondary' ?>">
                            <?= number_format($currentImportHistoryCount) ?>
                        </div>
                    </div>
                    <div>
                        <div class="gs-text-sm gs-text-secondary">Spårningsposter</div>
                        <div class="gs-h3 <?= $currentImportRecordsCount > 0 ? 'gs-text-accent' : 'gs-text-secondary' ?>">
                            <?= number_format($currentImportRecordsCount) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Warning Card -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-danger">
                    <i data-lucide="alert-triangle"></i>
                    VARNING - Läs detta först!
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-danger gs-mb-md">
                    <strong>Detta script kommer att:</strong>
                </p>
                <ul class="gs-text-secondary" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                    <li>Radera <strong>ALLA resultat</strong> från results-tabellen</li>
                    <li>Radera <strong>ALLA importhistorik</strong> från import_history-tabellen</li>
                    <li>Radera <strong>ALLA spårningsposter</strong> från import_records-tabellen</li>
                    <li>Tömma rollback-menyn helt</li>
                </ul>
                <p class="gs-text-danger gs-mt-md">
                    <strong>Detta går INTE att ångra!</strong>
                </p>
                <p class="gs-text-secondary gs-mt-md">
                    <strong>OBS:</strong> Deltagare (riders), tävlingar (events), serier, klubbar och venues påverkas INTE.
                    Endast resultat och importhistorik raderas.
                </p>
            </div>
        </div>

        <!-- What happens after -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="info"></i>
                    Vad händer efter radering?
                </h2>
            </div>
            <div class="gs-card-content">
                <ul class="gs-text-secondary" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                    <li>Rollback-menyn blir tom (inga importer att rulla tillbaka)</li>
                    <li>Framtida importer kommer att spåras korrekt</li>
                    <li>Du kan importera resultat på nytt</li>
                    <li>Rollback kommer fungera korrekt för nya importer</li>
                    <li>Importhistoriken försvinner när den rullas tillbaka (som förväntat)</li>
                </ul>
            </div>
        </div>

        <!-- Confirm Form -->
        <?php if ($currentResultsCount > 0 || $currentImportHistoryCount > 0): ?>
            <div class="gs-card">
                <div class="gs-card-content">
                    <form method="POST" onsubmit="return confirm('Är du ABSOLUT säker på att du vill radera alla resultat och importhistorik? Detta går inte att ångra!');">
                        <?= csrf_field() ?>

                        <div class="gs-form-group">
                            <label class="gs-checkbox-label">
                                <input type="checkbox" required>
                                <span>Jag förstår att detta kommer radera alla <?= number_format($currentResultsCount) ?> resultat</span>
                            </label>
                        </div>

                        <div class="gs-form-group">
                            <label class="gs-checkbox-label">
                                <input type="checkbox" required>
                                <span>Jag förstår att all importhistorik kommer raderas</span>
                            </label>
                        </div>

                        <div class="gs-form-group">
                            <label class="gs-checkbox-label">
                                <input type="checkbox" required>
                                <span>Jag förstår att detta inte går att ångra</span>
                            </label>
                        </div>

                        <div class="gs-flex gs-gap-md gs-mt-lg">
                            <button type="submit" name="confirm_reset" class="gs-btn gs-btn-danger">
                                <i data-lucide="trash-2"></i>
                                Radera Allt
                            </button>
                            <a href="/admin/results.php" class="gs-btn gs-btn-outline">
                                <i data-lucide="x"></i>
                                Avbryt
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="gs-card">
                <div class="gs-card-content">
                    <div class="gs-text-center">
                        <i data-lucide="check-circle" style="width: 64px; height: 64px; margin: 0 auto 1rem; color: var(--gs-success);"></i>
                        <h3 class="gs-h4 gs-text-success gs-mb-sm">Databasen är redan tom</h3>
                        <p class="gs-text-secondary">Det finns inga resultat eller importhistorik att radera.</p>
                        <a href="/admin/results.php" class="gs-btn gs-btn-primary gs-mt-lg">
                            <i data-lucide="arrow-left"></i>
                            Tillbaka till Resultat
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
