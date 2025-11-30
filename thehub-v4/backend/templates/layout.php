<?php
require_once __DIR__ . '/../core/config.php';
?><!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <title>TheHUB V4 · Backend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?php echo url('css/admin.css'); ?>">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <div class="app-title">TheHUB V4 · Backend</div>
      <nav class="app-nav">
        <a href="<?php echo url('?module=riders'); ?>">Riders</a>
        <a href="<?php echo url('?module=events'); ?>">Events</a>
        <a href="<?php echo url('?module=api'); ?>">API Explorer</a>
        <a href="/thehub-v4/">Till frontend</a>
      </nav>
    </header>
    <main class="app-main">
      <?php echo $content; ?>
    </main>
    <footer class="app-footer">
      GravitySeries · Backend · <?php echo date('Y'); ?>
    </footer>
  </div>
</body>
</html>
