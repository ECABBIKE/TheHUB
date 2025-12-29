<?php
/**
 * Import Results - V3.5 Tabbed System
 * Supports: Enduro, Downhill, XC, Dual Slalom
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-history.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_once __DIR__ . '/../includes/import-functions.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Get active tab from URL or default to enduro
$activeTab = $_GET['tab'] ?? 'enduro';
$validTabs = ['enduro', 'dh', 'xc', 'dual_slalom'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'enduro';
}

// Handle template download
if (isset($_GET['template'])) {
    $format = $_GET['template'];
    header('Content-Type: text/csv; charset=utf-8');

    if ($format === 'enduro') {
        header('Content-Disposition: attachment; filename="resultat_enduro_mall.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, [
            'Category', 'PlaceByCategory', 'Bib no', 'FirstName', 'LastName', 'Club', 'UCI-ID',
            'NetTime', 'Status', 'SS1', 'SS2', 'SS3', 'SS4', 'SS5', 'SS6'
        ], ';');
        fputcsv($output, [
            'Herrar Elit', '1', '101', 'Erik', 'Svensson', 'Stockholm MTB', '10012345678',
            '15:42.33', 'FIN', '2:15.44', '1:52.11', '2:33.55', '2:18.22', '3:01.88', '3:21.13'
        ], ';');
    } elseif ($format === 'dh') {
        header('Content-Disposition: attachment; filename="resultat_dh_mall.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, [
            'Category', 'PlaceByCategory', 'Bib no', 'FirstName', 'LastName', 'Club', 'UCI-ID',
            'Run1', 'Run2', 'NetTime', 'Status'
        ], ';');
        fputcsv($output, [
            'Herrar Elit', '1', '101', 'Erik', 'Svensson', 'Stockholm MTB', '10012345678',
            '2:15.44', '2:12.33', '2:12.33', 'FIN'
        ], ';');
    } elseif ($format === 'xc') {
        header('Content-Disposition: attachment; filename="resultat_xc_mall.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, [
            'Category', 'PlaceByCategory', 'Bib no', 'FirstName', 'LastName', 'Club', 'UCI-ID',
            'NetTime', 'Status', 'Lap1', 'Lap2', 'Lap3', 'Lap4', 'Lap5'
        ], ';');
        fputcsv($output, [
            'Herrar Elit', '1', '101', 'Erik', 'Svensson', 'Stockholm MTB', '10012345678',
            '1:02:42.33', 'FIN', '12:15.44', '11:52.11', '12:33.55', '12:18.22', '13:43.01'
        ], ';');
    } elseif ($format === 'dual_slalom') {
        header('Content-Disposition: attachment; filename="resultat_ds_kval_mall.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, [
            'Category', 'Bib no', 'FirstName', 'LastName', 'Club',
            'Run1', 'Run2', 'BestTime', 'Status'
        ], ';');
        fputcsv($output, [
            'Herrar Elit', '101', 'Erik', 'Svensson', 'Stockholm MTB',
            '32.44', '31.22', '31.22', 'FIN'
        ], ';');
    }

    fclose($output);
    exit;
}

// Load existing events for dropdown with series info
$existingEvents = $db->getAll("
    SELECT e.id, e.name, e.date, e.location, YEAR(e.date) as event_year,
        e.discipline, e.event_format,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as series_names
    FROM events e
    LEFT JOIN series_events se ON e.id = se.event_id
    LEFT JOIN series s ON se.series_id = s.id
    GROUP BY e.id
    ORDER BY e.date DESC
    LIMIT 500
");

// Get unique years for filter
$eventYears = array_unique(array_column($existingEvents, 'event_year'));
rsort($eventYears);

// Handle CSV upload - redirect to preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    checkCsrf();

    $file = $_FILES['import_file'];
    $selectedEventId = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
    $importFormat = !empty($_POST['import_format']) ? $_POST['import_format'] : null;

    // Validate format and event selection
    if (!$importFormat || !in_array($importFormat, ['enduro', 'dh', 'xc'])) {
        $message = 'Du måste välja ett giltigt format';
        $messageType = 'error';
    } elseif (!$selectedEventId) {
        $message = 'Du måste välja ett event först';
        $messageType = 'error';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Filuppladdning misslyckades';
        $messageType = 'error';
    } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
        $message = 'Filen är för stor (max ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB)';
        $messageType = 'error';
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            $message = 'Endast CSV-filer stöds för resultatimport';
            $messageType = 'error';
        } else {
            // Save file and redirect to preview
            $uploaded = UPLOADS_PATH . '/' . time() . '_preview_' . basename($file['name']);

            if (move_uploaded_file($file['tmp_name'], $uploaded)) {
                // Clear old preview data
                unset($_SESSION['import_preview_file']);
                unset($_SESSION['import_preview_filename']);
                unset($_SESSION['import_preview_data']);
                unset($_SESSION['import_events_summary']);
                unset($_SESSION['import_selected_event']);

                // Store in session and redirect to preview
                $_SESSION['import_preview_file'] = $uploaded;
                $_SESSION['import_preview_filename'] = $file['name'];
                $_SESSION['import_selected_event'] = $selectedEventId;
                $_SESSION['import_format'] = $importFormat;

                header('Location: /admin/import-results-preview.php');
                exit;
            } else {
                $message = 'Kunde inte ladda upp filen';
                $messageType = 'error';
            }
        }
    }
}

// Page config for unified layout
$page_title = 'Importera Resultat';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import'],
    ['label' => 'Resultat']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Messages -->
<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Tab Navigation -->
<div class="tabs mb-lg">
    <nav class="tabs-nav">
        <a href="?tab=enduro" class="tab-btn <?= $activeTab === 'enduro' ? 'active' : '' ?>">
            <i data-lucide="mountain"></i>
            Enduro
        </a>
        <a href="?tab=dh" class="tab-btn <?= $activeTab === 'dh' ? 'active' : '' ?>">
            <i data-lucide="arrow-down"></i>
            Downhill
        </a>
        <a href="?tab=xc" class="tab-btn <?= $activeTab === 'xc' ? 'active' : '' ?>">
            <i data-lucide="circle"></i>
            XC
        </a>
        <a href="?tab=dual_slalom" class="tab-btn <?= $activeTab === 'dual_slalom' ? 'active' : '' ?>">
            <i data-lucide="git-branch"></i>
            Dual Slalom
        </a>
    </nav>
</div>

<?php if ($activeTab === 'dual_slalom'): ?>
<!-- DUAL SLALOM TAB -->
<div class="card">
    <div class="card-header">
        <h2 class="text-primary">
            <i data-lucide="git-branch"></i>
            Dual Slalom / Elimination
        </h2>
    </div>
    <div class="card-body">
        <p class="mb-lg">Dual Slalom använder ett separat system för kvalificering och bracket-hantering.</p>

        <div class="grid grid-cols-1 md-grid-cols-2 gap-lg">
            <!-- Import Qualifying -->
            <div class="card card-bordered">
                <div class="card-header">
                    <h3><i data-lucide="upload"></i> Importera kvalresultat</h3>
                </div>
                <div class="card-body">
                    <p class="text-sm text-secondary mb-md">
                        Importera CSV med kvaltider för att seeda deltagare till bracket.
                    </p>
                    <a href="/admin/elimination-import-qualifying.php" class="btn btn--primary w-full">
                        <i data-lucide="file-plus"></i>
                        Importera kvalresultat
                    </a>
                </div>
            </div>

            <!-- Manage Bracket -->
            <div class="card card-bordered">
                <div class="card-header">
                    <h3><i data-lucide="git-merge"></i> Hantera bracket</h3>
                </div>
                <div class="card-body">
                    <p class="text-sm text-secondary mb-md">
                        Hantera elimination-bracket och registrera resultat från omgångar.
                    </p>
                    <a href="/admin/elimination.php" class="btn btn--secondary w-full">
                        <i data-lucide="list"></i>
                        Visa alla DS-events
                    </a>
                </div>
            </div>
        </div>

        <!-- Template Download -->
        <div class="mt-lg">
            <a href="?template=dual_slalom" class="btn btn--secondary btn--sm">
                <i data-lucide="download"></i>
                Ladda ner mall för kvalresultat
            </a>
        </div>

        <!-- Format Info -->
        <div class="alert alert--info mt-lg">
            <i data-lucide="info"></i>
            <div>
                <strong>CSV-format för kvalificering:</strong><br>
                <code>Category, Bib, FirstName, LastName, Club, Run1, Run2, BestTime, Status</code>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ENDURO / DH / XC TABS -->
<div class="card">
    <div class="card-header">
        <h2 class="text-primary">
            <?php if ($activeTab === 'enduro'): ?>
            <i data-lucide="mountain"></i> Importera Enduro-resultat
            <?php elseif ($activeTab === 'dh'): ?>
            <i data-lucide="arrow-down"></i> Importera Downhill-resultat
            <?php else: ?>
            <i data-lucide="circle"></i> Importera XC-resultat
            <?php endif; ?>
        </h2>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="gs-form">
            <?= csrf_field() ?>
            <input type="hidden" name="import_format" value="<?= $activeTab === 'xc' ? 'xc' : $activeTab ?>">

            <!-- Download Template -->
            <div class="form-group mb-lg">
                <a href="?template=<?= $activeTab ?>" class="btn btn--secondary btn--sm">
                    <i data-lucide="download"></i>
                    Ladda ner <?= $activeTab === 'enduro' ? 'Enduro' : ($activeTab === 'dh' ? 'DH' : 'XC') ?>-mall
                </a>
            </div>

            <!-- Step 1: Select Year -->
            <div class="form-group mb-lg">
                <label for="year_filter" class="label label-lg">
                    <span class="badge badge-primary mr-sm">1</span>
                    Välj år
                </label>
                <select id="year_filter" class="input input-lg">
                    <option value="">-- Alla år --</option>
                    <?php foreach ($eventYears as $year): ?>
                    <option value="<?= $year ?>" <?= $year == date('Y') ? 'selected' : '' ?>><?= $year ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Step 2: Select Event -->
            <div class="form-group mb-lg">
                <label for="event_id" class="label label-lg">
                    <span class="badge badge-primary mr-sm">2</span>
                    Välj event
                </label>
                <select id="event_id" name="event_id" class="input input-lg" required>
                    <option value="">-- Välj ett event --</option>
                    <?php foreach ($existingEvents as $event): ?>
                    <option value="<?= $event['id'] ?>" data-year="<?= $event['event_year'] ?>">
                        <?php if ($event['series_names']): ?>[<?= h($event['series_names']) ?>] <?php endif; ?>
                        <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                        <?php if ($event['location']): ?>- <?= h($event['location']) ?><?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Step 3: Select File -->
            <div class="form-group mb-lg">
                <label for="import_file" class="label label-lg">
                    <span class="badge badge-primary mr-sm">3</span>
                    Välj CSV-fil
                </label>
                <input type="file"
                    id="import_file"
                    name="import_file"
                    class="input input-lg"
                    accept=".csv"
                    required>
                <p class="text-sm text-secondary mt-sm">
                    Max filstorlek: <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?>MB
                </p>
            </div>

            <!-- Step 4: Preview Button -->
            <div class="form-group">
                <button type="submit" class="btn btn--primary btn-lg w-full">
                    <i data-lucide="eye"></i>
                    <span class="badge badge-light mr-sm">4</span>
                    Förhandsgranska import
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Format Info Card -->
<div class="card mt-lg">
    <div class="card-header">
        <h3 class="text-primary">
            <i data-lucide="file-text"></i>
            CSV Format - <?= $activeTab === 'enduro' ? 'Enduro' : ($activeTab === 'dh' ? 'Downhill' : 'XC') ?>
        </h3>
    </div>
    <div class="card-body">
        <p class="text-sm mb-md"><strong>Obligatoriska kolumner:</strong></p>
        <code class="gs-code-block mb-md">
Category, PlaceByCategory, FirstName, LastName, Club, NetTime, Status
        </code>

        <?php if ($activeTab === 'enduro'): ?>
        <p class="text-sm mb-md"><strong>Enduro-specifika kolumner:</strong></p>
        <code class="gs-code-block mb-md">
SS1, SS2, SS3, SS4, SS5... (upp till SS15)
        </code>
        <p class="text-xs text-secondary">
            Stödjer även: Prostage, Powerstage, Stage1, Sträcka1
        </p>

        <?php elseif ($activeTab === 'dh'): ?>
        <p class="text-sm mb-md"><strong>DH-specifika kolumner:</strong></p>
        <code class="gs-code-block mb-md">
Run1, Run2
        </code>
        <p class="text-xs text-secondary">
            Bästa tid av två åk används. Stödjer även: Åk1, Åk2, Kval, Final
        </p>

        <?php else: ?>
        <p class="text-sm mb-md"><strong>XC-specifika kolumner:</strong></p>
        <code class="gs-code-block mb-md">
Lap1, Lap2, Lap3... (varvtider)
        </code>
        <p class="text-xs text-secondary">
            Stödjer även: Varv1, Varv2, Split1, Split2
        </p>
        <?php endif; ?>

        <details class="gs-details mt-lg">
            <summary class="text-sm text-primary">Visa exempel CSV</summary>
            <pre class="gs-code-dark mt-md"><?php
if ($activeTab === 'enduro') {
    echo 'Category,PlaceByCategory,FirstName,LastName,Club,UCI-ID,NetTime,Status,SS1,SS2,SS3
Damer Junior,1,Ella,MÅRTENSSON,Borås CA,10022510347,16:19.16,FIN,2:10.55,1:47.08,1:51.10
Herrar Elite,1,Johan,ANDERSSON,Stockholm CK,10011223344,14:16.42,FIN,1:58.22,1:38.55,1:42.33';
} elseif ($activeTab === 'dh') {
    echo 'Category,PlaceByCategory,FirstName,LastName,Club,UCI-ID,Run1,Run2,NetTime,Status
Herrar Elite,1,Erik,SVENSSON,Stockholm MTB,10012345678,2:15.44,2:12.33,2:12.33,FIN
Damer Elite,1,Anna,JOHANSSON,Göteborg CK,10087654321,2:45.22,2:42.11,2:42.11,FIN';
} else {
    echo 'Category,PlaceByCategory,FirstName,LastName,Club,UCI-ID,NetTime,Status,Lap1,Lap2,Lap3
Herrar Elite,1,Johan,ANDERSSON,Stockholm CK,10011223344,36:16.42,FIN,11:58.22,11:38.55,12:39.65
Damer Elite,1,Maria,SVENSSON,Göteborg CK,10087654321,42:05.67,FIN,13:45.22,14:08.33,14:12.12';
}
?></pre>
        </details>
    </div>
</div>
<?php endif; ?>

<!-- Tools Section -->
<div class="card mt-lg">
    <div class="card-header">
        <h3 class="text-primary">
            <i data-lucide="wrench"></i>
            Verktyg
        </h3>
    </div>
    <div class="card-body">
        <a href="/admin/fix-time-format.php" class="btn btn--secondary">
            <i data-lucide="clock"></i>
            Fixa tidsformat
        </a>
        <span class="text-secondary text-sm ml-sm">Korrigerar tider med fel format</span>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const yearFilter = document.getElementById('year_filter');
    const eventSelect = document.getElementById('event_id');

    if (yearFilter && eventSelect) {
        const allOptions = Array.from(eventSelect.querySelectorAll('option[data-year]'));

        function filterEvents() {
            const selectedYear = yearFilter.value;
            eventSelect.value = '';

            allOptions.forEach(option => {
                if (!selectedYear || option.dataset.year === selectedYear) {
                    option.style.display = '';
                    option.disabled = false;
                } else {
                    option.style.display = 'none';
                    option.disabled = true;
                }
            });
        }

        yearFilter.addEventListener('change', filterEvents);
        filterEvents();
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
