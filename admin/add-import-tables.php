<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Handle SQL execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    checkCsrf();

    if ($_POST['action'] === 'add_tables') {
        try {
            $pdo = $db->getConnection();

            if (!$pdo || !$pdo instanceof PDO) {
                throw new Exception('Database connection not available');
            }

            // Read SQL file
            $sqlFile = __DIR__ . '/../database/add_import_history_tables.sql';

            if (!file_exists($sqlFile)) {
                throw new Exception('SQL file not found');
            }

            $sql = file_get_contents($sqlFile);

            // Execute SQL statements
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($stmt) {
                    return !empty($stmt) && !preg_match('/^--/', $stmt);
                }
            );

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            $message = "Import history tabeller tillagda! Du kan nu backa importer via import-history sidan.";
            $messageType = 'success';

        } catch (Exception $e) {
            $message = 'Fel: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Check if tables exist
$tablesExist = false;
try {
    $pdo = $db->getConnection();
    if ($pdo) {
        $stmt = $pdo->query("SHOW TABLES LIKE 'import_history'");
        $tablesExist = ($stmt->rowCount() > 0);
    }
} catch (Exception $e) {
    // Ignore
}

$pageTitle = 'Add Import Tables';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h2 gs-mb-lg">
            <i data-lucide="database"></i>
            Lägg till Import History Tabeller
        </h1>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h3">Status</h2>
            </div>
            <div class="gs-card-content">
                <?php if ($tablesExist): ?>
                    <div class="gs-alert gs-alert-success">
                        <i data-lucide="check-circle"></i>
                        <strong>Import history tabeller finns redan!</strong>
                        <p class="gs-mt-sm">Du kan nu använda rollback-funktionen i import-history.</p>
                    </div>
                <?php else: ?>
                    <div class="gs-alert gs-alert-warning">
                        <i data-lucide="alert-triangle"></i>
                        <strong>Import history tabeller saknas</strong>
                        <p class="gs-mt-sm">Kör scriptet nedan för att lägga till rollback-funktionalitet.</p>
                    </div>

                    <div class="gs-mt-lg">
                        <h3 class="gs-h4 gs-mb-md">Vad gör detta?</h3>
                        <ul class="gs-list gs-mb-lg">
                            <li>✅ Lägger till <code>import_history</code> tabellen</li>
                            <li>✅ Lägger till <code>import_records</code> tabellen</li>
                            <li>✅ Aktiverar rollback för alla framtida importer</li>
                            <li>⚠️ Påverkar INTE befintlig data (CREATE TABLE IF NOT EXISTS)</li>
                        </ul>

                        <form method="POST" onsubmit="return confirm('Är du säker på att du vill lägga till tabellerna?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_tables">
                            <button type="submit" class="gs-btn gs-btn-primary">
                                <i data-lucide="play"></i>
                                Lägg till tabeller
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h3">Information</h2>
            </div>
            <div class="gs-card-content">
                <h3 class="gs-h4 gs-mb-sm">Varför behövs detta?</h3>
                <p class="gs-mb-md">
                    De nya import history tabellerna ger dig möjlighet att:
                </p>
                <ul class="gs-list gs-mb-lg">
                    <li><strong>Backa importer</strong> - Radera alla poster som skapades vid en felaktig import</li>
                    <li><strong>Se importhistorik</strong> - Lista alla importer med statistik</li>
                    <li><strong>Återställa data</strong> - Ångra uppdateringar och återställ gamla värden</li>
                </ul>

                <div class="gs-alert gs-alert-info">
                    <i data-lucide="info"></i>
                    <strong>OBS:</strong> Detta påverkar endast <em>framtida</em> importer. Tidigare importer (som de 50 eventen) kan inte backas automatiskt eftersom de inte trackades.
                </div>

                <div class="gs-mt-lg">
                    <h3 class="gs-h4 gs-mb-sm">Nästa steg efter installation:</h3>
                    <ol class="gs-list" style="list-style: decimal; margin-left: 1.5rem;">
                        <li>Lägg till tabellerna genom att klicka på knappen ovan</li>
                        <li>Gör en ny testimport</li>
                        <li>Gå till <a href="/admin/import-history.php" class="gs-link">Import History</a> för att se importen</li>
                        <li>Testa rollback-funktionen</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
