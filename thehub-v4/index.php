<?php
// TheHUB V4 - NeoGlass UI (frontend shell)
?><!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <title>TheHUB V4 – GravitySeries</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/thehub-v4/assets/css/app.css?v=40">
</head>
<body>
  <div class="app-root">
    <aside class="sidebar">
      <div class="sidebar-logo">
        <div class="sidebar-logo-mark">HUB</div>
        <div class="sidebar-logo-text">
          <span class="sidebar-title">TheHUB V4</span>
          <span class="sidebar-sub">GravitySeries</span>
        </div>
      </div>

      <nav class="sidebar-nav">
        <button class="sidebar-link is-active" data-target="dashboard">
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
        <div class="sidebar-meta">
          <span>V4 · API-driven</span>
        </div>
      </div>
    </aside>

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
        <!-- Dashboard -->
        <section id="view-dashboard" class="view view-active">
          <div class="card hero-card">
            <h2>Gravity Series Dashboard</h2>
            <p>Snabb överblick över säsongen. Data hämtas från V4-backend och din TheHUB-databas.</p>
            <div class="hero-meta-row">
              <div class="hero-pill">
                <span class="pill-label">Riders</span>
                <span class="pill-value" id="stat-riders">–</span>
              </div>
              <div class="hero-pill">
                <span class="pill-label">Events</span>
                <span class="pill-value" id="stat-events">–</span>
              </div>
              <div class="hero-pill">
                <span class="pill-label">Serier</span>
                <span class="pill-value">Capital · Götaland · Jämtland · GS Total</span>
              </div>
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
              <p class="muted">Detta är V4-frontenden. Den läser all data via backend-API:t. Du kan bygga vidare på vyerna utan att röra databasen.</p>
            </div>
          </div>
        </section>

        <!-- Results -->
        <section id="view-results" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Resultat / Tävlingar</h2>
              <span class="badge" id="results-events-count">0 tävlingar</span>
            </div>
            <div class="filter-bar">
              <div class="filter-group">
                <label for="results-year">År</label>
                <select id="results-year">
                  <option value="">Alla år</option>
                </select>
              </div>
            </div>
            <div id="results-events-status" class="status-text">Laddar inte än…</div>
            <div id="results-events-list" class="card-list"></div>
          </div>
        </section>

        <!-- Riders -->
        <section id="view-riders" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Riders</h2>
              <span class="badge" id="riders-count-badge">0 st</span>
            </div>
            <div class="filter-bar">
              <div class="filter-group wide">
                <label for="riders-search">Sök</label>
                <input type="text" id="riders-search" placeholder="Namn, Gravity ID, licens…" />
              </div>
            </div>
            <div id="riders-status" class="status-text">Klicka Riders i menyn för att ladda…</div>
            <div id="riders-list" class="card-list"></div>
          </div>
        </section>

        <!-- Events -->
        <section id="view-events" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Events</h2>
              <span class="badge" id="events-count-badge">0 events</span>
            </div>
            <div id="events-status" class="status-text">Klicka Events i menyn för att ladda…</div>
            <div id="events-list" class="card-list"></div>
          </div>
        </section>

        <!-- Ranking -->
        <section id="view-ranking" class="view">
          <div class="card">
            <div class="card-header">
              <h2>Ranking</h2>
              <span class="badge">beta</span>
            </div>
            <div class="filter-bar">
              <div class="filter-group">
                <label for="ranking-series">Serie</label>
                <select id="ranking-series">
                  <option value="capital">Capital</option>
                  <option value="gotland">Götaland</option>
                  <option value="jamtland">Jämtland</option>
                  <option value="gstotal">GS Total</option>
                </select>
              </div>
            </div>
            <div id="ranking-status" class="status-text">Välj serie för att visa ranking…</div>
            <div id="ranking-list" class="card-list"></div>
          </div>
        </section>
      </main>

      <nav class="tabbar">
        <button class="tab-btn tab-active" data-target="dashboard">
          <span class="tab-label">Start</span>
        </button>
        <button class="tab-btn" data-target="results">
          <span class="tab-label">Resultat</span>
        </button>
        <button class="tab-btn" data-target="riders">
          <span class="tab-label">Riders</span>
        </button>
        <button class="tab-btn" data-target="events">
          <span class="tab-label">Events</span>
        </button>
      </nav>
    </div>
  </div>

  <script src="/thehub-v4/assets/js/app.js?v=40"></script>
</body>
</html>
