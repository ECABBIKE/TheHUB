<?php
/**
 * Organizer App - Export (DEMO)
 */

require_once __DIR__ . '/config.php';
requireOrganizer();

// Hämta event
$eventId = (int)($_GET['event'] ?? 0);
if (!$eventId) {
    header('Location: dashboard.php');
    exit;
}

$event = getEventWithClasses($eventId);
if (!$event) {
    die('Eventet hittades inte.');
}

// Demo-data för export
$demoRegistrations = [
    ['bib_number' => '101', 'first_name' => 'Erik', 'last_name' => 'Andersson', 'class' => 'Men Elite', 'club_name' => 'Cykelklubben', 'license_number' => 'SWE-12345', 'gender' => 'M', 'birth_year' => '1992', 'email' => 'erik@example.com', 'phone' => '070-123 45 67', 'payment_status' => 'paid', 'registration_source' => 'online'],
    ['bib_number' => '102', 'first_name' => 'Anna', 'last_name' => 'Svensson', 'class' => 'Women Elite', 'club_name' => 'MTB Klubben', 'license_number' => 'SWE-12346', 'gender' => 'F', 'birth_year' => '1995', 'email' => 'anna@example.com', 'phone' => '070-234 56 78', 'payment_status' => 'paid', 'registration_source' => 'online'],
    ['bib_number' => '201', 'first_name' => 'Johan', 'last_name' => 'Eriksson', 'class' => 'Men Sport', 'club_name' => '', 'license_number' => '', 'gender' => 'M', 'birth_year' => '1988', 'email' => '', 'phone' => '070-345 67 89', 'payment_status' => 'unpaid', 'registration_source' => 'onsite'],
];

// Hantera export
if (isset($_GET['download'])) {
    $source = $_GET['source'] ?? 'all';

    $registrations = $demoRegistrations;
    if ($source === 'onsite') {
        $registrations = array_filter($registrations, fn($r) => $r['registration_source'] === 'onsite');
    } elseif ($source === 'online') {
        $registrations = array_filter($registrations, fn($r) => $r['registration_source'] !== 'onsite');
    }

    // Skapa filnamn
    $filename = 'demo_export_' . date('Y-m-d') . '.csv';

    // Skicka headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // BOM för Excel UTF-8
    echo "\xEF\xBB\xBF";

    // Skapa CSV
    $output = fopen('php://output', 'w');

    // Header
    fputcsv($output, [
        'Startnummer', 'Förnamn', 'Efternamn', 'Klass', 'Klubb', 'Licens',
        'Kön', 'Födelseår', 'E-post', 'Telefon', 'Betald', 'Källa'
    ], ';');

    // Data
    foreach ($registrations as $reg) {
        fputcsv($output, [
            $reg['bib_number'],
            $reg['first_name'],
            $reg['last_name'],
            $reg['class'],
            $reg['club_name'],
            $reg['license_number'],
            $reg['gender'] === 'M' ? 'Man' : ($reg['gender'] === 'F' ? 'Kvinna' : ''),
            $reg['birth_year'],
            $reg['email'],
            $reg['phone'],
            $reg['payment_status'] === 'paid' ? 'Ja' : 'Nej',
            $reg['registration_source'] === 'onsite' ? 'Plats' : 'Online'
        ], ';');
    }

    fclose($output);
    exit;
}

$counts = countEventRegistrations($eventId);

$pageTitle = 'Exportera';
$showHeader = true;
$headerTitle = 'Exportera';
$headerSubtitle = $event['name'];
$showBackButton = true;
$backUrl = 'register.php?event=' . $eventId;
$showLogout = true;

include __DIR__ . '/includes/header.php';
?>

<div class="org-card">
    <div class="org-card__header">
        <h2 class="org-card__title">Exportera startlista</h2>
    </div>
    <div class="org-card__body">
        <p class="org-mb-lg">
            Exportera deltagarlistan som CSV-fil för import till tidtagningsprogram.
        </p>

        <div style="display: flex; flex-direction: column; gap: 16px; max-width: 500px;">

            <a href="?event=<?= $eventId ?>&download=1&source=all"
               class="org-btn org-btn--primary org-btn--large">
                <i data-lucide="download"></i>
                Alla deltagare (<?= (int)$counts['total'] ?>)
            </a>

            <a href="?event=<?= $eventId ?>&download=1&source=onsite"
               class="org-btn org-btn--secondary org-btn--large">
                <i data-lucide="download"></i>
                Endast platsen (<?= (int)$counts['onsite'] ?>)
            </a>

            <a href="?event=<?= $eventId ?>&download=1&source=online"
               class="org-btn org-btn--ghost org-btn--large">
                <i data-lucide="download"></i>
                Endast förhandsanmälda (<?= (int)$counts['online'] ?>)
            </a>

        </div>
    </div>
</div>

<div class="org-card org-mt-lg">
    <div class="org-card__body org-text-center" style="padding: 24px;">
        <p class="org-text-muted" style="font-size: 14px;">
            <i data-lucide="info" style="width: 16px; height: 16px; vertical-align: middle;"></i>
            Demo-version - exporterar exempeldata
        </p>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
