<?php
/**
 * V3 Riders Page - Matches v2 structure with search and ranking
 */

$db = hub_db();

// Load filter setting from database (with file fallback)
$filter = site_setting('public_riders_display', 'with_results');

// Get search query
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

try {
    // Count total active riders based on filter
    if ($filter === 'with_results') {
        $totalCount = $db->query("
            SELECT COUNT(DISTINCT r.id)
            FROM riders r
            INNER JOIN results res ON r.id = res.cyclist_id
            WHERE r.active = 1
        ")->fetchColumn();
    } else {
        $totalCount = $db->query("SELECT COUNT(*) FROM riders WHERE active = 1")->fetchColumn();
    }

    // Determine JOIN type based on filter
    $joinType = ($filter === 'with_results') ? 'INNER' : 'LEFT';

    // Pre-aggregate results per rider (much faster than GROUP BY on main query with many JOINs)
    // Also pre-aggregate rider_club_seasons to avoid correlated subquery
    $riderStatsSubquery = "
        SELECT cyclist_id,
               COUNT(*) as total_races,
               COUNT(CASE WHEN position <= 3 THEN 1 END) as podiums,
               MIN(position) as best_position,
               SUM(COALESCE(points, 0)) as total_points
        FROM results
        GROUP BY cyclist_id
    ";

    $rcsSubquery = "
        SELECT rcs.rider_id, rcs.club_id
        FROM rider_club_seasons rcs
        INNER JOIN (
            SELECT rider_id, MAX(season_year) as max_year
            FROM rider_club_seasons
            GROUP BY rider_id
        ) rcs_max ON rcs.rider_id = rcs_max.rider_id AND rcs.season_year = rcs_max.max_year
    ";

    if ($search !== '') {
        // Split search into words for better matching
        $searchWords = preg_split('/\s+/', $search);
        if (count($searchWords) >= 2) {
            // Multi-word: match firstname + lastname
            $whereSearch = "(c.firstname LIKE ? AND c.lastname LIKE ?)";
            $searchParams = ['%' . $searchWords[0] . '%', '%' . $searchWords[1] . '%'];
        } else {
            $searchTerm = '%' . $search . '%';
            $whereSearch = "(c.firstname LIKE ? OR c.lastname LIKE ? OR c.license_number LIKE ?)";
            $searchParams = [$searchTerm, $searchTerm, $searchTerm];
        }

        $stmt = $db->prepare("
            SELECT c.id, c.firstname, c.lastname, c.birth_year, c.gender,
                   c.license_number, c.license_type,
                   COALESCE(cl.name, cl_season.name) as club_name,
                   COALESCE(cl.id, rcs_latest.club_id) as club_id,
                   COALESCE(rs.total_races, 0) as total_races,
                   COALESCE(rs.podiums, 0) as podiums,
                   rs.best_position,
                   COALESCE(rs.total_points, 0) as total_points
            FROM riders c
            LEFT JOIN clubs cl ON c.club_id = cl.id
            LEFT JOIN ({$rcsSubquery}) rcs_latest ON rcs_latest.rider_id = c.id AND c.club_id IS NULL
            LEFT JOIN clubs cl_season ON rcs_latest.club_id = cl_season.id
            {$joinType} JOIN ({$riderStatsSubquery}) rs ON rs.cyclist_id = c.id
            WHERE c.active = 1 AND {$whereSearch}
            ORDER BY total_races DESC, c.lastname, c.firstname
            LIMIT 200
        ");
        $stmt->execute($searchParams);
    } else {
        $stmt = $db->query("
            SELECT c.id, c.firstname, c.lastname, c.birth_year, c.gender,
                   c.license_number, c.license_type,
                   COALESCE(cl.name, cl_season.name) as club_name,
                   COALESCE(cl.id, rcs_latest.club_id) as club_id,
                   COALESCE(rs.total_races, 0) as total_races,
                   COALESCE(rs.podiums, 0) as podiums,
                   rs.best_position,
                   COALESCE(rs.total_points, 0) as total_points
            FROM riders c
            LEFT JOIN clubs cl ON c.club_id = cl.id
            LEFT JOIN ({$rcsSubquery}) rcs_latest ON rcs_latest.rider_id = c.id AND c.club_id IS NULL
            LEFT JOIN clubs cl_season ON rcs_latest.club_id = cl_season.id
            {$joinType} JOIN ({$riderStatsSubquery}) rs ON rs.cyclist_id = c.id
            WHERE c.active = 1
            ORDER BY total_races DESC, c.lastname, c.firstname
            LIMIT 500
        ");
    }
    $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $resultCount = count($riders);

    // Count clubs
    $clubCount = $db->query("SELECT COUNT(*) FROM clubs WHERE active = 1")->fetchColumn();

} catch (Exception $e) {
    $riders = [];
    $totalCount = 0;
    $resultCount = 0;
    $clubCount = 0;
    $error = $e->getMessage();
}
?>

<div class="page-header">
  <h1 class="page-title">Aktiva Deltagare</h1>
  <div class="page-meta">
    <span class="chip chip--primary"><?= number_format($totalCount) ?> deltagare</span>
    <span class="chip"><?= number_format($clubCount) ?> klubbar</span>
  </div>
</div>

<!-- Search -->
<section class="card mb-lg">
  <form method="get" action="/riders" class="search-form" role="search">
    <div class="search-wrapper">
      <span class="search-icon">🔍</span>
      <input
        type="search"
        id="rider-search"
        name="q"
        placeholder="Sök namn, klubb eller licens..."
        value="<?= htmlspecialchars($search) ?>"
        class="search-input"
        autocomplete="off"
      >
    </div>
    <button type="submit" class="btn btn--primary">Sök</button>
    <?php if ($search): ?>
      <a href="/riders" class="btn btn--ghost">Visa alla</a>
    <?php endif; ?>
  </form>
</section>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title text-error">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<?php if ($search): ?>
<div class="search-results-info mb-md">
  <span class="chip chip--info">
    <?= $resultCount ?> träffar för "<?= htmlspecialchars($search) ?>"
  </span>
</div>
<?php endif; ?>

<!-- Rider Table (Desktop/Landscape) -->
<section class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title"><?= $search ? 'Sökresultat' : 'Alla deltagare' ?></h2>
      <p class="card-subtitle">Sorterat efter antal starter</p>
    </div>
  </div>

  <?php if (empty($riders)): ?>
    <div class="empty-state">
      <div class="empty-state-icon">🔍</div>
      <p><?= $search ? 'Inga deltagare hittades för "' . htmlspecialchars($search) . '"' : 'Inga deltagare att visa' ?></p>
    </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="col-rider">Namn</th>
          <th class="col-club table-col-hide-portrait">Klubb</th>
          <th class="col-license table-col-hide-portrait">Licens</th>
          <th class="text-center">Starter</th>
          <th class="text-center table-col-hide-portrait">Pallplatser</th>
          <th class="text-center">Bästa</th>
          <th class="text-right table-col-hide-portrait">Poäng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($riders as $rider): ?>
        <tr onclick="window.location='/rider/<?= $rider['id'] ?>'" class="cursor-pointer">
          <td class="col-rider">
            <a href="/rider/<?= $rider['id'] ?>" class="rider-link">
              <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?>
            </a>
            <?php if ($rider['birth_year']): ?>
              <span class="rider-year"><?= $rider['birth_year'] ?></span>
            <?php endif; ?>
          </td>
          <td class="col-club table-col-hide-portrait">
            <?php if ($rider['club_id']): ?>
              <a href="/club/<?= $rider['club_id'] ?>"><?= htmlspecialchars($rider['club_name'] ?? '-') ?></a>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td class="col-license table-col-hide-portrait">
            <?php if ($rider['license_number']): ?>
              <span class="license-badge"><?= htmlspecialchars($rider['license_number']) ?></span>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <strong><?= $rider['total_races'] ?: 0 ?></strong>
          </td>
          <td class="text-center table-col-hide-portrait">
            <?php if ($rider['podiums'] > 0): ?>
              <span class="podium-badge">🏆 <?= $rider['podiums'] ?></span>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($rider['best_position']): ?>
              <?php if ($rider['best_position'] == 1): ?>
                <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
              <?php elseif ($rider['best_position'] == 2): ?>
                <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
              <?php elseif ($rider['best_position'] == 3): ?>
                <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
              <?php else: ?>
                <span class="position-badge">#<?= $rider['best_position'] ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td class="text-right table-col-hide-portrait">
            <?php if ($rider['total_points'] > 0): ?>
              <span class="points-value"><?= number_format($rider['total_points'], 0) ?></span>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach ($riders as $rider): ?>
    <a href="/rider/<?= $rider['id'] ?>" class="result-item">
      <div class="result-place">
        <?php if ($rider['best_position'] && $rider['best_position'] <= 3): ?>
          <?php if ($rider['best_position'] == 1): ?>
            <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
          <?php elseif ($rider['best_position'] == 2): ?>
            <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
          <?php else: ?>
            <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
          <?php endif; ?>
        <?php else: ?>
          <?= $rider['total_races'] ?: 0 ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></div>
        <div class="result-club"><?= htmlspecialchars($rider['club_name'] ?? 'Ingen klubb') ?></div>
      </div>
      <div class="result-stats">
        <div class="result-races"><?= $rider['total_races'] ?: 0 ?> starter</div>
        <?php if ($rider['podiums'] > 0): ?>
          <div class="result-podiums">🏆 <?= $rider['podiums'] ?></div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
