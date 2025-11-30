<?php
// TheHUB V4 – Dashboard SPA (API-driven)
?><!DOCTYPE html>
<html lang="sv" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>TheHUB V4 – GravitySeries</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- GravitySeries Branding Fonts -->
    <link rel="stylesheet" href="https://gravityseries.se/branding/fonts.css">

    <!-- V3 CSS System -->
    <link rel="stylesheet" href="/thehub-v4/assets/css/main.css?v=55">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<!-- Skip Link (Accessibility) -->
<a href="#main-content" class="skip-link">Hoppa till huvudinnehåll</a>

<!-- Header -->
<header class="header">
    <div class="header-brand">
        <a href="/thehub/" class="back-link">
            <i data-lucide="arrow-left"></i>
            <span>Tillbaka</span>
        </a>
        <span style="margin-left: var(--space-md);">TheHUB V4</span>
    </div>
    <div class="header-actions">
        <span class="header-version">V4 Beta</span>
        <a href="/admin/" class="btn btn--ghost btn--sm" id="admin-link" style="margin-left: var(--space-sm);">
            <i data-lucide="settings"></i>
            <span>Admin</span>
        </a>
        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--color-accent); color: white; display: flex; align-items: center; justify-content: center; font-weight: var(--weight-semibold); margin-left: var(--space-sm);">A</div>
    </div>
</header>

<div class="app-layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <a class="sidebar-link" href="#" data-view="dashboard" aria-current="page">
                <span class="sidebar-icon"><i data-lucide="home"></i></span>
                <span>Dashboard</span>
            </a>
            <a class="sidebar-link" href="#" data-view="calendar">
                <span class="sidebar-icon"><i data-lucide="calendar"></i></span>
                <span>Kalender</span>
            </a>
            <a class="sidebar-link" href="#" data-view="results">
                <span class="sidebar-icon"><i data-lucide="flag"></i></span>
                <span>Resultat</span>
            </a>
            <a class="sidebar-link" href="#" data-view="series">
                <span class="sidebar-icon"><i data-lucide="trophy"></i></span>
                <span>Serier</span>
            </a>
            <a class="sidebar-link" href="#" data-view="database">
                <span class="sidebar-icon"><i data-lucide="search"></i></span>
                <span>Databas</span>
            </a>
            <a class="sidebar-link" href="#" data-view="ranking">
                <span class="sidebar-icon"><i data-lucide="trending-up"></i></span>
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
                    <button class="btn btn--primary" data-jump-view="database" data-jump-tab="riders">
                        <i data-lucide="users"></i> Åkare
                    </button>
                    <button class="btn btn--primary" data-jump-view="database" data-jump-tab="clubs">
                        <i data-lucide="shield"></i> Klubbar
                    </button>
                    <button class="btn btn--primary" data-jump-view="results">
                        <i data-lucide="flag"></i> Resultat
                    </button>
                    <button class="btn btn--primary" data-jump-view="series">
                        <i data-lucide="trophy"></i> Serier
                    </button>
                </div>
            </div>

            <!-- Series Badges Showcase -->
            <div class="card mt-md">
                <div class="card-header">
                    <h2 class="card-title">Tävlingsserier</h2>
                </div>
                <div class="flex flex-wrap gap-sm">
                    <span class="series-badge series-badge--enduro">Enduro</span>
                    <span class="series-badge series-badge--downhill">Downhill</span>
                    <span class="series-badge series-badge--xc">XC</span>
                    <span class="series-badge series-badge--gravel">Gravel</span>
                    <span class="series-badge series-badge--ges">GES</span>
                    <span class="series-badge series-badge--ggs">GGS</span>
                    <span class="series-badge series-badge--gss">GSS</span>
                    <span class="series-badge series-badge--dual">Dual</span>
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
                        <div class="stat-label">ÅKARE</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="stat-clubs-total">–</div>
                        <div class="stat-label">KLUBBAR</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="stat-events-total">–</div>
                        <div class="stat-label">EVENT</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="stat-results-total">–</div>
                        <div class="stat-label">RESULTAT</div>
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
                    <h2 class="card-title">
                        <i data-lucide="calendar" class="icon-sm"></i> Kalender
                    </h2>
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
                    <div class="flex flex-col gap-xs">
                        <label class="text-sm text-secondary" for="cal-discipline-filter">Disciplin</label>
                        <select id="cal-discipline-filter" class="form-select">
                            <option value="">Alla discipliner</option>
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
                    <h2 class="card-title">
                        <i data-lucide="flag" class="icon-sm"></i> Resultat
                    </h2>
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
                    <h2 class="card-title">
                        <i data-lucide="trophy" class="icon-sm"></i> Tävlingsserier
                    </h2>
                </div>
                <div id="series-grid" class="page-grid mt-md"></div>
                <div id="series-empty" class="text-muted text-sm">Laddar serier…</div>
            </div>
        </section>

        <!-- DATABASE VIEW -->
        <section class="page-content" id="view-database" data-view="database" style="display:none;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i data-lucide="database" class="icon-sm"></i> Databas
                    </h2>
                </div>

                <div class="stats-row">
                    <div class="stat-block">
                        <div class="stat-value" id="db-riders-total">–</div>
                        <div class="stat-label">ÅKARE</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="db-clubs-total">–</div>
                        <div class="stat-label">KLUBBAR</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="db-results-total">–</div>
                        <div class="stat-label">RESULTAT</div>
                    </div>
                </div>

                <div class="flex gap-sm mt-md">
                    <button class="btn btn--secondary db-tab-active" data-db-tab="riders">
                        <i data-lucide="users"></i> Åkare
                    </button>
                    <button class="btn btn--ghost" data-db-tab="clubs">
                        <i data-lucide="shield"></i> Klubbar
                    </button>
                </div>

                <div class="mt-md">
                    <div class="flex gap-sm items-center">
                        <i data-lucide="search" class="text-muted"></i>
                        <input id="db-search-input" type="text" class="form-input" placeholder="Skriv namn, klubb eller Gravity ID…" style="flex:1;max-width:400px;">
                    </div>
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
                    <h2 class="card-title">
                        <i data-lucide="trending-up" class="icon-sm"></i> GravitySeries Ranking
                    </h2>
                    <div class="flex gap-xs">
                        <button class="btn btn--secondary btn--sm rank-disc-active" data-rank-discipline="gravity">Gravity</button>
                        <button class="btn btn--ghost btn--sm" data-rank-discipline="enduro">Enduro</button>
                        <button class="btn btn--ghost btn--sm" data-rank-discipline="downhill">Downhill</button>
                    </div>
                </div>

                <div class="flex gap-sm mt-md">
                    <button class="btn btn--secondary rank-mode-active" data-rank-mode="riders">
                        <i data-lucide="users"></i> Åkare
                    </button>
                    <button class="btn btn--ghost" data-rank-mode="clubs">
                        <i data-lucide="shield"></i> Klubbar
                    </button>
                </div>

                <div class="mt-md" style="background: var(--color-accent-light); border-left: 3px solid var(--color-accent); padding: var(--space-md); border-radius: var(--radius-md);">
                    <div style="font-size: var(--text-xs); color: var(--color-text-secondary); display: flex; align-items: center; gap: var(--space-xs);">
                        <i data-lucide="info" style="width: 14px; height: 14px;"></i>
                        24 månaders rullande ranking. Poäng viktas efter fältstorlek och eventtyp.
                    </div>
                </div>

                <div id="ranking-status" class="text-muted text-sm mt-md">Laddar ranking…</div>
                <div id="ranking-table-wrapper" class="table-wrapper mt-md"></div>
            </div>
        </section>

        <!-- RIDER PROFILE VIEW -->
        <section class="page-content" id="view-rider" data-view="rider" style="display:none;">
            <!-- Back Navigation -->
            <div style="margin-bottom: var(--space-lg);">
                <button class="btn btn--ghost" onclick="history.back()">
                    <i data-lucide="arrow-left"></i> Tillbaka
                </button>
            </div>

            <!-- Rider Header -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-lg); flex-wrap: wrap; gap: var(--space-md);">
                    <div style="display: flex; gap: var(--space-lg); align-items: start;">
                        <!-- Avatar -->
                        <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--color-accent-light); color: var(--color-accent-text); display: flex; align-items: center; justify-content: center; font-size: var(--text-2xl); font-weight: var(--weight-bold); flex-shrink: 0;">
                            <span id="rider-avatar">–</span>
                        </div>

                        <!-- Info -->
                        <div>
                            <h1 id="rider-name" style="margin: 0; font-size: var(--text-2xl); font-weight: var(--weight-bold);">Laddar...</h1>
                            <div id="rider-club" style="font-size: var(--text-base); color: var(--color-text-secondary); margin-top: var(--space-2xs);"></div>
                            <div id="rider-meta" style="display: flex; gap: var(--space-md); margin-top: var(--space-xs); font-size: var(--text-sm); color: var(--color-text-tertiary); flex-wrap: wrap;"></div>
                        </div>
                    </div>

                    <!-- Ranking Badge -->
                    <div id="rider-ranking-badge" style="background: var(--color-success); color: white; border-radius: var(--radius-md); padding: var(--space-md); text-align: center; min-width: 80px;">
                        <div style="font-size: var(--text-xs); opacity: 0.9;">RANKING</div>
                        <div style="font-size: var(--text-3xl); font-weight: var(--weight-bold);">#–</div>
                    </div>
                </div>

                <!-- Stats Tiles -->
                <div class="stats-row">
                    <div class="stat-block">
                        <div class="stat-value" id="rider-stat-starts">–</div>
                        <div class="stat-label">STARTER</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="rider-stat-completed">–</div>
                        <div class="stat-label">FULLFÖLJT</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="rider-stat-wins">–</div>
                        <div class="stat-label">SEGRAR</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="rider-stat-podiums">–</div>
                        <div class="stat-label">PALLPLATSER</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div style="display: flex; gap: var(--space-xs); margin: var(--space-lg) 0; border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-xs);">
                <button class="btn btn--ghost rider-tab-btn active" data-tab="results">Resultat</button>
                <button class="btn btn--ghost rider-tab-btn" data-tab="ranking">Ranking</button>
                <button class="btn btn--ghost rider-tab-btn" data-tab="stats">Statistik</button>
            </div>

            <!-- Tab Content -->
            <div id="rider-tab-results" class="rider-tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Resultathistorik</h2>
                    </div>
                    <div id="rider-results-list"></div>
                </div>
            </div>

            <div id="rider-tab-ranking" class="rider-tab-content" style="display:none">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Ranking Utveckling</h2>
                    </div>
                    <div id="rider-ranking-chart" class="placeholder">Rankinghistorik kommer snart</div>
                </div>
            </div>

            <div id="rider-tab-stats" class="rider-tab-content" style="display:none">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Detaljerad Statistik</h2>
                    </div>
                    <div id="rider-stats-detail" class="placeholder">Statistik kommer snart</div>
                </div>
            </div>
        </section>

        <!-- CLUB PROFILE VIEW -->
        <section class="page-content" id="view-club" data-view="club" style="display:none;">
            <!-- Back Navigation -->
            <div style="margin-bottom: var(--space-lg);">
                <button class="btn btn--ghost" onclick="history.back()">
                    <i data-lucide="arrow-left"></i> Tillbaka
                </button>
            </div>

            <!-- Club Header -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: var(--space-md);">
                    <div>
                        <h1 id="club-name" style="margin: 0; font-size: var(--text-2xl); font-weight: var(--weight-bold);">Laddar...</h1>
                        <div id="club-location" style="font-size: var(--text-base); color: var(--color-text-secondary); margin-top: var(--space-xs);"></div>
                    </div>

                    <!-- Logo placeholder -->
                    <div style="width: 80px; height: 80px; border-radius: var(--radius-md); background: var(--color-bg-sunken); display: flex; align-items: center; justify-content: center; color: var(--color-text-muted); font-size: var(--text-xs); flex-shrink: 0;">
                        <i data-lucide="shield" style="width: 32px; height: 32px;"></i>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row" style="margin-top: var(--space-lg);">
                    <div class="stat-block">
                        <div class="stat-value" id="club-stat-members">–</div>
                        <div class="stat-label">MEDLEMMAR</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="club-stat-active">–</div>
                        <div class="stat-label">AKTIVA</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="club-stat-starts">–</div>
                        <div class="stat-label">TOTALA STARTER</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="club-stat-points">–</div>
                        <div class="stat-label">KLUBBPOÄNG</div>
                    </div>
                </div>
            </div>

            <!-- Members Grid -->
            <div class="page-grid page-grid--2col" style="margin-top: var(--space-lg);">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Toppåkare</h2>
                    </div>
                    <div id="club-top-riders"></div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Senaste Resultat</h2>
                    </div>
                    <div id="club-recent-results"></div>
                </div>
            </div>
        </section>

        <!-- EVENT DETAIL VIEW -->
        <section class="page-content" id="view-event" data-view="event" style="display:none;">
            <!-- Back Navigation -->
            <div style="margin-bottom: var(--space-lg);">
                <button class="btn btn--ghost" onclick="history.back()">
                    <i data-lucide="arrow-left"></i> Tillbaka
                </button>
            </div>

            <!-- Event Header -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-md); flex-wrap: wrap; gap: var(--space-md);">
                    <div style="flex: 1; min-width: 200px;">
                        <h1 id="event-name" style="margin: 0; font-size: var(--text-2xl); font-weight: var(--weight-bold);">Laddar...</h1>
                        <div id="event-meta" style="display: flex; gap: var(--space-md); margin-top: var(--space-md); flex-wrap: wrap;"></div>
                    </div>

                    <!-- Date Badge -->
                    <div id="event-date-badge" style="flex-shrink: 0; text-align: center; background: var(--color-accent); color: white; border-radius: var(--radius-md); padding: var(--space-md); min-width: 80px;">
                        <div style="font-size: var(--text-xs); opacity: 0.9;">–</div>
                        <div style="font-size: var(--text-3xl); font-weight: var(--weight-bold);">–</div>
                    </div>
                </div>

                <!-- Event Info Grid -->
                <div class="stats-row">
                    <div class="stat-block">
                        <div class="stat-value" id="event-stat-participants">–</div>
                        <div class="stat-label">DELTAGARE</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="event-stat-categories">–</div>
                        <div class="stat-label">KATEGORIER</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-value" id="event-stat-clubs">–</div>
                        <div class="stat-label">KLUBBAR</div>
                    </div>
                </div>
            </div>

            <!-- Results by Category -->
            <div id="event-results-container" style="margin-top: var(--space-lg);"></div>
        </section>

    </main>
</div>

<!-- Mobile Nav (V3 component) -->
<nav class="mobile-nav">
    <div class="mobile-nav-inner">
        <a class="mobile-nav-link active" href="#" data-view="dashboard">
            <span class="mobile-nav-icon"><i data-lucide="home"></i></span>
            <span>Start</span>
        </a>
        <a class="mobile-nav-link" href="#" data-view="calendar">
            <span class="mobile-nav-icon"><i data-lucide="calendar"></i></span>
            <span>Kalender</span>
        </a>
        <a class="mobile-nav-link" href="#" data-view="results">
            <span class="mobile-nav-icon"><i data-lucide="flag"></i></span>
            <span>Resultat</span>
        </a>
        <a class="mobile-nav-link" href="#" data-view="database">
            <span class="mobile-nav-icon"><i data-lucide="search"></i></span>
            <span>Databas</span>
        </a>
    </div>
</nav>

<!-- Theme Toggle (V3 component) -->
<div class="theme-toggle">
    <button class="theme-toggle-btn" data-theme="light" aria-pressed="false">
        <i data-lucide="sun"></i>
    </button>
    <button class="theme-toggle-btn" data-theme="dark" aria-pressed="true">
        <i data-lucide="moon"></i>
    </button>
</div>

<!-- JS -->
<script src="/thehub-v4/assets/js/theme.js?v=55"></script>
<script src="/thehub-v4/assets/js/app.js?v=55"></script>
</body>
</html>
