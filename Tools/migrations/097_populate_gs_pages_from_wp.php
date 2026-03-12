<?php
/**
 * Migration 097: Populera GravitySeries CMS-sidor med riktigt innehåll från WordPress
 *
 * Uppdaterar 6 befintliga CMS-sidor med riktigt innehåll från WordPress-exporten.
 * Sidor: Information (om-oss), Arrangör Info, Licenser, Gravity-ID, Kontakt, Allmänna villkor.
 *
 * Kör via migrations.php eller: php Tools/migrations/097_populate_gs_pages_from_wp.php
 */

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
    if ($isCli) echo $msg . "\n";
    else echo "<p>" . htmlspecialchars($msg) . "</p>";
}

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><title>Migration 097</title><style>body{font-family:monospace;padding:20px;background:#f5f3ef;} p{margin:4px 0;} .ok{color:#059669;} .skip{color:#d97706;}</style></head><body>';
    echo '<h2>Migration 097 — Populera GS-sidor från WordPress</h2>';
}

// ─── Page content from WordPress export ──────────────────────

$pages = [

    // 1. Information → om-oss
    [
        'slug' => 'om-oss',
        'title' => 'Om GravitySeries',
        'meta_description' => 'GravitySeries är ett samarbete mellan ECAB Bike Adventure, Ride and Develop och BACC. #gravityföralla',
        'nav_label' => 'Information',
        'nav_order' => 10,
        'show_in_nav' => 1,
        'content' => '
<h2>#Gravityföralla</h2>
<p>GravitySeries är ett samarbete mellan ECAB Bike Adventure, Ride and Develop och Bike Adventure Cycling Club.</p>
<p>GravitySeries har som målsättning att erbjuda de bästa eventen och med det möjligheterna för svenska och nordiska cyklister.</p>

<h2>The Crew</h2>

<h3>Roger Edvinsson</h3>
<p><strong>Projektledare &amp; Eventansvarig</strong></p>
<p>50 år, boende i Stockholm med rötterna i Blekinge.</p>
<p>Hobbycyklist som tydligen aldrig får slut på knäppa visioner. Cyklat allt från XC till enduro men som efter en närkontakt med gruset 2019 fått ägna sig mest åt gravelcykling.</p>
<p>Brinner för att skapa och utsätta både sig själv och andra för prövningar på cykeln, och att scouta rutter samt planera event är enligt Roger bästa formen av avkoppling.</p>
<p>Utbildad tävlingsledare och Trainee Kommissarie. Grundare av ECAB och Ordförande i BACC.</p>

<h3>Carro Åhman</h3>
<p><strong>Kommunikationsansvarig &amp; Backoffice</strong></p>
<p>35 år och boende i Stockholm. Sjukvårdaren och ständiga följeslagaren som blev maskineriets viktigaste kugge.</p>
<p>Sköter näst intill uteslutande vårt backoffice på eventen. Även primär kontakt för sponsorer.</p>
<p>Utbildad tävlingsledare och Trainee Kommissarie. Medgrundare av ECAB och Sekreterare i BACC.</p>

<h3>Philip Fagerberg</h3>
<p><strong>Projektledare &amp; Eventansvarig</strong></p>
<p>29 år gammal tävlingscyklist från Huskvarna med erfarenhet från internationellt tävlande. Grundare &amp; VD på Ride and Develop AB.</p>

<h3>Mattias Arnerdal</h3>
<p><strong>Banansvarig &amp; Backoffice</strong></p>
<p>27 år gammal aktiv tävlingscyklist från Bankeryd. Coach &amp; medarbetare på Ride and Develop.</p>

<h3>Joakim Olsson</h3>
<p><strong>Media — Escape Gravity Media</strong></p>
<p>Kanske mer känd som ESCAPEGRAVITYMEDIA där han senaste åren presenterat mängder av foto och filmer på framförallt Enduro och annan utförscykling.</p>
<p>Jocke har följt Capital ganska frekvent de senaste åren och även cyklat ett antal av eventen där han inte fotograferat.</p>

<h3>August Järpemo</h3>
<p><strong>Media — Fotograf &amp; Designer</strong></p>
<p>Bröllopsfotografen som aldrig tar ett steg utan sin kameraväska. "Vår" hovfotograf för gravel och mingel och en inspirationskälla utan dess like för oss andra.</p>
<p>August är även designer och upphovsman till den tidigare hemsidan.</p>
'
    ],

    // 2. Arrangör Info
    [
        'slug' => 'arrangor-info',
        'title' => 'Arrangörsinformation',
        'meta_description' => 'Information till dig som vill arrangera en tävling inom GravitySeries. Eventservice, tidtagning och mediacrew.',
        'nav_label' => 'Arrangör Info',
        'nav_order' => 20,
        'show_in_nav' => 1,
        'content' => '
<h2>Information till dig som vill arrangera en tävling</h2>
<p>ECAB Bike Adventures och Ride and Develop har mångårig erfarenhet av att arrangera enduro- och downhill-event och vi erbjuder vår hjälp för er som vill ta steget att bli arrangör. Vi kan hjälpa er med alla praktiska delar och tillsammans driva eventet.</p>

<h2>Eventservice</h2>
<p>Har ni en idé för ett event så kan vi hjälpa med alla praktiska detaljer:</p>
<ul>
<li>Tillståndsansökan</li>
<li>Onlinereklam</li>
<li>Preppning och val av bansträckning</li>
<li>Anmälningsportal</li>
<li>Sjukvård</li>
<li>Tidtagning</li>
<li>Resultathantering</li>
<li>Mediacrew</li>
<li>Nummerlappar</li>
<li>Backoffice</li>
</ul>
<p>Så tveka inte att fråga om ni har funderingar.</p>

<h2>Gravity Series</h2>
<p>Känner du att ert område saknar riktiga event för enduro eller downhill, men ni vet att ni har både anläggningar och spår som skulle passa sig utmärkt?</p>
<p>Gravity Series ger dig då möjligheten att tillsammans med oss skapa en egen lokal serie där ni som klubb tillsammans med andra klubbar och företag kan skapa en scen för cykeltävlingar med lokal förankring.</p>
<p>Instegstävlingar är ett ypperligt sätt att skapa förutsättningar för åkare, klubbar och företag att lokalt kunna profilera sig.</p>
<p>Med vår hjälp finns alla förutsättningar för att komma igång då vi sitter på kunskapen och har produkten för att en del stora hinder ska kunna röjas ur vägen — allt från anmälan, backoffice och tidtagning är saker som får många att backa från att arrangera och där delar vi med oss av vår expertis.</p>

<h2>MediaCrew</h2>
<p>Media och reklam från eventen är en av våra viktigaste beståndsdelar. Här ger vi både sponsorer och åkare möjlighet att dela med sig av sin dag och samtidigt ger det er massvis av publicitet i deras kanaler.</p>
<p>Gravity Series samarbetar med 5–6 olika fotografer och filmare med erfarenhet av cykelevent både via våra event och via SweCups deltävlingar.</p>
<p>Vi erbjuder er även en mediaplattform för att få ut bilderna efter avslutat event.</p>

<h2>Tidtagning</h2>
<p>Under de senaste fyra åren har vi skött tidtagningen på ett 40-tal event för enduro, downhill, XC, triathlon och löpning. För enduro har vi varit ansvariga för både Svenska mästerskap såväl som för kompistävlingar med 5–6 åkare.</p>
<p>Vi har investerat i utrustning så att vi ska vara självförsörjande på event, men har även Sportident Sverige som partner där vi kan hyra utrustning för att komplettera vår egen.</p>
<p>Vi har i dagsläget möjlighet att arrangera tidtagning på event upp mot 1 000 deltagare och med nästan obegränsat antal specialsträckor eller mellantider.</p>
<p>Till detta använder vi även vår egen databas för liveresultat och resultathantering och är ledande i Sverige för just denna service.</p>
<p>Planerar ni ett event så finns det flera varianter att utnyttja genom oss. Antingen hyr ni in oss för att sköta allt och kan själva luta er tillbaka och bara presentera allt för deltagarna. Vill ni sköta en del själva så hyr vi även ut utrustning, helt färdigprogramerad för ert event och med det antal tidtagningschip ni behöver för era deltagare. Vi kan då på distans hjälpa er att rätta till eventuella problem eller justeringar under eventets gång.</p>
<p>Så tveka inte att ta hjälp av oss för ert event.</p>
'
    ],

    // 3. Licenser
    [
        'slug' => 'licenser',
        'title' => 'Licenser & SCF',
        'meta_description' => 'Information om SCF-licenser för tävling inom GravitySeries. Tävlingslicens, motionslicens och engångslicens.',
        'nav_label' => 'Licenser',
        'nav_order' => 30,
        'show_in_nav' => 1,
        'content' => '
<h2>Licens 2026</h2>
<p>Alla deltagare ska vara försäkrade under tävling. Genom vårt samarbete med Svenska Cykelförbundet så gäller deras licenser som försäkring på våra event.</p>
<p>Ytterligare en anledning för att vi kräver licenser är för att det då ger oss möjlighet att sanktionera eventen hos SCF och därmed är även våra funktionärer försäkrade genom era avgifter.</p>

<h3>Licenstyper</h3>
<p>SCF erbjuder olika typer av licens som kan användas hos oss. Detta kan vara lite krångligt att få rätt så se till att detaljerna vad du ska tävla i stämmer mot den licens du köper.</p>
<p>För ungdomar under 15 år finns ingen motionslicens så här är det enbart tävlingslicens eller engångslicens som är ett alternativ.</p>
<p>För barn upp till 11 år är licens och tillhörande försäkring kostnadsfri, men måste ändå skaffas genom SCF.</p>
<p>Läs mer om licens hos SCF <a href="https://scf.se/licenser/" target="_blank" rel="noopener">här</a>.</p>

<h2>Tävlingslicenser</h2>
<p><strong>Tävlingslicens: 0–960 kr/år</strong></p>
<p>Tävlingslicens rekommenderar vi för er som tänkt tävla på nationell nivå på SweCup Enduro. Dessa licenser fungerar utmärkt även i samtliga av våra tävlingsklasser, men se till att välja rätt.</p>
<p><strong><a href="https://scf.se/licenser/" target="_blank" rel="noopener">Köp din tävlingslicens av SCF via denna länk...</a></strong></p>

<h3>Under 11 — Men/Women</h3>
<ul>
<li>För pojkar och flickor 5–10 år</li>
<li>Används i klasserna Gravity Series Nybörjare 7–12 år</li>
<li><strong>Används INTE hos SweCup Enduro</strong></li>
<li>Ger deltagaren ett UCI ID</li>
<li>0 kr</li>
</ul>

<h3>Youth — Men/Women</h3>
<ul>
<li>För pojkar och flickor 11–16 år</li>
<li>Används i Gravity Series P/F 13–14 och 15–16</li>
<li>Används i SweCup Enduro P/F 13–14, 15–16 och E-Bike</li>
<li>Ger deltagaren ett UCI ID</li>
<li>260 kr</li>
</ul>

<h3>Junior — Men/Women</h3>
<ul>
<li>För pojkar och flickor 17–21 år</li>
<li>Används i Gravity Series P/F Junior samt 19+ (Elit) fram tills året du fyller 21</li>
<li>Används i SweCup Enduro Junior samt Elit fram till det året du fyller 21 samt E-Bike</li>
<li>Ger deltagaren ett UCI ID</li>
<li>660 kr</li>
</ul>

<h3>Under 23 — Men/Women</h3>
<ul>
<li>För män och kvinnor 22–23 år</li>
<li>Används i Gravity Series H/D 19+ (Elit)</li>
<li>Används i SweCup Enduro H/D Elit, Motion Lång och E-Bike</li>
<li>Ger deltagaren ett UCI ID</li>
<li>910 kr</li>
</ul>

<h3>Elite — Men/Women</h3>
<ul>
<li>För män och kvinnor 24+ år</li>
<li>Används i Gravity Series H/D 19+ (Elit) samt 35+ (Master)</li>
<li>Används i SweCup Enduro H/D Elit, Motion Lång och E-Bike</li>
<li>Ger deltagaren ett UCI ID</li>
<li>910 kr</li>
</ul>

<h3>Master — Men/Women</h3>
<ul>
<li>För män och kvinnor 30+ år</li>
<li>Används i Gravity Series H/D 19+ (Elit) samt 35+ (Master)</li>
<li>Används i SweCup Enduro H/D Master 35+, Master 45+, Motion Lång och E-Bike. Används även på SM i Downhill Master 30.</li>
<li><strong>OBS: Du får inte tävla i tävlingsklass Elit på SweCup med denna licens</strong></li>
<li>Ger deltagaren ett UCI ID</li>
<li>910 kr</li>
</ul>

<h3>Baslicens — Men/Women</h3>
<ul>
<li>För män och kvinnor 15+ år</li>
<li>Används i Gravity Series P/F 15–16, P/F Junior, H/D Elit 19+</li>
<li>Används i SweCup Enduro Motion Lång och E-Bike</li>
<li><strong>OBS: Du får inte tävla i tävlingsklass på SweCup med denna licens</strong></li>
<li>Ger deltagaren ett UCI ID</li>
<li>420 kr</li>
</ul>

<h2>Motionslicens 2026</h2>
<p><strong>Motionslicens: 300 kr/år</strong></p>
<p>Ska du tävla hos oss men tänker även prova på SweCup och då i en av motionsklasserna rekommenderar vi dig denna licens.</p>
<p>Ger dig rätt att delta på alla av Cykelförbundet sanktionerade motionslopp, samt ger dig även rätt att ställa upp i SCFs samtliga E-cycling-arrangemang.</p>
<ul>
<li>För män och kvinnor 15+ år</li>
<li>Används i Gravity Series P/F 15–16, 17–18 (Junior), 19+ (Elit) samt 35+ (Master)</li>
<li>Används i SweCup Enduro Motion Lång och E-Bike</li>
<li><strong>OBS: Du får inte tävla i tävlingsklass på SweCup med denna licens</strong></li>
<li>Ger <strong>INTE</strong> deltagaren ett UCI ID</li>
<li>Ger <a href="https://www.gjensidige.se/partners/svenska-cykelforbundet" target="_blank" rel="noopener">olycksfallsförsäkring</a> vid idrottsutövande under både cykellopp och träning nationellt och internationellt samt vid resor till och från sina cykelaktiviteter t.o.m. 31 december pågående licensår</li>
<li>Innehåller även Aktiv vård, läs mer <a href="https://www.gjensidige.se/partners/svenska-cykelforbundet" target="_blank" rel="noopener">här</a></li>
<li>Tillgång till SweCycling Community i Cardskipper som bland annat innehåller rabatter och erbjudanden från SCFs partners</li>
</ul>

<h2>Engångslicens 2026</h2>
<p><strong>Engångslicens: 90 kr/tävling</strong></p>
<p>Engångslicens är som det låter en licens som gäller över tävlingsdagen, och funkar i samtliga av våra klasser.</p>
<p>Samtliga cyklister som tecknar engångslicens omfattas av en försäkring som gäller vid olycksfallsskada som inträffar under deltagande i tävlingen. Observera att denna licens EJ täcker skador på fria träningen.</p>
<p>Försäkringen gäller dock även under direkt färd till och från eventet.</p>
<p>För deltagare 12 år och äldre kostar licensen 90 kr.</p>
<p>För ungdomar 7–11 år är den gratis.</p>
<p><strong>Engångslicensen kan enbart tecknas via denna länk:</strong></p>
<p><strong><a href="https://licens.scf.se" target="_blank" rel="noopener">https://licens.scf.se</a></strong></p>
<p>Vid tecknandet väljer du det lopp som du vill köra, fyller i uppgifter och betalar. Därefter får du meddelande om hur du installerar Cardskipper-appen där du har din engångslicens.</p>
'
    ],

    // 4. Gravity-ID (crowdfunding/tidtagningschip)
    [
        'slug' => 'gravity-id',
        'title' => 'Gravity-ID',
        'meta_description' => 'Gravity-ID ger dig rabatter och fördelar på GravitySeries-event under säsongerna 2025–2028.',
        'nav_label' => 'Gravity-ID',
        'nav_order' => 40,
        'show_in_nav' => 1,
        'content' => '
<h2>Gravity-ID 2025</h2>
<p>Vi har sedan starten av Capital Enduro 2019 ständigt uppdaterat vårt system för tidtagning och har idag ett system som är fullt kapabelt att tidta både de största Endurostävlingarna såväl som de mindre MatesRace-eventen.</p>
<p>Men utrustningen för tidtagning är en ganska stor utgift på eventen och vårt mål har från start varit att vi ska hyra in så lite material som möjligt och istället kunna erbjuda samarbetspartners och klubbar att hyra av oss, både hårdvara men även våra tjänster för kompletta event.</p>
<p>2019 startade vi en Crowdfunding bland deltagare för att kunna expandera och det har sedan dess växt bra och vi har idag ca 120 deltagare med ID, så pass att vi varit helt självförsörjande av hårdvara på både Capital och Götaland Enduro.</p>
<p>Men 2025 betyder att vi expanderar än mer och antalet deltagare kommer bli fler så därför drar vi igång en ny runda av Crowdfunding.</p>

<h2>Crowdfunding</h2>
<p>Crowdfundingen ger dig som deltagare rabatter och fördelar under säsongerna 2025–2028.</p>
<p><strong>Gravity ID är en engångskostnad och kostar 1 000 SEK. Ditt ID gäller på samtliga event arrangerade under Gravity Series flagg.</strong></p>
<ul>
<li>Gravity ID ger dig 50 SEK rabatt på startavgiften per event</li>
<li>Gravity ID ger dig möjlighet att köpa säsongspass till förmånligare pris</li>
<li>Gravity ID ger förtur på extra startplatser till våra event som är slutsålda</li>
<li>Gravity ID ger dig 10% rabatt på vår GravitySeries merch</li>
<li>Gravity ID ger dig löpande erbjudanden från partners under året</li>
</ul>
<p>Samtliga deltagare som redan införskaffat ett CES-ID under tidigare säsonger kommer få sitt ID överfört till Gravity Series.</p>
<p>Så hjälp oss expandera — köp ett Gravity-ID!</p>
'
    ],

    // 5. Kontakt
    [
        'slug' => 'kontakt',
        'title' => 'Kontakt',
        'meta_description' => 'Kontakta GravitySeries — evenemang, förfrågningar och allmänna frågor.',
        'nav_label' => 'Kontakt',
        'nav_order' => 50,
        'show_in_nav' => 1,
        'content' => '
<h2>Kontakta oss</h2>

<h3>Philip Fagerberg</h3>
<p><a href="mailto:philip@rideanddevelop.se">philip@rideanddevelop.se</a></p>
<ul>
<li>Evenemang</li>
<li>Förfrågningar</li>
</ul>

<h3>Roger Edvinsson</h3>
<p><a href="mailto:roger@ecab.bike">roger@ecab.bike</a></p>
<ul>
<li>Evenemang</li>
<li>Förfrågningar</li>
</ul>

<h3>Caroline Åhman</h3>
<p><a href="mailto:caroline@ecab.bike">caroline@ecab.bike</a></p>
<ul>
<li>Evenemang</li>
<li>Sponsorer &amp; samarbeten</li>
</ul>
'
    ],

    // 6. Allmänna villkor
    [
        'slug' => 'allmanna-villkor',
        'title' => 'Allmänna villkor',
        'meta_description' => 'Allmänna villkor för deltagande i GravitySeries-event och användning av GravitySeries.se.',
        'nav_label' => 'Villkor',
        'nav_order' => 60,
        'show_in_nav' => 0,
        'content' => '
<h2>Allmänna villkor</h2>
<p><strong>Vi vill att du känner dig trygg när du använder GravitySeries.se.</strong></p>
<p>Därför är det viktigt för oss att du förstår både dina egna rättigheter och våra skyldigheter som tjänsteleverantör.</p>
<p>Nedan hittar du en tydlig genomgång av vad som gäller när du använder våra tjänster.</p>

<h3>1. Inledning</h3>
<p><strong>1.1</strong> GravitySeries.se är en digital plattform som drivs av Edvinsson Consulting AB, org.nr 556794-5844 ("ECAB"). Webbplatsen utgör en mötesplats för både cykelintresserade ("Deltagare") och arrangörer av cykelevenemang ("Arrangörer"). Via plattformen kan Deltagare enkelt ta del av information om kommande tävlingar och event samt anmäla sig till dessa ("Evenemang"). ECAB erbjuder även tillgång till resultat, statistik och personligt anpassad information relaterad till Evenemang, produkter och tjänster inom cykelsporten.</p>
<p><strong>1.2</strong> För att använda GravitySeries.se:s tjänster krävs att du godkänner dessa allmänna villkor ("Villkoren"). Genom registrering eller anmälan till ett Evenemang bekräftar du att du tagit del av och accepterar Villkoren.</p>

<h3>2. Tjänstens omfattning</h3>
<p><strong>2.1</strong> ECAB tillhandahåller via GravitySeries.se information om Evenemang, hanterar anmälningar och mottar deltagaravgifter.</p>
<p><strong>2.2</strong> ECAB fungerar enbart som teknisk leverantör och betalningsombud. Det är alltid den enskilda Arrangören som ansvarar för genomförandet av Evenemanget. Eventuella frågor, klagomål eller ersättningskrav kopplade till ett Evenemang ska därför riktas direkt till den aktuella Arrangören.</p>
<p><strong>2.3</strong> Arrangörer har rätt att ställa upp egna särskilda villkor för sina Evenemang. Dessa gäller parallellt med dessa Villkor.</p>
<p><strong>2.4</strong> Arrangörens särskilda villkor ska finnas tillgängliga via respektive Evenemangs informationssida på GravitySeries.se eller via länk till extern webbplats.</p>

<h3>3. Anmälan till Evenemang</h3>
<p><strong>3.1</strong> Anmälan till ett Evenemang sker via GravitySeries.se:s formulär.</p>
<p><strong>3.2</strong> Genom att anmäla dig till ett Evenemang godkänner du även de specifika villkor som Arrangören kan ha upprättat för det aktuella Evenemanget. Du ansvarar själv för att ha läst och förstått dessa innan anmälan slutförs.</p>

<h3>4. Betalning</h3>
<p><strong>4.1</strong> Deltagande i ett Evenemang kräver betalning av den anmälningsavgift som angetts av Arrangören ("Deltagaravgift").</p>
<p><strong>4.2</strong> Betalning sker via de betalmetoder som ECAB erbjuder vid varje given tidpunkt.</p>
<p><strong>4.3</strong> Avgiftens storlek anges på Evenemangets informationssida.</p>
<p><strong>4.4</strong> En administrativ avgift (serviceavgift) kan tillkomma och antingen inkluderas i eller adderas till Deltagaravgiften.</p>
<p><strong>4.5</strong> En bekräftelse skickas till angiven e-postadress när betalning har mottagits och anmälan är giltig.</p>

<h3>5. Återbetalning och ångerrätt</h3>
<p><strong>5.1</strong> Enligt lagen om distansavtal gäller inte ångerrätt för deltagande i idrottsevenemang. Återbetalning av Deltagaravgift sker endast om det anges i Arrangörens villkor.</p>
<p><strong>5.2</strong> Eventuella begäran om återbetalning ska ställas direkt till Arrangören.</p>
<p><strong>5.3</strong> Om återbetalning beviljas, sker denna till samma konto som användes vid betalningen.</p>

<h3>6. Ansvarsfördelning</h3>
<p><strong>6.1</strong> ECAB ansvarar inte för själva genomförandet av Evenemangen. Eventuella krav eller reklamationer ska riktas till Arrangören.</p>

<h3>7. Användarkonto och profil</h3>
<p><strong>7.1</strong> Registrering på GravitySeries.se ger tillgång till ett personligt konto med möjlighet att spara tävlingsresultat, anmälningar och statistik.</p>
<p><strong>7.2</strong> Vid registrering krävs att du lämnar namn, e-postadress och väljer ett lösenord.</p>
<p><strong>7.3</strong> Du ansvarar själv för riktigheten i de uppgifter du lämnar samt för att hålla dessa aktuella.</p>
<p><strong>7.4</strong> Du kan frivilligt skapa en användarprofil där du t.ex. laddar upp foton, kommenterar Evenemang och interagerar med andra användare.</p>
<p><strong>7.5</strong> Du ansvarar för allt innehåll du publicerar och försäkrar att du har rätt att dela detta material.</p>
<p><strong>7.6</strong> Publicering av olämpligt eller rättighetskränkande material är förbjudet. Du får inte heller uppträda kränkande mot andra användare.</p>
<p><strong>7.7</strong> Du behåller upphovsrätten till det material du delar, men ger ECAB en icke-exklusiv rätt att använda innehållet i enlighet med vår personuppgiftspolicy.</p>
<p><strong>7.8</strong> GravitySeries.se kan innehålla annonsering som finansierar plattformen. Genom att använda tjänsten samtycker du till att annonser visas i anslutning till ditt innehåll.</p>

<h3>8. Immateriella rättigheter</h3>
<p><strong>8.1</strong> Allt material på GravitySeries.se kan vara skyddat av upphovs- eller varumärkesrätt. Kopiering eller användning utan tillstånd är förbjudet.</p>
<p><strong>8.2</strong> Du får inte ladda upp material som gör intrång i tredje parts rättigheter.</p>

<h3>9. Personuppgifter</h3>
<p><strong>9.1</strong> Genom att godkänna dessa Villkor samtycker du även till vår personuppgiftspolicy som du finner på webbplatsen.</p>
<p><strong>9.2</strong> Vi informerar om eventuella ändringar av policyn via e-post eller genom uppdateringar på webbplatsen.</p>

<h3>10. Ansvar och begränsningar</h3>
<p><strong>10.1</strong> Om någon part grovt åsidosätter dessa Villkor kan skadestånd utgå för direkt uppkommen skada.</p>
<p><strong>10.2</strong> Tekniskt underhåll, driftstörningar eller externa händelser såsom strömavbrott utgör inte avtalsbrott. Deltagare hänvisas i sådana fall till respektive Arrangör.</p>
<p><strong>10.3</strong> ECABs eventuella ansvar är begränsat till återbetalning av Deltagaravgiften för Evenemang som Deltagaren inte kunnat delta i på grund av ECABs försummelse.</p>
<p><strong>10.4</strong> Vid brott mot Villkoren förbehåller sig ECAB rätten att stänga av användarkonton och radera innehåll.</p>

<h3>11. Force majeure</h3>
<p><strong>11.1</strong> Varken ECAB, Arrangören eller Deltagaren hålls ansvariga för händelser utanför deras kontroll, t.ex. naturkatastrofer, pandemier, myndighetsbeslut eller strejker.</p>
<p><strong>11.2</strong> Om ett Evenemang ställs in på grund av force majeure, har Deltagaren inte rätt till ersättning från ECAB eller Arrangören.</p>
'
    ],

];

// ─── Update/Insert pages ─────────────────────────────────────

$updated = 0;
$created = 0;

foreach ($pages as $p) {
    // Check if page exists
    $check = $pdo->prepare("SELECT id FROM pages WHERE slug = ? LIMIT 1");
    $check->execute([$p['slug']]);
    $existing = $check->fetch();

    if ($existing) {
        // Update existing page
        $stmt = $pdo->prepare("
            UPDATE pages
            SET title = ?, meta_description = ?, content = ?,
                nav_label = ?, nav_order = ?, show_in_nav = ?,
                status = 'published', updated_at = NOW()
            WHERE slug = ?
        ");
        $stmt->execute([
            $p['title'],
            $p['meta_description'],
            trim($p['content']),
            $p['nav_label'],
            $p['nav_order'],
            $p['show_in_nav'],
            $p['slug'],
        ]);
        $updated++;
        out("  Uppdaterad: {$p['title']} (/{$p['slug']})", $isCli);
    } else {
        // Create new page
        $stmt = $pdo->prepare("
            INSERT INTO pages (slug, title, meta_description, content, template, status, show_in_nav, nav_order, nav_label, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'default', 'published', ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $p['slug'],
            $p['title'],
            $p['meta_description'],
            trim($p['content']),
            $p['show_in_nav'],
            $p['nav_order'],
            $p['nav_label'],
        ]);
        $created++;
        out("  Skapad: {$p['title']} (/{$p['slug']})", $isCli);
    }
}

out("", $isCli);
out("Klart! {$updated} sidor uppdaterade, {$created} sidor skapade.", $isCli);

if (!$isCli) {
    echo '<p style="margin-top:20px;"><a href="/admin/pages/" style="color:#3fa84d;">Gå till sidhanteringen &rarr;</a></p>';
    echo '</body></html>';
}
