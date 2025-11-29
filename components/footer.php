<?php $currentTheme = hub_get_theme(); ?>
<div class="theme-toggle" role="group" aria-label="Välj tema">
  <button type="button" class="theme-toggle-btn" data-theme="light" aria-pressed="<?= $currentTheme==='light'?'true':'false' ?>" aria-label="Ljust tema">☀️</button>
  <button type="button" class="theme-toggle-btn" data-theme="dark" aria-pressed="<?= $currentTheme==='dark'?'true':'false' ?>" aria-label="Mörkt tema">🌙</button>
  <button type="button" class="theme-toggle-btn" data-theme="auto" aria-pressed="<?= $currentTheme==='auto'?'true':'false' ?>" aria-label="Automatiskt tema">📱</button>
</div>
