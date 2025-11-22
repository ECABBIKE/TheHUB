<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'TheHUB - Hem';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">

        <!-- Hero Section -->
        <div class="gs-hero gs-text-center">
            <img src="http://gravityseries.se/wp-content/uploads/2024/03/Gravity-Series-White.png"
                 alt="Gravity Series"
                 style="width: 100%; max-width: 600px; margin-bottom: 1rem;">
            <h1 class="gs-h1 gs-hero-title-white">
                TheHUB
            </h1>
        </div>

        <!-- About TheHUB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h2">
                    <i data-lucide="zap"></i>
                    En awesome plattform för alla
                </h2>
            </div>
            <div class="gs-card-content gs-card-content-large">
                <p>
                    <strong>TheHUB</strong> är det centrala navet för alla som älskar att tävla på cykel i Sverige.
                    Här samlas cyklister, arrangörer och serier under samma tak med allt du behöver för att
                    delta, följa eller arrangera tävlingar.
                </p>
                <p>
                    Med TheHUB får du tillgång till:
                </p>
                <ul class="gs-list-styled">
                    <li><strong>Live-resultat</strong> från alla stora serier</li>
                    <li><strong>Detaljerade poänglistor</strong> över hela säsongen</li>
                    <li><strong>Enkel anmälan</strong> till kommande events</li>
                    <li><strong>Komplett kalender</strong> med alla tävlingar</li>
                    <li><strong>Personliga profiler</strong> för alla cyklister</li>
                    <li><strong>Klubb- och lagstatistik</strong></li>
                </ul>
            </div>
        </div>

        <!-- About Gravity Series -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h2">
                    <i data-lucide="mountain"></i>
                    Om Gravity Series
                </h2>
            </div>
            <div class="gs-card-content gs-card-content-large">
                <p>
                    <strong>Gravity Series</strong> är en fristående tävlingsorganisation som kompletterar
                    Svenska Cykelförbundets SweCup-tävlingar. Huvudfokus ligger på disciplinerna
                    <strong>Enduro</strong> och <strong>Downhill</strong>, men våra eventhelger inkluderar
                    även spännande tävlingsformat som <strong>Dual Slalom</strong> och <strong>Hillclimb</strong>,
                    vilket skapar en bred och dynamisk upplevelse för både deltagare och publik.
                </p>

                <p>
                    Vi har snabbt etablerat oss som den självklara mötesplatsen för såväl
                    <strong>nybörjare</strong>, <strong>motionärer</strong> eller <strong>proffs</strong>.
                    Vår ständiga strävan är att ta sporten till en ny nivå i Sverige genom att leverera
                    professionella och inspirerande cykelevent.
                </p>

                <p>
                    Till <strong>2025</strong> ser vi fram emot att kunna öppna upp för än fler nya mindre
                    lokala serier runt om hela Sverige. Allt samlat under samma tak med målet att erbjuda
                    fler alternativ för de som gillar eller vill prova på att tävla utför.
                </p>
            </div>
        </div>

    </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
