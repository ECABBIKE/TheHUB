<?php
/**
 * V3 Riders Page - Shows all riders with search
 */

$db = hub_db();

// Get search query if any
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

try {
    // Count total active riders
    $totalCount = $db->query("SELECT COUNT(*) FROM riders WHERE active = 1")->fetchColumn();

    // Fetch riders with stats
    if ($search !== '') {
        $searchTerm = '%' . $search . '%';
        $stmt = $db->prepare("
            SELECT
                c.id,
                c.firstname,
                c.lastname,
                c.birth_year,
                c.gender,
                cl.name as club_name,
                COUNT(DISTINCT r.id) as total_races,
                COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
                MIN(r.position) as best_position
            FROM riders c
            LEFT JOIN clubs cl ON c.club_id = cl.id
            LEFT JOIN results r ON c.id = r.cyclist_id
            WHERE c.active = 1
              AND (c.firstname LIKE ? OR c.lastname LIKE ? OR cl.name LIKE ?
                   OR CONCAT(c.firstname, ' ', c.lastname) LIKE ?)
            GROUP BY c.id
            ORDER BY c.lastname, c.firstname
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    } else {
        $stmt = $db->query("
            SELECT
                c.id,
                c.firstname,
                c.lastname,
                c.birth_year,
                c.gender,
                cl.name as club_name,
                COUNT(DISTINCT r.id) as total_races,
                COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
                MIN(r.position) as best_position
            FROM riders c
            LEFT JOIN clubs cl ON c.club_id = cl.id
            LEFT JOIN results r ON c.id = r.cyclist_id
            WHERE c.active = 1
            GROUP BY c.id
            ORDER BY c.lastname, c.firstname
        ");
    }
    $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $resultCount = count($riders);
} catch (Exception $e) {
    $riders = [];
    $totalCount = 0;
    $resultCount = 0;
    $error = $e->getMessage();
}
?>

<h1 class="text-2xl font-bold mb-lg">Åkare</h1>

<div class="page-grid">
  <!-- Search -->
  <section class="card grid-full">
    <form method="get" action="/v3/riders" class="search-form" role="search">
      <label for="rider-search" class="sr-only">Sök åkare</label>
      <input
        type="search"
        id="rider-search"
        name="q"
        placeholder="Sök namn eller klubb..."
        value="<?= htmlspecialchars($search) ?>"
        class="search-input"
        autocomplete="off"
      >
      <button type="submit" class="btn btn--primary">Sök</button>
      <?php if ($search): ?>
        <a href="/v3/riders" class="btn btn--ghost">Rensa</a>
      <?php endif; ?>
    </form>
  </section>

  <!-- Stats -->
  <section class="card">
    <div class="card-title">Statistik</div>
    <div class="stats-row">
      <div class="stat-block">
        <div class="stat-value"><?= number_format($totalCount) ?></div>
        <div class="stat-label">Totalt aktiva</div>
      </div>
      <?php if ($search): ?>
      <div class="stat-block">
        <div class="stat-value"><?= number_format($resultCount) ?></div>
        <div class="stat-label">Träffar</div>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if (isset($error)): ?>
  <section class="card grid-full">
    <div class="card-title" style="color: var(--color-error)">Fel</div>
    <p><?= htmlspecialchars($error) ?></p>
  </section>
  <?php endif; ?>

  <!-- Rider List -->
  <section class="card grid-full" aria-labelledby="riders-title">
    <div class="card-header">
      <div>
        <h2 id="riders-title" class="card-title">
          <?= $search ? 'Sökresultat' : 'Alla åkare' ?>
        </h2>
        <p class="card-subtitle">
          <?php if ($search): ?>
            Visar <?= $resultCount ?> träffar för "<?= htmlspecialchars($search) ?>"
          <?php else: ?>
            Visar <?= $resultCount ?> åkare
          <?php endif; ?>
        </p>
      </div>
    </div>

    <?php if (empty($riders)): ?>
      <p class="text-muted p-lg">
        <?= $search ? 'Inga åkare hittades för "' . htmlspecialchars($search) . '"' : 'Inga åkare att visa' ?>
      </p>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table table--striped table--clickable">
        <thead>
          <tr>
            <th class="col-rider" scope="col">Namn</th>
            <th class="col-club table-col-hide-portrait" scope="col">Klubb</th>
            <th scope="col" class="text-right">Starter</th>
            <th scope="col" class="text-right table-col-hide-portrait">Pallplatser</th>
            <th scope="col" class="text-right">Bästa</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($riders as $rider): ?>
          <tr data-href="/v3/rider/<?= $rider['id'] ?>">
            <td class="col-rider"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></td>
            <td class="col-club table-col-hide-portrait"><?= htmlspecialchars($rider['club_name'] ?? '-') ?></td>
            <td class="text-right"><?= $rider['total_races'] ?></td>
            <td class="text-right table-col-hide-portrait"><?= $rider['podiums'] ?></td>
            <td class="text-right"><?= $rider['best_position'] ? '#' . $rider['best_position'] : '-' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card View -->
    <div class="result-list">
      <?php foreach ($riders as $rider): ?>
      <a href="/v3/rider/<?= $rider['id'] ?>" class="result-item">
        <div class="result-place"><?= $rider['total_races'] ?: '0' ?></div>
        <div class="result-info">
          <div class="result-name"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></div>
          <div class="result-club"><?= htmlspecialchars($rider['club_name'] ?? 'Ingen klubb') ?></div>
        </div>
        <div class="result-time"><?= $rider['best_position'] ? '#' . $rider['best_position'] : '-' ?></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<style>
.search-form {
  display: flex;
  gap: var(--space-sm);
  flex-wrap: wrap;
  width: 100%;
}
.search-input {
  flex: 1;
  min-width: 0;
  width: 100%;
  padding: var(--space-sm) var(--space-md);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  font-size: var(--text-base);
}
.search-input:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.search-input::placeholder {
  color: var(--color-text-muted);
}
@media (max-width: 599px) {
  .search-form {
    flex-direction: column;
  }
  .search-form .btn {
    width: 100%;
  }
}
</style>
