<?php
$currentPage = $pageInfo['page'] ?? 'dashboard';

// Pages that have their own breadcrumb/navigation (don't show global back link)
$pagesWithOwnNav = [
    'calendar-event',
    'series-show',
    'database-rider',
    'database-club',
    'results-event',
    'event',
    'profile-edit',
    'profile-children',
    'profile-registrations',
    'profile-results',
    'profile-receipts'
];

// Don't show back link on dashboard/home/welcome or pages with their own navigation
if ($currentPage !== 'dashboard' && $currentPage !== 'welcome' && !in_array($currentPage, $pagesWithOwnNav)):
?>
<nav class="back-nav" aria-label="Navigation">
  <a href="javascript:history.back()" class="back-link">
    <span class="back-arrow">←</span>
    <span>Tillbaka</span>
  </a>
</nav>
<?php endif; ?>
