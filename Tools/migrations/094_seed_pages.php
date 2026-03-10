<?php
/**
 * Seed-skript för GravitySeries Pages CMS
 * Skapar 6 grundsidor + sponsors + collaborators
 *
 * Kör via migrations.php eller: php Tools/migrations/094_seed_pages.php
 */

// Load database config
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config/database.php';

$isCli = php_sapi_name() === 'cli';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('Databasanslutning misslyckades: ' . $e->getMessage());
}

function out($msg, $isCli) {
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo "<p>" . htmlspecialchars($msg) . "</p>";
    }
}

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><title>Seed Pages</title><style>body{font-family:monospace;padding:20px;background:#f5f3ef;} p{margin:4px 0;} .ok{color:#059669;} .skip{color:#d97706;} .err{color:#dc2626;}</style></head><body>';
    echo '<h2>GravitySeries — Seed Pages</h2>';
}

// ─── 1. Create tables if they don't exist ───────────────────
$sqlFile = __DIR__ . '/094_pages_and_gs_sponsors.sql';
if (file_exists($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (empty($stmt) || strpos($stmt, '--') === 0) continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // Table might already exist
            if (strpos($e->getMessage(), 'already exists') === false) {
                out("SQL-fel: " . $e->getMessage(), $isCli);
            }
        }
    }
    out("Tabeller skapade (eller finns redan).", $isCli);
} else {
    out("Varning: create_pages_table.sql hittades inte. Kör den manuellt.", $isCli);
}

// ─── 2. Seed pages ──────────────────────────────────────────
$pages = [
    [
        'slug' => 'om-oss',
        'title' => 'Om GravitySeries',
        'meta_description' => 'GravitySeries är organisationen bakom svensk gravitycykling — enduro och downhill från Motion till Elite.',
        'nav_label' => 'Om oss',
        'nav_order' => 10,
        'show_in_nav' => 1,
        'content' => '<h2>Om GravitySeries</h2>
<p>GravitySeries är paraplyorganisationen för svensk gravitycykling. Vi driver tävlingsserier inom enduro och downhill, från bredd- till elitnivå, över hela Sverige.</p>
<h3>Vår mission</h3>
<p>Vi vill göra gravitycykling tillgängligt för alla — oavsett ambitionsnivå. Genom att arrangera tävlingar, sätta regler och utveckla sporten skapar vi en plattform där alla kan delta.</p>
<h3>Historia</h3>
<p>GravitySeries grundades 2016 och har sedan dess vuxit till att omfatta flera regionala och nationella tävlingsserier med tusentals deltagare.</p>'
    ],
    [
        'slug' => 'arrangor-info',
        'title' => 'Arrangörsinformation',
        'meta_description' => 'Vill du arrangera en tävling inom GravitySeries? Här hittar du allt du behöver veta.',
        'nav_label' => 'Arrangera',
        'nav_order' => 20,
        'show_in_nav' => 1,
        'content' => '<h2>Arrangera en tävling</h2>
<p>Vill du arrangera en tävling inom GravitySeries? Vi välkomnar nya arrangörer och hjälper dig genom hela processen.</p>
<h3>Hur du ansöker</h3>
<p>Kontakta oss via <a href="mailto:info@gravityseries.se">info@gravityseries.se</a> med information om din klubb, tänkt tävlingsplats och ungefärligt datum.</p>
<h3>Vad vi erbjuder</h3>
<ul>
<li>Anmälningssystem via TheHUB</li>
<li>Tidtagningsstöd via GravityTiming</li>
<li>Resultathantering och ranking</li>
<li>Marknadsföring via våra kanaler</li>
</ul>'
    ],
    [
        'slug' => 'licenser',
        'title' => 'Licenser & SCF',
        'meta_description' => 'Information om SCF-licenser för tävling inom GravitySeries.',
        'nav_label' => 'Licenser',
        'nav_order' => 30,
        'show_in_nav' => 1,
        'content' => '<h2>Licenser</h2>
<p>För att tävla i GravitySeries behöver du en giltig licens från Svenska Cykelförbundet (SCF).</p>
<h3>Licenstyper</h3>
<ul>
<li><strong>Tävlingslicens</strong> — för alla som vill tävla regelbundet</li>
<li><strong>Dagslicens</strong> — tillfällig licens för enstaka tävlingar</li>
<li><strong>sportMotion</strong> — för motionsklasser (lägre krav)</li>
</ul>
<h3>Skaffa licens</h3>
<p>Licenser köps via <a href="https://www.svenskcykling.se" target="_blank">svenskcykling.se</a>. Du behöver ett UCI-ID som kopplas till din profil på TheHUB.</p>'
    ],
    [
        'slug' => 'gravity-id',
        'title' => 'Gravity-ID',
        'meta_description' => 'Ditt Gravity-ID kopplar ihop dina tävlingsresultat, licens och profil.',
        'nav_label' => 'Gravity-ID',
        'nav_order' => 40,
        'show_in_nav' => 1,
        'content' => '<h2>Gravity-ID</h2>
<p>Ditt Gravity-ID är din digitala identitet inom GravitySeries. Det kopplar ihop alla dina tävlingsresultat, din licens och din profil — oavsett vilken serie du kör.</p>
<h3>Vad ingår</h3>
<ul>
<li>Samlad resultathistorik</li>
<li>Ranking inom alla serier</li>
<li>Automatisk licensvalidering</li>
<li>Rabatter vid serieanmälan</li>
</ul>
<h3>Skapa ditt ID</h3>
<p>Registrera dig på <a href="https://thehub.gravityseries.se" target="_blank">TheHUB</a> och koppla ditt UCI-ID för att aktivera ditt Gravity-ID.</p>'
    ],
    [
        'slug' => 'kontakt',
        'title' => 'Kontakt',
        'meta_description' => 'Kontakta GravitySeries — allmänna frågor, media och arrangörsförfrågningar.',
        'nav_label' => 'Kontakt',
        'nav_order' => 50,
        'show_in_nav' => 1,
        'content' => '<h2>Kontakta oss</h2>
<p>Vi finns här för att hjälpa dig. Välj rätt kontaktväg nedan.</p>
<h3>Allmänna frågor</h3>
<p>E-post: <a href="mailto:info@gravityseries.se">info@gravityseries.se</a></p>
<h3>Arrangörsfrågor</h3>
<p>E-post: <a href="mailto:tavling@gravityseries.se">tavling@gravityseries.se</a></p>
<h3>Teknisk support (TheHUB)</h3>
<p>E-post: <a href="mailto:teknik@gravityseries.se">teknik@gravityseries.se</a></p>
<h3>Sociala medier</h3>
<ul>
<li>Instagram: @gravityseries</li>
<li>Facebook: GravitySeries</li>
</ul>'
    ],
    [
        'slug' => 'allmanna-villkor',
        'title' => 'Allmänna villkor',
        'meta_description' => 'Allmänna villkor för deltagande i GravitySeries-tävlingar och användning av TheHUB.',
        'nav_label' => 'Villkor',
        'nav_order' => 60,
        'show_in_nav' => 0,
        'content' => '<h2>Allmänna villkor</h2>
<p>Dessa villkor gäller för deltagande i tävlingar arrangerade inom GravitySeries samt användning av plattformen TheHUB.</p>
<h3>Deltagande</h3>
<p>Alla deltagare måste ha giltig licens och registrering via TheHUB. Deltagare ansvarar för att kontrollera att utrustning och cykel uppfyller gällande säkerhetskrav.</p>
<h3>Betalning & avbokning</h3>
<p>Startavgifter betalas via TheHUB (kort eller Swish). Avbokning följer respektive serie-arrangörs policy.</p>
<h3>Personuppgifter</h3>
<p>GravitySeries behandlar personuppgifter i enlighet med GDPR. Kontakta oss för frågor om dataskydd.</p>'
    ],
];

$insertStmt = $pdo->prepare("
    INSERT INTO pages (slug, title, meta_description, content, template, status, show_in_nav, nav_order, nav_label)
    VALUES (?, ?, ?, ?, 'default', 'draft', ?, ?, ?)
    ON DUPLICATE KEY UPDATE title = VALUES(title)
");

$count = 0;
foreach ($pages as $p) {
    try {
        $insertStmt->execute([
            $p['slug'],
            $p['title'],
            $p['meta_description'],
            $p['content'],
            $p['show_in_nav'],
            $p['nav_order'],
            $p['nav_label'],
        ]);
        $count++;
        out("  Sida skapad: {$p['title']} (/{$p['slug']})", $isCli);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            out("  Hoppar över (finns redan): {$p['title']}", $isCli);
        } else {
            out("  Fel vid skapande av {$p['title']}: " . $e->getMessage(), $isCli);
        }
    }
}

out("", $isCli);
out("{$count} sidor skapade som utkast. Publicera dem via admin/pages/.", $isCli);

// ─── 3. Seed sponsors ───────────────────────────────────────
$sponsors = [
    ['name' => 'Sportson', 'website_url' => 'https://www.sportson.se', 'type' => 'sponsor', 'sort_order' => 1],
    ['name' => 'Zeeksack', 'website_url' => 'https://zeeksack.se', 'type' => 'sponsor', 'sort_order' => 2],
    ['name' => 'Tershine', 'website_url' => 'https://tershine.com', 'type' => 'sponsor', 'sort_order' => 3],
];

$collaborators = [
    ['name' => 'Svenska Cykelförbundet', 'website_url' => 'https://www.svenskcykling.se', 'type' => 'collaborator', 'sort_order' => 1],
];

$sponsorStmt = $pdo->prepare("
    INSERT INTO gs_sponsors (name, website_url, type, sort_order)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE website_url = VALUES(website_url)
");

// Check if gs_sponsors exists
try {
    $pdo->query("SELECT 1 FROM gs_sponsors LIMIT 1");
    $hasSponsorTable = true;
} catch (PDOException $e) {
    $hasSponsorTable = false;
}

if ($hasSponsorTable) {
    out("", $isCli);
    out("Skapar sponsors och samarbeten...", $isCli);

    foreach (array_merge($sponsors, $collaborators) as $sp) {
        try {
            $sponsorStmt->execute([$sp['name'], $sp['website_url'], $sp['type'], $sp['sort_order']]);
            $label = $sp['type'] === 'collaborator' ? 'Samarbete' : 'Sponsor';
            out("  {$label}: {$sp['name']}", $isCli);
        } catch (PDOException $e) {
            out("  Fel: {$sp['name']} — " . $e->getMessage(), $isCli);
        }
    }
} else {
    out("Tabellen gs_sponsors saknas — hoppar över sponsors.", $isCli);
}

out("", $isCli);
out("Klart! Besök /admin/pages/ för att redigera och publicera sidorna.", $isCli);

if (!$isCli) {
    echo '<p style="margin-top:20px;"><a href="/admin/pages/" style="color:#3fa84d;">Gå till sidhanteringen &rarr;</a></p>';
    echo '</body></html>';
}
