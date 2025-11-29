<?php
$events = [
    [
        'date' => '28',
        'month' => 'SEP',
        'name' => 'SweCup Enduro - Isaberg',
        'series' => 'SweCup Enduro 2025',
        'location' => 'Isaberg Mountain Resort',
    ],
    [
        'date' => '21',
        'month' => 'SEP',
        'name' => 'Capital Enduro #4 - Väsjö',
        'series' => 'Capital Gravity Series',
        'location' => 'Väsjö Bikepark',
    ],
    [
        'date' => '14',
        'month' => 'SEP',
        'name' => 'SweCup Enduro - Falun',
        'series' => 'SweCup Enduro 2025',
        'location' => 'Källviksbacken',
    ],
    [
        'date' => '31',
        'month' => 'AUG',
        'name' => 'SweCup Enduro - Sundsvall',
        'series' => 'SweCup Enduro 2025',
        'location' => 'Sundsvall',
    ],
    [
        'date' => '27',
        'month' => 'JUL',
        'name' => 'Capital Enduro #3 - Flottsbro',
        'series' => 'Capital Gravity Series',
        'location' => 'Flottsbro Bikepark',
    ],
];
?><!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <title>GS WebApp V4 · Demo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link rel="stylesheet" href="css/app.css">
</head>
<body class="theme-dark">
<div class="app-viewport">
  <header class="app-topbar">
    <div class="topbar-left">
      <span class="topbar-title">Resultat</span>
    </div>
    <button class="topbar-toggle" id="themeToggle" aria-label="Toggle theme">
      <span class="icon-sun">☀️</span>
      <span class="icon-moon">🌙</span>
    </button>
  </header>

  <main class="app-main">
    <section class="filter-bar">
      <label class="filter-label" for="yearSelect">År:</label>
      <div class="filter-select-wrapper">
        <select id="yearSelect">
          <option>Alla år</option>
          <option>2025</option>
          <option>2024</option>
        </select>
      </div>
    </section>

    <section class="event-list" aria-label="Kommande tävlingar">
      <?php foreach ($events as $event): ?>
        <article class="event-card">
          <div class="event-date-badge">
            <div class="event-date-day"><?php echo htmlspecialchars($event['date']); ?></div>
            <div class="event-date-month"><?php echo htmlspecialchars($event['month']); ?></div>
          </div>
          <div class="event-body">
            <h2 class="event-title"><?php echo htmlspecialchars($event['name']); ?></h2>
            <div class="event-series"><?php echo htmlspecialchars($event['series']); ?></div>
            <div class="event-location"><?php echo htmlspecialchars($event['location']); ?></div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </main>

  <nav class="app-tabbar" aria-label="Huvudnavigation">
    <button class="tab-item">
      <span class="tab-icon">📅</span>
      <span class="tab-label">Kalender</span>
    </button>
    <button class="tab-item tab-item-active">
      <span class="tab-icon">🏁</span>
      <span class="tab-label">Resultat</span>
    </button>
    <button class="tab-item">
      <span class="tab-icon">🏆</span>
      <span class="tab-label">Serier</span>
    </button>
    <button class="tab-item">
      <span class="tab-icon">🔎</span>
      <span class="tab-label">Databas</span>
    </button>
    <button class="tab-item">
      <span class="tab-icon">📊</span>
      <span class="tab-label">Ranking</span>
    </button>
  </nav>
</div>

<script src="js/app.js"></script>
</body>
</html>
