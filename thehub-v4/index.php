<?php
// TheHUB V4 – frontend app (API-baserad)
?><!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <title>TheHUB V4</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <div class="logo">TheHUB V4</div>
      <div class="logo-sub">GravitySeries · Resultat & Statistik</div>
    </header>

    <main class="app-main">
      <!-- HOME / DASHBOARD -->
      <section id="view-home" class="view view-active">
        <div class="card hero-card">
          <h1>TheHUB V4 – Grundstruktur</h1>
          <p>Detta är en V4-webbapp som använder din befintliga databas och ett nytt modulärt backend.</p>
          <p>Välj flik längst ned för att visa Riders, Events m.m.</p>
        </div>

        <div class="card">
          <h2>Snabbinfo</h2>
          <ul class="meta-list">
            <li><span>Backend:</span> /thehub-v4/backend/</li>
            <li><span>Riders API:</span> backend/public/api/riders.php</li>
            <li><span>Events API:</span> backend/public/api/events.php</li>
          </ul>
        </div>
      </section>

      <!-- RIDERS -->
      <section id="view-riders" class="view">
        <div class="card">
          <div class="card-header">
            <h1>Riders</h1>
            <span class="badge" id="riders-count">0 st</span>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label>Sök</label>
              <input type="text" id="riders-search" placeholder="Namn, Gravity ID, licens…">
            </div>
          </div>

          <div id="riders-status" class="status-text">Laddar inte än…</div>
          <div id="riders-list" class="list"></div>
        </div>
      </section>

      <!-- EVENTS -->
      <section id="view-events" class="view">
        <div class="card">
          <div class="card-header">
            <h1>Events</h1>
            <span class="badge" id="events-count">0 st</span>
          </div>

          <div id="events-status" class="status-text">Laddar inte än…</div>
          <div id="events-list" class="list"></div>
        </div>
      </section>
    </main>

    <!-- BOTTOM TABS (som app) -->
    <nav class="tabbar">
      <button class="tab-btn tab-active" data-target="home">
        <span class="tab-label">Start</span>
      </button>
      <button class="tab-btn" data-target="riders">
        <span class="tab-label">Riders</span>
      </button>
      <button class="tab-btn" data-target="events">
        <span class="tab-label">Events</span>
      </button>
      <button class="tab-btn" data-target="backend">
        <span class="tab-label">Backend</span>
      </button>
    </nav>
  </div>

  <script src="assets/js/app.js"></script>
</body>
</html>
