<?php
/**
 * Club District Mapper
 *
 * Automatiskt verktyg for att koppla klubbar till cykeldistrikt
 * baserat pa klubbens stad/ort.
 *
 * Svenska Cykeldistrikt:
 * - Bohuslän-Dals CF, Dalarnas CF, Gästriklands CF, Göteborgs CF
 * - Hallands CF, Hälsinglands CF, Jämtland-Härjedalens CF, Norrbottens CF
 * - Skånes CF, Smålands CF, Stockholms CF, Södermanlands CF
 * - Upplands CF, Värmlands CF, Västerbottens CF, Västergötlands CF
 * - Västernorrlands CF, Västmanlands CF, Örebro Läns CF, Östergötlands CF
 *
 * @package TheHUB Admin
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

global $pdo;

// Svenska cykeldistrikt
$districts = [
    'stockholms' => 'Stockholms CF',
    'upplands' => 'Upplands CF',
    'sodermanlands' => 'Södermanlands CF',
    'ostergotlands' => 'Östergötlands CF',
    'smalands' => 'Smålands CF',
    'skanes' => 'Skånes CF',
    'hallands' => 'Hallands CF',
    'goteborgs' => 'Göteborgs CF',
    'bohuslan_dals' => 'Bohuslän-Dals CF',
    'vastergotlands' => 'Västergötlands CF',
    'varmlands' => 'Värmlands CF',
    'orebro' => 'Örebro Läns CF',
    'vastmanlands' => 'Västmanlands CF',
    'dalarnas' => 'Dalarnas CF',
    'gastriklands' => 'Gästriklands CF',
    'halsinglands' => 'Hälsinglands CF',
    'vasternorrlands' => 'Västernorrlands CF',
    'jamtland' => 'Jämtland-Härjedalens CF',
    'vasterbottens' => 'Västerbottens CF',
    'norrbottens' => 'Norrbottens CF'
];

// Mappning fran stad/ort till distrikt
// Inkluderar de flesta storre orter och kommuner
$cityToDistrict = [
    // Stockholms CF (Stockholms län)
    'stockholm' => 'stockholms',
    'södertälje' => 'stockholms',
    'sodertalje' => 'stockholms',
    'nacka' => 'stockholms',
    'solna' => 'stockholms',
    'sundbyberg' => 'stockholms',
    'huddinge' => 'stockholms',
    'botkyrka' => 'stockholms',
    'tumba' => 'stockholms',
    'haninge' => 'stockholms',
    'tyresö' => 'stockholms',
    'värmdö' => 'stockholms',
    'gustavsberg' => 'stockholms',
    'lidingö' => 'stockholms',
    'täby' => 'stockholms',
    'taby' => 'stockholms',
    'danderyd' => 'stockholms',
    'sollentuna' => 'stockholms',
    'järfälla' => 'stockholms',
    'jarfalla' => 'stockholms',
    'sigtuna' => 'stockholms',
    'märsta' => 'stockholms',
    'marsta' => 'stockholms',
    'upplands väsby' => 'stockholms',
    'upplands vasby' => 'stockholms',
    'vallentuna' => 'stockholms',
    'norrtälje' => 'stockholms',
    'norrtalje' => 'stockholms',
    'nynäshamn' => 'stockholms',
    'nynashamn' => 'stockholms',
    'ekerö' => 'stockholms',
    'ekero' => 'stockholms',
    'vaxholm' => 'stockholms',
    'österåker' => 'stockholms',
    'osteraker' => 'stockholms',
    'åkersberga' => 'stockholms',
    'akersberga' => 'stockholms',

    // Upplands CF (Uppsala län)
    'uppsala' => 'upplands',
    'enköping' => 'upplands',
    'enkoping' => 'upplands',
    'knivsta' => 'upplands',
    'tierp' => 'upplands',
    'östhammar' => 'upplands',
    'osthammar' => 'upplands',
    'älvkarleby' => 'upplands',
    'alvkarleby' => 'upplands',
    'skutskär' => 'upplands',
    'skutskar' => 'upplands',
    'heby' => 'upplands',

    // Södermanlands CF (Södermanlands län)
    'eskilstuna' => 'sodermanlands',
    'nyköping' => 'sodermanlands',
    'nykoping' => 'sodermanlands',
    'strängnäs' => 'sodermanlands',
    'strangnas' => 'sodermanlands',
    'katrineholm' => 'sodermanlands',
    'flen' => 'sodermanlands',
    'gnesta' => 'sodermanlands',
    'oxelösund' => 'sodermanlands',
    'oxelosund' => 'sodermanlands',
    'trosa' => 'sodermanlands',
    'vingåker' => 'sodermanlands',
    'vingaker' => 'sodermanlands',

    // Östergötlands CF (Östergötlands län)
    'linköping' => 'ostergotlands',
    'linkoping' => 'ostergotlands',
    'norrköping' => 'ostergotlands',
    'norrkoping' => 'ostergotlands',
    'motala' => 'ostergotlands',
    'mjölby' => 'ostergotlands',
    'mjolby' => 'ostergotlands',
    'finspång' => 'ostergotlands',
    'finspang' => 'ostergotlands',
    'vadstena' => 'ostergotlands',
    'söderköping' => 'ostergotlands',
    'soderkoping' => 'ostergotlands',
    'boxholm' => 'ostergotlands',
    'kinda' => 'ostergotlands',
    'ydre' => 'ostergotlands',
    'ödeshög' => 'ostergotlands',
    'odeshog' => 'ostergotlands',
    'valdemarsvik' => 'ostergotlands',
    'åtvidaberg' => 'ostergotlands',
    'atvidaberg' => 'ostergotlands',

    // Smålands CF (Jönköpings, Kalmar, Kronobergs län)
    'jönköping' => 'smalands',
    'jonkoping' => 'smalands',
    'huskvarna' => 'smalands',
    'värnamo' => 'smalands',
    'varnamo' => 'smalands',
    'nässjö' => 'smalands',
    'nassjo' => 'smalands',
    'vetlanda' => 'smalands',
    'eksjö' => 'smalands',
    'eksjo' => 'smalands',
    'tranås' => 'smalands',
    'tranas' => 'smalands',
    'gislaved' => 'smalands',
    'gnosjö' => 'smalands',
    'gnosjo' => 'smalands',
    'sävsjö' => 'smalands',
    'savsjo' => 'smalands',
    'aneby' => 'smalands',
    'mullsjö' => 'smalands',
    'mullsjo' => 'smalands',
    'habo' => 'smalands',
    'vaggeryd' => 'smalands',
    'kalmar' => 'smalands',
    'västervik' => 'smalands',
    'vastervik' => 'smalands',
    'oskarshamn' => 'smalands',
    'vimmerby' => 'smalands',
    'hultsfred' => 'smalands',
    'nybro' => 'smalands',
    'borgholm' => 'smalands',
    'mörbylånga' => 'smalands',
    'morbylanga' => 'smalands',
    'emmaboda' => 'smalands',
    'torsås' => 'smalands',
    'torsas' => 'smalands',
    'högsby' => 'smalands',
    'hogsby' => 'smalands',
    'mönsterås' => 'smalands',
    'monsteras' => 'smalands',
    'växjö' => 'smalands',
    'vaxjo' => 'smalands',
    'ljungby' => 'smalands',
    'älmhult' => 'smalands',
    'almhult' => 'smalands',
    'tingsryd' => 'smalands',
    'alvesta' => 'smalands',
    'uppvidinge' => 'smalands',
    'åseda' => 'smalands',
    'aseda' => 'smalands',
    'lessebo' => 'smalands',
    'markaryd' => 'smalands',

    // Skånes CF (Skåne & Blekinge län)
    'malmö' => 'skanes',
    'malmo' => 'skanes',
    'helsingborg' => 'skanes',
    'lund' => 'skanes',
    'kristianstad' => 'skanes',
    'hässleholm' => 'skanes',
    'hassleholm' => 'skanes',
    'landskrona' => 'skanes',
    'trelleborg' => 'skanes',
    'ängelholm' => 'skanes',
    'angelholm' => 'skanes',
    'eslöv' => 'skanes',
    'eslov' => 'skanes',
    'ystad' => 'skanes',
    'höganäs' => 'skanes',
    'hoganas' => 'skanes',
    'staffanstorp' => 'skanes',
    'lomma' => 'skanes',
    'svedala' => 'skanes',
    'kävlinge' => 'skanes',
    'kavlinge' => 'skanes',
    'burlöv' => 'skanes',
    'burlov' => 'skanes',
    'vellinge' => 'skanes',
    'klippan' => 'skanes',
    'åstorp' => 'skanes',
    'astorp' => 'skanes',
    'bjuv' => 'skanes',
    'höör' => 'skanes',
    'hoor' => 'skanes',
    'tomelilla' => 'skanes',
    'sjöbo' => 'skanes',
    'sjobo' => 'skanes',
    'simrishamn' => 'skanes',
    'skurup' => 'skanes',
    'båstad' => 'skanes',
    'bastad' => 'skanes',
    'bromölla' => 'skanes',
    'bromolla' => 'skanes',
    'osby' => 'skanes',
    'perstorp' => 'skanes',
    'örkelljunga' => 'skanes',
    'orkelljunga' => 'skanes',
    'svalöv' => 'skanes',
    'svalov' => 'skanes',
    'hörby' => 'skanes',
    'horby' => 'skanes',
    'karlshamn' => 'skanes',
    'ronneby' => 'skanes',
    'karlskrona' => 'skanes',
    'sölvesborg' => 'skanes',
    'solvesborg' => 'skanes',
    'olofström' => 'skanes',
    'olofstrom' => 'skanes',

    // Hallands CF (Hallands län)
    'halmstad' => 'hallands',
    'varberg' => 'hallands',
    'falkenberg' => 'hallands',
    'kungsbacka' => 'hallands',
    'laholm' => 'hallands',
    'hylte' => 'hallands',

    // Göteborgs CF (Göteborg stad)
    'göteborg' => 'goteborgs',
    'goteborg' => 'goteborgs',
    'mölndal' => 'goteborgs',
    'molndal' => 'goteborgs',
    'partille' => 'goteborgs',
    'härryda' => 'goteborgs',
    'harryda' => 'goteborgs',
    'öckerö' => 'goteborgs',
    'ockero' => 'goteborgs',

    // Bohuslän-Dals CF (norra Västra Götaland - kusten och Dal)
    'uddevalla' => 'bohuslan_dals',
    'trollhättan' => 'bohuslan_dals',
    'trollhattan' => 'bohuslan_dals',
    'vänersborg' => 'bohuslan_dals',
    'vanersborg' => 'bohuslan_dals',
    'lysekil' => 'bohuslan_dals',
    'strömstad' => 'bohuslan_dals',
    'stromstad' => 'bohuslan_dals',
    'munkedal' => 'bohuslan_dals',
    'tanum' => 'bohuslan_dals',
    'sotenäs' => 'bohuslan_dals',
    'sotenas' => 'bohuslan_dals',
    'orust' => 'bohuslan_dals',
    'färgelanda' => 'bohuslan_dals',
    'fargelanda' => 'bohuslan_dals',
    'dals-ed' => 'bohuslan_dals',
    'mellerud' => 'bohuslan_dals',
    'bengtsfors' => 'bohuslan_dals',
    'åmål' => 'bohuslan_dals',
    'amal' => 'bohuslan_dals',

    // Västergötlands CF (södra Västra Götaland)
    'borås' => 'vastergotlands',
    'boras' => 'vastergotlands',
    'skövde' => 'vastergotlands',
    'skovde' => 'vastergotlands',
    'lidköping' => 'vastergotlands',
    'lidkoping' => 'vastergotlands',
    'falköping' => 'vastergotlands',
    'falkoping' => 'vastergotlands',
    'mariestad' => 'vastergotlands',
    'skara' => 'vastergotlands',
    'alingsås' => 'vastergotlands',
    'alingsas' => 'vastergotlands',
    'lerum' => 'vastergotlands',
    'kungälv' => 'vastergotlands',
    'kungalv' => 'vastergotlands',
    'stenungsund' => 'vastergotlands',
    'tjörn' => 'vastergotlands',
    'tjorn' => 'vastergotlands',
    'ale' => 'vastergotlands',
    'lilla edet' => 'vastergotlands',
    'mark' => 'vastergotlands',
    'kinna' => 'vastergotlands',
    'svenljunga' => 'vastergotlands',
    'tranemo' => 'vastergotlands',
    'ulricehamn' => 'vastergotlands',
    'tidaholm' => 'vastergotlands',
    'hjo' => 'vastergotlands',
    'tibro' => 'vastergotlands',
    'karlsborg' => 'vastergotlands',
    'töreboda' => 'vastergotlands',
    'toreboda' => 'vastergotlands',
    'gullspång' => 'vastergotlands',
    'gullspang' => 'vastergotlands',
    'götene' => 'vastergotlands',
    'gotene' => 'vastergotlands',
    'vara' => 'vastergotlands',
    'essunga' => 'vastergotlands',
    'grästorp' => 'vastergotlands',
    'grastorp' => 'vastergotlands',
    'herrljunga' => 'vastergotlands',
    'vårgårda' => 'vastergotlands',
    'vargarda' => 'vastergotlands',
    'bollebygd' => 'vastergotlands',

    // Värmlands CF (Värmlands län)
    'karlstad' => 'varmlands',
    'kristinehamn' => 'varmlands',
    'arvika' => 'varmlands',
    'säffle' => 'varmlands',
    'saffle' => 'varmlands',
    'hagfors' => 'varmlands',
    'sunne' => 'varmlands',
    'filipstad' => 'varmlands',
    'forshaga' => 'varmlands',
    'grums' => 'varmlands',
    'hammarö' => 'varmlands',
    'hammaro' => 'varmlands',
    'kil' => 'varmlands',
    'munkfors' => 'varmlands',
    'storfors' => 'varmlands',
    'torsby' => 'varmlands',
    'årjäng' => 'varmlands',
    'arjang' => 'varmlands',
    'eda' => 'varmlands',

    // Örebro Läns CF (Örebro län)
    'örebro' => 'orebro',
    'orebro' => 'orebro',
    'kumla' => 'orebro',
    'hallsberg' => 'orebro',
    'lindesberg' => 'orebro',
    'nora' => 'orebro',
    'karlskoga' => 'orebro',
    'degerfors' => 'orebro',
    'askersund' => 'orebro',
    'laxå' => 'orebro',
    'laxa' => 'orebro',
    'ljusnarsberg' => 'orebro',
    'hällefors' => 'orebro',
    'hallefors' => 'orebro',
    'lekeberg' => 'orebro',

    // Västmanlands CF (Västmanlands län)
    'västerås' => 'vastmanlands',
    'vasteras' => 'vastmanlands',
    'köping' => 'vastmanlands',
    'koping' => 'vastmanlands',
    'arboga' => 'vastmanlands',
    'sala' => 'vastmanlands',
    'fagersta' => 'vastmanlands',
    'surahammar' => 'vastmanlands',
    'hallstahammar' => 'vastmanlands',
    'skinnskatteberg' => 'vastmanlands',
    'norberg' => 'vastmanlands',

    // Dalarnas CF (Dalarnas län)
    'falun' => 'dalarnas',
    'borlänge' => 'dalarnas',
    'borlange' => 'dalarnas',
    'mora' => 'dalarnas',
    'ludvika' => 'dalarnas',
    'avesta' => 'dalarnas',
    'hedemora' => 'dalarnas',
    'leksand' => 'dalarnas',
    'rättvik' => 'dalarnas',
    'rattvik' => 'dalarnas',
    'malung' => 'dalarnas',
    'sälen' => 'dalarnas',
    'salen' => 'dalarnas',
    'älvdalen' => 'dalarnas',
    'alvdalen' => 'dalarnas',
    'orsa' => 'dalarnas',
    'vansbro' => 'dalarnas',
    'gagnef' => 'dalarnas',
    'säter' => 'dalarnas',
    'sater' => 'dalarnas',
    'smedjebacken' => 'dalarnas',

    // Gästriklands CF (södra Gävleborg)
    'gävle' => 'gastriklands',
    'gavle' => 'gastriklands',
    'sandviken' => 'gastriklands',
    'hofors' => 'gastriklands',
    'ockelbo' => 'gastriklands',

    // Hälsinglands CF (norra Gävleborg)
    'söderhamn' => 'halsinglands',
    'soderhamn' => 'halsinglands',
    'bollnäs' => 'halsinglands',
    'bollnas' => 'halsinglands',
    'hudiksvall' => 'halsinglands',
    'ljusdal' => 'halsinglands',
    'ovanåker' => 'halsinglands',
    'ovanaker' => 'halsinglands',
    'edsbyn' => 'halsinglands',
    'nordanstig' => 'halsinglands',

    // Västernorrlands CF (Västernorrlands län)
    'sundsvall' => 'vasternorrlands',
    'härnösand' => 'vasternorrlands',
    'harnosand' => 'vasternorrlands',
    'örnsköldsvik' => 'vasternorrlands',
    'ornskoldsvik' => 'vasternorrlands',
    'sollefteå' => 'vasternorrlands',
    'solleftea' => 'vasternorrlands',
    'kramfors' => 'vasternorrlands',
    'timrå' => 'vasternorrlands',
    'timra' => 'vasternorrlands',
    'ånge' => 'vasternorrlands',
    'ange' => 'vasternorrlands',

    // Jämtland-Härjedalens CF (Jämtlands län)
    'östersund' => 'jamtland',
    'ostersund' => 'jamtland',
    'sveg' => 'jamtland',
    'strömsund' => 'jamtland',
    'stromsund' => 'jamtland',
    'krokom' => 'jamtland',
    'åre' => 'jamtland',
    'are' => 'jamtland',
    'berg' => 'jamtland',
    'härjedalen' => 'jamtland',
    'harjedalen' => 'jamtland',
    'bräcke' => 'jamtland',
    'bracke' => 'jamtland',
    'ragunda' => 'jamtland',

    // Västerbottens CF (Västerbottens län)
    'umeå' => 'vasterbottens',
    'umea' => 'vasterbottens',
    'skellefteå' => 'vasterbottens',
    'skelleftea' => 'vasterbottens',
    'lycksele' => 'vasterbottens',
    'vindeln' => 'vasterbottens',
    'robertsfors' => 'vasterbottens',
    'vännäs' => 'vasterbottens',
    'vannas' => 'vasterbottens',
    'nordmaling' => 'vasterbottens',
    'bjurholm' => 'vasterbottens',
    'åsele' => 'vasterbottens',
    'asele' => 'vasterbottens',
    'dorotea' => 'vasterbottens',
    'vilhelmina' => 'vasterbottens',
    'storuman' => 'vasterbottens',
    'sorsele' => 'vasterbottens',
    'malå' => 'vasterbottens',
    'mala' => 'vasterbottens',
    'norsjö' => 'vasterbottens',
    'norsjo' => 'vasterbottens',

    // Norrbottens CF (Norrbottens län)
    'luleå' => 'norrbottens',
    'lulea' => 'norrbottens',
    'piteå' => 'norrbottens',
    'pitea' => 'norrbottens',
    'boden' => 'norrbottens',
    'kiruna' => 'norrbottens',
    'gällivare' => 'norrbottens',
    'gallivare' => 'norrbottens',
    'haparanda' => 'norrbottens',
    'kalix' => 'norrbottens',
    'älvsbyn' => 'norrbottens',
    'alvsbyn' => 'norrbottens',
    'överkalix' => 'norrbottens',
    'overkalix' => 'norrbottens',
    'övertorneå' => 'norrbottens',
    'overtornea' => 'norrbottens',
    'pajala' => 'norrbottens',
    'jokkmokk' => 'norrbottens',
    'arjeplog' => 'norrbottens',
    'arvidsjaur' => 'norrbottens'
];

/**
 * Normalisera stadnamn for matchning
 */
function normalizeCity($city) {
    $city = mb_strtolower(trim($city));
    // Ta bort vanliga suffix
    $city = preg_replace('/\s*(kommun|stad|tätort)$/u', '', $city);
    return $city;
}

/**
 * Hitta distrikt for en stad
 */
function findDistrictForCity($city, $cityToDistrict, $districts) {
    $normalizedCity = normalizeCity($city);
    if (isset($cityToDistrict[$normalizedCity])) {
        $districtKey = $cityToDistrict[$normalizedCity];
        return $districts[$districtKey];
    }
    return null;
}

// Hantera uppdateringar
$message = '';
$messageType = '';
$updated = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    if (isset($_POST['update_selected'])) {
        // Uppdatera valda klubbar
        $clubUpdates = $_POST['club_district'] ?? [];
        foreach ($clubUpdates as $clubId => $district) {
            if (!empty($district)) {
                $stmt = $pdo->prepare("UPDATE clubs SET region = ? WHERE id = ?");
                $stmt->execute([$district, $clubId]);
                $updated++;
            }
        }
        $message = "Uppdaterade $updated klubbar med distrikt.";
        $messageType = 'success';
    } elseif (isset($_POST['auto_map_all'])) {
        // Auto-mappa alla klubbar utan distrikt
        $stmt = $pdo->query("SELECT id, name, city FROM clubs WHERE (region IS NULL OR region = '') AND city IS NOT NULL AND city != ''");
        $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($clubs as $club) {
            $district = findDistrictForCity($club['city'], $cityToDistrict, $districts);
            if ($district) {
                $updateStmt = $pdo->prepare("UPDATE clubs SET region = ? WHERE id = ?");
                $updateStmt->execute([$district, $club['id']]);
                $updated++;
            }
        }
        $message = "Auto-mappade $updated klubbar till distrikt baserat pa stad.";
        $messageType = 'success';
    }
}

// Hamta statistik
$stats = [];
try {
    // Totalt antal klubbar
    $stmt = $pdo->query("SELECT COUNT(*) FROM clubs WHERE active = 1");
    $stats['total_clubs'] = $stmt->fetchColumn();

    // Klubbar med distrikt
    $stmt = $pdo->query("SELECT COUNT(*) FROM clubs WHERE active = 1 AND region IS NOT NULL AND region != ''");
    $stats['with_region'] = $stmt->fetchColumn();

    // Klubbar utan distrikt men med stad
    $stmt = $pdo->query("SELECT COUNT(*) FROM clubs WHERE active = 1 AND (region IS NULL OR region = '') AND city IS NOT NULL AND city != ''");
    $stats['without_region_with_city'] = $stmt->fetchColumn();

    // Klubbar utan bade distrikt och stad
    $stmt = $pdo->query("SELECT COUNT(*) FROM clubs WHERE active = 1 AND (region IS NULL OR region = '') AND (city IS NULL OR city = '')");
    $stats['without_both'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $stats = ['total_clubs' => 0, 'with_region' => 0, 'without_region_with_city' => 0, 'without_both' => 0];
}

// Hamta klubbar utan distrikt
$clubsWithoutRegion = [];
try {
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.city, c.region,
               (SELECT COUNT(DISTINCT r.id) FROM riders r WHERE r.club_id = c.id AND r.active = 1) as rider_count
        FROM clubs c
        WHERE c.active = 1
          AND (c.region IS NULL OR c.region = '')
        ORDER BY rider_count DESC, c.name
    ");
    $clubsWithoutRegion = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Foreslå distrikt for varje klubb
    foreach ($clubsWithoutRegion as &$club) {
        $club['suggested_district'] = null;
        if (!empty($club['city'])) {
            $club['suggested_district'] = findDistrictForCity($club['city'], $cityToDistrict, $districts);
        }
    }
    unset($club);
} catch (Exception $e) {
    // Ignore
}

// Hamta distribution per distrikt
$districtDistribution = [];
try {
    $stmt = $pdo->query("
        SELECT
            COALESCE(NULLIF(c.region, ''), 'Saknar distrikt') as district,
            COUNT(DISTINCT c.id) as club_count,
            COUNT(DISTINCT r.id) as rider_count
        FROM clubs c
        LEFT JOIN riders r ON r.club_id = c.id AND r.active = 1
        WHERE c.active = 1
        GROUP BY district
        ORDER BY rider_count DESC
    ");
    $districtDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore
}

// Page config
$page_title = 'Distriktsmappning';
$breadcrumbs = [
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'Geografi', 'url' => '/admin/analytics-geography.php'],
    ['label' => 'Distriktsmappning']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.stat-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    text-align: center;
}
.stat-card.success .stat-value {
    color: var(--color-success);
}
.stat-card.warning .stat-value {
    color: var(--color-warning);
}
.stat-card.error .stat-value {
    color: var(--color-error);
}
.stat-value {
    font-size: 2rem;
    font-weight: 700;
    font-family: var(--font-heading);
}
.stat-label {
    font-size: 0.875rem;
    color: var(--color-text-muted);
    margin-top: var(--space-xs);
}
.auto-map-section {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-xl);
}
.suggested-badge {
    background: var(--color-accent-light);
    color: var(--color-accent);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
}
.no-suggestion {
    color: var(--color-text-muted);
    font-style: italic;
}
.form-select-sm {
    padding: var(--space-xs) var(--space-sm);
    font-size: 0.875rem;
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['total_clubs']) ?></div>
        <div class="stat-label">Totalt antal klubbar</div>
    </div>
    <div class="stat-card success">
        <div class="stat-value"><?= number_format($stats['with_region']) ?></div>
        <div class="stat-label">Med distrikt</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-value"><?= number_format($stats['without_region_with_city']) ?></div>
        <div class="stat-label">Utan distrikt (har stad)</div>
    </div>
    <div class="stat-card error">
        <div class="stat-value"><?= number_format($stats['without_both']) ?></div>
        <div class="stat-label">Saknar bade stad och distrikt</div>
    </div>
</div>

<?php if ($stats['without_region_with_city'] > 0): ?>
<!-- Auto-map section -->
<div class="auto-map-section">
    <h3><i data-lucide="zap"></i> Automatisk mappning</h3>
    <p class="text-muted">
        Det finns <strong><?= $stats['without_region_with_city'] ?></strong> klubbar som har en stad angiven men saknar distrikt.
        Klicka nedan for att automatiskt koppla dem till distrikt baserat pa deras stad.
    </p>
    <form method="post" style="margin-top: var(--space-md);">
        <?= csrfField() ?>
        <button type="submit" name="auto_map_all" class="btn btn-primary">
            <i data-lucide="wand-2"></i> Auto-mappa alla
        </button>
    </form>
</div>
<?php endif; ?>

<!-- District Distribution -->
<div class="card" style="margin-bottom: var(--space-xl);">
    <div class="card-header">
        <h3><i data-lucide="pie-chart"></i> Fordelning per distrikt</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Distrikt</th>
                        <th class="text-right">Klubbar</th>
                        <th class="text-right">Riders</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($districtDistribution as $dist): ?>
                    <tr>
                        <td><?= htmlspecialchars($dist['district']) ?></td>
                        <td class="text-right"><?= number_format($dist['club_count']) ?></td>
                        <td class="text-right"><?= number_format($dist['rider_count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Clubs without region -->
<?php if (!empty($clubsWithoutRegion)): ?>
<div class="card">
    <div class="card-header">
        <h3><i data-lucide="building-2"></i> Klubbar utan distrikt (<?= count($clubsWithoutRegion) ?>)</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Klubb</th>
                            <th>Stad</th>
                            <th>Riders</th>
                            <th>Forslag</th>
                            <th>Valj distrikt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clubsWithoutRegion as $club): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($club['name']) ?></strong></td>
                            <td><?= htmlspecialchars($club['city'] ?? '-') ?></td>
                            <td><?= number_format($club['rider_count']) ?></td>
                            <td>
                                <?php if ($club['suggested_district']): ?>
                                    <span class="suggested-badge"><?= htmlspecialchars($club['suggested_district']) ?></span>
                                <?php else: ?>
                                    <span class="no-suggestion">Ingen matchning</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="club_district[<?= $club['id'] ?>]" class="form-select form-select-sm">
                                    <option value="">-- Valj --</option>
                                    <?php if ($club['suggested_district']): ?>
                                        <option value="<?= htmlspecialchars($club['suggested_district']) ?>" selected>
                                            <?= htmlspecialchars($club['suggested_district']) ?> (foreslaget)
                                        </option>
                                    <?php endif; ?>
                                    <?php foreach ($districts as $key => $name): ?>
                                        <?php if ($name !== $club['suggested_district']): ?>
                                            <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: var(--space-lg);">
                <button type="submit" name="update_selected" class="btn btn-primary">
                    <i data-lucide="save"></i> Spara valda distrikt
                </button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert-success">
    <i data-lucide="check-circle"></i>
    <strong>Alla klubbar har distrikt!</strong>
    Det finns inga klubbar som saknar distriktskoppling.
</div>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
