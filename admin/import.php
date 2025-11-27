<?php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$current_admin = get_current_admin();
$message = '';
$messageType = 'success';

// Current tab
$tab = $_GET['tab'] ?? 'import';

// Load import history helper functions for history tab
if ($tab === 'history') {
    require_once __DIR__ . '/../includes/import-history.php';
}

// Handle file upload (import tab)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    checkCsrf();
    $importType = $_POST['import_type'] ?? '';
    $file = $_FILES['import_file'];

    $validation = validateFileUpload($file, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel']);

    if (!$validation['valid']) {
        $message = $validation['error'];
        $messageType = 'error';
    } else {
        // Move uploaded file
        $uploadDir = UPLOADS_PATH;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = time() . '_' . basename($file['name']);
        $filepath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Import file based on type
            if ($importType === 'cyclists') {
                require_once __DIR__ . '/../imports/import_cyclists.php';
                $importer = new CyclistImporter();

                ob_start();
                $success = $importer->import($filepath);
                $output = ob_get_clean();

                $stats = $importer->getStats();

                if ($success) {
                    $message = "Import klar! {$stats['success']} av {$stats['total']} rader importerade.";
                    $messageType = 'success';
                } else {
                    $message = "Import misslyckades. Kontrollera filformatet.";
                    $messageType = 'error';
                }
            } elseif ($importType === 'results') {
                require_once __DIR__ . '/../imports/import_results.php';
                $importer = new ResultImporter();

                ob_start();
                $success = $importer->import($filepath);
                $output = ob_get_clean();

                $stats = $importer->getStats();

                if ($success) {
                    $message = "Import klar! {$stats['success']} resultat importerade.";
                    $messageType = 'success';
                } else {
                    $message = "Import misslyckades. Kontrollera filformatet.";
                    $messageType = 'error';
                }
            }

            // Clean up uploaded file
            @unlink($filepath);
        } else {
            $message = "Kunde inte ladda upp filen.";
            $messageType = 'error';
        }
    }

    if ($message) {
        set_flash($messageType, $message);
        redirect('/admin/import.php');
    }
}

// Handle rollback/delete actions (history tab)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'history') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'rollback') {
        $importId = intval($_POST['import_id']);
        $result = rollbackImport($db, $importId, $current_admin['username'] ?? 'admin');

        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
    } elseif ($action === 'delete') {
        $importId = intval($_POST['import_id']);
        $result = deleteImportHistory($db, $importId);

        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
    }
}

// Handle filters for history tab
$type = $_GET['type'] ?? '';

// Get import history for history tab
$imports = [];
if ($tab === 'history') {
    $imports = getImportHistory($db, 100, $type ?: null);
}

$pageTitle = 'Import Data';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <?php
        render_admin_header('Import & Data');
        ?>

        <!-- Tabs -->
        <div class="gs-tabs gs-mb-lg">
            <a href="?tab=import" class="gs-tab <?= $tab === 'import' ? 'active' : '' ?>">
                <i data-lucide="upload" class="gs-icon-sm"></i>
                Import
            </a>
            <a href="?tab=history" class="gs-tab <?= $tab === 'history' ? 'active' : '' ?>">
                <i data-lucide="history" class="gs-icon-sm"></i>
                Historik
            </a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'import'): ?>
            <!-- Import Options Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md gs-mb-lg">

                <!-- Deltagare -->
                <div class="gs-card" style="border-left: 4px solid var(--gs-success);">
                    <div class="gs-card-header">
                        <h2 class="gs-h6">
                            <i data-lucide="users"></i>
                            Deltagare
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-sm gs-text-secondary gs-mb-md">
                            Importera cyklister med klubb, licens och personuppgifter.
                        </p>
                        <div class="gs-flex gs-gap-sm">
                            <a href="/admin/download-templates.php?template=riders" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="download"></i>
                                Mall
                            </a>
                            <a href="/admin/import-riders-flexible.php" class="gs-btn gs-btn-success gs-btn-sm gs-flex-1">
                                <i data-lucide="upload"></i>
                                Importera
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Resultat -->
                <div class="gs-card" style="border-left: 4px solid var(--gs-warning);">
                    <div class="gs-card-header">
                        <h2 class="gs-h6">
                            <i data-lucide="flag"></i>
                            Resultat
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-sm gs-text-secondary gs-mb-md">
                            Importera Enduro (SS1-SS15) eller Downhill (Run1, Run2) resultat.
                        </p>
                        <div class="gs-flex gs-gap-sm">
                            <a href="/admin/import-results.php?template=enduro" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="download"></i>
                                Mall
                            </a>
                            <a href="/admin/import-results.php" class="gs-btn gs-btn-warning gs-btn-sm gs-flex-1">
                                <i data-lucide="upload"></i>
                                Importera
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Events -->
                <div class="gs-card" style="border-left: 4px solid var(--gs-info);">
                    <div class="gs-card-header">
                        <h2 class="gs-h6">
                            <i data-lucide="calendar"></i>
                            Events
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-sm gs-text-secondary gs-mb-md">
                            Importera events med datum, plats, arrangör och mer.
                        </p>
                        <div class="gs-flex gs-gap-sm">
                            <a href="/admin/import-events.php?template=1" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="download"></i>
                                Mall
                            </a>
                            <a href="/admin/import-events.php" class="gs-btn gs-btn-info gs-btn-sm gs-flex-1">
                                <i data-lucide="upload"></i>
                                Importera
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Poängmallar -->
                <div class="gs-card" style="border-left: 4px solid var(--gs-primary);">
                    <div class="gs-card-header">
                        <h2 class="gs-h6">
                            <i data-lucide="trophy"></i>
                            Poängmallar
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-sm gs-text-secondary gs-mb-md">
                            Importera poängskala för serier och tävlingar.
                        </p>
                        <div class="gs-flex gs-gap-sm">
                            <a href="/templates/poangmall-standard.csv" class="gs-btn gs-btn-outline gs-btn-sm" download>
                                <i data-lucide="download"></i>
                                Mall
                            </a>
                            <a href="/admin/point-scales.php" class="gs-btn gs-btn-primary gs-btn-sm gs-flex-1">
                                <i data-lucide="settings"></i>
                                Hantera
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Gravity ID -->
                <div class="gs-card" style="border-left: 4px solid #764ba2;">
                    <div class="gs-card-header">
                        <h2 class="gs-h6">
                            <i data-lucide="id-card"></i>
                            Gravity ID
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-sm gs-text-secondary gs-mb-md">
                            Tilldela Gravity ID för medlemsrabatter vid eventanmälan.
                        </p>
                        <div class="gs-flex gs-gap-sm">
                            <a href="/admin/import-gravity-id.php?template=1" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="download"></i>
                                Mall
                            </a>
                            <a href="/admin/import-gravity-id.php" class="gs-btn gs-btn-sm gs-flex-1" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                <i data-lucide="upload"></i>
                                Importera
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Format Guide -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h6">
                        <i data-lucide="info"></i>
                        Format-guide
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                        <details class="gs-details">
                            <summary class="gs-text-sm">Deltagare-kolumner</summary>
                            <ul class="gs-text-xs gs-mt-sm" style="list-style: disc; padding-left: 1.5rem;">
                                <li><strong>first_name, last_name</strong> (required)</li>
                                <li><strong>birth_year</strong> eller <strong>personnummer</strong></li>
                                <li>uci_id, swe_id, club_name, gender</li>
                                <li>license_type, license_category, discipline</li>
                            </ul>
                        </details>

                        <details class="gs-details">
                            <summary class="gs-text-sm">Resultat-kolumner</summary>
                            <ul class="gs-text-xs gs-mt-sm" style="list-style: disc; padding-left: 1.5rem;">
                                <li><strong>Category, FirstName, LastName</strong> (required)</li>
                                <li>PlaceByCategory, Bib no, Club, UCI-ID</li>
                                <li>NetTime, Status (FIN/DNF/DNS/DQ)</li>
                                <li>SS1-SS15 (Enduro) eller Run1/Run2 (DH)</li>
                            </ul>
                        </details>

                        <details class="gs-details">
                            <summary class="gs-text-sm">Event-kolumner</summary>
                            <ul class="gs-text-xs gs-mt-sm" style="list-style: disc; padding-left: 1.5rem;">
                                <li><strong>Namn, Datum</strong> (required)</li>
                                <li>Advent ID, Plats, Bana, Disciplin</li>
                                <li>Distans, Höjdmeter, Arrangör</li>
                                <li>Webbplats, Anmälningsfrist, Kontakt</li>
                            </ul>
                        </details>

                        <details class="gs-details">
                            <summary class="gs-text-sm">Poängmall-kolumner</summary>
                            <ul class="gs-text-xs gs-mt-sm" style="list-style: disc; padding-left: 1.5rem;">
                                <li><strong>Position, Poäng</strong> (standard)</li>
                                <li><strong>Position, Kval, Final</strong> (DH)</li>
                                <li>Använd semikolon (;) som separator</li>
                            </ul>
                        </details>
                    </div>

                    <div class="gs-alert gs-alert-info gs-mt-md">
                        <strong>Tips:</strong> Alla importer stöder svenska och engelska kolumnnamn. Spara som CSV (UTF-8) för bästa resultat.
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- History Tab -->

            <!-- Info Alert -->
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <div>
                    <strong>Import History & Rollback</strong><br>
                    Här kan du se alla imports som gjorts i systemet och vid behov återställa (rollback) en import.
                    Rollback raderar alla poster som skapades och återställer uppdaterade poster till sina tidigare värden.
                </div>
            </div>

            <!-- Filters -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-flex gs-gap-md gs-items-end">
                        <input type="hidden" name="tab" value="history">
                        <div>
                            <label for="type" class="gs-label">
                                <i data-lucide="filter"></i>
                                Importtyp
                            </label>
                            <select id="type" name="type" class="gs-input" style="max-width: 200px;">
                                <option value="">Alla</option>
                                <option value="uci" <?= $type === 'uci' ? 'selected' : '' ?>>UCI Import</option>
                                <option value="riders" <?= $type === 'riders' ? 'selected' : '' ?>>Riders</option>
                                <option value="results" <?= $type === 'results' ? 'selected' : '' ?>>Results</option>
                                <option value="events" <?= $type === 'events' ? 'selected' : '' ?>>Events</option>
                                <option value="clubs" <?= $type === 'clubs' ? 'selected' : '' ?>>Clubs</option>
                            </select>
                        </div>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="filter"></i>
                            Filtrera
                        </button>
                        <?php if ($type): ?>
                            <a href="?tab=history" class="gs-btn gs-btn-outline">
                                Rensa
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="database" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($imports) ?></div>
                    <div class="gs-stat-label">Totalt imports</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($imports, fn($i) => $i['status'] === 'completed')) ?>
                    </div>
                    <div class="gs-stat-label">Lyckade</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="rotate-ccw" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($imports, fn($i) => $i['status'] === 'rolled_back')) ?>
                    </div>
                    <div class="gs-stat-label">Återställda</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="file-text" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($imports, 'total_records')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt poster</div>
                </div>
            </div>

            <!-- Import History Table -->
            <?php if (empty($imports)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="database" class="gs-icon-64-secondary"></i>
                        <p class="gs-text-secondary">Ingen importhistorik ännu</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="calendar"></i>
                                        Datum & Tid
                                    </th>
                                    <th>
                                        <i data-lucide="tag"></i>
                                        Typ
                                    </th>
                                    <th>
                                        <i data-lucide="file"></i>
                                        Fil
                                    </th>
                                    <th class="gs-text-center">
                                        <i data-lucide="hash"></i>
                                        Poster
                                    </th>
                                    <th class="gs-text-center">
                                        <i data-lucide="check"></i>
                                        Lyckade
                                    </th>
                                    <th class="gs-text-center">
                                        <i data-lucide="edit"></i>
                                        Uppdaterade
                                    </th>
                                    <th class="gs-text-center">
                                        <i data-lucide="x"></i>
                                        Misslyckade
                                    </th>
                                    <th>
                                        <i data-lucide="activity"></i>
                                        Status
                                    </th>
                                    <th class="gs-table-col-w150-right">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($imports as $import): ?>
                                    <tr>
                                        <td>
                                            <span class="gs-text-secondary gs-font-monospace">
                                                <?= date('Y-m-d H:i', strtotime($import['imported_at'])) ?>
                                            </span>
                                            <br>
                                            <span class="gs-text-xs gs-text-secondary">
                                                av <?= h($import['imported_by']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="gs-badge gs-badge-primary">
                                                <i data-lucide="<?= getImportIcon($import['import_type']) ?>"></i>
                                                <?= h(ucfirst($import['import_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= h($import['filename']) ?></strong>
                                            <?php if ($import['file_size']): ?>
                                                <br>
                                                <span class="gs-text-xs gs-text-secondary">
                                                    <?= formatFileSize($import['file_size']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-center">
                                            <strong><?= $import['total_records'] ?></strong>
                                        </td>
                                        <td class="gs-text-center">
                                            <span class="gs-text-success">
                                                <strong><?= $import['success_count'] ?></strong>
                                            </span>
                                        </td>
                                        <td class="gs-text-center">
                                            <span class="gs-text-primary">
                                                <?= $import['updated_count'] ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-center">
                                            <?php if ($import['failed_count'] > 0): ?>
                                                <span class="gs-text-error">
                                                    <strong><?= $import['failed_count'] ?></strong>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $statusMap = [
                                                'completed' => ['badge' => 'success', 'icon' => 'check-circle', 'text' => 'Lyckad'],
                                                'failed' => ['badge' => 'danger', 'icon' => 'x-circle', 'text' => 'Misslyckad'],
                                                'rolled_back' => ['badge' => 'warning', 'icon' => 'rotate-ccw', 'text' => 'Återställd']
                                            ];
                                            $statusInfo = $statusMap[$import['status']] ?? ['badge' => 'secondary', 'icon' => 'help-circle', 'text' => $import['status']];
                                            ?>
                                            <span class="gs-badge gs-badge-<?= $statusInfo['badge'] ?>">
                                                <i data-lucide="<?= $statusInfo['icon'] ?>"></i>
                                                <?= $statusInfo['text'] ?>
                                            </span>
                                            <?php if ($import['status'] === 'rolled_back'): ?>
                                                <br>
                                                <span class="gs-text-xs gs-text-secondary">
                                                    <?= date('Y-m-d H:i', strtotime($import['rolled_back_at'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-right">
                                            <div class="gs-flex gs-gap-sm gs-justify-end">
                                                <?php if ($import['status'] === 'completed'): ?>
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline gs-btn-warning"
                                                        onclick="rollbackImport(<?= $import['id'] ?>, '<?= addslashes(h($import['filename'])) ?>', <?= $import['success_count'] + $import['updated_count'] ?>)"
                                                        title="Återställ import"
                                                    >
                                                        <i data-lucide="rotate-ccw"></i>
                                                        Rollback
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($import['error_summary']): ?>
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        onclick="showErrors(<?= $import['id'] ?>, <?= htmlspecialchars(json_encode($import['error_summary']), ENT_QUOTES) ?>)"
                                                        title="Visa fel"
                                                    >
                                                        <i data-lucide="alert-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button
                                                    type="button"
                                                    class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                    onclick="deleteImport(<?= $import['id'] ?>, '<?= addslashes(h($import['filename'])) ?>')"
                                                    title="Radera från historik"
                                                >
                                                    <i data-lucide="trash-2"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <script>
                // Rollback import with confirmation
                function rollbackImport(id, filename, affectedRecords) {
                    if (!confirm(`VARNING! Vill du verkligen återställa importen "${filename}"?\n\nDetta kommer att:\n- Radera ${affectedRecords} skapade poster\n- Återställa uppdaterade poster till tidigare värden\n\nDenna åtgärd kan INTE ångras!`)) {
                        return;
                    }

                    // Create form and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rollback">
                        <input type="hidden" name="import_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }

                // Show error details
                function showErrors(id, errorSummary) {
                    alert('Fel från import:\n\n' + errorSummary);
                }

                // Delete import from history
                function deleteImport(id, filename) {
                    if (!confirm(`Vill du radera "${filename}" från importhistoriken?\n\nOBS: Detta raderar bara historikposten, inte de importerade posterna.`)) {
                        return;
                    }

                    // Create form and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="import_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            </script>

        <?php endif; ?>
    </div>
</main>


<?php render_admin_footer(); ?>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

<?php
// Helper function for import type icons
function getImportIcon($type) {
    $icons = [
        'uci' => 'file-badge',
        'riders' => 'users',
        'results' => 'trophy',
        'events' => 'calendar',
        'clubs' => 'building',
        'other' => 'file'
    ];
    return $icons[$type] ?? 'file';
}

// Helper function for file size formatting
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>
