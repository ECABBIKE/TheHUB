<?php
// TheHUB V4 – Dashboard SPA (API-driven)
?><!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>TheHUB V4 – GravitySeries</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSS (ligger på rätt path) -->
    <link rel="stylesheet" href="/thehub-v4/assets/css/app.css?v=40">
</head>
<body class="hub theme-dark">

<div class="hub-shell">
    <!-- SIDEBAR -->
    <aside class="hub-sidebar">
        <div class="hub-sidebar-header">
            <div class="hub-logo-circle">HUB</div>
            <div class="hub-logo-text">
                <div class="hub-logo-title">TheHUB</div>
                <div class="hub-logo-sub">GravitySeries</div>
            </div>
        </div>

        <nav class="hub-nav">
            <button class="hub-nav-item is-active" data-view-target="dashboard">
                <span class="hub-nav-icon">🏠</span>
                <span>Dashboard</span>
            </button>
            <button class="hub-nav-item" data-view-target="calendar">
                <span class="hub-nav-icon">📅</span>
                <span>Kalender</span>
            </button>
            <button class="hub-nav-item" data-view-target="results">
                <span class="hub-nav-icon">🏁</span>
                <span>Resultat</span>
            </button>
            <button class="hub-nav-item" data-view-target="series">
                <span class="hub-nav-icon">🏆</span>
                <span>Serier</span>
            </button>
            <button class="hub-nav-item" data-view-target="database">
                <span class="hub-nav-icon">🔍</span>
                <span>Databas</span>
            </button>
            <button class="hub-nav-item" data-view-target="ranking">
                <span class="hub-nav-icon">📊</span>
                <span>Ranking</span>
            </button>
        </nav>

        <div class="hub-sidebar-footer">
            <div class="hub-sidebar-meta">V4 · API-driven</div>
            <button class="hub-theme-toggle" id="theme-toggle" type="button">
                <span class="hub-theme-icon" data-theme="light">☀️</span>
                <span class="hub-theme-icon" data-theme="dark">🌙</span>
            </button>
        </div>
    </aside>

    <!-- MAIN AREA -->
    <div class="hub-main">
        <!-- TOPBAR -->
        <header class="hub-topbar">
            <div class="hub-topbar-left">
                <button class="hub-back-link" type="button" onclick="window.location.href='/thehub/';">
                    ← Tillbaka
                </button>
                <div class="hub-breadcrumb">TheHUB V4</div>
                <h1 class="hub-page-title" id="hub-page-title">Dashboard</h1>
            </div>
            <div class="hub-topbar-right">
                <div class="hub-user-pill">
                    <span class="hub-user-name">Admin</span>
                    <span class="hub-user-avatar">A</span>
                </div>
            </div>
        </header>

        <!-- VIEWS WRAPPER -->
        <main class="hub-main-inner">

            <!-- DASHBOARD VIEW -->
            <section class="hub-view hub-view-active" id="view-dashboard" data-view="dashboard">
                <!-- Snabblänkar -->
                <div class="hub-card">
                    <div class="hub-card-header">
                        <h2>Snabblänkar</h2>
                    </div>
                    <div class="hub-quick-links">
                        <button class="hub-pill-button" data-jump-view="database" data-jump-tab="riders">🚴‍♂️ Åkare</button>
                        <button class="hub-pill-button" data-jump-view="database" data-jump-tab="clubs">🏅 Klubbar</button>
                        <button class="hub-pill-button" data-jump-view="results">🏁 Resultat</button>
                        <button class="hub-pill-button" data-jump-view="series">🏆 Serier</button>
                    </div>
                </div>

                <!-- Översikt -->
                <div class="hub-card hub-grid-4">
                    <div class="hub-stat-tile">
                        <div class="hub-stat-label">Åkare</div>
                        <div class="hub-stat-value" id="stat-riders-total">–</div>
                        <div class="hub-stat-sub">Aktiva i databasen</div>
                    </div>
                    <div class="hub-stat-tile">
                        <div class="hub-stat-label">Klubbar</div>
                        <div class="hub-stat-value" id="stat-clubs-total">–</div>
                        <div class="hub-stat-sub">Unika klubbar</div>
                    </div>
                    <div class="hub-stat-tile">
                        <div class="hub-stat-label">Event</div>
                        <div class="hub-stat-value" id="stat-events-total">–</div>
                        <div class="hub-stat-sub">Tävlingsdagar</div>
                    </div>
                    <div class="hub-stat-tile">
                        <div class="hub-stat-label">Resultat</div>
                        <div class="hub-stat-value" id="stat-results-total">–</div>
                        <div class="hub-stat-sub">Registrerade resultat</div>
                    </div>
                </div>

                <!-- Aktiva serier & Mest aktiva åkare -->
                <div class="hub-grid-2">
                    <div class="hub-card">
                        <div class="hub-card-header">
                            <h2>Aktiva serier</h2>
                            <button class="hub-link-button" data-jump-view="series">Visa alla</button>
                        </div>
                        <div id="dash-series-list" class="hub-list"></div>
                        <div id="dash-series-empty" class="hub-empty">Laddar serier…</div>
                    </div>

                    <div class="hub-card">
                        <div class="hub-card-header">
                            <h2>Mest aktiva åkare</h2>
                            <button class="hub-link-button" data-jump-view="database" data-jump-tab="riders">Visa databas</button>
                        </div>
                        <div id="dash-riders-list" class="hub-list"></div>
                        <div id="dash-riders-empty" class="hub-empty">Laddar åkare…</div>
                    </div>
                </div>
            </section>

            <!-- KALENDER VIEW -->
            <section class="hub-view" id="view-calendar" data-view="calendar">
                <div class="hub-card">
                    <div class="hub-card-header">
                        <h2>Kalender</h2>
                        <span class="hub-badge" id="calendar-count-badge">0 event</span>
                    </div>

                    <div class="hub-filters">
                        <div class="hub-filter-group">
                            <label for="cal-series-filter">Serie</label>
                            <select id="cal-series-filter">
                                <option value="">Alla serier</option>
                            </select>
                        </div>
                        <div class="hub-filter-group">
                            <label for="cal-year-filter">År</label>
                            <select id="cal-year-filter">
                                <option value="">Alla år</option>
                            </select>
                        </div>
                    </div>

                    <div id="calendar-status" class="hub-status-text">Laddar event…</div>
                    <div id="calendar-list" class="hub-event-list"></div>
                </div>
            </section>

            <!-- RESULTAT VIEW -->
            <section class="hub-view" id="view-results" data-view="results">
                <div class="hub-card">
                    <div class="hub-card-header">
                        <h2>Resultat</h2>
                        <span class="hub-badge" id="results-count-badge">0 tävlingar</span>
                    </div>

                    <div class="hub-filters">
                        <div class="hub-filter-group">
                            <label for="res-series-filter">Serie</label>
                            <select id="res-series-filter">
                                <option value="">Alla serier</option>
                            </select>
                        </div>
                        <div class="hub-filter-group">
                            <label for="res-year-filter">År</label>
                            <select id="res-year-filter">
                                <option value="">Alla år</option>
                            </select>
                        </div>
                    </div>

                    <div id="results-status" class="hub-status-text">Laddar resultat…</div>
                    <div id="results-list" class="hub-event-list"></div>
                </div>
            </section>

            <!-- SERIER VIEW -->
            <section class="hub-view" id="view-series" data-view="series">
                <div class="hub-card">
                    <div class="hub-card-header">
                        <h2>Tävlingsserier</h2>
                    </div>
                    <div id="series-grid" class="hub-series-grid"></div>
                    <div id="series-empty" class="hub-empty">Laddar serier…</div>
                </div>
            </section>

            <!-- DATABAS VIEW -->
            <section class="hub-view" id="view-database" data-view="database">
                <div class="hub-card">
                    <div class="hub-card-header">
                        <h2>Databas</h2>
                    </div>

                    <div class="hub-card hub-subcard-grid">
                        <div class="hub-stat-tile">
                            <div class="hub-stat-label">Åkare</div>
                            <div class="hub-stat-value" id="db-riders-total">–</div>
                        </div>
                        <div class="hub-stat-tile">
                            <div class="hub-stat-label">Klubbar</div>
                            <div class="hub-stat-value" id="db-clubs-total">–</div>
                        </div>
                        <div class="hub-stat-tile">
                            <div class="hub-stat-label">Resultat</div>
                            <div class="hub-stat-value" id="db-results-total">–</div>
                        </div>
                    </div>

                    <div class="hub-db-toggle">
                        <button class="hub-tab-button hub-tab-active" data-db-tab="riders">👥 Åkare</button>
                        <button class="hub-tab-button" data-db-tab="clubs">🏅 Klubbar</button>
                    </div>

                    <div class="hub-filter-row">
                        <input id="db-search-input" type="text" placeholder="Skriv namn, klubb eller Gravity ID…">
                        <div class="hub-help-text">Skriv minst 2 tecken för att söka.</div>
                    </div>

                    <div class="hub-db-columns">
                        <div class="hub-db-column" id="db-riders-column">
                            <h3>Toppåkare</h3>
                            <div id="db-riders-list" class="hub-list"></div>
                        </div>
                        <div class="hub-db-column" id="db-clubs-column">
                            <h3>Toppklubbar</h3>
                            <div id="db-clubs-list" class="hub-list"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- RANKING VIEW -->
            <section class="hub-view" id="view-ranking" data-view="ranking">
                <div class="hub-card">
                    <div class="hub-card-header">
                        <h2>GravitySeries Ranking</h2>
                        <div class="hub-ranking-tabs">
                            <button class="hub-tab-button hub-tab-active" data-rank-discipline="gravity">Gravity</button>
                            <button class="hub-tab-button" data-rank-discipline="enduro">Enduro</button>
                            <button class="hub-tab-button" data-rank-discipline="downhill">Downhill</button>
                        </div>
                    </div>

                    <div class="hub-ranking-toggle">
                        <button class="hub-pill-button hub-pill-active" data-rank-mode="riders">Åkare</button>
                        <button class="hub-pill-button" data-rank-mode="clubs">Klubbar</button>
                    </div>

                    <div id="ranking-status" class="hub-status-text">Ranking­systemet är inte konfigurerat än. (Placeholder)</div>
                    <div id="ranking-table-wrapper" class="hub-ranking-table-wrapper"></div>
                </div>
            </section>

        </main>
    </div>
</div>

<!-- JS -->
<script src="/thehub-v4/assets/js/app.js?v=40"></script>
</body>
</html>
