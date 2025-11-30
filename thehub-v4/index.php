<?php
// TheHUB v4 - NeoGlass Dark Shell
// Root file: /thehub-v4/index.php
?><!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>TheHUB v4 · GravitySeries</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div id="app-shell">
    <aside class="hub-sidebar">
        <div class="hub-logo">
            <div class="hub-logo-mark">GS</div>
            <div class="hub-logo-text">
                <span class="hub-logo-title">TheHUB</span>
                <span class="hub-logo-subtitle">GravitySeries</span>
            </div>
        </div>

        <nav class="hub-nav">
            <button class="hub-nav-item is-active" data-route="dashboard">
                <span class="hub-nav-icon">🏠</span>
                <span>Dashboard</span>
            </button>
            <button class="hub-nav-item" data-route="events">
                <span class="hub-nav-icon">📅</span>
                <span>Event</span>
            </button>
            <button class="hub-nav-item" data-route="riders">
                <span class="hub-nav-icon">🚴‍♀️</span>
                <span>Riders</span>
            </button>
            <button class="hub-nav-item" data-route="ranking">
                <span class="hub-nav-icon">🏆</span>
                <span>Ranking</span>
            </button>
        </nav>

        <div class="hub-sidebar-footer">
            <div class="hub-badge">v4 · PHP SPA</div>
            <div class="hub-footer-meta">
                <span>Server: PHP</span>
                <span>Frontend: Vanilla JS</span>
            </div>
        </div>
    </aside>

    <div class="hub-main">
        <header class="hub-header">
            <div>
                <h1 id="hub-page-title">Dashboard</h1>
                <p id="hub-page-subtitle">Överblick över events, åkare och ranking.</p>
            </div>
            <div class="hub-header-actions">
                <button class="hub-chip" data-theme="dark">Dark</button>
                <button class="hub-chip" data-theme="light">Light</button>
            </div>
        </header>

        <main id="hub-view-root">
            <!-- SPA-views injiceras här av app.js -->
            <div class="hub-loading">
                <div class="hub-spinner"></div>
                <p>Laddar TheHUB v4…</p>
            </div>
        </main>

        <footer class="hub-main-footer">
            <span>© <?php echo date('Y'); ?> GravitySeries / TheHUB v4</span>
            <span class="hub-main-footer-right">Data via backend/public/api/*.php</span>
        </footer>
    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
