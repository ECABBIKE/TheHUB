<?php
/**
 * V3 Series Page - All competition series with badge design
 *
 * Uses the TheHUB Badge Design System for a bold, modern display.
 */

// Include badge component
require_once HUB_V3_ROOT . '/components/series-badge.php';

$db = hub_db();

// Get filter parameters
$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : null;
$selectedSeriesSlug = isset($_GET['serie']) ? trim($_GET['serie']) : null;

// Determine filter mode: 'series' if a series is selected, otherwise 'year'
$filterMode = $selectedSeriesSlug ? 'series' : 'year';

try {
    // Get all available years that have series
    $availableYears = $db->query("
        SELECT DISTINCT year
        FROM series
        WHERE status IN ('active', 'completed') AND year IS NOT NULL
        ORDER BY year DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    // Get all unique series names for the series dropdown
    // Group by slug prefix (without year suffix) to find unique series
    $allSeriesRaw = $db->query("
        SELECT id, name, slug, year
        FROM series
        WHERE status IN ('active', 'completed')
        ORDER BY year DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Extract base names by removing trailing year from name
    $seriesBaseNames = [];
    foreach ($allSeriesRaw as $s) {
        // Remove trailing 4-digit year from name
        $baseName = trim(preg_replace('/\s*\d{4}$/', '', $s['name']));
        if (!isset($seriesBaseNames[$baseName])) {
            $seriesBaseNames[$baseName] = [
                'base_name' => $baseName,
                'slug' => $s['slug']  // Use first (most recent) slug
            ];
        }
    }
    $allSeriesNames = array_values($seriesBaseNames);
    usort($allSeriesNames, fn($a, $b) => strcmp($a['base_name'], $b['base_name']));

    if ($filterMode === 'series') {
        // Find the base name for the selected series
        $selectedSeriesBaseName = null;
        foreach ($allSeriesNames as $sn) {
            if ($sn['slug'] === $selectedSeriesSlug) {
                $selectedSeriesBaseName = $sn['base_name'];
                break;
            }
        }

        // Get all series matching this base name (all years)
        if ($selectedSeriesBaseName) {
            // Find all series IDs that match this base name
            $matchingSeriesIds = [];
            foreach ($allSeriesRaw as $s) {
                $baseName = trim(preg_replace('/\s*\d{4}$/', '', $s['name']));
                if ($baseName === $selectedSeriesBaseName) {
                    $matchingSeriesIds[] = $s['id'];
                }
            }

            if (!empty($matchingSeriesIds)) {
                $placeholders = implode(',', array_fill(0, count($matchingSeriesIds), '?'));
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
                $stmt->execute($matchingSeriesIds);
                $series = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $series = [];
            }

            $pageTitle = trim($selectedSeriesBaseName);
            $pageSubtitle = 'Historik för alla år';
        } else {
            $series = [];
            $pageTitle = 'Serie hittades inte';
            $pageSubtitle = '';
        }
    } else {
        // Year filter mode (default)
        if ($selectedYear === null) {
            $selectedYear = !empty($availableYears) ? $availableYears[0] : $currentYear;
        }

        // If selected year not in available years, default to most recent
        if (!in_array($selectedYear, $availableYears) && !empty($availableYears)) {
            $selectedYear = $availableYears[0];
        }

        // Get series for selected year
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
        $stmt->execute([$selectedYear]);
        $series = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pageTitle = "Tävlingsserier $selectedYear";
        $pageSubtitle = 'Alla GravitySeries och andra tävlingsserier';
    }

    $totalSeries = count($series);
    $totalEvents = array_sum(array_column($series, 'event_count'));

    // Count unique participants
    if ($filterMode === 'series' && !empty($series)) {
        $seriesIds = array_column($series, 'id');
        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT r.cyclist_id) as total
            FROM results r
            JOIN events e ON r.event_id = e.id
            WHERE e.series_id IN ($placeholders)
        ");
        $stmt->execute($seriesIds);
        $uniqueParticipants = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalParticipants = $uniqueParticipants['total'] ?? 0;
    } else {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT r.cyclist_id) as total
            FROM results r
            JOIN events e ON r.event_id = e.id
            JOIN series s ON e.series_id = s.id
            WHERE s.status IN ('active', 'completed') AND s.year = ?
        ");
        $stmt->execute([$selectedYear]);
        $uniqueParticipants = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalParticipants = $uniqueParticipants['total'] ?? 0;
    }

} catch (Exception $e) {
    $series = [];
    $availableYears = [$currentYear];
    $allSeriesNames = [];
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
      <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
      <?php if ($pageSubtitle): ?>
      <p class="page-subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>
      <?php endif; ?>
    </div>
    <div class="filter-selectors">
      <?php if (!empty($availableYears)): ?>
      <div class="filter-group">
        <label for="year-select" class="filter-label">År</label>
        <select id="year-select" class="filter-select" onchange="window.location.href='?year=' + this.value">
          <?php foreach ($availableYears as $year): ?>
            <option value="<?= $year ?>" <?= ($filterMode === 'year' && $year == $selectedYear) ? 'selected' : '' ?>><?= $year ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if (!empty($allSeriesNames)): ?>
      <div class="filter-group">
        <label for="series-select" class="filter-label">Serie</label>
        <select id="series-select" class="filter-select" onchange="if(this.value) window.location.href='?serie=' + this.value; else window.location.href='?year=<?= $selectedYear ?? $currentYear ?>';">
          <option value="">Alla serier</option>
          <?php foreach ($allSeriesNames as $sn): ?>
            <option value="<?= htmlspecialchars($sn['slug']) ?>" <?= ($filterMode === 'series' && $sn['slug'] === $selectedSeriesSlug) ? 'selected' : '' ?>><?= htmlspecialchars($sn['base_name']) ?></option>
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
      <div class="stat-label"><?= $filterMode === 'series' ? 'Säsonger' : 'Serier' ?></div>
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
  min-width: 100px;
}
.filter-select:hover {
  border-color: var(--color-accent);
}
.filter-select:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px rgba(97, 206, 112, 0.2);
}
#series-select {
  min-width: 160px;
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
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
  .page-header-row {
    flex-direction: column;
    gap: var(--space-sm);
  }
  .filter-selectors {
    align-self: flex-start;
    flex-wrap: wrap;
  }
  .filter-group {
    flex: 1;
    min-width: 100px;
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
