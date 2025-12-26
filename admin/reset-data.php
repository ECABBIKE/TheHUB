<?php
/**
 * Reset Data Tool - Superadmin Only
 * Clears results, riders, clubs while keeping events and settings
 */

require_once __DIR__ . '/../config.php';
require_admin();

// Only superadmin can access this
if (!hasRole('super_admin')) {
    header('Location: /admin?error=access_denied');
    exit;
}

$db = getDB();
$message = '';
$error = '';

// Handle reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $confirmation = trim($_POST['confirmation'] ?? '');

    if ($confirmation !== 'RADERA ALLT') {
        $error = 'Fel bekräftelse. Skriv exakt "RADERA ALLT" för att fortsätta.';
    } else {
        try {
            $db->pdo->beginTransaction();

            // Disable foreign key checks temporarily
            $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            // Delete in order (child tables first)
            $tables = [
                'series_results' => 'Serieresultat',
                'rider_club_seasons' => 'Klubbsäsonger',
                'rider_parents' => 'Föräldrakopplingar',
                'rider_claims' => 'Profilförfrågningar',
                'results' => 'Resultat',
                'registrations' => 'Anmälningar',
                'riders' => 'Åkare',
                'clubs' => 'Klubbar',
            ];

            $deleted = [];
            foreach ($tables as $table => $label) {
                try {
                    $count = $db->pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                    $db->pdo->exec("TRUNCATE TABLE `$table`");
                    $deleted[$label] = $count;
                } catch (Exception $e) {
                    // Table might not exist, skip
                    $deleted[$label] = 'N/A';
                }
            }

            // Re-enable foreign key checks
            $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $db->pdo->commit();

            $message = 'Data raderad! Raderade: ';
            $parts = [];
            foreach ($deleted as $label => $count) {
                if ($count !== 'N/A' && $count > 0) {
                    $parts[] = "$count $label";
                }
            }
            $message .= implode(', ', $parts);

        } catch (Exception $e) {
            $db->pdo->rollBack();
            $error = 'Fel vid radering: ' . $e->getMessage();
        }
    }
}

// Get current counts
$counts = [
    'results' => $db->getRow("SELECT COUNT(*) as c FROM results")['c'] ?? 0,
    'riders' => $db->getRow("SELECT COUNT(*) as c FROM riders")['c'] ?? 0,
    'clubs' => $db->getRow("SELECT COUNT(*) as c FROM clubs")['c'] ?? 0,
    'series_results' => $db->getRow("SELECT COUNT(*) as c FROM series_results")['c'] ?? 0,
    'events' => $db->getRow("SELECT COUNT(*) as c FROM events")['c'] ?? 0,
    'series' => $db->getRow("SELECT COUNT(*) as c FROM series")['c'] ?? 0,
];

// Page config
$page_title = 'Återställ Data';
$breadcrumbs = [
    ['label' => 'Verktyg'],
    ['label' => 'Återställ Data']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-success mb-lg">
    <i data-lucide="check-circle"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger mb-lg">
    <i data-lucide="alert-circle"></i>
    <?= h($error) ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="text-danger">
            <i data-lucide="trash-2"></i>
            Återställ Data
        </h2>
    </div>
    <div class="card-body">
        <div class="alert alert-danger mb-lg">
            <i data-lucide="alert-triangle"></i>
            <div>
                <strong>VARNING!</strong> Detta raderar permanent all data nedan.
                Se till att du har tagit en <a href="/admin/backup.php">backup</a> först!
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <h3 class="mb-md text-danger">
                    <i data-lucide="x-circle"></i>
                    Kommer raderas
                </h3>
                <table class="table">
                    <tr>
                        <td>Resultat</td>
                        <td class="text-right"><strong><?= number_format($counts['results']) ?></strong></td>
                    </tr>
                    <tr>
                        <td>Åkare</td>
                        <td class="text-right"><strong><?= number_format($counts['riders']) ?></strong></td>
                    </tr>
                    <tr>
                        <td>Klubbar</td>
                        <td class="text-right"><strong><?= number_format($counts['clubs']) ?></strong></td>
                    </tr>
                    <tr>
                        <td>Serieresultat</td>
                        <td class="text-right"><strong><?= number_format($counts['series_results']) ?></strong></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h3 class="mb-md text-success">
                    <i data-lucide="check-circle"></i>
                    Behålls
                </h3>
                <table class="table">
                    <tr>
                        <td>Events/Tävlingar</td>
                        <td class="text-right"><strong><?= number_format($counts['events']) ?></strong></td>
                    </tr>
                    <tr>
                        <td>Serier</td>
                        <td class="text-right"><strong><?= number_format($counts['series']) ?></strong></td>
                    </tr>
                    <tr>
                        <td>Klasser</td>
                        <td class="text-right"><strong>Alla</strong></td>
                    </tr>
                    <tr>
                        <td>Venues, Inställningar</td>
                        <td class="text-right"><strong>Alla</strong></td>
                    </tr>
                </table>
            </div>
        </div>

        <?php if ($counts['results'] > 0 || $counts['riders'] > 0): ?>
        <hr class="my-lg">

        <form method="POST" onsubmit="return confirmReset()">
            <div class="form-group">
                <label class="form-label">
                    Skriv <strong class="text-danger">RADERA ALLT</strong> för att bekräfta:
                </label>
                <input type="text" name="confirmation" class="form-input"
                       placeholder="Skriv här..." autocomplete="off" style="max-width: 300px;">
            </div>

            <button type="submit" name="confirm_reset" class="btn btn-danger btn-lg">
                <i data-lucide="trash-2"></i>
                Radera all data
            </button>
        </form>

        <script>
        function confirmReset() {
            return confirm('Är du HELT säker? Detta går inte att ångra!');
        }
        </script>
        <?php else: ?>
        <div class="alert alert-info mt-lg">
            <i data-lucide="info"></i>
            Ingen data att radera. Databasen är redan tom.
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-lg">
    <div class="card-header">
        <h3>
            <i data-lucide="info"></i>
            Efter radering
        </h3>
    </div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Gå till <a href="/admin/import.php">Import</a></li>
            <li>Importera resultat år för år, börja med 2016</li>
            <li>Verifiera data efter varje år</li>
            <li>Kör <a href="/admin/recalculate-all-points.php">Omräkning av poäng</a> när allt är importerat</li>
        </ol>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
