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
                                <li><strong>time</strong> eller <strong>finish_time:</strong> Total tid i format mm:ss.cc eller h:mm:ss.mmm, ex: 16:19.16 eller 1:16:19.164 (optional)</li>
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

            <!-- Flexibel Import -->
            <div class="gs-card" style="border: 2px solid #86efac; background: #f0fdf4; margin-bottom: var(--gs-space-xl);">
                <div class="gs-card-header" style="background: #10b981;">
                    <h2 class="gs-h4" style="color: white; margin: 0;">
                        <i data-lucide="sparkles"></i>
                        Flexibel Deltagare Import ⭐
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        <strong>REKOMMENDERAD!</strong> Importera CSV med kolumner i valfri ordning.
                    </p>
                    <ul class="gs-text-sm gs-text-secondary gs-mb-md" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
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

            <!-- Flexibel Enduro Resultat Import -->
            <div class="gs-card" style="border: 2px solid #fbbf24; background: #fffbeb; margin-bottom: var(--gs-space-xl);">
                <div class="gs-card-header" style="background: #f59e0b;">
                    <h2 class="gs-h4" style="color: white; margin: 0;">
                        <i data-lucide="zap"></i>
                        Flexibel Enduro Resultat Import 🏁
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        <strong>NY!</strong> Importera Enduro-resultat med flexibelt antal stage-sektioner (SS1, SS2, SS3...)
                    </p>

                    <div style="background: white; padding: 1.25rem; border-radius: var(--gs-radius-md); border: 1px solid #fbbf24; margin-bottom: 1rem;">
                        <h4 class="gs-h5 gs-mb-md" style="color: #f59e0b;">
                            <i data-lucide="file-text"></i>
                            CSV Format-krav
                        </h4>
                        <p class="gs-text-sm gs-mb-sm"><strong>Obligatoriska kolumner:</strong></p>
                        <code style="display: block; background: #f3f4f6; padding: 0.75rem; border-radius: 4px; font-size: 0.875rem; margin-bottom: 1rem;">
event_name, event_date, discipline, category, position, first_name, last_name, club_name, uci_id, time_seconds, status
                        </code>

                        <p class="gs-text-sm gs-mb-sm"><strong>Flexibla Stage-kolumner:</strong></p>
                        <code style="display: block; background: #f3f4f6; padding: 0.75rem; border-radius: 4px; font-size: 0.875rem; margin-bottom: 1rem;">
SS1, SS2, SS3, SS4, SS5, SS6, SS7, ... (valfritt antal)
                        </code>

                        <div style="background: #eff6ff; padding: 1rem; border-left: 3px solid #3b82f6; border-radius: 4px; margin-top: 1rem;">
                            <p class="gs-text-sm" style="margin: 0;"><strong>💡 Tips:</strong> Systemet detekterar automatiskt antalet SS-kolumner i din CSV. Du kan ha 1, 5, 7, 10 eller vilket antal som helst!</p>
                        </div>
                    </div>

                    <details class="gs-mb-md" style="cursor: pointer;">
                        <summary style="cursor: pointer; font-weight: 600; padding: 0.75rem; background: var(--gs-light); border-radius: var(--gs-radius-sm); margin-bottom: 0.5rem;">
                            📋 Exempel CSV-format (SweCup Falun)
                        </summary>
                        <pre style="background: #1f2937; color: #f3f4f6; padding: 1rem; border-radius: 6px; font-size: 0.813rem; overflow-x: auto; line-height: 1.5;">
event_name,event_date,discipline,category,position,first_name,last_name,club_name,uci_id,time_seconds,status,SS1,SS2,SS3,SS4,SS5,SS6,SS7
SweCup Enduro Falun 2025,2025-09-14,END,Damer Junior,1,Ella,MÅRTENSSON,Borås CA,10022510347,979.16,FIN,130.55,107.08,111.10,127.70,92.10,74.83,157.35
SweCup Enduro Falun 2025,2025-09-14,END,Herrar Elite,1,Johan,ANDERSSON,Stockholm CK,10011223344,856.42,FIN,118.22,98.55,102.33,115.88,84.12,68.91,144.21
SweCup Enduro Falun 2025,2025-09-14,END,Herrar Elite,2,Erik,SVENSSON,Göteborg MTB,,DNF,DNF,115.34,99.21,DNF,DNF,DNF,DNF,DNF</pre>
                    </details>

                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                        <div>
                            <h5 class="gs-text-sm gs-text-primary" style="font-weight: 600; margin-bottom: 0.5rem;">Kolumnbeskrivningar:</h5>
                            <ul class="gs-text-xs" style="line-height: 1.7; margin-left: 1.25rem;">
                                <li><code>event_name</code>: Tävlingsnamn</li>
                                <li><code>event_date</code>: YYYY-MM-DD</li>
                                <li><code>discipline</code>: END/EDR/DHI/XC</li>
                                <li><code>category</code>: "Damer Junior", "Herrar Elite"</li>
                                <li><code>position</code>: Placering (nummer)</li>
                                <li><code>first_name</code>: Förnamn</li>
                                <li><code>last_name</code>: Efternamn</li>
                            </ul>
                        </div>
                        <div>
                            <h5 class="gs-text-sm gs-text-primary" style="font-weight: 600; margin-bottom: 0.5rem;">Fler kolumner:</h5>
                            <ul class="gs-text-xs" style="line-height: 1.7; margin-left: 1.25rem;">
                                <li><code>club_name</code>: Klubbnamn</li>
                                <li><code>uci_id</code>: UCI-ID (optional)</li>
                                <li><code>time</code> eller <code>finish_time</code>: Total tid (format: mm:ss.cc eller h:mm:ss.mmm)</li>
                                <li><code>status</code>: FIN/DNF/DNS/DQ</li>
                                <li><code>SS1, SS2...</code>: Stage-tider (format: mm:ss.cc eller h:mm:ss.mmm)</li>
                            </ul>
                        </div>
                    </div>

                    <a href="/admin/import-results.php" class="gs-btn gs-btn-warning gs-btn-lg gs-w-full" style="margin-top: 1rem; background: #f59e0b; color: white;">
                        <i data-lucide="upload"></i>
                        Importera Enduro Resultat
                    </a>
                </div>
            </div>
        </div>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
