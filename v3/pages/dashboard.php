<h1 class="sr-only">Dashboard</h1>
<div class="page-grid">
  <!-- Rider Card -->
  <section class="card" aria-labelledby="rider-title">
    <div class="card-header"><div><h2 id="rider-title" class="card-title">Rider Profile</h2><p class="card-subtitle">Exempel-data</p></div></div>
    <div class="rider-profile">
      <div class="rider-avatar"></div>
      <div class="rider-info">
        <h3 class="rider-name">Paxton Eriksson</h3>
        <p class="rider-club">Falun MTB Klubb</p>
        <div class="rider-meta"><span>Herr Junior</span><span>•</span><span>UCI: 1234-5678</span></div>
      </div>
    </div>
    <div class="stats-row">
      <div class="stat-block"><div class="stat-value">4</div><div class="stat-label">Ranking</div></div>
      <div class="stat-block"><div class="stat-value">11</div><div class="stat-label">Starter</div></div>
      <div class="stat-block"><div class="stat-value">4:22</div><div class="stat-label">Bästa tid</div></div>
    </div>
  </section>

  <!-- Event Card -->
  <section class="card" aria-labelledby="event-title">
    <div class="card-header"><div><h2 id="event-title" class="card-title">Senaste event</h2><p class="card-subtitle">Gesunda Enduro • Heat 3</p></div></div>
    <div class="card-body">
      <p><strong>Datum:</strong> 2025-08-23</p>
      <p class="mt-xs"><strong>Klass:</strong> Herr Junior</p>
      <div class="flex gap-xs mt-md"><span class="chip chip--enduro">Enduro</span><span class="chip chip--ges">GES</span></div>
    </div>
  </section>

  <!-- Heat Standings -->
  <section class="card grid-full" aria-labelledby="standings-title">
    <div class="card-header"><div><h2 id="standings-title" class="card-title">Heat Standings</h2><p class="card-subtitle">Top 5</p></div></div>
    <div class="table-wrapper">
      <table class="table table--striped table--clickable">
        <thead><tr>
          <th class="col-place" scope="col">#</th>
          <th class="col-rider" scope="col">Åkare</th>
          <th class="col-club table-col-hide-portrait" scope="col">Klubb</th>
          <th class="col-time" scope="col">Tid</th>
          <th class="col-diff" scope="col">+Tid</th>
          <th class="col-split" scope="col">S1</th>
          <th class="col-split" scope="col">S2</th>
          <th class="col-split" scope="col">S3</th>
        </tr></thead>
        <tbody>
          <tr data-href="/v3/rider/1"><td class="col-place col-place--1">1</td><td class="col-rider">Paxton Eriksson</td><td class="col-club table-col-hide-portrait">Falun MTB</td><td class="col-time">4:22.31</td><td class="col-diff">-</td><td class="col-split">1:12.4</td><td class="col-split">1:33.2</td><td class="col-split">1:36.7</td></tr>
          <tr data-href="/v3/rider/2"><td class="col-place col-place--2">2</td><td class="col-rider">Adam Svensson</td><td class="col-club table-col-hide-portrait">Uppsala CK</td><td class="col-time">4:29.87</td><td class="col-diff">+7.56</td><td class="col-split">1:15.1</td><td class="col-split">1:35.8</td><td class="col-split">1:38.9</td></tr>
          <tr data-href="/v3/rider/3"><td class="col-place col-place--3">3</td><td class="col-rider">Erik Lindqvist</td><td class="col-club table-col-hide-portrait">Göteborg MTB</td><td class="col-time">4:31.22</td><td class="col-diff">+8.91</td><td class="col-split">1:14.8</td><td class="col-split">1:37.4</td><td class="col-split">1:39.0</td></tr>
          <tr data-href="/v3/rider/4"><td class="col-place">4</td><td class="col-rider">Johan Nilsson</td><td class="col-club table-col-hide-portrait">Malmö CK</td><td class="col-time">4:35.44</td><td class="col-diff">+13.13</td><td class="col-split">1:16.2</td><td class="col-split">1:38.1</td><td class="col-split">1:41.1</td></tr>
          <tr data-href="/v3/rider/5"><td class="col-place">5</td><td class="col-rider">Viktor Andersson</td><td class="col-club table-col-hide-portrait">Stockholm MTB</td><td class="col-time">4:38.90</td><td class="col-diff">+16.59</td><td class="col-split">1:17.5</td><td class="col-split">1:39.3</td><td class="col-split">1:42.1</td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Placement History -->
  <section class="card" aria-labelledby="history-title">
    <div class="card-header"><div><h2 id="history-title" class="card-title">Placement History</h2><p class="card-subtitle">Utveckling</p></div></div>
    <div class="placeholder">Diagram placeholder – ersätt med Chart.js</div>
  </section>

  <!-- Series Standings -->
  <section class="card" aria-labelledby="series-title">
    <div class="card-header"><div><h2 id="series-title" class="card-title">Serie Standings</h2><p class="card-subtitle">GES Enduro 2025</p></div><a href="/v3/series" class="btn btn--ghost text-sm">Visa alla →</a></div>
    <div class="table-wrapper">
      <table class="table table--compact">
        <thead><tr><th class="col-place" scope="col">#</th><th scope="col">Åkare</th><th class="col-points" scope="col">Poäng</th></tr></thead>
        <tbody>
          <tr><td class="col-place">1</td><td>Wilhelm Lund</td><td class="col-points">101</td></tr>
          <tr><td class="col-place">2</td><td>Markus Nylander</td><td class="col-points">102</td></tr>
          <tr><td class="col-place">3</td><td>Paxton Eriksson</td><td class="col-points">81</td></tr>
          <tr><td class="col-place">4</td><td>Theo Sundling</td><td class="col-points">83</td></tr>
          <tr><td class="col-place">5</td><td>Arvid Blomqvist</td><td class="col-points">87</td></tr>
        </tbody>
      </table>
    </div>
  </section>
</div>
