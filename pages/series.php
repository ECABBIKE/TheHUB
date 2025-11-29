<?php
/**
 * V3 Series Page - All competition series with links to standings
 */

$db = hub_db();

try {
    // Get all series with event and participant counts
    $series = $db->query("
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo,
               COUNT(DISTINCT e.id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN events e2 ON r.event_id = e2.id
                WHERE e2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN events e ON s.id = e.series_id
        WHERE s.status = 'active'
        GROUP BY s.id
        ORDER BY s.year DESC, s.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $totalSeries = count($series);
    $totalEvents = array_sum(array_column($series, 'event_count'));

    // Count unique participants across all series
    $uniqueParticipants = $db->query("
        SELECT COUNT(DISTINCT r.cyclist_id) as total
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN series s ON e.series_id = s.id
        WHERE s.status = 'active'
    ")->fetch(PDO::FETCH_ASSOC);
    $totalParticipants = $uniqueParticipants['total'] ?? 0;

} catch (Exception $e) {
    $series = [];
    $totalSeries = 0;
    $totalEvents = 0;
    $totalParticipants = 0;
    $error = $e->getMessage();
}
?>

<div class="page-header">
  <h1 class="page-title">Tävlingsserier</h1>
  <p class="page-subtitle">Alla GravitySeries och andra tävlingsserier</p>
</div>

<!-- Stats -->
<section class="card mb-lg">
  <div class="stats-row">
    <div class="stat-block">
      <div class="stat-value"><?= $totalSeries ?></div>
      <div class="stat-label">Aktiva serier</div>
    </div>
    <div class="stat-block">
      <div class="stat-value"><?= $totalEvents ?></div>
      <div class="stat-label">Totalt event</div>
    </div>
    <div class="stat-block">
      <div class="stat-value"><?= number_format($totalParticipants) ?></div>
      <div class="stat-label">Unika deltagare</div>
    </div>
  </div>
</section>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<?php if (empty($series)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🏆</div>
    <h3>Inga aktiva serier ännu</h3>
    <p class="text-muted">Det finns inga tävlingsserier registrerade.</p>
  </div>
</section>
<?php else: ?>

<div class="series-grid">
  <?php foreach ($series as $s): ?>
  <a href="/series/<?= $s['id'] ?>" class="series-card">
    <div class="series-header">
      <div class="series-title"><?= htmlspecialchars($s['name']) ?></div>
      <div class="series-year"><?= $s['year'] ?></div>
    </div>
    <?php if ($s['description']): ?>
    <p class="series-description"><?= htmlspecialchars($s['description']) ?></p>
    <?php endif; ?>
    <div class="series-stats">
      <span class="series-stat">
        <span class="stat-icon">📅</span>
        <?= $s['event_count'] ?> <?= $s['event_count'] == 1 ? 'tävling' : 'tävlingar' ?>
      </span>
      <?php if ($s['participant_count'] > 0): ?>
      <span class="series-stat">
        <span class="stat-icon">👥</span>
        <?= number_format($s['participant_count']) ?> deltagare
      </span>
      <?php endif; ?>
    </div>
    <div class="series-action">
      Visa ställning →
    </div>
  </a>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<style>
.page-header {
  margin-bottom: var(--space-lg);
}
.page-title {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  margin: 0 0 var(--space-xs) 0;
}
.page-subtitle {
  color: var(--color-text-secondary);
  margin: 0;
}

.mb-lg { margin-bottom: var(--space-lg); }

.stats-row {
  display: flex;
  gap: var(--space-lg);
}
.stat-block {
  flex: 1;
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

.empty-state {
  text-align: center;
  padding: var(--space-2xl);
}
.empty-state-icon {
  font-size: 48px;
  margin-bottom: var(--space-md);
}
.empty-state h3 {
  margin: 0 0 var(--space-sm) 0;
}
.text-muted {
  color: var(--color-text-muted);
}

.series-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
  gap: var(--space-lg);
}

.series-card {
  display: flex;
  flex-direction: column;
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-lg);
  transition: all var(--transition-fast);
}
.series-card:hover {
  border-color: var(--color-accent);
  transform: translateY(-2px);
  box-shadow: var(--shadow-lg);
}

.series-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-sm);
  margin-bottom: var(--space-sm);
}
.series-title {
  font-size: var(--text-lg);
  font-weight: var(--weight-semibold);
  color: var(--color-text);
}
.series-year {
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  padding: var(--space-2xs) var(--space-sm);
  background: var(--color-accent);
  color: var(--color-text-inverse);
  border-radius: var(--radius-full);
}

.series-description {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin: 0 0 var(--space-md) 0;
  flex: 1;
}

.series-stats {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-md);
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin-bottom: var(--space-md);
}
.series-stat {
  display: flex;
  align-items: center;
  gap: var(--space-2xs);
}
.stat-icon {
  font-size: var(--text-base);
}

.series-action {
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  color: var(--color-accent-text);
}

@media (max-width: 599px) {
  .stats-row {
    gap: var(--space-md);
  }
  .stat-value {
    font-size: var(--text-xl);
  }
  .page-title {
    font-size: var(--text-xl);
  }
  .series-card {
    padding: var(--space-md);
  }
}
</style>
