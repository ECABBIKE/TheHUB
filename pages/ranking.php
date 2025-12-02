<?php
/**
 * V3 Ranking Page - 24 month rolling ranking
 * Matches v2 structure with discipline tabs
 */

$db = hub_db();

// Include ranking functions - try multiple paths for V3 routing compatibility
$hasRankingSystem = false;
$possiblePaths = [
    dirname(__DIR__) . '/includes/ranking_functions.php',
    __DIR__ . '/../includes/ranking_functions.php',
    $_SERVER['DOCUMENT_ROOT'] . '/includes/ranking_functions.php',
];

foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $hasRankingSystem = true;
        break;
    }
}

// Get selected discipline
$discipline = isset($_GET['discipline']) ? strtoupper($_GET['discipline']) : 'GRAVITY';
if (!in_array($discipline, ['ENDURO', 'DH', 'GRAVITY'])) {
    $discipline = 'GRAVITY';
}

// Get view (riders or clubs)
$view = isset($_GET['view']) ? $_GET['view'] : 'riders';
if (!in_array($view, ['riders', 'clubs'])) {
    $view = 'riders';
}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$ranking = ['riders' => [], 'clubs' => [], 'total' => 0, 'snapshot_date' => null];
$error = null;

try {
    if ($hasRankingSystem && function_exists('rankingTablesExist')) {
        // Use parent getDB function if available, otherwise use hub_db()
        $parentDb = function_exists('getDB') ? getDB() : $db;

        if ($parentDb && rankingTablesExist($parentDb)) {
            if ($view === 'clubs' && function_exists('getCurrentClubRanking')) {
                $ranking = getCurrentClubRanking($parentDb, $discipline, $perPage, $offset);
            } elseif (function_exists('getCurrentRanking')) {
                $ranking = getCurrentRanking($parentDb, $discipline, $perPage, $offset);
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$totalPages = max(1, ceil(($ranking['total'] ?? 0) / $perPage));
$disciplineNames = [
    'GRAVITY' => 'Gravity',
    'ENDURO' => 'Enduro',
    'DH' => 'Downhill'
];
?>

<div class="page-header">
  <h1 class="page-title">
    📈 GravitySeries Ranking
  </h1>
  <?php if (!empty($ranking['snapshot_date'])): ?>
    <p class="page-subtitle">Uppdaterad <?= date('j F Y', strtotime($ranking['snapshot_date'])) ?></p>
  <?php endif; ?>
</div>

<!-- Discipline Tabs -->
<div class="discipline-tabs mb-md">
  <a href="/ranking?discipline=GRAVITY&view=<?= $view ?>"
     class="discipline-tab <?= $discipline === 'GRAVITY' ? 'active' : '' ?>">
    Gravity
  </a>
  <a href="/ranking?discipline=ENDURO&view=<?= $view ?>"
     class="discipline-tab <?= $discipline === 'ENDURO' ? 'active' : '' ?>">
    Enduro
  </a>
  <a href="/ranking?discipline=DH&view=<?= $view ?>"
     class="discipline-tab <?= $discipline === 'DH' ? 'active' : '' ?>">
    Downhill
  </a>
</div>

<!-- View Toggle -->
<div class="view-toggle mb-lg">
  <a href="/ranking?discipline=<?= $discipline ?>&view=riders"
     class="view-btn <?= $view === 'riders' ? 'active' : '' ?>">
    👤 Åkare
  </a>
  <a href="/ranking?discipline=<?= $discipline ?>&view=clubs"
     class="view-btn <?= $view === 'clubs' ? 'active' : '' ?>">
    🛡️ Klubbar
  </a>
</div>

<!-- Info Banner -->
<div class="info-banner mb-lg">
  <span class="info-icon">ℹ️</span>
  <span>24 månaders rullande ranking. Poäng viktas efter fältstorlek och eventtyp.</span>
</div>

<?php if ($error): ?>
<div class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</div>
<?php endif; ?>

<?php if (!$hasRankingSystem): ?>
<div class="card text-center">
  <div class="empty-state">
    <div class="empty-state-icon">⚙️</div>
    <h3>Rankingsystemet är inte konfigurerat</h3>
    <p class="text-muted">Kontakta administratör.</p>
  </div>
</div>
<?php elseif ($view === 'riders' && empty($ranking['riders'])): ?>
<div class="card text-center">
  <div class="empty-state">
    <div class="empty-state-icon">🏆</div>
    <h3>Ingen <?= $disciplineNames[$discipline] ?>-ranking ännu</h3>
    <p class="text-muted">Rankingen uppdateras efter att resultat har registrerats.</p>
  </div>
</div>
<?php elseif ($view === 'clubs' && empty($ranking['clubs'])): ?>
<div class="card text-center">
  <div class="empty-state">
    <div class="empty-state-icon">🛡️</div>
    <h3>Ingen klubbranking ännu</h3>
    <p class="text-muted">Klubbranking beräknas baserat på åkarnas poäng.</p>
  </div>
</div>
<?php else: ?>

<!-- Stats -->
<div class="stats-grid mb-lg">
  <div class="stat-card">
    <div class="stat-value"><?= number_format($ranking['total'] ?? 0) ?></div>
    <div class="stat-label"><?= $view === 'clubs' ? 'Klubbar' : 'Åkare' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-value">24</div>
    <div class="stat-label">Månader</div>
  </div>
</div>

<?php if ($view === 'riders' && !empty($ranking['riders'])): ?>
<!-- Riders Ranking -->
<section class="card">
  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="text-center" style="width:60px">#</th>
          <th class="col-rider">Åkare</th>
          <th class="table-col-hide-portrait">Klubb</th>
          <th class="text-center table-col-hide-portrait">Events</th>
          <th class="text-right">Poäng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ranking['riders'] as $rider): ?>
        <tr onclick="window.location='/rider/<?= $rider['rider_id'] ?>'" style="cursor:pointer">
          <td class="text-center">
            <?php if ($rider['ranking_position'] == 1): ?>
              <span class="medal">🥇</span>
            <?php elseif ($rider['ranking_position'] == 2): ?>
              <span class="medal">🥈</span>
            <?php elseif ($rider['ranking_position'] == 3): ?>
              <span class="medal">🥉</span>
            <?php else: ?>
              <span class="rank-number"><?= $rider['ranking_position'] ?></span>
            <?php endif; ?>
          </td>
          <td class="col-rider">
            <a href="/rider/<?= $rider['rider_id'] ?>" class="rider-link">
              <?= htmlspecialchars(($rider['firstname'] ?? '') . ' ' . ($rider['lastname'] ?? '')) ?>
            </a>
          </td>
          <td class="table-col-hide-portrait text-muted">
            <?= htmlspecialchars($rider['club_name'] ?? '-') ?>
          </td>
          <td class="text-center table-col-hide-portrait">
            <?= $rider['events_count'] ?? 0 ?>
          </td>
          <td class="text-right">
            <span class="points-value"><?= number_format($rider['total_ranking_points'] ?? $rider['total_points'] ?? 0, 1) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach ($ranking['riders'] as $rider): ?>
    <a href="/rider/<?= $rider['rider_id'] ?>" class="result-item">
      <div class="result-place <?= $rider['ranking_position'] <= 3 ? 'top-3' : '' ?>">
        <?php if ($rider['ranking_position'] == 1): ?>🥇
        <?php elseif ($rider['ranking_position'] == 2): ?>🥈
        <?php elseif ($rider['ranking_position'] == 3): ?>🥉
        <?php else: ?><?= $rider['ranking_position'] ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars(($rider['firstname'] ?? '') . ' ' . ($rider['lastname'] ?? '')) ?></div>
        <div class="result-club">
          <?= htmlspecialchars($rider['club_name'] ?? '-') ?>
          <?php if (!empty($rider['events_count'])): ?>
            • <?= $rider['events_count'] ?> events
          <?php endif; ?>
        </div>
      </div>
      <div class="result-points">
        <div class="points-big"><?= number_format($rider['total_ranking_points'] ?? $rider['total_points'] ?? 0, 1) ?></div>
        <div class="points-label">poäng</div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>

<?php elseif ($view === 'clubs' && !empty($ranking['clubs'])): ?>
<!-- Clubs Ranking -->
<section class="card">
  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="text-center" style="width:60px">#</th>
          <th class="col-club">Klubb</th>
          <th class="text-center table-col-hide-portrait">Åkare</th>
          <th class="text-right">Poäng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ranking['clubs'] as $club): ?>
        <tr onclick="window.location='/club/<?= $club['club_id'] ?>'" style="cursor:pointer">
          <td class="text-center">
            <?php if ($club['ranking_position'] == 1): ?>
              <span class="medal">🥇</span>
            <?php elseif ($club['ranking_position'] == 2): ?>
              <span class="medal">🥈</span>
            <?php elseif ($club['ranking_position'] == 3): ?>
              <span class="medal">🥉</span>
            <?php else: ?>
              <span class="rank-number"><?= $club['ranking_position'] ?></span>
            <?php endif; ?>
          </td>
          <td class="col-club">
            <a href="/club/<?= $club['club_id'] ?>" class="club-link">
              <?= htmlspecialchars($club['club_name'] ?? '') ?>
            </a>
          </td>
          <td class="text-center table-col-hide-portrait">
            <?= $club['riders_count'] ?? 0 ?>
          </td>
          <td class="text-right">
            <span class="points-value"><?= number_format($club['total_ranking_points'] ?? 0, 1) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach ($ranking['clubs'] as $club): ?>
    <a href="/club/<?= $club['club_id'] ?>" class="result-item">
      <div class="result-place <?= $club['ranking_position'] <= 3 ? 'top-3' : '' ?>">
        <?php if ($club['ranking_position'] == 1): ?>🥇
        <?php elseif ($club['ranking_position'] == 2): ?>🥈
        <?php elseif ($club['ranking_position'] == 3): ?>🥉
        <?php else: ?><?= $club['ranking_position'] ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($club['club_name'] ?? '') ?></div>
        <div class="result-club"><?= $club['riders_count'] ?? 0 ?> åkare</div>
      </div>
      <div class="result-points">
        <div class="points-big"><?= number_format($club['total_ranking_points'] ?? 0, 1) ?></div>
        <div class="points-label">poäng</div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="pagination mt-lg" aria-label="Sidnavigering">
  <?php if ($page > 1): ?>
    <a href="/ranking?discipline=<?= $discipline ?>&view=<?= $view ?>&page=<?= $page - 1 ?>" class="btn btn--ghost">← Föregående</a>
  <?php endif; ?>

  <span class="pagination-info">Sida <?= $page ?> av <?= $totalPages ?></span>

  <?php if ($page < $totalPages): ?>
    <a href="/ranking?discipline=<?= $discipline ?>&view=<?= $view ?>&page=<?= $page + 1 ?>" class="btn btn--ghost">Nästa →</a>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php endif; ?>

<style>
.page-header {
  margin-bottom: var(--space-lg);
}
.page-title {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  margin: 0;
}
.page-subtitle {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin-top: var(--space-xs);
}

.discipline-tabs {
  display: flex;
  gap: var(--space-xs);
  background: var(--color-bg-sunken);
  padding: var(--space-xs);
  border-radius: var(--radius-lg);
  overflow-x: auto;
}
.discipline-tab {
  flex: 1;
  padding: var(--space-sm) var(--space-md);
  text-align: center;
  border-radius: var(--radius-md);
  font-weight: var(--weight-medium);
  color: var(--color-text-secondary);
  white-space: nowrap;
  transition: all var(--transition-fast);
}
.discipline-tab:hover {
  background: var(--color-bg-hover);
  color: var(--color-text);
}
.discipline-tab.active {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}

.view-toggle {
  display: flex;
  gap: var(--space-sm);
}
.view-btn {
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-md);
  font-weight: var(--weight-medium);
  color: var(--color-text-secondary);
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  transition: all var(--transition-fast);
}
.view-btn:hover {
  background: var(--color-bg-hover);
}
.view-btn.active {
  background: var(--color-accent-light);
  color: var(--color-accent-text);
  border-color: var(--color-accent);
}

.info-banner {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-sm) var(--space-md);
  background: var(--color-bg-sunken);
  border-radius: var(--radius-md);
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
}
.info-icon {
  flex-shrink: 0;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-md);
}
.stat-card {
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-md);
  text-align: center;
}
.stat-value {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}
.stat-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  margin-top: var(--space-2xs);
}

.medal {
  font-size: var(--text-lg);
}
.rank-number {
  font-weight: var(--weight-semibold);
  color: var(--color-text-secondary);
}
.rider-link, .club-link {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}
.rider-link:hover, .club-link:hover {
  color: var(--color-accent-text);
}
.points-value {
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}

.result-place.top-3 {
  background: var(--color-accent-light);
}
.result-points {
  text-align: right;
}
.points-big {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}
.points-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: var(--space-md);
}
.pagination-info {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
}

.empty-state {
  padding: var(--space-2xl);
}
.empty-state-icon {
  font-size: 48px;
  margin-bottom: var(--space-md);
}
.empty-state h3 {
  margin: 0 0 var(--space-sm) 0;
}

.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.mt-lg { margin-top: var(--space-lg); }
.text-center { text-align: center; }
.text-muted { color: var(--color-text-muted); }

@media (max-width: 599px) {
  .discipline-tabs {
    gap: 2px;
    padding: 2px;
  }
  .discipline-tab {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-sm);
  }
  .view-toggle {
    flex-direction: column;
  }
  .view-btn {
    text-align: center;
  }
  .stats-grid {
    grid-template-columns: 1fr 1fr;
  }
  .page-title {
    font-size: var(--text-xl);
  }
}
</style>
