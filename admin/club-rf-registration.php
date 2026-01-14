<?php
/**
 * Club RF Registration Tool
 *
 * Matches clubs from the Swedish Cycling Federation (SCF) registry
 * and marks them as RF-registered active clubs.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'RF-registrering av Klubbar';
include __DIR__ . '/../includes/admin-header.php';

// SCF Districts with their registered clubs
$scf_districts = [
    'Bohuslän-Dals Cykelförbund' => [
        '444 Cycling Club',
        'Bokenäs Idrottsförening',
        'Camp Dalsland',
        'Cykelklubben Sundet',
        'Framtidens Idrotts Klubb',
        'Friluftsfrämjandet Bengtsfors',
        'Munkedals Skid & Cykelklubb',
        'OK Kroppefjäll',
        'Skidklubben Granan',
        'Strömstad Cykelklubb',
        'Svanesunds Gymnastik o Idrottsförening',
        'Team Swedemount Sports Club',
        'Uddevalla Cykelklubb',
        'Åmåls Cykelamatörer'
    ],
    'Dalarnas Cykelförbund' => [
        'Biketown Cycling Club',
        'Bjursås Idrottsklubb',
        'Borlänge Cykelklubb',
        'Brudpiga Roddklubb',
        'CK Uven',
        'CK Vansbro Mtb',
        'CSK Ludvika',
        'Cykelklubben Natén Säter',
        'Cykelklubben Soldi',
        'Dala-Järna Idrottsklubb',
        'Falu Cykelklubb',
        'Idrottsföreningen Vulcanus',
        'Idrottsklubben Jarl Rättvik',
        'Lima Idrottsförening',
        'Malungs Cykelklubb',
        'ML Sports Club',
        'Mora Cykel Klubb',
        'Perbellum Sportsällskap',
        'Rembo Idrottsklubb',
        'Sollerö Idrottsförening',
        'Sågmyra Skidklubb',
        'Sälens Idrottsförening',
        'Särna Cykelklubb',
        'Team KBK Bikes Cykelklubb',
        'Velodrom Cykelklubben',
        'Älvdalens CK'
    ],
    'Gästriklands Cykelförbund' => [
        'Gogagaski Cycle Club',
        'Gästrike Cup MTB Förening',
        'Gävle Cykelamatörer',
        'Hofors AIF Skid och Cykelklubb',
        'Järbo Idrottsförening',
        'Orienteringsklubben Hammaren',
        'Sandvikens Cykelklubb',
        'Storviks Idrottsförening',
        'Årsunda Idrottsförening'
    ],
    'Göteborgs Cykelförbund' => [
        'Aktivitus Sports Club',
        'Ale 90 Idrottsklubb',
        'Angered Cykelförening',
        'Cykelklubben Lygnens Venner',
        'Cykelklubben Master',
        'Cykelklubben Rävlanda',
        'Giro Cycle Club',
        'Göteborgs Atlet- & Triathlonsällskap',
        'Göteborgs Cykelklubb',
        'Göteborgs Polismäns IF',
        'Göteborgs Stigcyklister',
        'Hisingens Cykelklubb',
        'Kungälvs Cykelklubb',
        'Lerums Cykelklubb',
        'Mölndals Cykelklubb',
        'Partille Cykelklubb',
        'Partille Trialklubb',
        'Rendezvous Cycling Club',
        'Sävedalens Cykelklubb',
        'Team Hestra-Advokat Cycling Club',
        'Team Kungälv Idrottssällskap',
        'Torslanda Cykelklubb',
        'Umara Sports Club'
    ],
    'Hallands Cykelförbund' => [
        'Bukten Mountainbike Klubb',
        'Cogheart Cycle Club Varberg',
        'Cykelklubben Bure',
        'Cykelklubben Wano',
        'Falkenbergs Cykelklubb',
        'Halmstad Mountainbikeklubb',
        'Halmstads Cykelklubb',
        'Hawkhills Trialklubb',
        'Idrottsföreningen Rigor',
        'Kungsbacka BMX-Klubb',
        'Kungsbacka Cykelklubb',
        'Kungsbackacyklisten Idrottsförening',
        'Laholmscyklisten Cykelklubb',
        'Nordic Sports Academy Sports Club',
        'Rooster Club Varberg Idrottsförening'
    ],
    'Hälsinglands Cykelförbund' => [
        'Bollnäs Cykelklubb',
        'Edsbyns Skidklubb',
        'Hudik Triathlon Klubb',
        'Hälsinglands Sportklubb',
        'IFK Söderhamn',
        'Järvsö Bergscykelklubb'
    ],
    'Jämtland-Härjedalens Cykelförbund' => [
        'Brunflo Idrottsförening',
        'Edelweiss Cykelklubb',
        'Frösö Idrottsförening',
        'Funäsdalens Idrottsförening',
        'Genvalla Idrottsförening',
        'Lofsdalens Sportklubb',
        'Sockertoppen Idrottsförening',
        'Svegs Idrottsklubb',
        'Vemdalens Idrottsförening',
        'Åre Bergscyklister',
        'Åsarna Idrottsklubb',
        'Östersunds Cykelklubb'
    ],
    'Norrbottens Cykelförbund' => [
        'Befastonabike cykelklubb',
        'Bodens Cykelklubb',
        'Dundret MTB',
        'Föreningen Luleå Terrängcyklister',
        'Gammelgårdens Idrottsförening',
        'Gällivare Endurance Club',
        'IFK Arvidsjaur Skidor',
        'Kiruna Fjällcyklister',
        'Luleå Cykelklubb',
        'Nybyns Cykelklubb',
        'Orienteringsklubben Renen',
        'Pite MTB-förening',
        'Råneå Skidklubb'
    ],
    'Skånes Cykelförbund' => [
        'Beijers Coaching Cykel Klubb',
        'CK Öresund',
        'Cykelklubb Pedali Skurup',
        'Cykelklubben Barriär',
        'Cykelklubben C4',
        'Cykelklubben Fix',
        'Cykelklubben Lunedi',
        'Cykelklubben Ringen',
        'Cykelklubben Wheels of Carlshamn',
        'Engelholms Bmx-klubb',
        'Eslövs Cykelklubb',
        'Friluftsklubben Trampen',
        'Frosta Multisport',
        'Heleneholms Idrottsförening',
        'Hässleholms Cykelklubb',
        'Höllviken Cykelklubb',
        'Hörby CykelKlubb',
        'Idrottsklubben Vinco',
        'Karlskrona Cykelklubb',
        'Klubben Cyklisten',
        'Knutstorp Cykel- och Motorklubb',
        'Landskrona Cykelklubb',
        'Malmö BMX Racing',
        'Malmö Cykelcross Klubb',
        'Malmö Cykelklubb',
        'MIND Triathlon Sports Club',
        'Olofströms Fritidsklubb',
        'Peak Vélo CK',
        'Roslins Cykelklubb',
        'Staffanstorp Cykelklubb',
        'Sölvesborgs Cykel & Sportklubb',
        'Team Albert cykelklubb',
        'X-CUP Cykelklubb',
        'Ystad MTB Cykelklubb',
        'Åhus Cykelklubb',
        'Åstorps Cykelklubb',
        'Örestadscyklisterna Idrottsförening',
        'Örkelljunga Outdoors Klubb'
    ],
    'Smålands Cykelförbund' => [
        '338 Småland Triathlon & MF',
        'Abloc Idrottsförening',
        'Actionsport Unite IF',
        'Anderstorps Orienteringsklubb',
        'Bankeryds Skid o Motionsklubb',
        'Bauer Endurance Club',
        'Borgholm Cykelklubb',
        'Bottnaryds Idrottsförening',
        'Braås Gymnastik O IF',
        'Brittatorps Träningsklubb',
        'Burseryds Idrottsförening',
        'Cykelklubben Grand La Coupe',
        'Cykelklubben Wimer',
        'Eksjö Cykelklubb',
        'Elmhults Sports Club',
        'Emmaboda Verda OK',
        'Friluftsfrämjandets Lokalavd i Värnamo',
        'Föreningen Wexiö Velo',
        'Glasrikets Runningclub Nybro',
        'Gnosjö Friluftsklubb',
        'Gotland Grand National Sports Club',
        'Gränna-Bygdens OK',
        'Highland Cycling Club',
        'Hjortens Sportklubb',
        'Hultsfreds Löparklubb',
        'Idrottsklubben Habocyklisterna',
        'IF Hallby Skid o Orienteringsklubb',
        'IFK Sävsjö',
        'IKHP Huskvarna Idrottsklubb',
        'JHWC Cykelklubb',
        'Jönköpings Cykelklubb',
        'KA 3 Idrottsförening',
        'Kalmar Cykelklubb',
        'Kalmar Running Club Triathlon',
        'Lammhult Cyklisterna',
        'Malmbäcks Idrottsförening',
        'Markaryds Friluftsklubb',
        'Mix Sports Club',
        'Möre Bollklubb',
        'Nässjö Cykelklubb',
        'Orienteringsklubben Vivill',
        'Orrefors Idrottsförening',
        'Oskarshamns Cykelklubb',
        'Ringmurens Cykelklubb',
        'Sandhems Idrottsförening',
        'Skid- och orienteringslöparna Tranås',
        'Skillingaryds Frisksportklubb',
        'Sportson Cykelklubb Jönköping',
        'Team Kalmarsund Cykelklubb',
        'Tenhults Skid o Orienteringsklubb',
        'Tjust Bike o Running Club',
        'Tranås Cykelklubb',
        'Vaggeryds Skid o Orienteringsklubb',
        'Värnamo Cykelklubb',
        'Växjö Stigcyklister',
        'Ålems Cykelklubb'
    ],
    'Stockholms Cykelförbund' => [
        '58&fam Racing Club',
        'Athletic Club Salt Lake',
        'Atlas Copco Idrottsförening',
        'AXA Sports Club',
        'Baroudeur Fietsclub',
        'Bike Adventure Cycling Club',
        'Brottby Sportklubb',
        'Capital Bikepark Locals CK',
        'Cykelklubb La Chemise.se',
        'Cykelklubben Falken',
        'Cykelklubben Nollåtta',
        'Cykelklubben Valhall',
        'Djurgårdens IF Cykelförening',
        'Echelon Cycling and Triathlon Club',
        'Ekerö MTB',
        'Evolite Cycling Club',
        'Fredrikshofs IF Cykelklubb',
        'Förening Rocket Racing Sweden',
        'Hammarby IF Cykelförening',
        'Ingarö Idrottsförening',
        'Järfälla Cykel Klubb',
        'Kia Merida racing team Cykelklubb',
        'Lidingö Cykelklubb',
        'Lucky Sport Cycling Club',
        'Länna Sport Cykelklubb',
        'Mountainbikeskolan CK',
        'MTB Täby Mountainbikeklubb',
        'Muskö Idrottsförening',
        'Pain Free Power Athletic Club',
        'Reaktion Cycling Club',
        'Ryska Posten SK',
        'Saltsjöbadens Cykelklubb',
        'She Rides Cykelklubb',
        'She Rise Athletic Club',
        'Sigge Cykel Klubb',
        'Specialized Concept Store Cykelklubb',
        'Spårvägen Cykelförening',
        'Sthlm Bike Cykelklubb',
        'Stockholm City Triathlon Club',
        'Stockholm Cykelklubb',
        'Stockholm Multisport Klubb',
        'Sumo Cycling Club',
        'Sveaskogs Idrottsförening',
        'Södertälje Cykelklubb',
        'Södertälje-Nykvarn Orienteringsförening',
        'Södertörns Cykelklubb',
        'Team Jarla Cykelklubb',
        'Team Snabbare Idrottsförening',
        'Team Utan Gränser Cykelklubb',
        'Terrible Tuesdays Athletic Club',
        'Trailstore Collective BC',
        'Tullinge Sportklubb',
        'Tyresö Cykelklubb',
        'Vallentuna Cykelklubb',
        'Waxholm Cykelklubb',
        'Velocipedföreningen Punschrullen',
        'Värmdö Cykelklubb',
        'Älvsjö Bmx-klubb',
        'Ängby cycling club'
    ],
    'Södermanlands Cykelförbund' => [
        'Cykelklubben Ceres',
        'Cykelklubben Dainon',
        'Eskilstuna Cykelklubb',
        'Eskilstuna Idrottsklubb',
        'Oxvretens Skidklubb',
        'Strängnäs Cykelklubb',
        'Team Average Athletic Club',
        'Team Sméstan Cykelklubb',
        'Trosabygdens Orienteringsklubb',
        'Vingåkers CK',
        'Ärla Idrottsförening'
    ],
    'Upplands Cykelförbund' => [
        'BAUHAUS Sportklubb',
        'Björklinge Sportförening',
        'Bålsta Cykelklubb',
        'Cykelklubben Norrtälje',
        'Cykelklubben Stabil',
        'Cykelklubben Uni',
        'Enköpings Cykelklubb',
        'Heby Allmänna Idrottsförening',
        'Häverödals Sportklubb',
        'Idrottsföreningen Mantra Sport',
        'IK Rex',
        'Knivsta Cykelklubb',
        'Marma Sportklubb',
        'Motorklubben Orion',
        'Månkarbo Idrottsförening',
        'Märsta BMX Club-83',
        'Rimbo Skid o Orienteringsklubb',
        'Shimano Nordic Racing Cykelklubb',
        'Sigtuna Märsta Arlanda Cykelklubb',
        'Sigtuna Sports Club',
        'SMK Uppsala',
        'Storvreta Idrottsklubb',
        'Tierps Cykelklubb',
        'Upplands-Ekeby Idrottsförening',
        'Upsala Cykelklubb',
        'Womens Bikejoy Cykelklubb',
        'Öregrunds Idrottsklubb',
        'Östervåla Idrottsförening',
        'Östra Aros Cykelklubb'
    ],
    'Värmlands Cykelförbund' => [
        'Arvika Cykelklubb',
        'Arvika Idrottssällskap',
        'Carba Sports Club',
        'Cykelklubben Filip',
        'Ekshärads Cykelklubb',
        'Grava Skidklubb',
        'I 2 Idrottsförening',
        'Jössefors Idrottsklubb',
        'Karlskogacyklisterna',
        'KONG Arrangörs- och Idrottsförening',
        'Kristinehamns Cykelklubb',
        'Mattila Idrottsklubb',
        'Orienteringsklubben Tyr',
        'Skidklubben Bore Torsby',
        'Skoghalls Cykelklubb-Hammarö',
        'Solsta Cykelklubb',
        'South Wermland Sports Club',
        'Sunne DH & Enduro Klubb',
        'Säffle Idrottsklubb',
        'Team Skoglöfs Bil Idrottsförening',
        'Värmullsåsens Mountainbike & Enduro Klubb',
        'Västra Ämterviks Idrottsförening'
    ],
    'Västerbottens Cykelförbund' => [
        'Bergsbyns Sportklubb',
        'Burträsk aktivitetsförening',
        'Bygdeå Gymnastik Idrottsförening',
        'Föreningen Bygdsiljumsbacken',
        'Föreningen Cykelintresset',
        'Gimonäs Cykelklubb',
        'Lycksele Idrottsförening',
        'Lögdeå Sportklubb',
        'Norrlandscyklisterna Idrottsklubb',
        'Obbola Idrottsklubb',
        'Skellefteå AIK Cykelklubb',
        'Stensele Sportklubb',
        'Tavelsjö Allmänna Idrottsklubb',
        'Team UV Idrottsklubb',
        'Tough Training Group Umeå Idrottsförening',
        'Wattfabriken CK',
        'Vännäs Cykelklubb'
    ],
    'Västergötlands Cykelförbund' => [
        'Alingsås Sportsclub',
        'Alliansloppet Skidklubb',
        'Bollekollen sportklubb',
        'Borås Cykelamatörer',
        'Borås Gymnastik o Idrottsförening',
        'Cycling Club 22',
        'Cykelklubben Olympic',
        'Cykelklubben U6',
        'Cykelklubben Wänershof',
        'Falköpings Cykelklubb',
        'Fritsla Vinter Idrottsklubb',
        'Föreningen 7h ParaAlpint',
        'Gånghester Cykelklubb',
        'Herrljunga Cykelklubb',
        'Hjo Velocipedklubb',
        'Hunneberg Sport och Motionsklubb',
        'Hyssna Idrottsförening',
        'IK Trasten',
        'Kesberget Bikepark CK',
        'Kinds Go Green Cykelklubb',
        'Kvänums Idrottsförening',
        'Lidköpings Cykelklubb',
        'Lindgren Racing Cyclingclub',
        'Långareds Boll o Idrottssällskap',
        'Mariestadcyklisten',
        'Serneke Allebike Cykelklubb',
        'Skara Cykelklubb',
        'Skövde Cykelklubb',
        'Tibro Cykelklubb',
        'Tidaholms Cykelamatörer',
        'Tranemo Cykelsällskap',
        'Tre Berg Cykelklubb',
        'Trollhättans Skid o Orienteringsklubb',
        'Träningskonsulten Sports Club',
        'Töreboda Cykelklubb',
        'Ulricehamns Cykelklubb',
        'West Heath Sportsclub',
        'Vettlösa Cykelklubb',
        'Vincents Idrottsförening',
        'Vårgårda Cykelklubb',
        'Västgötaloppsföreningen'
    ],
    'Västernorrlands Cykelförbund' => [
        'Alnö Race Team Föreningen',
        'Cykelklubben Örnen',
        'Drakstadens Sub Sport Klubb',
        'Föreningen Höga Kusten Cyklisterna',
        'Härnösands Cykelklubb',
        'Northern XC Sportsclub',
        'Resele Idrottsförening',
        'Sundsvallscyklisterna'
    ],
    'Västmanlands Cykelförbund' => [
        'Fagersta Södra Idrottsklubb',
        'Föreningen Hallsta Mountainbike',
        'Gäddeholm Stigcyklister',
        'IFK Sala',
        'Kungsörs Sportklubb',
        'Köpings Cykelklubb',
        'Medåkers Idrottsförening',
        'Norbergs Cykelklubb',
        'Ramnäs Cykelklubb',
        'Sala Cykelklubb',
        'Skultuna Allmänna Idrotts Klubb',
        'Västerås Cykelklubb'
    ],
    'Örebro Läns Cykelförbund' => [
        'Almby Idrottsklubb',
        'Cykelklubben Armkraft',
        'Cykelklubben Kultur',
        'Föreningen BBR Sports',
        'Grythyttans Cykel Klubb',
        'Hjulsjö Byförening Löparklubb',
        'Kilsmo Idrottsklubb',
        'Kopparbergs Cykelklubb',
        'Kumla Skidförening',
        'Laxå Cykelklubb',
        'Lekebergs Idrotts Förening',
        'Lindecyklisterna',
        'Ramsbergs Idrottsförening',
        'Sörbybackens Kamratförening',
        'Åsbro Gymnastik O IF'
    ],
    'Östergötlands Cykelförbund' => [
        'Bistro Cycling Club',
        'Borensbergs IF Sportklubb',
        'Boxholm-Ekeby Skidklubb',
        'Cykelföreningen Tajts MTB',
        'Cykelklubben Antilopen',
        'Cykelklubben Hymer',
        'Cykelklubben SubXX',
        'Finspångs Cykelamatörer',
        'Föreningen Vadstena Cykel',
        'Godegårds Skidklubb',
        'Idrottsklubben Nocout.se',
        'Kisa Sportklubb',
        'Klubb Team LeWa Sport',
        'Kolmårdens Mountainbike Klubb',
        'Mera Lera Mountainbikeklubb',
        'Mjölby Cykelklubb',
        'Motala AIF Cykelklubb',
        'Norrköpings Skidklubb',
        'Sya Skidklubb',
        'Söderköpings Skidklubb',
        'Vreta Skid o Motionsklubb',
        'Åtvidabergs Idrottsförening'
    ]
];

// Check if columns exist, if not run migration
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM clubs LIKE 'rf_registered'");
    if ($stmt->rowCount() === 0) {
        // Run migration
        $pdo->exec("ALTER TABLE clubs ADD COLUMN rf_registered TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE clubs ADD COLUMN rf_registered_year INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE clubs ADD COLUMN scf_district VARCHAR(100) DEFAULT NULL");
    }
} catch (PDOException $e) {
    // Columns might already exist
}

// Handle form submission
$message = '';
$messageType = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'sync_rf') {
        $matched = 0;
        $notFound = [];
        $updated = 0;

        // First, reset all RF registrations
        $pdo->exec("UPDATE clubs SET rf_registered = 0, rf_registered_year = NULL, scf_district = NULL");

        foreach ($scf_districts as $district => $clubs) {
            foreach ($clubs as $clubName) {
                // Try exact match first
                $stmt = $pdo->prepare("SELECT id, name FROM clubs WHERE name = ?");
                $stmt->execute([$clubName]);
                $club = $stmt->fetch();

                if (!$club) {
                    // Try fuzzy match - remove common suffixes and try again
                    $normalized = preg_replace('/\s+(cykelklubb|cykelklubb|ck|if|ik|sk|ok|idrottsförening|idrottsförening|sportklubb|cykelförening|idrottsklubb)\s*$/i', '', $clubName);
                    $stmt = $pdo->prepare("SELECT id, name FROM clubs WHERE name LIKE ? OR name LIKE ?");
                    $stmt->execute(["%$normalized%", "%$clubName%"]);
                    $club = $stmt->fetch();
                }

                if ($club) {
                    $stmt = $pdo->prepare("UPDATE clubs SET rf_registered = 1, rf_registered_year = 2025, scf_district = ? WHERE id = ?");
                    $stmt->execute([$district, $club['id']]);
                    $matched++;
                    $results[] = [
                        'status' => 'matched',
                        'rf_name' => $clubName,
                        'hub_name' => $club['name'],
                        'district' => $district
                    ];
                } else {
                    $notFound[] = ['name' => $clubName, 'district' => $district];
                    $results[] = [
                        'status' => 'not_found',
                        'rf_name' => $clubName,
                        'hub_name' => null,
                        'district' => $district
                    ];
                }
            }
        }

        $message = "RF-synkronisering klar: $matched klubbar matchade, " . count($notFound) . " ej hittade.";
        $messageType = 'success';
    }

    if ($_POST['action'] === 'manual_match' && isset($_POST['club_id']) && isset($_POST['rf_name']) && isset($_POST['district'])) {
        $stmt = $pdo->prepare("UPDATE clubs SET rf_registered = 1, rf_registered_year = 2025, scf_district = ? WHERE id = ?");
        $stmt->execute([$_POST['district'], $_POST['club_id']]);
        $message = "Manuell koppling sparad.";
        $messageType = 'success';
    }
}

// Get statistics
$stats = $pdo->query("
    SELECT
        COUNT(*) as total_clubs,
        SUM(rf_registered = 1) as rf_registered,
        SUM(rf_registered = 0 OR rf_registered IS NULL) as not_registered
    FROM clubs
")->fetch();

// Get clubs by district
$districtStats = $pdo->query("
    SELECT
        scf_district,
        COUNT(*) as club_count,
        SUM((SELECT COUNT(*) FROM riders r WHERE r.club_id = c.id)) as rider_count
    FROM clubs c
    WHERE scf_district IS NOT NULL
    GROUP BY scf_district
    ORDER BY club_count DESC
")->fetchAll();

// Get unmatched clubs
$unmatchedClubs = $pdo->query("
    SELECT id, name, city
    FROM clubs
    WHERE rf_registered = 0 OR rf_registered IS NULL
    ORDER BY name
")->fetchAll();

// Count total RF clubs in list
$totalRfClubs = 0;
foreach ($scf_districts as $clubs) {
    $totalRfClubs += count($clubs);
}
?>

<div class="admin-content">
    <div class="page-header">
        <h1><?= $pageTitle ?></h1>
        <p class="text-muted">Synkronisera klubbar med Svenska Cykelförbundets distriktsregister</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Statistik</h3>
        </div>
        <div class="card-body">
            <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-md);">
                <div class="stat-card" style="background: var(--color-bg-hover); padding: var(--space-md); border-radius: var(--radius-md);">
                    <div style="font-size: 2rem; font-weight: bold; color: var(--color-accent);"><?= $totalRfClubs ?></div>
                    <div class="text-muted">Klubbar i RF-registret</div>
                </div>
                <div class="stat-card" style="background: var(--color-bg-hover); padding: var(--space-md); border-radius: var(--radius-md);">
                    <div style="font-size: 2rem; font-weight: bold; color: var(--color-success);"><?= $stats['rf_registered'] ?? 0 ?></div>
                    <div class="text-muted">Matchade i TheHUB</div>
                </div>
                <div class="stat-card" style="background: var(--color-bg-hover); padding: var(--space-md); border-radius: var(--radius-md);">
                    <div style="font-size: 2rem; font-weight: bold; color: var(--color-warning);"><?= $stats['not_registered'] ?? 0 ?></div>
                    <div class="text-muted">Ej RF-registrerade</div>
                </div>
                <div class="stat-card" style="background: var(--color-bg-hover); padding: var(--space-md); border-radius: var(--radius-md);">
                    <div style="font-size: 2rem; font-weight: bold; color: var(--color-text-primary);"><?= count($scf_districts) ?></div>
                    <div class="text-muted">SCF-distrikt</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sync Button -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Synkronisera med RF</h3>
        </div>
        <div class="card-body">
            <p>Klicka på knappen nedan för att matcha klubbar i TheHUB med den officiella RF-registerlistan för 2025.</p>
            <form method="POST">
                <input type="hidden" name="action" value="sync_rf">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="refresh-cw"></i>
                    Synkronisera RF-registrering
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($results)): ?>
    <!-- Sync Results -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Synkroniseringsresultat</h3>
        </div>
        <div class="card-body">
            <div class="tabs">
                <nav class="tabs-nav">
                    <button class="tab-btn active" data-tab="matched">Matchade (<?= count(array_filter($results, fn($r) => $r['status'] === 'matched')) ?>)</button>
                    <button class="tab-btn" data-tab="not-found">Ej hittade (<?= count(array_filter($results, fn($r) => $r['status'] === 'not_found')) ?>)</button>
                </nav>

                <div class="tab-content active" id="matched">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>RF-namn</th>
                                    <th>TheHUB-namn</th>
                                    <th>Distrikt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $r): ?>
                                    <?php if ($r['status'] === 'matched'): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['rf_name']) ?></td>
                                        <td><span class="badge badge-success"><?= htmlspecialchars($r['hub_name']) ?></span></td>
                                        <td><?= htmlspecialchars($r['district']) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-content" id="not-found">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>RF-namn</th>
                                    <th>Distrikt</th>
                                    <th>Manuell koppling</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $r): ?>
                                    <?php if ($r['status'] === 'not_found'): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['rf_name']) ?></td>
                                        <td><?= htmlspecialchars($r['district']) ?></td>
                                        <td>
                                            <form method="POST" style="display: flex; gap: var(--space-xs);">
                                                <input type="hidden" name="action" value="manual_match">
                                                <input type="hidden" name="rf_name" value="<?= htmlspecialchars($r['rf_name']) ?>">
                                                <input type="hidden" name="district" value="<?= htmlspecialchars($r['district']) ?>">
                                                <select name="club_id" class="form-select" style="max-width: 200px;">
                                                    <option value="">-- Välj klubb --</option>
                                                    <?php foreach ($unmatchedClubs as $club): ?>
                                                        <option value="<?= $club['id'] ?>"><?= htmlspecialchars($club['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-secondary btn-sm">Koppla</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- District Statistics -->
    <?php if (!empty($districtStats)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h3>Klubbar per distrikt</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Distrikt</th>
                            <th>Klubbar</th>
                            <th>Aktiva åkare</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($districtStats as $ds): ?>
                        <tr>
                            <td><?= htmlspecialchars($ds['scf_district']) ?></td>
                            <td><?= $ds['club_count'] ?></td>
                            <td><?= $ds['rider_count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    });
});
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
