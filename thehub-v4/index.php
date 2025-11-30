<?php
// TheHUB V4 – Dashboard SPA (API-driven)
?><!DOCTYPE html>
<html lang="sv" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>TheHUB V4 – GravitySeries</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSS (V3 design system) -->
    <link rel="stylesheet" href="/thehub-v4/assets/css/main.css?v=50">
</head>
<body>

<!-- Header -->
<header class="header">
    <div class="header-brand">
        <span>TheHUB</span>
        <span class="header-version">V4</span>
    </div>
    <div class="header-actions">
        <a href="/thehub/" class="btn btn--ghost">← Tillbaka till V3</a>
    </div>
</header>

<div class="app-layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <a class="sidebar-link" href="#" data-view="dashboard" aria-current="page">
                <span class="sidebar-icon">🏠</span>
                <span>Dashboard</span>
            </a>
            <a class="sidebar-link" href="#" data-view="calendar">
                <span class="sidebar-icon">📅</span>
                <span>Kalender</span>
            </a>
            <a class="sidebar-link" href="#" data-view="results">
                <span class="sidebar-icon">🏁</span>
                <span>Resultat</span>
            </a>
            <a class="sidebar-link" href="#" data-view="series">
                <span class="sidebar-icon">🏆</span>
                <span>Serier</span>
            </a>
            <a class="sidebar-link" href="#" data-view="database">
                <span class="sidebar-icon">🔍</span>
                <span>Databas</span>
            </a>
            <a class="sidebar-link" href="#" data-view="ranking">
                <span class="sidebar-icon">📊</span>
                <span>Ranking</span>
            </a>
        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content" id="main-content">

        <!-- DASHBOARD VIEW -->
        <section class="page-content" id="view-dashboard" data-view="dashboard">
            <!-- Quick Links -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Snabblänkar</h2>
                </div>
                <div class="flex flex-wrap gap-sm">
                    <button class="btn btn--secondary" data-jump-view="database" data-jump-tab="riders">🚴‍♂️ Åkare</button>
                    <button class="btn btn--secondary" data-jump-view="database" data-jump-tab="clubs">🏅 Klubbar</button>
                    <button class="btn btn--secondary" data-jump-view="results">🏁 Resultat</button>
                    <button class="btn btn--secondary" data-jump-view="series">🏆 Serier</button>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="card mt-md">
                <div class="card-header">
                    <h2 class="card-title">Översikt</h2>
                </div>
                <div class="stats-row">
                    <div class="stat-block">
                        <div class="stat-value" id="stat-riders-total">–</div>
                        <div class="stat-label">Åkare</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="stat-clubs-total">–</div>
                        <div class="stat-label">Klubbar</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="stat-events-total">–</div>
                        <div class="stat-label">Event</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="stat-results-total">–</div>
                        <div class="stat-label">Resultat</div>
                    </div>
                </div>
            </div>

            <!-- Active Series & Top Riders -->
            <div class="page-grid mt-md">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Aktiva serier</h2>
                        <button class="btn btn--ghost btn--sm" data-jump-view="series">Visa alla</button>
                    </div>
                    <div id="dash-series-list"></div>
                    <div id="dash-series-empty" class="text-muted text-sm">Laddar serier…</div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Mest aktiva åkare</h2>
                        <button class="btn btn--ghost btn--sm" data-jump-view="database" data-jump-tab="riders">Visa databas</button>
                    </div>
                    <div id="dash-riders-list"></div>
                    <div id="dash-riders-empty" class="text-muted text-sm">Laddar åkare…</div>
                </div>
            </div>
        </section>

        <!-- CALENDAR VIEW -->
        <section class="page-content" id="view-calendar" data-view="calendar" style="display:none;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Kalender</h2>
                    <span class="chip" id="calendar-count-badge">0 event</span>
                </div>

                <div class="flex flex-wrap gap-md mt-sm">
                    <div class="flex flex-col gap-xs">
                        <label class="text-sm text-secondary" for="cal-series-filter">Serie</label>
                        <select id="cal-series-filter" class="form-select">
                            <option value="">Alla serier</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-xs">
                        <label class="text-sm text-secondary" for="cal-year-filter">År</label>
                        <select id="cal-year-filter" class="form-select">
                            <option value="">Alla år</option>
                        </select>
                    </div>
                </div>

                <div id="calendar-status" class="text-muted text-sm mt-md">Laddar event…</div>
                <div id="calendar-list" class="mt-md"></div>
            </div>
        </section>

        <!-- RESULTS VIEW -->
        <section class="page-content" id="view-results" data-view="results" style="display:none;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Resultat</h2>
                    <span class="chip" id="results-count-badge">0 tävlingar</span>
                </div>

                <div class="flex flex-wrap gap-md mt-sm">
                    <div class="flex flex-col gap-xs">
                        <label class="text-sm text-secondary" for="res-series-filter">Serie</label>
                        <select id="res-series-filter" class="form-select">
                            <option value="">Alla serier</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-xs">
                        <label class="text-sm text-secondary" for="res-year-filter">År</label>
                        <select id="res-year-filter" class="form-select">
                            <option value="">Alla år</option>
                        </select>
                    </div>
                </div>

                <div id="results-status" class="text-muted text-sm mt-md">Laddar resultat…</div>
                <div id="results-list" class="mt-md"></div>
            </div>
        </section>

        <!-- SERIES VIEW -->
        <section class="page-content" id="view-series" data-view="series" style="display:none;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Tävlingsserier</h2>
                </div>
                <div id="series-grid" class="page-grid mt-md"></div>
                <div id="series-empty" class="text-muted text-sm">Laddar serier…</div>
            </div>
        </section>

        <!-- DATABASE VIEW -->
        <section class="page-content" id="view-database" data-view="database" style="display:none;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Databas</h2>
                </div>

                <div class="stats-row">
                    <div class="stat-block">
                        <div class="stat-value" id="db-riders-total">–</div>
                        <div class="stat-label">Åkare</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="db-clubs-total">–</div>
                        <div class="stat-label">Klubbar</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="db-results-total">–</div>
                        <div class="stat-label">Resultat</div>
                    </div>
                </div>

                <div class="flex gap-sm mt-md">
                    <button class="btn btn--secondary db-tab-active" data-db-tab="riders">👥 Åkare</button>
                    <button class="btn btn--ghost" data-db-tab="clubs">🏅 Klubbar</button>
                </div>

                <div class="mt-md">
                    <input id="db-search-input" type="text" class="form-input" placeholder="Skriv namn, klubb eller Gravity ID…" style="width:100%;max-width:400px;">
                    <div class="text-muted text-xs mt-xs">Skriv minst 2 tecken för att söka.</div>
                </div>

                <div class="page-grid mt-md">
                    <div id="db-riders-column">
                        <h3 class="text-md font-semibold mb-sm">Toppåkare</h3>
                        <div id="db-riders-list"></div>
                    </div>
                    <div id="db-clubs-column" style="display:none;">
                        <h3 class="text-md font-semibold mb-sm">Toppklubbar</h3>
                        <div id="db-clubs-list"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- RANKING VIEW -->
        <section class="page-content" id="view-ranking" data-view="ranking" style="display:none;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">GravitySeries Ranking</h2>
                    <div class="flex gap-xs">
                        <button class="btn btn--secondary btn--sm rank-disc-active" data-rank-discipline="gravity">Gravity</button>
                        <button class="btn btn--ghost btn--sm" data-rank-discipline="enduro">Enduro</button>
                        <button class="btn btn--ghost btn--sm" data-rank-discipline="downhill">Downhill</button>
                    </div>
                </div>

                <div class="flex gap-sm mt-md">
                    <button class="btn btn--secondary rank-mode-active" data-rank-mode="riders">Åkare</button>
                    <button class="btn btn--ghost" data-rank-mode="clubs">Klubbar</button>
                </div>

                <div id="ranking-status" class="text-muted text-sm mt-md">Laddar ranking…</div>
                <div id="ranking-table-wrapper" class="table-wrapper mt-md"></div>
            </div>
        </section>

    </main>
</div>

<!-- Mobile Nav (V3 component) -->
<nav class="mobile-nav">
    <div class="mobile-nav-inner">
        <a class="mobile-nav-link active" href="#" data-view="dashboard">
            <span class="mobile-nav-icon">🏠</span>
            <span>Start</span>
        </a>
        <a class="mobile-nav-link" href="#" data-view="calendar">
            <span class="mobile-nav-icon">📅</span>
            <span>Kalender</span>
        </a>
        <a class="mobile-nav-link" href="#" data-view="results">
            <span class="mobile-nav-icon">🏁</span>
            <span>Resultat</span>
        </a>
        <a class="mobile-nav-link" href="#" data-view="database">
            <span class="mobile-nav-icon">🔍</span>
            <span>Databas</span>
        </a>
    </div>
</nav>

<!-- Theme Toggle (V3 component) -->
<div class="theme-toggle">
    <button class="theme-toggle-btn" data-theme="light" aria-pressed="false">☀️</button>
    <button class="theme-toggle-btn" data-theme="dark" aria-pressed="true">🌙</button>
</div>

<!-- JS -->
<script src="/thehub-v4/assets/js/theme.js?v=50"></script>
<script src="/thehub-v4/assets/js/app.js?v=50"></script>
</body>
</html>
