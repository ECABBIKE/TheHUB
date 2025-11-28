<?php
/**
 * V3 Riders Page - Matches v2 structure with search and ranking
 */

$db = hub_db();

// Get search query
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

try {
    // Count total active riders
    $totalCount = $db->query("SELECT COUNT(*) FROM riders WHERE active = 1")->fetchColumn();

    // Fetch riders with stats - matching v2 structure
    if ($search !== '') {
        $searchTerm = '%' . $search . '%';
        $stmt = $db->prepare("
            SELECT
                c.id,
                c.firstname,
                c.lastname,
                c.birth_year,
                c.gender,
                c.license_number,
                c.license_type,
                cl.name as club_name,
                cl.id as club_id,
                COUNT(DISTINCT r.id) as total_races,
                COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
                MIN(r.position) as best_position,
                SUM(COALESCE(r.points, 0)) as total_points
            FROM riders c
            LEFT JOIN clubs cl ON c.club_id = cl.id
            LEFT JOIN results r ON c.id = r.cyclist_id
            WHERE c.active = 1
              AND (c.firstname LIKE ? OR c.lastname LIKE ? OR cl.name LIKE ?
                   OR CONCAT(c.firstname, ' ', c.lastname) LIKE ?
                   OR c.license_number LIKE ?)
            GROUP BY c.id
            ORDER BY total_races DESC, c.lastname, c.firstname
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    } else {
        // Show all riders, ordered by most races first
        $stmt = $db->query("
            SELECT
                c.id,
                c.firstname,
                c.lastname,
                c.birth_year,
                c.gender,
                c.license_number,
                c.license_type,
                cl.name as club_name,
                cl.id as club_id,
                COUNT(DISTINCT r.id) as total_races,
                COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
                MIN(r.position) as best_position,
                SUM(COALESCE(r.points, 0)) as total_points
            FROM riders c
            LEFT JOIN clubs cl ON c.club_id = cl.id
            LEFT JOIN results r ON c.id = r.cyclist_id
            WHERE c.active = 1
            GROUP BY c.id
            ORDER BY total_races DESC, c.lastname, c.firstname
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
  <form method="get" action="/v3/riders" class="search-form" role="search">
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
      <a href="/v3/riders" class="btn btn--ghost">Visa alla</a>
    <?php endif; ?>
  </form>
</section>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
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
        <tr onclick="window.location='/v3/rider/<?= $rider['id'] ?>'" style="cursor:pointer">
          <td class="col-rider">
            <a href="/v3/rider/<?= $rider['id'] ?>" class="rider-link">
              <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?>
            </a>
            <?php if ($rider['birth_year']): ?>
              <span class="rider-year"><?= $rider['birth_year'] ?></span>
            <?php endif; ?>
          </td>
          <td class="col-club table-col-hide-portrait">
            <?php if ($rider['club_id']): ?>
              <a href="/v3/club/<?= $rider['club_id'] ?>"><?= htmlspecialchars($rider['club_name'] ?? '-') ?></a>
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
                <span class="position-badge position--1">🥇</span>
              <?php elseif ($rider['best_position'] == 2): ?>
                <span class="position-badge position--2">🥈</span>
              <?php elseif ($rider['best_position'] == 3): ?>
                <span class="position-badge position--3">🥉</span>
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
    <a href="/v3/rider/<?= $rider['id'] ?>" class="result-item">
      <div class="result-place">
        <?php if ($rider['best_position'] && $rider['best_position'] <= 3): ?>
          <?= $rider['best_position'] == 1 ? '🥇' : ($rider['best_position'] == 2 ? '🥈' : '🥉') ?>
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

<style>
.page-header {
  margin-bottom: var(--space-lg);
}
.page-title {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  margin: 0 0 var(--space-sm) 0;
}
.page-meta {
  display: flex;
  gap: var(--space-sm);
  flex-wrap: wrap;
}
.chip--primary {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}
.chip--info {
  background: var(--color-info-light, rgba(59, 130, 246, 0.1));
  color: var(--color-accent-text);
}
.mb-lg { margin-bottom: var(--space-lg); }
.mb-md { margin-bottom: var(--space-md); }

.search-form {
  display: flex;
  gap: var(--space-sm);
  flex-wrap: wrap;
  align-items: stretch;
}
.search-wrapper {
  flex: 1;
  min-width: 0;
  position: relative;
  display: flex;
  align-items: center;
}
.search-icon {
  position: absolute;
  left: var(--space-sm);
  font-size: var(--text-base);
  pointer-events: none;
}
.search-input {
  width: 100%;
  padding: var(--space-sm) var(--space-md) var(--space-sm) calc(var(--space-sm) + 24px);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg-surface);
  color: var(--color-text);
  font-size: var(--text-base);
}
.search-input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.rider-link {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}
.rider-link:hover {
  color: var(--color-accent-text);
}
.rider-year {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  margin-left: var(--space-xs);
}
.license-badge {
  font-family: var(--font-mono);
  font-size: var(--text-xs);
  padding: 2px 6px;
  background: var(--color-bg-sunken);
  border-radius: var(--radius-sm);
}
.podium-badge {
  font-size: var(--text-sm);
}
.position-badge {
  font-weight: var(--weight-semibold);
}
.points-value {
  font-weight: var(--weight-semibold);
  color: var(--color-accent-text);
}
.text-muted {
  color: var(--color-text-muted);
}

.empty-state {
  text-align: center;
  padding: var(--space-2xl);
  color: var(--color-text-muted);
}
.empty-state-icon {
  font-size: 48px;
  margin-bottom: var(--space-md);
}

.table--hover tbody tr:hover {
  background: var(--color-bg-hover);
}

.result-stats {
  text-align: right;
  font-size: var(--text-xs);
}
.result-races {
  color: var(--color-text-secondary);
}
.result-podiums {
  color: var(--color-accent-text);
}

@media (max-width: 599px) {
  .search-form {
    flex-direction: column;
  }
  .search-form .btn {
    width: 100%;
  }
  .page-title {
    font-size: var(--text-xl);
  }
}
</style>
