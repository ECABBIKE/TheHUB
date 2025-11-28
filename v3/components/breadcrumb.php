<?php
$currentPage = $pageInfo['page'] ?? 'dashboard';

// Don't show back link on dashboard/home
if ($currentPage !== 'dashboard'):
?>
<nav class="back-nav" aria-label="Navigation">
  <a href="javascript:history.back()" class="back-link">
    <span class="back-arrow">←</span>
    <span>Tillbaka</span>
  </a>
</nav>
<?php endif; ?>
