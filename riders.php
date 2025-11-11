<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Deltagare';
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
                <i data-lucide="users"></i>
                Deltagare
            </h1>

            <!-- Info Alert -->
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <div>
                    <strong>Demo-läge</strong><br>
                    Denna sida visar demo-data. Anslut databasen för att se riktiga deltagare.
                </div>
            </div>

            <!-- Search -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <div class="gs-input-group">
                        <i data-lucide="search"></i>
                        <input
                            type="text"
                            class="gs-input"
                            placeholder="Sök efter namn, klubb eller licensnummer..."
                        >
                    </div>
                </div>
            </div>

            <!-- Riders Table -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="list"></i>
                        Demo-deltagare
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th>Kategori</th>
                                    <th>Licens</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Erik Andersson</strong></td>
                                    <td class="gs-text-secondary">Team GravitySeries</td>
                                    <td><span class="gs-badge gs-badge-primary">Elite Herr</span></td>
                                    <td class="gs-text-secondary" style="font-family: monospace;">SWE-2025-1234</td>
                                </tr>
                                <tr>
                                    <td><strong>Anna Karlsson</strong></td>
                                    <td class="gs-text-secondary">CK Olympia</td>
                                    <td><span class="gs-badge gs-badge-accent">Elite Dam</span></td>
                                    <td class="gs-text-secondary" style="font-family: monospace;">SWE-2025-2345</td>
                                </tr>
                                <tr>
                                    <td><strong>Johan Svensson</strong></td>
                                    <td class="gs-text-secondary">Uppsala CK</td>
                                    <td><span class="gs-badge gs-badge-primary">Elite Herr</span></td>
                                    <td class="gs-text-secondary" style="font-family: monospace;">SWE-2025-3456</td>
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
