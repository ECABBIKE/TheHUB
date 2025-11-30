<?php
// TheHUB V4 – frontend shell (PHP, mobil-first)
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
      <nav class="main-nav">
        <a href="./">Start</a>
        <a href="backend/?module=riders">Riders</a>
        <a href="backend/?module=events">Events</a>
        <a href="backend/?module=api">API Explorer</a>
      </nav>
    </header>

    <main class="app-main">
      <section class="hero">
        <h1>TheHUB V4 – Grundstruktur</h1>
        <p>Detta är en ren V4-installation som använder din befintliga databas men ett nytt modulärt backend.</p>
        <p>Backend ligger under <code>/thehub-v4/backend/</code>.</p>
      </section>
    </main>

    <footer class="app-footer">
      GravitySeries · TheHUB V4 · <?php echo date('Y'); ?>
    </footer>
  </div>

  <script src="assets/js/app.js"></script>
</body>
</html>
