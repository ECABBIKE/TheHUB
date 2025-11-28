<?php
$currentPage = $pageInfo['page'] ?? 'dashboard';
$params = $pageInfo['params'] ?? [];
$breadcrumbs = [['label' => 'Hem', 'url' => '/v3/']];

switch ($currentPage) {
  case 'series': $breadcrumbs[] = ['label' => 'Serier', 'url' => null]; break;
  case 'results': $breadcrumbs[] = ['label' => 'Resultat', 'url' => null]; break;
  case 'event': $breadcrumbs[] = ['label' => 'Resultat', 'url' => '/v3/results']; $breadcrumbs[] = ['label' => 'Event #'.($params['id']??''), 'url' => null]; break;
  case 'riders': $breadcrumbs[] = ['label' => 'Åkare', 'url' => null]; break;
  case 'rider': $breadcrumbs[] = ['label' => 'Åkare', 'url' => '/v3/riders']; $breadcrumbs[] = ['label' => 'Profil', 'url' => null]; break;
  case 'clubs': $breadcrumbs[] = ['label' => 'Klubbar', 'url' => null]; break;
  case 'club': $breadcrumbs[] = ['label' => 'Klubbar', 'url' => '/v3/clubs']; $breadcrumbs[] = ['label' => 'Klubb', 'url' => null]; break;
  case '404': $breadcrumbs[] = ['label' => 'Sidan hittades inte', 'url' => null]; break;
}
?>
<?php if (count($breadcrumbs) > 1): ?>
<nav class="breadcrumb" aria-label="Brödsmulor">
  <?php foreach ($breadcrumbs as $i => $crumb): ?>
    <?php if ($i > 0): ?><span class="breadcrumb-separator" aria-hidden="true">›</span><?php endif; ?>
    <?php if ($crumb['url'] && $i < count($breadcrumbs) - 1): ?>
      <a href="<?= htmlspecialchars($crumb['url']) ?>" class="breadcrumb-link"><?= htmlspecialchars($crumb['label']) ?></a>
    <?php else: ?>
      <span class="breadcrumb-current" aria-current="page"><?= htmlspecialchars($crumb['label']) ?></span>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
