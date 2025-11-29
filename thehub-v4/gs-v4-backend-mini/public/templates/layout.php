<?php
require_once __DIR__ . '/../../core/config.php';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>GS V4 Backend · Riders</title>
  <link rel="stylesheet" href="<?php echo url('css/admin.css'); ?>">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <div class="app-title">GS V4 · Backend Test</div>
      <nav class="app-nav">
        <a href="<?php echo url('?module=riders'); ?>">Riders</a>
      </nav>
    </header>
    <main class="app-main">
      <?php echo $content; ?>
    </main>
    <footer class="app-footer">
      GravitySeries · Backend test · <?php echo date('Y'); ?>
    </footer>
  </div>
</body>
</html>
