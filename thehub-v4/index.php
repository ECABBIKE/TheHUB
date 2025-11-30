<?php
// TheHUB V4 – Gravity admin UI (light/dark)
?><!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <title>TheHUB V4 – GravitySeries</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/thehub-v4/assets/css/app.css?v=40">
</head>
<body>
  <div class="layout-root">
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-logo">
        <div class="sidebar-logo-mark">HUB</div>
        <div class="sidebar-logo-text">
          <span class="sidebar-logo-title">TheHUB</span>
          <span class="sidebar-logo-sub">GravitySeries</span>
        </div>
      </div>

      <nav class="sidebar-nav">
        <button class="sidebar-link is-active" data-view="dashboard">
          <span class="sidebar-icon">📊</span>
          <span>Dashboard</span>
        </button>
        <button class="sidebar-link" data-view="calendar">
          <span class="sidebar-icon">📅</span>
          <span>Kalender</span>
        </button>
        <button class="sidebar-link" data-view="results">
          <span class="sidebar-icon">🏁</span>
          <span>Resultat</span>
        </button>
        <button class="sidebar-link" data-view="series">
          <span class="sidebar-icon">🥇</span>
          <span>Serier</span>
        </button>
        <button class="sidebar-link" data-view="database">
          <span class="sidebar-icon">📚</span>
          <span>Databas</span>
        </button>
        <button class="sidebar-link" data-view="ranking">
          <span class="sidebar-icon">📈</span>
          <span>Ranking</span>
        </button>
      </nav>

      <div class="sidebar-footer">
        <div class="sidebar-footer-text">V4 · API-driven</div>
      </div>
    </aside>

    <!-- MAIN AREA -->
    <div class="main-shell">
      <header class="topbar">
        <div class="topbar-left">
          <button class="topbar-back" data-action="back">
            ← <span>Tillbaka</span>
          </button>
          <div>
            <div class="topbar-eyebrow" id="topbar-eyebrow">TheHUB V4</div>
            <h1 class="topbar-title" id="topbar-title">Dashboard</h1>
          </div>
        </div>

        <div class="topbar-right">
          <div class="theme-toggle" aria-label="Färgtema">
            <button class="theme-btn" data-theme="light" title="Ljust läge">☀️</button>
            <button class="theme-btn" data-theme="auto" title="Följ system" aria-pressed="true">🌓</button>
            <button class="theme-btn" data-theme="dark" title="Mörkt läge">🌙</button>
          </div>
          <div class="topbar-user">
            <span class="topbar-user-name">Admin</span>
            <span class="topbar-user-avatar">A</span>
          </div>
        </div>
      </header>

      <main class="main-content">
        <!-- DASHBOARD -->
        <section id="view-dashboard" class="view view-active" data-title="Dashboard" data-eyebrow="Översikt">
          <div class="card quick-links-card">
            <div class="card-header-row">
              <h2>Snabblänkar</h2>
            </div>
            <div class="quick-link-row">
              <button class="quick-chip" data-goto="database" data-chip="riders">
                <span class="chip-icon">🚴</span> <span>Åkare</span>
              </button>
              <button class="quick-chip" data-goto="database" data-chip="clubs">
                <span class="chip-icon">🏟</span> <span>Klubbar</span>
              </button>
              <button class="quick-chip" data-goto="results">
                <span class="chip-icon">🏁</span> <span>Resultat</span>
              </button>
              <button class="quick-chip" data-goto="series">
                <span class="chip-icon">🥇</span> <span>Serier</span>
              </button>
            </div>
          </div>

          <div class="card kpi-card">
            <h2>Översikt</h2>
            <div class="kpi-grid">
              <div class="kpi-tile">
                <div class="kpi-value" id="kpi-riders">–</div>
                <div class="kpi-label">Åkare</div>
              </div>
              <div class="kpi-tile">
                <div class="kpi-value" id="kpi-clubs">–</div>
                <div class="kpi-label">Klubbar</div>
              </div>
              <div class="kpi-tile">
                <div class="kpi-value" id="kpi-events">–</div>
                <div class="kpi-label">Event</div>
              </div>
              <div class="kpi-tile">
                <div class="kpi-value" id="kpi-results">–</div>
                <div class="kpi-label">Resultat</div>
              </div>
            </div>
          </div>

          <div class="card-grid-2">
            <div class="card">
              <div class="card-header-row">
                <h2>Aktiva serier</h2>
                <button class="link-button" data-goto="series">Visa alla</button>
              </div>
              <div id="dashboard-series-list" class="table-like small">
                <!-- fylls via JS -->
              </div>
            </div>

            <div class="card">
              <div class="card-header-row">
                <h2>Mest aktiva åkare</h2>
                <button class="link-button" data-goto="database">Visa databas</button>
              </div>
              <div id="dashboard-riders-list" class="table-like small">
                <!-- fylls via JS -->
              </div>
            </div>
          </div>
        </section>

        <!-- KALENDER -->
        <section id="view-calendar" class="view" data-title="Kalender" data-eyebrow="Kommande tävlingar">
          <div class="card">
            <div class="card-header-row">
              <h2>Kalender</h2>
            </div>
            <div class="filter-row">
              <div class="filter-group">
                <label for="cal-series">Serie</label>
                <select id="cal-series">
                  <option value="">Alla serier</option>
                </select>
              </div>
            </div>
            <div id="calendar-status" class="status-text">Laddar inte än…</div>
            <div id="calendar-list" class="list-stack">
              <!-- event grupperas per månad via JS -->
            </div>
          </div>
        </section>

        <!-- RESULTAT -->
        <section id="view-results" class="view" data-title="Resultat" data-eyebrow="Alla tävlingar">
          <div class="card">
            <div class="card-header-row">
              <h2>Resultat</h2>
              <span class="badge" id="results-count-badge">0 tävlingar</span>
            </div>

            <div class="filter-row">
              <div class="filter-group">
                <label for="res-series">Serie</label>
                <select id="res-series">
                  <option value="">Alla serier</option>
                </select>
              </div>
              <div class="filter-group">
                <label for="res-year">År</label>
                <select id="res-year">
                  <option value="">Alla år</option>
                </select>
              </div>
            </div>

            <div id="results-status" class="status-text">Välj filter eller klicka på en tävling.</div>
            <div id="results-list" class="event-card-list">
              <!-- fylls via JS -->
            </div>
          </div>
        </section>

        <!-- SERIER -->
        <section id="view-series" class="view" data-title="Tävlingsserier" data-eyebrow="Alla GravitySeries och SweCup">
          <div class="card">
            <div class="card-header-row">
              <h2>Tävlingsserier</h2>
            </div>
            <div id="series-grid" class="series-grid">
              <!-- serie-kort fylls via JS -->
            </div>
          </div>
        </section>

        <!-- DATABAS -->
        <section id="view-database" class="view" data-title="Databas" data-eyebrow="Åkare & klubbar">
          <div class="card">
            <div class="card-header-row">
              <h2>Databas</h2>
            </div>

            <div class="kpi-grid">
              <div class="kpi-tile small">
                <div class="kpi-value" id="db-riders">–</div>
                <div class="kpi-label">Åkare</div>
              </div>
              <div class="kpi-tile small">
                <div class="kpi-value" id="db-clubs">–</div>
                <div class="kpi-label">Klubbar</div>
              </div>
              <div class="kpi-tile small">
                <div class="kpi-value" id="db-results">–</div>
                <div class="kpi-label">Resultat</div>
              </div>
            </div>

            <div class="db-toggle-row">
              <button class="chip-toggle is-active" data-db-mode="riders">👥 Sök åkare</button>
              <button class="chip-toggle" data-db-mode="clubs">🏟 Sök klubbar</button>
            </div>

            <div class="search-row">
              <input id="db-search" type="text" placeholder="Skriv namn för att söka…">
              <span class="help-text">Skriv minst 2 tecken för att söka</span>
            </div>

            <div class="card-grid-2">
              <div>
                <h3 class="subheading">Topp-presterande åkare</h3>
                <div id="db-top-riders" class="table-like">
                  <!-- fylls via JS -->
                </div>
              </div>
              <div>
                <h3 class="subheading">Toppklubbar</h3>
                <div id="db-top-clubs" class="table-like">
                  <!-- fylls via JS -->
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- RANKING -->
        <section id="view-ranking" class="view" data-title="GravitySeries Ranking" data-eyebrow="24 månaders rullande ranking">
          <div class="card">
            <div class="card-header-row">
              <h2>GravitySeries Ranking</h2>
              <span class="badge badge-soft">beta</span>
            </div>

            <div class="tabs-row">
              <button class="tab-pill is-active" data-rank-scope="gravity">Gravity</button>
              <button class="tab-pill" data-rank-scope="enduro">Enduro</button>
              <button class="tab-pill" data-rank-scope="dh">Downhill</button>
            </div>

            <div class="tabs-row second">
              <button class="tab-pill is-active" data-rank-mode="riders">Åkare</button>
              <button class="tab-pill" data-rank-mode="clubs">Klubbar</button>
            </div>

            <p class="muted rank-help">
              24 månaders rullande ranking. Poäng viktas efter startfält och eventtyp (kommer kopplas mot dina poängmatriser).
            </p>

            <div id="ranking-status" class="status-text status-info">
              Rankingsystemet är inte konfigurerat än. Detta är en ren UI-placeholder som vi kopplar mot ditt
              RankingEngine-API när allt är bestämt.
            </div>
          </div>
        </section>
      </main>
    </div>
  </div>

  <script src="/thehub-v4/assets/js/app.js?v=40"></script>
</body>
</html>
