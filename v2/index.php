<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// V3.5 Migration: Block access for non-admin users
if (!hasRole('admin')) {
    ?>
    <!DOCTYPE html>
    <html lang="sv">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TheHUB - Work In Progress</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Inter', system-ui, sans-serif;
                background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #f8fafc;
            }
            .container {
                text-align: center;
                padding: 3rem;
                max-width: 600px;
            }
            .logo {
                width: 100%;
                max-width: 350px;
                margin-bottom: 2rem;
                opacity: 0.9;
            }
            h1 {
                font-size: 2.5rem;
                font-weight: 700;
                margin-bottom: 1rem;
                background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
            }
            .badge {
                display: inline-block;
                padding: 0.5rem 1.5rem;
                background: rgba(251, 191, 36, 0.15);
                border: 1px solid rgba(251, 191, 36, 0.3);
                border-radius: 9999px;
                color: #fbbf24;
                font-weight: 600;
                font-size: 0.875rem;
                text-transform: uppercase;
                letter-spacing: 0.1em;
                margin-bottom: 1.5rem;
            }
            p {
                font-size: 1.125rem;
                color: #94a3b8;
                line-height: 1.7;
                margin-bottom: 2rem;
            }
            .version {
                font-size: 0.875rem;
                color: #475569;
            }
            .admin-link {
                display: inline-block;
                margin-top: 2rem;
                padding: 0.75rem 2rem;
                background: rgba(59, 130, 246, 0.1);
                border: 1px solid rgba(59, 130, 246, 0.3);
                border-radius: 0.5rem;
                color: #60a5fa;
                text-decoration: none;
                font-weight: 500;
                transition: all 0.2s;
            }
            .admin-link:hover {
                background: rgba(59, 130, 246, 0.2);
                border-color: rgba(59, 130, 246, 0.5);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <img src="http://gravityseries.se/wp-content/uploads/2024/03/Gravity-Series-White.png"
                 alt="Gravity Series" class="logo">
            <div class="badge">Work In Progress</div>
            <h1>TheHUB v3.5</h1>
            <p>
                Vi bygger om TheHUB till en ny och förbättrad version.
                Plattformen är tillfälligt stängd för allmänheten medan vi färdigställer uppgraderingen.
            </p>
            <p class="version">Återkommer snart med nya funktioner!</p>
            <a href="/admin/login.php" class="admin-link">Admin Login</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$pageTitle = 'TheHUB - Hem';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="main-content">
    <div class="page-content">

        <!-- Hero Section -->
        <div class="hero text-center">
            <img src="http://gravityseries.se/wp-content/uploads/2024/03/Gravity-Series-White.png"
                 alt="Gravity Series"
                 style="width: 100%; max-width: 600px; margin-bottom: 1rem;">
            <h1 class="hero-title">
                TheHUB
            </h1>
        </div>

        <!-- About TheHUB -->
        <div class="card mb-lg">
            <div class="card-header">
                <h2>
                    <i data-lucide="zap"></i>
                    En awesome plattform för alla
                </h2>
            </div>
            <div class="card-body">
                <p>
                    <strong>TheHUB</strong> är det centrala navet för alla som älskar att tävla på cykel i Sverige.
                    Här samlas cyklister, arrangörer och serier under samma tak med allt du behöver för att
                    delta, följa eller arrangera tävlingar.
                </p>
                <p>
                    Med TheHUB får du tillgång till:
                </p>
                <ul class="list-styled">
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
        <div class="card mb-lg">
            <div class="card-header">
                <h2>
                    <i data-lucide="mountain"></i>
                    Om Gravity Series
                </h2>
            </div>
            <div class="card-body">
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
