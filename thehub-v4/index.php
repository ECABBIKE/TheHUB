<?php
?><!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <title>TheHUB V4</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/thehub-v4/assets/css/app.css?v=10">
</head>
<body>
  <div class="app-root">
    <!-- DESKTOP SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-logo">
        <div class="sidebar-logo-mark">HUB</div>
        <div class="sidebar-logo-text">
          <span class="sidebar-title">TheHUB V4</span>
          <span class="sidebar-sub">GravitySeries</span>
        </div>
      </div>

      <nav class="sidebar-nav">
        <button class="sidebar-link is-active" data-target="home">
          <span class="icon">🏠</span>
          <span>Översikt</span>
        </button>
        <button class="sidebar-link" data-target="calendar">
          <span class="icon">📅</span>
          <span>Kalender</span>
        </button>
        <button class="sidebar-link" data-target="results">
          <span class="icon">🏁</span>
          <span>Resultat</span>
        </button>
        <button class="sidebar-link" data-target="series">
          <span class="icon">🏆</span>
          <span>Serier</span>
        </button>
        <button class="sidebar-link" data-target="database">
          <span class="icon">👥</span>
          <span>Databas</span>
        </button>
        <button class="sidebar-link" data-target="ranking">
          <span class="icon">📊</span>
          <span>Ranking</span>
        </button>
      </nav>

      <div class="sidebar-footer">
        <button class="sidebar-link sidebar-link-secondary" onclick="window.location='/thehub-v4/backend/'">
          <span class="icon">🛠️</span>
          <span>Backend</span>
        </button>
        <div class="sidebar-meta">
          <span>V4 · API driven</span>
        </div>
      </div>
    </aside>

    <!-- MAIN APPLICATION AREA -->
    <div class="app-shell">
      <header class="topbar">
        <div>
          <div class="topbar-eyebrow">TheHUB V4</div>
          <h1 class="topbar-title" id="topbar-title">Översikt</h1>
        </div>
        <div class="topbar-actions">
          <span class="topbar-pill">Live</span>
        </div>
      </header>

      <main class="app-main">
        <!-- HOME -->
        <section id="view-home" class="view view-active">
          <div class="card hero-card">
            <h2>TheHUB V4 – Grundstruktur</h2>
            <p>Detta är den nya V4-webbappen: mobiloptimerad, modulär och API-driven.</p>
            <p>Data hämtas från din befintliga TheHUB-databas via backend under <code>/thehub-v4/backend/</code>.</p>
          </div>

          <div class="card grid-two">
            <div>
              <h3>Snabbinfo</h3>
              <ul class="meta-list">
                <li><span>Backend:</span> /thehub-v4/backend/</li>
                <li><span>Riders API:</span> /thehub-v4/backend/public/api/riders.php</li>
                <li><span>Events API:</span> /thehub-v4/backend/public/api/events.php</li>
              </ul>
            </div>
            <div>
              <h3>Status</h3>
              <p class="muted">
                Välj Resultat eller Databas för att hämta riktiga data via API:et.
                Layouten är samma typ som din mockup: mörk, card-baserad och redo för mobil & desktop.
              </p>
            </div>
          </div>
        </section>

        <!-- CALENDAR -->
        <section id="view-calendar" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Kalender</h2>
              <span class="badge">placeholder</span>
            </div>
            <p class="muted">
              Här kan vi senare visa kalendern (hämtad från events-tabellen, grupperad på datum/serie).
            </p>
          </div>
        </section>

        <!-- RESULTS / EVENTS -->
        <section id="view-results" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Resultat / Tävlingar</h2>
              <span class="badge" id="events-count-badge">0 tävlingar</span>
            </div>

            <div class="filter-bar">
              <div class="filter-group">
                <label for="results-series">Serie</label>
                <select id="results-series">
                  <option value="">Alla serier</option>
                </select>
              </div>
              <div class="filter-group">
                <label for="results-year">År</label>
                <select id="results-year">
                  <option value="">Alla år</option>
                </select>
              </div>
            </div>

            <div id="events-status" class="status-text">Klicka Resultat i menyn för att ladda tävlingar…</div>
            <div id="events-list" class="card-list"></div>
          </div>
        </section>

        <!-- SERIES -->
        <section id="view-series" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Serier</h2>
              <span class="badge">placeholder</span>
            </div>
            <p class="muted">
              Här kan vi bygga poängtabeller för Capital, Götaland, Jämtland, GravitySeries Total m.m.
              Frontend-layouten är redo, vi behöver bara bestämma vilka API-endpoints vi vill ha.
            </p>
          </div>
        </section>

        <!-- DATABASE (RIDERS) -->
        <section id="view-database" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Rider-databas</h2>
              <span class="badge" id="riders-count-badge">0 riders</span>
            </div>

            <div class="filter-bar">
              <div class="filter-group wide">
                <label for="riders-search">Sök</label>
                <input type="text" id="riders-search" placeholder="Namn, Gravity ID, licens…">
              </div>
            </div>

            <div id="riders-status" class="status-text">Klicka Databas i menyn för att ladda riders…</div>
            <div id="riders-list" class="card-list"></div>
          </div>
        </section>

        <!-- RANKING -->
        <section id="view-ranking" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Ranking & poäng</h2>
              <span class="badge">placeholder</span>
            </div>
            <p class="muted">
              Här bygger vi senare ut rankinglistor baserade på dina poängmatriser för Enduro, Downhill,
              Gravel och team-poäng (bästa + näst bästa åkare per klubb).
            </p>
          </div>
        </section>
      </main>

      <!-- MOBILE TABBAR -->
      <nav class="tabbar">
        <button class="tab-btn tab-active" data-target="home">
          <span class="tab-label">Start</span>
        </button>
        <button class="tab-btn" data-target="results">
          <span class="tab-label">Resultat</span>
        </button>
        <button class="tab-btn" data-target="database">
          <span class="tab-label">Databas</span>
        </button>
        <button class="tab-btn" data-target="menu">
          <span class="tab-label">Meny</span>
        </button>
      </nav>
    </div>
  </div>

  <script src="/thehub-v4/assets/js/app.js?v=10"></script>
</body>
</html>
