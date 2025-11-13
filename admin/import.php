<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$db = getDB();
$message = '';
$messageType = 'success';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $importType = $_POST['import_type'] ?? '';
    $file = $_FILES['import_file'];

    $validation = validateUpload($file);

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
        setFlash($message, $messageType);
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
            <h1 class="gs-h1 gs-text-primary gs-mb-lg">Importera data</h1>

            <!-- Download Templates -->
            <div class="gs-card gs-mb-xl" style="background: linear-gradient(135deg, rgba(0, 74, 152, 0.05) 0%, rgba(239, 118, 31, 0.05) 100%); border: 2px solid var(--gs-primary);">
                <div class="gs-card-header" style="background-color: var(--gs-primary); color: var(--gs-white);">
                    <h2 class="gs-h4" style="color: var(--gs-white);">
                        <i data-lucide="download"></i>
                        📥 Ladda ner importmallar
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        <strong>Använd dessa mallar för att säkerställa att dina CSV-filer har rätt kolumner och format.</strong> Mallarna innehåller exempel-data som visar exakt hur informationen ska struktureras.
                    </p>

                    <div class="gs-flex gs-gap-md gs-mb-lg">
                        <a href="/admin/download-templates.php?template=riders"
                           class="gs-btn gs-btn-primary gs-btn-lg"
                           download>
                            <i data-lucide="users"></i>
                            Deltagare-mall (CSV)
                        </a>

                        <a href="/admin/download-templates.php?template=results"
                           class="gs-btn gs-btn-accent gs-btn-lg"
                           download>
                            <i data-lucide="flag"></i>
                            Resultat-mall (CSV)
                        </a>
                    </div>

                    <!-- Column Info -->
                    <div style="background: var(--gs-white); padding: 1.5rem; border-radius: var(--gs-radius-md); border: 1px solid var(--gs-border);">
                        <h4 class="gs-h5 gs-mb-md" style="color: var(--gs-primary);">
                            <i data-lucide="info"></i>
                            Kolumn-beskrivningar
                        </h4>

                        <details class="gs-mb-md" style="cursor: pointer;">
                            <summary style="cursor: pointer; font-weight: 600; padding: 0.5rem; background: var(--gs-light); border-radius: var(--gs-radius-sm);">
                                📄 Deltagare-kolumner (12 kolumner)
                            </summary>
                            <ul style="margin-top: 0.75rem; margin-left: 1.5rem; line-height: 1.8;">
                                <li><strong>first_name:</strong> Förnamn (required)</li>
                                <li><strong>last_name:</strong> Efternamn (required)</li>
                                <li><strong>personnummer:</strong> Svenskt personnummer - YYYYMMDD-XXXX eller YYMMDD-XXXX (optional, parsas automatiskt till födelseår)</li>
                                <li><strong>birth_year:</strong> Födelseår, format: YYYY (required om personnummer saknas)</li>
                                <li><strong>uci_id:</strong> UCI-ID, format: SWE19950101 (optional, används för matchning)</li>
                                <li><strong>swe_id:</strong> SWE-ID, format: SWE25XXXXX (optional, autogenereras om tomt)</li>
                                <li><strong>club_name:</strong> Klubbnamn (fuzzy matching används för att hitta befintliga klubbar)</li>
                                <li><strong>gender:</strong> Kön: M/F/Other (required)</li>
                                <li><strong>license_type:</strong> Licens-typ: Elite/Youth/Hobby/Beginner/None</li>
                                <li><strong>license_category:</strong> Licenskategori: "Elite Men", "Youth Women", "Master Men 35+", etc</li>
                                <li><strong>discipline:</strong> Gren: MTB/Road/Track/BMX/CX/Trial/Para/E-cycling/Gravel</li>
                                <li><strong>license_valid_until:</strong> Licens giltig till, format: YYYY-MM-DD</li>
                            </ul>
                            <div style="margin-top: 0.75rem; padding: 0.75rem; background: rgba(239, 118, 31, 0.1); border-left: 3px solid var(--gs-accent); border-radius: var(--gs-radius-sm);">
                                <strong>💡 Tips personnummer:</strong> Både format 19950525-1234 och 950525-1234 fungerar. Systemet beräknar automatiskt ålder och föreslår lämplig licenskategori baserat på födelsedatum och kön.
                            </div>
                            <div style="margin-top: 0.75rem; padding: 0.75rem; background: rgba(67, 114, 100, 0.1); border-left: 3px solid var(--gs-success); border-radius: var(--gs-radius-sm);">
                                <strong>💡 Tips licens:</strong> Om UCI-ID saknas genereras SWE-ID automatiskt (format: SWE25XXXXX). Licenskategori föreslås automatiskt baserat på ålder och kön om fältet lämnas tomt.
                            </div>
                        </details>

                        <details style="cursor: pointer;">
                            <summary style="cursor: pointer; font-weight: 600; padding: 0.5rem; background: var(--gs-light); border-radius: var(--gs-radius-sm);">
                                🏁 Resultat-kolumner (12 kolumner)
                            </summary>
                            <ul style="margin-top: 0.75rem; margin-left: 1.5rem; line-height: 1.8;">
                                <li><strong>event_name:</strong> Tävlingsnamn (required, används för att matcha event)</li>
                                <li><strong>event_date:</strong> Datum, format: YYYY-MM-DD (required)</li>
                                <li><strong>discipline:</strong> Disciplin: EDR/DHI/DS/XC (required)</li>
                                <li><strong>category:</strong> Kategori, ex: "Elite Men" (required)</li>
                                <li><strong>position:</strong> Placering, nummer (required för finished)</li>
                                <li><strong>first_name:</strong> Förnamn (required)</li>
                                <li><strong>last_name:</strong> Efternamn (required)</li>
                                <li><strong>club_name:</strong> Klubbnamn (optional)</li>
                                <li><strong>uci_id:</strong> UCI-ID för matchning av cyklist (optional men rekommenderas)</li>
                                <li><strong>swe_id:</strong> SWE-ID för matchning av cyklist (optional)</li>
                                <li><strong>time_seconds:</strong> Tid i sekunder, ex: 185.45 (optional)</li>
                                <li><strong>status:</strong> Status: finished/dnf/dns/dq (default: finished)</li>
                            </ul>
                            <div style="margin-top: 0.75rem; padding: 0.75rem; background: rgba(67, 114, 100, 0.1); border-left: 3px solid var(--gs-success); border-radius: var(--gs-radius-sm);">
                                <strong>💡 Tips:</strong> Systemet matchar cyklister via UCI-ID eller namn. Events matchas via namn och datum. För DNF/DNS/DQ lämna position tom.
                            </div>
                        </details>

                        <div style="margin-top: 1rem; padding: 1rem; background: rgba(0, 74, 152, 0.05); border-radius: var(--gs-radius-sm);">
                            <h5 style="font-weight: 600; margin-bottom: 0.5rem;">📋 Import-flöde:</h5>
                            <ol style="margin-left: 1.5rem; line-height: 1.8;">
                                <li>Ladda ner mall (CSV)</li>
                                <li>Öppna i Excel/Numbers/Google Sheets</li>
                                <li>Ta bort exempel-raderna</li>
                                <li>Lägg till din data</li>
                                <li>Spara som CSV (UTF-8)</li>
                                <li>Använd import-knapparna nedan</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Links to Specialized Import Pages -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg gs-mb-xl">
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="users-2"></i>
                            Importera Cyklister
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-secondary gs-mb-md">
                            Bulk-import av cyklister från CSV-fil med fuzzy matching för klubbar.
                        </p>
                        <ul class="gs-text-sm gs-text-secondary gs-mb-md" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                            <li>Stöder upp till 3000+ cyklister</li>
                            <li>Automatisk klubb-matchning</li>
                            <li>Dubbletthantering via license/namn</li>
                            <li>Progress tracking</li>
                        </ul>
                        <a href="/admin/import-riders.php" class="gs-btn gs-btn-primary">
                            <i data-lucide="arrow-right"></i>
                            Importera Cyklister
                        </a>
                    </div>
                </div>

                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="trophy"></i>
                            Importera Resultat
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-secondary gs-mb-md">
                            Bulk-import av tävlingsresultat från CSV-fil med automatisk matchning.
                        </p>
                        <ul class="gs-text-sm gs-text-secondary gs-mb-md" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                            <li>Matchar cyklister via UCI/namn</li>
                            <li>Matchar events via namn</li>
                            <li>Detaljerad matchnings-statistik</li>
                            <li>Uppdaterar befintliga resultat</li>
                        </ul>
                        <a href="/admin/import-results.php" class="gs-btn gs-btn-primary">
                            <i data-lucide="arrow-right"></i>
                            Importera Resultat
                        </a>
                    </div>
                </div>
            </div>

            <?php $flash = getFlash(); if ($flash): ?>
                <div class="gs-alert gs-alert-<?= h($flash['type']) ?> gs-mb-lg">
                    <?= h($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Upload Form -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="file-up"></i>
                        Ladda upp Excel-fil
                    </h2>
                </div>
                <div class="gs-card-content">
                    <form method="POST" enctype="multipart/form-data" style="max-width: 600px;">
                        <div class="gs-form-group">
                            <label for="import_type" class="gs-label">Importtyp</label>
                            <select id="import_type" name="import_type" class="gs-input" required>
                                <option value="">Välj typ...</option>
                                <option value="cyclists">Cyklister</option>
                                <option value="results">Resultat</option>
                            </select>
                        </div>

                        <div class="gs-form-group">
                            <label for="import_file" class="gs-label">Excel-fil (.xlsx, .xls)</label>
                            <input type="file" id="import_file" name="import_file" class="gs-input" accept=".xlsx,.xls,.csv" required>
                            <small class="gs-text-secondary gs-text-sm">Max storlek: <?= round(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB</small>
                        </div>

                        <button type="submit" class="gs-btn gs-btn-primary gs-btn-lg">
                            <i data-lucide="upload"></i>
                            Importera
                        </button>
                    </form>

                    <!-- Import Info -->
                    <div class="gs-mt-xl" style="padding-top: var(--gs-space-xl); border-top: 1px solid var(--gs-border);">
                        <h3 class="gs-h4 gs-text-primary gs-mb-md">
                            <i data-lucide="file-text"></i>
                            Filformat
                        </h3>

                        <div class="gs-mb-lg">
                            <h4 class="gs-text-base gs-text-primary gs-mb-sm" style="font-weight: 600;">Cyklister (cyclists):</h4>
                            <ul class="gs-text-secondary gs-text-sm" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                                <li>Kolumn A: Förnamn</li>
                                <li>Kolumn B: Efternamn</li>
                                <li>Kolumn C: Födelseår</li>
                                <li>Kolumn D: Kön (M/F)</li>
                                <li>Kolumn E: Klubb</li>
                                <li>Kolumn F: Licensnummer</li>
                                <li>Kolumn G: E-post</li>
                                <li>Kolumn H: Telefon</li>
                                <li>Kolumn I: Ort</li>
                            </ul>
                        </div>

                        <div>
                            <h4 class="gs-text-base gs-text-primary gs-mb-sm" style="font-weight: 600;">Resultat (results):</h4>
                            <ul class="gs-text-secondary gs-text-sm" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                                <li>Kolumn A: Tävlingsnamn</li>
                                <li>Kolumn B: Datum (YYYY-MM-DD)</li>
                                <li>Kolumn C: Plats</li>
                                <li>Kolumn D: Placering</li>
                                <li>Kolumn E: Startnummer</li>
                                <li>Kolumn F: Förnamn</li>
                                <li>Kolumn G: Efternamn</li>
                                <li>Kolumn H: Födelseår</li>
                                <li>Kolumn I: Klubb</li>
                                <li>Kolumn J: Tid (HH:MM:SS)</li>
                                <li>Kolumn K: Kategori</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Import History -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="clock"></i>
                        Importhistorik
                    </h2>
                </div>
                <div class="gs-card-content">
                    <?php if (empty($recentImports)): ?>
                        <p class="gs-text-secondary gs-text-center gs-py-lg">Inga importer ännu</p>
                    <?php else: ?>
                        <div class="gs-table-responsive">
                            <table class="gs-table">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Typ</th>
                                        <th>Fil</th>
                                        <th>Totalt</th>
                                        <th>Lyckade</th>
                                        <th>Misslyckade</th>
                                        <th>Importerad av</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentImports as $import): ?>
                                        <tr>
                                            <td class="gs-text-sm"><?= formatDate($import['created_at'], 'd M Y H:i') ?></td>
                                            <td class="gs-text-sm"><?= h($import['import_type']) ?></td>
                                            <td class="gs-text-secondary gs-text-sm"><?= h($import['filename']) ?></td>
                                            <td class="gs-text-sm"><?= number_format($import['records_total']) ?></td>
                                            <td style="color: var(--gs-success); font-weight: 600;"><?= number_format($import['records_success']) ?></td>
                                            <td style="color: #dc2626; font-weight: 600;"><?= number_format($import['records_failed']) ?></td>
                                            <td class="gs-text-secondary gs-text-sm"><?= h($import['imported_by']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
