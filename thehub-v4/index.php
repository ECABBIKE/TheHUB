<?php
// TheHUB V4 – NeoGlass Dark UI (Dashboard start)
?><!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <title>TheHUB V4</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/thehub-v4/assets/css/app.css?v=20">
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
        <button class="sidebar-link" data-target="results">
          <span class="icon">🏁</span>
          <span>Resultat</span>
        </button>
        <button class="sidebar-link" data-target="riders">
          <span class="icon">👥</span>
          <span>Riders</span>
        </button>
        <button class="sidebar-link" data-target="events">
          <span class="icon">📅</span>
          <span>Events</span>
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
          <span>V4 · API-driven</span>
        </div>
      </div>
    </aside>

    <!-- MAIN APPLICATION AREA -->
    <div class="app-shell">
      <header class="topbar">
        <div>
          <div class="topbar-eyebrow">TheHUB V4</div>
          <h1 class="topbar-title" id="topbar-title">Dashboard</h1>
        </div>
        <div class="topbar-actions">
          <span class="topbar-pill">Beta</span>
        </div>
      </header>

      <main class="app-main">
        <!-- DASHBOARD -->
        <section id="view-home" class="view view-active">
          <div class="card hero-card hero-grid">
            <div class="hero-main">
              <h2>Gravity Series Dashboard</h2>
              <p>Snabb överblick över säsongen. Data kommer från befintliga TheHUB-databasen.</p>
              <div class="hero-meta-row">
                <div class="hero-pill">
                  <span class="pill-label">Aktiva riders</span>
                  <span class="pill-value" id="dash-riders-count">–</span>
                </div>
                <div class="hero-pill">
                  <span class="pill-label">Tävlingsdagar</span>
                  <span class="pill-value" id="dash-events-count">–</span>
                </div>
                <div class="hero-pill">
                  <span class="pill-label">Serier</span>
                  <span class="pill-value">Capital · Götaland · Jämtland · GS Total</span>
                </div>
              </div>
            </div>
            <div class="hero-secondary">
              <div class="mini-chart-card">
                <div class="mini-chart-header">
                  <span>Säsongspuls</span>
                  <span class="dot-live">● Live</span>
                </div>
                <div class="mini-chart-placeholder">
                  <div class="sparkline">
                    <span></span><span></span><span></span><span></span><span></span>
                  </div>
                  <div class="sparkline-labels">
                    <span>Maj</span><span>Jun</span><span>Jul</span><span>Aug</span><span>Sep</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="card grid-three">
            <div class="stat-card">
              <div class="stat-label">Kommande event</div>
              <div class="stat-value" id="dash-upcoming-events">–</div>
              <div class="stat-sub">Nästa 30 dagar</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">Senaste event</div>
              <div class="stat-value" id="dash-last-event">–</div>
              <div class="stat-sub" id="dash-last-event-meta"></div>
            </div>
            <div class="stat-card">
              <div class="stat-label">Databas</div>
              <div class="stat-value">V4</div>
              <div class="stat-sub">Kopplad till befintlig MySQL</div>
            </div>
          </div>

          <div class="card grid-two">
            <div>
              <h3>Snabb åtkomst</h3>
              <div class="quick-links">
                <button class="quick-link" data-goto="results">Visa alla resultat</button>
                <button class="quick-link" data-goto="riders">Rider-databasen</button>
                <button class="quick-link" data-goto="events">Tävlingskalender</button>
              </div>
            </div>
            <div>
              <h3>Status</h3>
              <p class="muted">
                Detta är en första version av V4-dashbordet. Vi kan senare lägga in riktiga grafer
                (per serie, klubb, ålderskategori, osv.) baserat på dina poängmatriser och resultat.
              </p>
            </div>
          </div>
        </section>

        <!-- RESULTS / TÄVLINGAR -->
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

        <!-- RIDERS (Database view) -->
        <section id="view-riders" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Riders</h2>
              <span class="badge" id="riders-count-badge">0 riders</span>
            </div>

            <div class="filter-bar">
              <div class="filter-group wide">
                <label for="riders-search">Sök</label>
                <input type="text" id="riders-search" placeholder="Namn, Gravity ID, licens…">
              </div>
            </div>

            <div id="riders-status" class="status-text">Klicka Riders i menyn för att ladda riders…</div>
            <div id="riders-list" class="card-list"></div>
          </div>
        </section>

        <!-- EVENTS (placeholder – kan bli separat kalender) -->
        <section id="view-events" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Events (kalender)</h2>
              <span class="badge">placeholder</span>
            </div>
            <p class="muted">Här kan vi senare bygga en kalender-vy med samma data som resultatlistan, men grupperat per helg/anläggning.</p>
          </div>
        </section>

        <!-- RANKING (placeholder) -->
        <section id="view-ranking" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Ranking & poäng</h2>
              <span class="badge">placeholder</span>
            </div>
            <p class="muted">
              Här kommer vi senare att bygga rankinglistor baserat på dina poängsystem för Enduro, Downhill,
              Gravel, klubbar, ungdomsserier osv.
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
        <button class="tab-btn" data-target="riders">
          <span class="tab-label">Riders</span>
        </button>
        <button class="tab-btn" data-target="menu">
          <span class="tab-label">Meny</span>
        </button>
      </nav>
    </div>
  </div>

  <script src="/thehub-v4/assets/js/app.js?v=20"></script>
</body>
</html>
