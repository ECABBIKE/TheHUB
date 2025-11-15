<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'TheHUB - Hem';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">

        <!-- Hero Section -->
        <div class="gs-hero" style="background: linear-gradient(135deg, #004a98 0%, #0066cc 100%); color: white; padding: 4rem 2rem; border-radius: 12px; margin-bottom: 3rem; text-align: center;">
            <h1 class="gs-h1" style="color: white; font-size: 3rem; margin-bottom: 1rem;">
                Välkommen till TheHUB
            </h1>
            <p style="font-size: 1.5rem; opacity: 0.95; max-width: 800px; margin: 0 auto;">
                Din kompletta plattform för cykeltävlingar i Sverige
            </p>
        </div>

        <!-- About TheHUB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h2">
                    <i data-lucide="zap"></i>
                    En awesome plattform för alla
                </h2>
            </div>
            <div class="gs-card-content" style="font-size: 1.1rem; line-height: 1.8;">
                <p>
                    <strong>TheHUB</strong> är det centrala navet för alla som älskar att tävla på cykel i Sverige.
                    Här samlas cyklister, arrangörer och serier under samma tak med allt du behöver för att
                    delta, följa eller arrangera tävlingar.
                </p>
                <p>
                    Med TheHUB får du tillgång till:
                </p>
                <ul style="margin-left: 2rem; margin-top: 1rem;">
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
            <div class="gs-card-content" style="font-size: 1.1rem; line-height: 1.8;">
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

        <!-- Series Overview -->
        <div class="gs-mb-xl">
            <h2 class="gs-h2 gs-mb-lg" style="text-align: center;">
                <i data-lucide="trophy"></i>
                Våra Serier
            </h2>

            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-xl-grid-cols-4 gs-gap-lg">

                <!-- Enduro Series -->
                <div class="gs-card" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; border: none;">
                    <div class="gs-card-content" style="padding: 2rem; text-align: center;">
                        <i data-lucide="mountain" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block;"></i>
                        <h3 class="gs-h3" style="color: white; margin-bottom: 1rem;">Enduro Series</h3>
                        <p style="opacity: 0.9; margin-bottom: 1.5rem;">
                            Sveriges största enduroserie med tävlingar på de bästa banorna runt om i landet.
                        </p>
                        <a href="/series.php?discipline=enduro" class="gs-btn gs-btn-primary gs-w-full">
                            Mer info
                        </a>
                    </div>
                </div>

                <!-- Downhill Series -->
                <div class="gs-card" style="background: linear-gradient(135deg, #c21500 0%, #ffc500 100%); color: white; border: none;">
                    <div class="gs-card-content" style="padding: 2rem; text-align: center;">
                        <i data-lucide="zap" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block;"></i>
                        <h3 class="gs-h3" style="color: white; margin-bottom: 1rem;">Downhill Series</h3>
                        <p style="opacity: 0.9; margin-bottom: 1.5rem;">
                            Ren fart och teknik på de snabbaste och tuffaste nedförsbanorna.
                        </p>
                        <a href="/series.php?discipline=downhill" class="gs-btn gs-btn-primary gs-w-full">
                            Mer info
                        </a>
                    </div>
                </div>

                <!-- Dual Slalom -->
                <div class="gs-card" style="background: linear-gradient(135deg, #134e5e 0%, #71b280 100%); color: white; border: none;">
                    <div class="gs-card-content" style="padding: 2rem; text-align: center;">
                        <i data-lucide="users" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block;"></i>
                        <h3 class="gs-h3" style="color: white; margin-bottom: 1rem;">Dual Slalom</h3>
                        <p style="opacity: 0.9; margin-bottom: 1.5rem;">
                            Head-to-head racing där två cyklister kör parallella banor samtidigt.
                        </p>
                        <a href="/series.php?discipline=dual-slalom" class="gs-btn gs-btn-primary gs-w-full">
                            Mer info
                        </a>
                    </div>
                </div>

                <!-- Hillclimb -->
                <div class="gs-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                    <div class="gs-card-content" style="padding: 2rem; text-align: center;">
                        <i data-lucide="trending-up" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block;"></i>
                        <h3 class="gs-h3" style="color: white; margin-bottom: 1rem;">Hillclimb</h3>
                        <p style="opacity: 0.9; margin-bottom: 1.5rem;">
                            Utmanande uppförstävlingar som testar uthållighet och styrka.
                        </p>
                        <a href="/series.php?discipline=hillclimb" class="gs-btn gs-btn-primary gs-w-full">
                            Mer info
                        </a>
                    </div>
                </div>

                <!-- Local Series -->
                <div class="gs-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none;">
                    <div class="gs-card-content" style="padding: 2rem; text-align: center;">
                        <i data-lucide="map-pin" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block;"></i>
                        <h3 class="gs-h3" style="color: white; margin-bottom: 1rem;">Lokala Serier</h3>
                        <p style="opacity: 0.9; margin-bottom: 1.5rem;">
                            Mindre regionala serier som erbjuder fler tävlingsmöjligheter lokalt.
                        </p>
                        <a href="/series.php?type=local" class="gs-btn gs-btn-primary gs-w-full">
                            Mer info
                        </a>
                    </div>
                </div>

                <!-- SweCup -->
                <div class="gs-card" style="background: linear-gradient(135deg, #005aa7 0%, #fffde4 100%); color: #1a1a1a; border: none;">
                    <div class="gs-card-content" style="padding: 2rem; text-align: center;">
                        <i data-lucide="award" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block; color: #005aa7;"></i>
                        <h3 class="gs-h3" style="color: #1a1a1a; margin-bottom: 1rem;">SweCup</h3>
                        <p style="margin-bottom: 1.5rem;">
                            Svenska Cykelförbundets officiella tävlingsserier med nationella mästerskap.
                        </p>
                        <a href="/series.php?type=swecup" class="gs-btn" style="background: #005aa7; color: white; width: 100%;">
                            Mer info
                        </a>
                    </div>
                </div>

            </div>
        </div>

        <!-- Quick Links -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h2">
                    <i data-lucide="compass"></i>
                    Kom igång
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-md">

                    <a href="/events.php" class="gs-card gs-card-hover" style="text-decoration: none;">
                        <div class="gs-card-content gs-text-center" style="padding: 2rem;">
                            <i data-lucide="calendar" style="width: 48px; height: 48px; margin: 0 auto 1rem; display: block; color: #004a98;"></i>
                            <h4 class="gs-h4">Kommande Events</h4>
                            <p class="gs-text-secondary gs-mt-sm">Se alla tävlingar i kalendern</p>
                        </div>
                    </a>

                    <a href="/riders.php" class="gs-card gs-card-hover" style="text-decoration: none;">
                        <div class="gs-card-content gs-text-center" style="padding: 2rem;">
                            <i data-lucide="users" style="width: 48px; height: 48px; margin: 0 auto 1rem; display: block; color: #004a98;"></i>
                            <h4 class="gs-h4">Deltagare</h4>
                            <p class="gs-text-secondary gs-mt-sm">Sök efter cyklister och klubbar</p>
                        </div>
                    </a>

                    <a href="/series.php" class="gs-card gs-card-hover" style="text-decoration: none;">
                        <div class="gs-card-content gs-text-center" style="padding: 2rem;">
                            <i data-lucide="trophy" style="width: 48px; height: 48px; margin: 0 auto 1rem; display: block; color: #004a98;"></i>
                            <h4 class="gs-h4">Poänglistor</h4>
                            <p class="gs-text-secondary gs-mt-sm">Se ställningen i alla serier</p>
                        </div>
                    </a>

                </div>
            </div>
        </div>

    </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
