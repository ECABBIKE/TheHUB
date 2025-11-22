<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'success';

// Handle file upload
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

// Get recent imports
$recentImports = $db->getAll(
    "SELECT * FROM import_logs ORDER BY created_at DESC LIMIT 20"
);

$pageTitle = 'Import Data';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h3 gs-mb-lg">
            <i data-lucide="upload"></i>
            Importera data
        </h1>

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
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
