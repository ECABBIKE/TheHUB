<?php
require_once __DIR__ . '/../config.php';
require_admin();

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
            <div class="gs-card gs-mb-xl gs-featured-card-primary">
                <div class="gs-card-header gs-featured-header-primary">
                    <h2 class="gs-h4 gs-heading-white">
                        <i data-lucide="download"></i>
                        📥 Ladda ner importmallar
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        <strong>Använd dessa mallar för att säkerställa att dina CSV-filer har rätt kolumner och format.</strong> Mallarna innehåller exempel-data som visar exakt hur informationen ska struktureras.
                    </p>

                    <div class="gs-flex gs-gap-md gs-mb-lg gs-flex-wrap">
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
                            Enduro-resultat (CSV)
                        </a>

                        <a href="/admin/download-templates.php?template=results_dh"
                           class="gs-btn gs-btn-accent gs-btn-lg"
                           download>
                            <i data-lucide="mountain"></i>
                            DH-resultat (CSV)
                        </a>

                        <a href="/templates/poangmall-standard.csv"
                           class="gs-btn gs-btn-secondary gs-btn-lg"
                           download>
                            <i data-lucide="award"></i>
                            Poängmall Standard (CSV)
                        </a>

                        <a href="/templates/poangmall-dh.csv"
                           class="gs-btn gs-btn-secondary gs-btn-lg"
                           download>
                            <i data-lucide="trophy"></i>
                            Poängmall DH (CSV)
                        </a>
                    </div>

                    <!-- Column Info -->
                    <div class="gs-info-box">
                        <h4 class="gs-h5 gs-mb-md gs-heading-primary">
                            <i data-lucide="info"></i>
                            Kolumn-beskrivningar
                        </h4>

                        <details class="gs-mb-md gs-details">
                            <summary>
                                📄 Deltagare-kolumner (12 kolumner)
                            </summary>
                            <ul class="gs-list-spaced">
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
                            <div class="gs-alert-accent">
                                <strong>💡 Tips personnummer:</strong> Både format 19950525-1234 och 950525-1234 fungerar. Systemet beräknar automatiskt ålder och föreslår lämplig licenskategori baserat på födelsedatum och kön.
                            </div>
                            <div class="gs-alert-success">
                                <strong>💡 Tips licens:</strong> Om UCI-ID saknas genereras SWE-ID automatiskt (format: SWE25XXXXX). Licenskategori föreslås automatiskt baserat på ålder och kön om fältet lämnas tomt.
                            </div>
                        </details>

                        <details class="gs-details">
                            <summary>
                                🏁 Enduro Resultat-kolumner
                            </summary>
                            <ul class="gs-list-spaced">
                                <li><strong>Category:</strong> Klass, ex: "Damer Junior", "Herrar Elite" (required)</li>
                                <li><strong>PlaceByCategory:</strong> Placering inom klass (required för finished)</li>
                                <li><strong>FirstName:</strong> Förnamn (required)</li>
                                <li><strong>LastName:</strong> Efternamn (required)</li>
                                <li><strong>Club:</strong> Klubbnamn (optional)</li>
                                <li><strong>UCI-ID:</strong> UCI-ID för matchning (optional men rekommenderas)</li>
                                <li><strong>NetTime:</strong> Total tid, format: h:mm:ss.cc eller mm:ss.cc (optional)</li>
                                <li><strong>Status:</strong> FIN/DNF/DNS/DQ (default: FIN)</li>
                                <li><strong>SS1, SS2... SS15:</strong> Stage-tider, format: mm:ss.cc (optional)</li>
                            </ul>
                            <div class="gs-alert-success">
                                <strong>💡 Tips:</strong> Event väljs i förhandsgranskningen. Systemet matchar cyklister via UCI-ID eller namn.
                            </div>
                        </details>

                        <details class="gs-details">
                            <summary>
                                ⛷️ DH Resultat-kolumner
                            </summary>
                            <ul class="gs-list-spaced">
                                <li><strong>Category:</strong> Klass, ex: "Damer Junior", "Herrar Elite" (required)</li>
                                <li><strong>PlaceByCategory:</strong> Placering inom klass (required för finished)</li>
                                <li><strong>FirstName:</strong> Förnamn (required)</li>
                                <li><strong>LastName:</strong> Efternamn (required)</li>
                                <li><strong>Club:</strong> Klubbnamn (optional)</li>
                                <li><strong>UCI-ID:</strong> UCI-ID för matchning (optional men rekommenderas)</li>
                                <li><strong>NetTime:</strong> Bästa tid, format: mm:ss.cc (optional)</li>
                                <li><strong>Status:</strong> FIN/DNF/DNS/DQ (default: FIN)</li>
                                <li><strong>Run1, Run2:</strong> Åktider, format: mm:ss.cc (optional)</li>
                            </ul>
                            <div class="gs-alert-success">
                                <strong>💡 Tips:</strong> Bästa av två åk används som sluttid. Split-tider kan läggas i SS1-SS4 (Run 1) och SS5-SS8 (Run 2).
                            </div>
                        </details>

                        <details class="gs-details">
                            <summary>
                                🏆 Poängmall-kolumner
                            </summary>
                            <p class="gs-text-sm gs-mb-sm"><strong>Standard poängmall (2 kolumner):</strong></p>
                            <ul class="gs-list-spaced">
                                <li><strong>Position:</strong> Placering (1, 2, 3...) (required)</li>
                                <li><strong>Poäng:</strong> Poäng för denna placering (required)</li>
                            </ul>
                            <p class="gs-text-sm gs-mb-sm gs-mt-md"><strong>DH poängmall med Kval/Final (3 kolumner):</strong></p>
                            <ul class="gs-list-spaced">
                                <li><strong>Position:</strong> Placering (1, 2, 3...) (required)</li>
                                <li><strong>Kval:</strong> Poäng för kvalificering (required)</li>
                                <li><strong>Final:</strong> Poäng för final (required)</li>
                            </ul>
                            <div class="gs-alert-info">
                                <strong>💡 Tips:</strong> Använd semikolon (;) som kolumnseparator. Importera via Admin → Poängmallar → Importera från CSV.
                            </div>
                        </details>

                        <div class="gs-alert-primary">
                            <h5 class="gs-heading-semibold">📋 Import-flöde:</h5>
                            <ol class="gs-list-spaced">
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

            <!-- Flexibel Import -->
            <div class="gs-card gs-featured-card-success">
                <div class="gs-card-header gs-featured-header-success">
                    <h2 class="gs-h4 gs-featured-header-title">
                        <i data-lucide="sparkles"></i>
                        Flexibel Deltagare Import ⭐
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        <strong>REKOMMENDERAD!</strong> Importera CSV med kolumner i valfri ordning.
                    </p>
                    <ul class="gs-text-sm gs-text-secondary gs-mb-md gs-list-lg">
                        <li><strong>Kolumner i valfri ordning</strong></li>
                        <li>Okända kolumner ignoreras</li>
                        <li>Förhandsgranska innan import</li>
                        <li>Svenska & engelska kolumnnamn</li>
                        <li>Inkluderar privata fält (sekretess)</li>
                    </ul>
                    <a href="/admin/import-riders-flexible.php" class="gs-btn gs-btn-success gs-btn-lg gs-w-full">
                        <i data-lucide="arrow-right"></i>
                        Använd Flexibel Deltagare Import
                    </a>
                </div>
            </div>

            <!-- Flexibel Resultat Import -->
            <div class="gs-card gs-featured-card-warning">
                <div class="gs-card-header gs-featured-header-warning">
                    <h2 class="gs-h4 gs-featured-header-title">
                        <i data-lucide="zap"></i>
                        Flexibel Resultat Import 🏁
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        <strong>NY!</strong> Importera Enduro (SS1-SS15) eller Downhill (Run1, Run2) resultat
                    </p>

                    <div class="gs-info-box-bordered">
                        <h4 class="gs-h5 gs-mb-md gs-heading-warning">
                            <i data-lucide="file-text"></i>
                            CSV Format-krav
                        </h4>
                        <p class="gs-text-sm gs-mb-sm"><strong>Obligatoriska kolumner:</strong></p>
                        <code class="gs-code-block">
Category, PlaceByCategory, FirstName, LastName, Club, NetTime, Status
                        </code>

                        <p class="gs-text-sm gs-mb-sm"><strong>Valfria kolumner:</strong></p>
                        <code class="gs-code-block">
UCI-ID, SS1, SS2, SS3, SS4, SS5, SS6, SS7, SS8, SS9, SS10
                        </code>

                        <div class="gs-alert-info">
                            <p class="gs-text-sm gs-m-0"><strong>💡 Tips:</strong> Systemet detekterar automatiskt kolumnnamn. Event väljs i förhandsgranskningen om det saknas i filen. Stödjer också svenska namn som Klass, Placering, Förnamn, Efternamn, Klubb, Tid.</p>
                        </div>
                    </div>

                    <details class="gs-mb-md gs-details-lg">
                        <summary>
                            📋 Exempel CSV-format (Enduro)
                        </summary>
                        <pre class="gs-code-dark">
Category,PlaceByCategory,FirstName,LastName,Club,UCI-ID,NetTime,Status,SS1,SS2,SS3,SS4,SS5,SS6,SS7
Damer Junior,1,Ella,MÅRTENSSON,Borås CA,10022510347,16:19.16,FIN,2:10.55,1:47.08,1:51.10,2:07.70,1:32.10,1:14.83,2:37.35
Herrar Elite,1,Johan,ANDERSSON,Stockholm CK,10011223344,14:16.42,FIN,1:58.22,1:38.55,1:42.33,1:55.88,1:24.12,1:08.91,2:24.21
Herrar Elite,2,Erik,SVENSSON,Göteborg MTB,,DNF,DNF,1:55.34,1:39.21,DNF,DNF,DNF,DNF,DNF</pre>
                    </details>

                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                        <div>
                            <h5 class="gs-text-sm gs-text-primary gs-heading-semibold">Kolumnbeskrivningar:</h5>
                            <ul class="gs-text-xs gs-list-compact">
                                <li><code>Category</code>: Klass (ex: "Damer Junior", "Herrar Elite")</li>
                                <li><code>PlaceByCategory</code>: Placering (nummer)</li>
                                <li><code>FirstName</code>: Förnamn</li>
                                <li><code>LastName</code>: Efternamn</li>
                                <li><code>Club</code>: Klubbnamn</li>
                                <li><code>UCI-ID</code>: UCI-ID (optional)</li>
                            </ul>
                        </div>
                        <div>
                            <h5 class="gs-text-sm gs-text-primary gs-heading-semibold">Fler kolumner:</h5>
                            <ul class="gs-text-xs gs-list-compact">
                                <li><code>NetTime</code>: Total tid (format: mm:ss.cc)</li>
                                <li><code>Status</code>: FIN/DNF/DNS/DQ</li>
                                <li><code>SS1, SS2...</code>: Stage-tider (format: mm:ss.cc)</li>
                            </ul>
                            <p class="gs-text-xs gs-text-secondary gs-mt-sm">
                                Event väljs i förhandsgranskningen efter uppladdning.
                            </p>
                        </div>
                    </div>

                    <a href="/admin/import-results.php" class="gs-btn gs-btn-warning gs-btn-lg gs-w-full gs-btn-warning-solid">
                        <i data-lucide="upload"></i>
                        Importera Resultat (Enduro/DH)
                    </a>
                </div>
            </div>
        </div>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
