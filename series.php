<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Serier';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button id="mobile-menu-toggle" class="gs-mobile-menu-toggle">
        <i data-lucide="menu"></i>
        <span>Meny</span>
    </button>

    <!-- Mobile Overlay -->
    <div id="mobile-overlay" class="gs-mobile-overlay"></div>

    <?php include __DIR__ . '/includes/navigation.php'; ?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <h1 class="gs-h1 gs-text-primary gs-mb-lg">
                <i data-lucide="trophy"></i>
                Tävlingsserier
            </h1>

            <!-- Info Alert -->
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <div>
                    <strong>Demo-läge</strong><br>
                    Denna sida visar demo-data. Anslut databasen för att se riktiga serier och ställningar.
                </div>
            </div>

            <!-- Series Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                <?php
                // Demo series
                $demo_series = [
                    [
                        'name' => 'GravitySeries 2025',
                        'type' => 'XC',
                        'events' => 6,
                        'status' => 'active',
                        'description' => 'Sveriges största XC-serie med 6 deltävlingar runt om i landet.'
                    ],
                    [
                        'name' => 'Svenska Cupen MTB',
                        'type' => 'XC',
                        'events' => 8,
                        'status' => 'active',
                        'description' => 'Officiell svensk cupserien i mountainbike cross-country.'
                    ],
                    [
                        'name' => 'Vasaloppet Cycling',
                        'type' => 'Landsväg',
                        'events' => 4,
                        'status' => 'active',
                        'description' => 'Klassiska långlopp på landsväg i Vasaloppets anda.'
                    ],
                    [
                        'name' => 'Regionscupen Öst',
                        'type' => 'XC',
                        'events' => 5,
                        'status' => 'active',
                        'description' => 'Regional serie för östra Sverige med fokus på breddsatsning.'
                    ],
                ];

                foreach ($demo_series as $series):
                ?>
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="award"></i>
                            <?= h($series['name']) ?>
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-mb-md">
                            <span class="gs-badge gs-badge-primary">
                                <i data-lucide="flag"></i>
                                <?= h($series['type']) ?>
                            </span>
                            <span class="gs-badge gs-badge-success">
                                <i data-lucide="check-circle"></i>
                                <?= h(ucfirst($series['status'])) ?>
                            </span>
                        </div>
                        <p class="gs-text-secondary gs-mb-md">
                            <?= h($series['description']) ?>
                        </p>
                        <div class="gs-flex gs-items-center gs-justify-between gs-mb-md">
                            <span class="gs-text-secondary gs-text-sm">
                                <i data-lucide="calendar"></i>
                                <?= $series['events'] ?> deltävlingar
                            </span>
                        </div>
                        <div class="gs-flex gs-gap-sm">
                            <a href="#" class="gs-btn gs-btn-sm gs-btn-primary gs-flex-1">
                                <i data-lucide="trophy"></i>
                                Ställning
                            </a>
                            <a href="#" class="gs-btn gs-btn-sm gs-btn-outline gs-flex-1">
                                <i data-lucide="info"></i>
                                Detaljer
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Standings Example -->
            <div class="gs-card gs-mt-xl">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="bar-chart"></i>
                        Exempel: GravitySeries 2025 - Ställning
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">Plac</th>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th>Kategori</th>
                                    <th class="gs-text-center">Tävlingar</th>
                                    <th class="gs-text-right">Poäng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="gs-podium-1">
                                    <td style="font-weight: 700;"><span class="gs-text-warning">1</span></td>
                                    <td><strong>Erik Andersson</strong></td>
                                    <td class="gs-text-secondary">Team GravitySeries</td>
                                    <td><span class="gs-badge gs-badge-primary gs-text-xs">Elite Herr</span></td>
                                    <td class="gs-text-center">6/6</td>
                                    <td class="gs-text-right"><strong class="gs-text-primary">580</strong></td>
                                </tr>
                                <tr class="gs-podium-2">
                                    <td style="font-weight: 700;"><span class="gs-text-secondary">2</span></td>
                                    <td><strong>Anna Karlsson</strong></td>
                                    <td class="gs-text-secondary">CK Olympia</td>
                                    <td><span class="gs-badge gs-badge-accent gs-text-xs">Elite Dam</span></td>
                                    <td class="gs-text-center">6/6</td>
                                    <td class="gs-text-right"><strong class="gs-text-primary">545</strong></td>
                                </tr>
                                <tr class="gs-podium-3">
                                    <td style="font-weight: 700;"><span class="gs-text-accent">3</span></td>
                                    <td><strong>Johan Svensson</strong></td>
                                    <td class="gs-text-secondary">Uppsala CK</td>
                                    <td><span class="gs-badge gs-badge-primary gs-text-xs">Elite Herr</span></td>
                                    <td class="gs-text-center">5/6</td>
                                    <td class="gs-text-right"><strong class="gs-text-primary">490</strong></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 700;">4</td>
                                    <td><strong>Maria Lindström</strong></td>
                                    <td class="gs-text-secondary">Team Sportson</td>
                                    <td><span class="gs-badge gs-badge-accent gs-text-xs">Elite Dam</span></td>
                                    <td class="gs-text-center">6/6</td>
                                    <td class="gs-text-right">475</td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 700;">5</td>
                                    <td><strong>Peter Nilsson</strong></td>
                                    <td class="gs-text-secondary">IFK Göteborg CK</td>
                                    <td><span class="gs-badge gs-badge-primary gs-text-xs">Elite Herr</span></td>
                                    <td class="gs-text-center">5/6</td>
                                    <td class="gs-text-right">460</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- TheHUB JavaScript -->
    <script src="/assets/thehub.js"></script>
</body>
</html>
