<?php
/**
 * V3 Series Page - All competition series
 *
 * Simple filtering:
 * - Filter by series name (shows all years for that series)
 * - Filter by year (shows all series for that year)
 */

// Include badge component
require_once HUB_V3_ROOT . '/components/series-badge.php';

$db = hub_db();

// Get filter parameters
$filterSeries = isset($_GET['series']) ? trim($_GET['series']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

try {
    // Get all series for building filter options
    $allSeries = $db->query("
        SELECT id, name, year, slug, status
        FROM series
        WHERE status IN ('active', 'completed')
        ORDER BY year DESC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Extract unique years (sorted descending)
    $availableYears = array_unique(array_filter(array_column($allSeries, 'year')));
    rsort($availableYears);

    // Extract unique series names (base name without year)
    $seriesNames = [];
    foreach ($allSeries as $s) {
        // Remove trailing year from name (e.g., "GravitySeries 2024" -> "GravitySeries")
        $baseName = trim(preg_replace('/\s*\d{4}$/', '', $s['name']));
        if (empty($baseName)) $baseName = $s['name'];
        if (!in_array($baseName, $seriesNames)) {
            $seriesNames[] = $baseName;
        }
    }
    sort($seriesNames);

    // Determine what to display
    if ($filterSeries) {
        // Filter by series name - show all years for this series
        $matchingSeries = [];
        foreach ($allSeries as $s) {
            $baseName = trim(preg_replace('/\s*\d{4}$/', '', $s['name']));
            if (empty($baseName)) $baseName = $s['name'];
            if (strcasecmp($baseName, $filterSeries) === 0) {
                $matchingSeries[] = $s['id'];
            }
        }

        if (!empty($matchingSeries)) {
            $placeholders = implode(',', array_fill(0, count($matchingSeries), '?'));
            $stmt = $db->prepare("
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
                WHERE s.id IN ($placeholders)
                GROUP BY s.id
                ORDER BY s.year DESC
            ");
            $stmt->execute($matchingSeries);
            $series = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $series = [];
        }

        $pageTitle = htmlspecialchars($filterSeries);
        $pageSubtitle = 'Alla säsonger';
    } else {
        // Filter by year (or default to most recent year)
        if ($filterYear === null && !empty($availableYears)) {
            $filterYear = $availableYears[0];
        }

        $stmt = $db->prepare("
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
            WHERE s.status IN ('active', 'completed') AND s.year = ?
            GROUP BY s.id
            ORDER BY s.name ASC
        ");
        $stmt->execute([$filterYear]);
        $series = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pageTitle = "Tävlingsserier $filterYear";
        $pageSubtitle = 'Alla serier detta år';
    }

    // Calculate stats
    $totalSeries = count($series);
    $totalEvents = array_sum(array_column($series, 'event_count'));
    $totalParticipants = array_sum(array_column($series, 'participant_count'));

} catch (Exception $e) {
    $series = [];
    $availableYears = [];
    $seriesNames = [];
    $totalSeries = 0;
    $totalEvents = 0;
    $totalParticipants = 0;
    $pageTitle = 'Tävlingsserier';
    $pageSubtitle = '';
    $error = $e->getMessage();
}
?>

<div class="page-header">
  <div class="page-header-row">
    <div>
      <h1 class="page-title"><?= $pageTitle ?></h1>
      <?php if ($pageSubtitle): ?>
      <p class="page-subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>
      <?php endif; ?>
    </div>
    <div class="filter-selectors">
      <?php if (!empty($seriesNames)): ?>
      <div class="filter-group">
        <label class="filter-label">Serie</label>
        <select class="filter-select" onchange="if(this.value) window.location.href='?series=' + encodeURIComponent(this.value); else window.location.href='?year=<?= $filterYear ?? '' ?>';">
          <option value="">Välj serie...</option>
          <?php foreach ($seriesNames as $name): ?>
            <option value="<?= htmlspecialchars($name) ?>" <?= ($filterSeries && strcasecmp($filterSeries, $name) === 0) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if (!empty($availableYears)): ?>
      <div class="filter-group">
        <label class="filter-label">År</label>
        <select class="filter-select" onchange="window.location.href='?year=' + this.value">
          <?php foreach ($availableYears as $year): ?>
            <option value="<?= $year ?>" <?= (!$filterSeries && $year == $filterYear) ? 'selected' : '' ?>><?= $year ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Stats -->
<section class="card mb-lg">
  <div class="stats-row">
    <div class="stat-block">
      <div class="stat-value"><?= $totalSeries ?></div>
      <div class="stat-label"><?= $filterSeries ? 'Säsonger' : 'Serier' ?></div>
    </div>
    <div class="stat-block">
      <div class="stat-value"><?= $totalEvents ?></div>
      <div class="stat-label">Totalt event</div>
    </div>
    <div class="stat-block">
      <div class="stat-value"><?= number_format($totalParticipants) ?></div>
      <div class="stat-label">Deltagare</div>
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
    <i data-lucide="trophy" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
    <h3>Inga serier hittades</h3>
    <p class="text-muted">Prova ett annat filter.</p>
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
.page-header-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-md);
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

/* Filter Selectors */
.filter-selectors {
  display: flex;
  gap: var(--space-md);
  flex-shrink: 0;
}
.filter-group {
  display: flex;
  flex-direction: column;
  gap: var(--space-2xs);
}
.filter-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  font-weight: var(--weight-medium);
}
.filter-select {
  appearance: none;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-sm) var(--space-xl) var(--space-sm) var(--space-md);
  font-size: var(--text-sm);
  font-weight: var(--weight-semibold);
  color: var(--color-text);
  cursor: pointer;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%237A7A7A' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right var(--space-sm) center;
  min-width: 140px;
}
.filter-select:hover {
  border-color: var(--color-accent);
}
.filter-select:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px rgba(97, 206, 112, 0.2);
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
.empty-state h3 {
  margin: 0 0 var(--space-sm) 0;
}
.text-muted {
  color: var(--color-text-muted);
}

@media (max-width: 599px) {
  .page-header-row {
    flex-direction: column;
    gap: var(--space-sm);
  }
  .filter-selectors {
    align-self: stretch;
    flex-wrap: wrap;
  }
  .filter-group {
    flex: 1;
    min-width: 100px;
  }
  .filter-select {
    width: 100%;
  }
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
