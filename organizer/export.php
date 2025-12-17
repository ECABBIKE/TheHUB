<?php
/**
 * Organizer App - Export
 * Exportera deltagarlista för tidtagningsprogram
 */

require_once __DIR__ . '/config.php';
requireOrganizer();

// Hämta event
$eventId = (int)($_GET['event'] ?? 0);
if (!$eventId) {
    header('Location: dashboard.php');
    exit;
}

requireEventAccess($eventId);

$event = getEventWithClasses($eventId);
if (!$event) {
    die('Eventet hittades inte.');
}

// Hantera export
if (isset($_GET['download'])) {
    $pdo = hub_db();

    $source = $_GET['source'] ?? 'all';

    $sql = "
        SELECT
            er.bib_number,
            er.first_name,
            er.last_name,
            er.category as class,
            er.club_name,
            er.license_number,
            er.gender,
            er.birth_year,
            er.email,
            er.phone,
            er.payment_status,
            er.registration_source
        FROM event_registrations er
        WHERE er.event_id = ? AND er.status != 'cancelled'
    ";
    $params = [$eventId];

    if ($source === 'onsite') {
        $sql .= " AND er.registration_source = 'onsite'";
    } elseif ($source === 'online') {
        $sql .= " AND (er.registration_source = 'online' OR er.registration_source IS NULL)";
    }

    $sql .= " ORDER BY er.category, er.bib_number";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Skapa filnamn
    $filename = sanitize_filename($event['name']) . '_' . date('Y-m-d') . '.csv';

    // Skicka headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // BOM för Excel UTF-8
    echo "\xEF\xBB\xBF";

    // Skapa CSV
    $output = fopen('php://output', 'w');

    // Header
    fputcsv($output, [
        'Startnummer',
        'Förnamn',
        'Efternamn',
        'Klass',
        'Klubb',
        'Licens',
        'Kön',
        'Födelseår',
        'E-post',
        'Telefon',
        'Betald',
        'Källa'
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

// Hjälpfunktion för filnamn
function sanitize_filename($name) {
    $name = preg_replace('/[^a-zA-Z0-9åäöÅÄÖ\-_]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_');
}

// Räkna
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
            Filen innehåller startnummer, namn, klass, klubb och kontaktuppgifter.
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
    <div class="org-card__header">
        <h2 class="org-card__title">CSV-format</h2>
    </div>
    <div class="org-card__body">
        <p class="org-mb-md">Filen använder semikolon (;) som separator och UTF-8 encoding.</p>

        <div style="background: var(--color-star-fade); padding: 16px; border-radius: 8px; font-family: monospace; font-size: 14px; overflow-x: auto;">
            Startnummer;Förnamn;Efternamn;Klass;Klubb;Licens;Kön;Födelseår;E-post;Telefon;Betald;Källa
        </div>

        <p class="org-mt-lg org-text-muted" style="font-size: 16px;">
            <strong>Tips:</strong> Om du behöver ett annat format för ditt tidtagningsprogram,
            kontakta oss så kan vi lägga till fler exportalternativ.
        </p>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
