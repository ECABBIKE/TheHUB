<?php
/**
 * V3 Series Page - All competition series with badge design
 *
 * Uses the TheHUB Badge Design System for a bold, modern display.
 */

// Include badge component
require_once HUB_V3_ROOT . '/components/series-badge.php';

$db = hub_db();

try {
    // Get all series with event and participant counts (including badge styling fields)
    $series = $db->query("
        SELECT s.id, s.name, s.slug, s.description, s.year, s.status,
               s.type, s.discipline,
               s.logo, s.logo_light, s.logo_dark,
               s.gradient_start, s.gradient_end, s.accent_color,
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

<!-- Badge Grid -->
<?php render_series_badge_grid($series, [
    'badge_options' => [
        'show_discipline' => true,
        'show_cta' => true,
        'cta_text' => 'Visa ställning'
    ]
]); ?>

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

/* Badge grid empty state */
.badge-grid-empty {
  text-align: center;
  padding: var(--space-2xl);
  color: var(--color-text-muted);
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
}
</style>
