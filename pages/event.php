<?php
/**
 * V3 Event Results Page - Shows results grouped by class
 * Matches V2 event-results.php structure
 */

$db = hub_db();
$eventId = intval($pageInfo['params']['id'] ?? 0);

if (!$eventId) {
    header('Location: /results');
    exit;
}

// Helper function to convert time string to seconds for sorting
function timeToSeconds($time) {
    if (empty($time)) return PHP_INT_MAX;

    // Handle decimal part
    $decimal = 0;
    if (preg_match('/(\.\d+)$/', $time, $matches)) {
        $decimal = floatval($matches[1]);
        $time = preg_replace('/\.\d+$/', '', $time);
    }

    $parts = explode(':', $time);
    $seconds = 0;

    if (count($parts) === 3) {
        $seconds = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
    } elseif (count($parts) === 2) {
        $seconds = (int)$parts[0] * 60 + (int)$parts[1];
    } elseif (count($parts) === 1) {
        $seconds = (int)$parts[0];
    }

    return $seconds + $decimal;
}

// Helper function to format display time
function formatDisplayTime($time) {
    if (empty($time)) return null;

    $decimal = '';
    if (preg_match('/(\.\d+)$/', $time, $matches)) {
        $decimal = $matches[1];
        $time = preg_replace('/\.\d+$/', '', $time);
    }

    $parts = explode(':', $time);
    if (count($parts) === 3) {
        $hours = (int)$parts[0];
        $minutes = (int)$parts[1];
        $seconds = (int)$parts[2];

        if ($hours > 0) {
            return $hours . ':' . sprintf('%02d', $minutes) . ':' . sprintf('%02d', $seconds) . $decimal;
        } else {
            return $minutes . ':' . sprintf('%02d', $seconds) . $decimal;
        }
    }

    return $time . $decimal;
}

try {
    // Fetch event details
    $stmt = $db->prepare("
        SELECT
            e.*,
            s.id as series_id,
            s.name as series_name
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        WHERE e.id = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        include HUB_V3_ROOT . '/pages/404.php';
        return;
    }

    // Fetch all results for this event
    $stmt = $db->prepare("
        SELECT
            res.*,
            r.id as rider_id,
            r.firstname,
            r.lastname,
            r.gender,
            r.birth_year,
            r.license_number,
            c.name as club_name,
            c.id as club_id,
            cls.id as class_id,
            cls.name as class_name,
            cls.display_name as class_display_name,
            cls.sort_order as class_sort_order,
            cls.awards_points as class_awards_points,
            cls.ranking_type as class_ranking_type
        FROM results res
        INNER JOIN riders r ON res.cyclist_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        LEFT JOIN classes cls ON res.class_id = cls.id
        WHERE res.event_id = ?
        ORDER BY
            cls.sort_order ASC,
            COALESCE(cls.name, 'Oklassificerad'),
            CASE WHEN res.status = 'finished' THEN 0 ELSE 1 END,
            res.finish_time ASC
    ");
    $stmt->execute([$eventId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if any results have split times
    $hasSplitTimes = false;
    foreach ($results as $result) {
        for ($i = 1; $i <= 10; $i++) {
            if (!empty($result['ss' . $i])) {
                $hasSplitTimes = true;
                break 2;
            }
        }
    }

    // Group results by class
    $resultsByClass = [];
    $totalParticipants = count($results);
    $totalFinished = 0;

    foreach ($results as $result) {
        $classKey = $result['class_id'] ?? 'no_class';
        $className = $result['class_name'] ?? 'Oklassificerad';

        if (!isset($resultsByClass[$classKey])) {
            $resultsByClass[$classKey] = [
                'class_id' => $result['class_id'],
                'display_name' => $result['class_display_name'] ?? $className,
                'class_name' => $className,
                'sort_order' => $result['class_sort_order'] ?? 999,
                'awards_points' => (int)($result['class_awards_points'] ?? 1),
                'ranking_type' => $result['class_ranking_type'] ?? 'time',
                'results' => []
            ];
        }

        $resultsByClass[$classKey]['results'][] = $result;

        if ($result['status'] === 'finished') {
            $totalFinished++;
        }
    }

    // Sort results within each class and calculate positions
    foreach ($resultsByClass as $classKey => &$classData) {
        $rankingType = $classData['ranking_type'] ?? 'time';

        usort($classData['results'], function($a, $b) use ($rankingType) {
            // For non-time ranking (motion/kids), sort alphabetically by name
            if ($rankingType !== 'time') {
                $aName = ($a['lastname'] ?? '') . ' ' . ($a['firstname'] ?? '');
                $bName = ($b['lastname'] ?? '') . ' ' . ($b['firstname'] ?? '');
                return strcasecmp($aName, $bName);
            }

            // Finished riders come first
            if ($a['status'] === 'finished' && $b['status'] !== 'finished') return -1;
            if ($a['status'] !== 'finished' && $b['status'] === 'finished') return 1;

            // Both finished - sort by time
            if ($a['status'] === 'finished' && $b['status'] === 'finished') {
                $aSeconds = timeToSeconds($a['finish_time']);
                $bSeconds = timeToSeconds($b['finish_time']);
                return $aSeconds <=> $bSeconds;
            }

            // Both not finished - sort by status priority: DNF > DQ > DNS
            $statusPriority = ['dnf' => 1, 'dq' => 2, 'dns' => 3];
            $aPriority = $statusPriority[$a['status']] ?? 4;
            $bPriority = $statusPriority[$b['status']] ?? 4;
            return $aPriority <=> $bPriority;
        });

        // Calculate positions and time behind (only for time-ranked classes)
        $position = 0;
        $winnerSeconds = 0;
        foreach ($classData['results'] as &$result) {
            // Non-time ranked classes (motion/kids) don't get positions
            if ($rankingType !== 'time') {
                $result['class_position'] = null;
                $result['time_behind'] = null;
                continue;
            }

            if ($result['status'] === 'finished') {
                $position++;
                $result['class_position'] = $position;

                // Get winner time (position 1)
                if ($position === 1 && !empty($result['finish_time'])) {
                    $winnerSeconds = timeToSeconds($result['finish_time']);
                }

                // Calculate time behind leader
                if ($position > 1 && $winnerSeconds > 0 && !empty($result['finish_time'])) {
                    $riderSeconds = timeToSeconds($result['finish_time']);
                    $diffSeconds = $riderSeconds - $winnerSeconds;
                    if ($diffSeconds > 0) {
                        $hours = floor($diffSeconds / 3600);
                        $minutes = floor(($diffSeconds % 3600) / 60);
                        $wholeSeconds = floor($diffSeconds) % 60;
                        $decimals = $diffSeconds - floor($diffSeconds);
                        $decimalStr = $decimals > 0 ? sprintf('.%02d', round($decimals * 100)) : '';

                        if ($hours > 0) {
                            $result['time_behind'] = sprintf('+%d:%02d:%02d', $hours, $minutes, $wholeSeconds) . $decimalStr;
                        } else {
                            $result['time_behind'] = sprintf('+%d:%02d', $minutes, $wholeSeconds) . $decimalStr;
                        }
                    }
                }
            } else {
                $result['class_position'] = null;
            }
        }
        unset($result);
    }
    unset($classData);

    // Sort classes by sort_order
    uasort($resultsByClass, function($a, $b) {
        return $a['sort_order'] - $b['sort_order'];
    });

    // Selected class filter
    $selectedClass = isset($_GET['class']) ? $_GET['class'] : 'all';

} catch (Exception $e) {
    $error = $e->getMessage();
    $event = null;
}

if (!$event) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}
?>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Event Info Card -->
<section class="info-card mb-lg">
  <div class="info-card-stripe"></div>
  <div class="info-card-content">
    <div class="info-card-main">
      <h1 class="info-card-title"><?= htmlspecialchars($event['name']) ?></h1>
      <div class="info-card-meta">
        <?php if ($event['series_id']): ?>
          <a href="/series/<?= $event['series_id'] ?>" class="info-card-link"><?= htmlspecialchars($event['series_name']) ?></a>
          <span class="info-card-sep">•</span>
        <?php endif; ?>
        <?php if ($event['date']): ?>
          <span><?= date('j M Y', strtotime($event['date'])) ?></span>
        <?php endif; ?>
        <?php if ($event['location']): ?>
          <span class="info-card-sep">•</span>
          <span><?= htmlspecialchars($event['location']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="info-card-stats">
      <div class="info-card-stat">
        <span class="info-card-stat-value"><?= $totalParticipants ?></span>
        <span class="info-card-stat-label">deltagare</span>
      </div>
      <div class="info-card-stat">
        <span class="info-card-stat-value"><?= $totalFinished ?></span>
        <span class="info-card-stat-label">i mål</span>
      </div>
    </div>
  </div>
</section>

<!-- Filters: Class + Search -->
<div class="filter-row mb-lg">
  <div class="filter-field">
    <label class="filter-label">Klass</label>
    <select class="filter-select" id="classFilter" onchange="filterResults()">
      <option value="all" <?= $selectedClass === 'all' ? 'selected' : '' ?>>Alla klasser</option>
      <?php foreach ($resultsByClass as $classKey => $classData): ?>
      <option value="<?= $classKey ?>" <?= $selectedClass == $classKey ? 'selected' : '' ?>>
        <?= htmlspecialchars($classData['display_name']) ?> (<?= count($classData['results']) ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-field">
    <label class="filter-label">Sök åkare</label>
    <input type="text" class="filter-input" id="searchFilter" placeholder="Namn eller klubb..." oninput="filterResults()">
  </div>
</div>

<?php if (empty($resultsByClass)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🏁</div>
    <p>Inga resultat registrerade ännu</p>
  </div>
</section>
<?php else: ?>

<?php foreach ($resultsByClass as $classKey => $classData):
    // Class-specific display rules
    $isTimeRanked = ($classData['ranking_type'] ?? 'time') === 'time';
    $showPoints = ($classData['awards_points'] ?? 1) == 1;
?>
<section class="card mb-lg class-section" id="class-<?= $classKey ?>" data-class="<?= $classKey ?>">
  <div class="card-header">
    <div>
      <h2 class="card-title"><?= htmlspecialchars($classData['display_name']) ?></h2>
      <p class="card-subtitle"><?= count($classData['results']) ?> deltagare<?= !$isTimeRanked ? ' (motion)' : '' ?></p>
    </div>
  </div>

  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="col-place"><?= $isTimeRanked ? '#' : '' ?></th>
          <th class="col-rider">Åkare</th>
          <th class="table-col-hide-portrait">Klubb</th>
          <?php
          // Check which splits this class has
          $classSplits = [];
          if ($hasSplitTimes) {
              for ($ss = 1; $ss <= 10; $ss++) {
                  foreach ($classData['results'] as $r) {
                      if (!empty($r['ss' . $ss])) {
                          $classSplits[] = $ss;
                          break;
                      }
                  }
              }
          }
          ?>
          <?php foreach ($classSplits as $ss): ?>
          <th class="col-split table-col-hide-portrait">SS<?= $ss ?></th>
          <?php endforeach; ?>
          <th class="col-time">Tid</th>
          <?php if ($isTimeRanked): ?>
          <th class="col-gap table-col-hide-portrait">+Tid</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($classData['results'] as $result):
            $searchData = strtolower($result['firstname'] . ' ' . $result['lastname'] . ' ' . ($result['club_name'] ?? ''));
        ?>
        <tr class="result-row" onclick="window.location='/rider/<?= $result['rider_id'] ?>'" style="cursor:pointer" data-search="<?= htmlspecialchars($searchData) ?>">
          <td class="col-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'col-place--' . $result['class_position'] : '' ?>">
            <?php if (!$isTimeRanked): ?>
              ✓
            <?php elseif ($result['status'] !== 'finished'): ?>
              <span class="status-badge status-<?= strtolower($result['status']) ?>"><?= strtoupper($result['status']) ?></span>
            <?php elseif ($result['class_position'] == 1): ?>
              🥇
            <?php elseif ($result['class_position'] == 2): ?>
              🥈
            <?php elseif ($result['class_position'] == 3): ?>
              🥉
            <?php else: ?>
              <?= $result['class_position'] ?>
            <?php endif; ?>
          </td>
          <td class="col-rider">
            <a href="/rider/<?= $result['rider_id'] ?>" class="rider-link">
              <?= htmlspecialchars($result['firstname'] . ' ' . $result['lastname']) ?>
            </a>
          </td>
          <td class="table-col-hide-portrait text-muted">
            <?php if ($result['club_id']): ?>
              <a href="/club/<?= $result['club_id'] ?>"><?= htmlspecialchars($result['club_name'] ?? '-') ?></a>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <?php foreach ($classSplits as $ss): ?>
          <td class="col-split table-col-hide-portrait">
            <?= !empty($result['ss' . $ss]) ? formatDisplayTime($result['ss' . $ss]) : '-' ?>
          </td>
          <?php endforeach; ?>
          <td class="col-time">
            <?php if ($result['status'] === 'finished' && $result['finish_time']): ?>
              <?= formatDisplayTime($result['finish_time']) ?>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <?php if ($isTimeRanked): ?>
          <td class="col-gap table-col-hide-portrait text-muted">
            <?= $result['time_behind'] ?? '-' ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach ($classData['results'] as $result):
        $searchData = strtolower($result['firstname'] . ' ' . $result['lastname'] . ' ' . ($result['club_name'] ?? ''));
    ?>
    <a href="/rider/<?= $result['rider_id'] ?>" class="result-item" data-search="<?= htmlspecialchars($searchData) ?>">
      <div class="result-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'top-3' : '' ?>">
        <?php if (!$isTimeRanked): ?>
          ✓
        <?php elseif ($result['status'] !== 'finished'): ?>
          <span class="status-mini"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
        <?php elseif ($result['class_position'] == 1): ?>
          🥇
        <?php elseif ($result['class_position'] == 2): ?>
          🥈
        <?php elseif ($result['class_position'] == 3): ?>
          🥉
        <?php else: ?>
          <?= $result['class_position'] ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($result['firstname'] . ' ' . $result['lastname']) ?></div>
        <div class="result-club"><?= htmlspecialchars($result['club_name'] ?? '-') ?></div>
      </div>
      <div class="result-time-col">
        <?php if ($result['status'] === 'finished' && $result['finish_time']): ?>
          <div class="time-value"><?= formatDisplayTime($result['finish_time']) ?></div>
          <?php if ($isTimeRanked && !empty($result['time_behind'])): ?>
            <div class="time-behind-small"><?= $result['time_behind'] ?></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php endif; ?>

<script>
function filterResults() {
  const classFilter = document.getElementById('classFilter').value;
  const searchFilter = document.getElementById('searchFilter').value.toLowerCase().trim();

  // Filter class sections
  document.querySelectorAll('.class-section').forEach(section => {
    const classId = section.dataset.class;
    const showClass = classFilter === 'all' || classFilter === classId;
    section.style.display = showClass ? '' : 'none';

    if (showClass) {
      // Filter rows within this class
      section.querySelectorAll('.result-row, .result-item').forEach(row => {
        const searchData = row.dataset.search || '';
        const matchesSearch = !searchFilter || searchData.includes(searchFilter);
        row.style.display = matchesSearch ? '' : 'none';
      });
    }
  });
}
</script>

<style>
.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.text-secondary { color: var(--color-text-secondary); }
.text-muted { color: var(--color-text-muted); }

/* Info Card (shared by event/series) */
.info-card {
  background: var(--color-bg-surface);
  border-radius: var(--radius-md);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}
.info-card-stripe {
  height: 4px;
  background: linear-gradient(90deg, var(--color-accent) 0%, #00A3E0 100%);
}
.info-card-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-md);
  padding: var(--space-md);
}
.info-card-main {
  flex: 1;
  min-width: 0;
}
.info-card-title {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  margin: 0;
  line-height: 1.3;
}
.info-card-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin-top: var(--space-2xs);
}
.info-card-link {
  color: var(--color-accent-text);
  font-weight: var(--weight-medium);
}
.info-card-link:hover {
  text-decoration: underline;
}
.info-card-sep {
  color: var(--color-text-muted);
}
.info-card-stats {
  display: flex;
  gap: var(--space-sm);
  flex-shrink: 0;
}
.info-card-stat {
  text-align: center;
  padding: var(--space-xs) var(--space-sm);
  background: var(--color-bg-sunken);
  border-radius: var(--radius-sm);
  display: flex;
  align-items: baseline;
  gap: var(--space-2xs);
}
.info-card-stat-value {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}
.info-card-stat-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
}

/* Filter Row */
.filter-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-md);
}
.filter-field {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.filter-label {
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  color: var(--color-text-secondary);
}
.filter-select,
.filter-input {
  padding: var(--space-sm) var(--space-md);
  font-size: var(--text-sm);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg-surface);
  color: var(--color-text);
  width: 100%;
  box-sizing: border-box;
}
.filter-select {
  cursor: pointer;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  padding-right: var(--space-xl);
}
.filter-select:focus,
.filter-input:focus {
  outline: none;
  border-color: var(--color-accent);
}
.filter-input::placeholder {
  color: var(--color-text-muted);
}

.col-place {
  width: 50px;
  text-align: center;
  font-weight: var(--weight-bold);
}
.col-place--1 { color: #FFD700; }
.col-place--2 { color: #C0C0C0; }
.col-place--3 { color: #CD7F32; }

.col-time {
  text-align: right;
  font-family: var(--font-mono);
  white-space: nowrap;
}
.col-gap {
  text-align: right;
  font-family: var(--font-mono);
  font-size: var(--text-sm);
  white-space: nowrap;
}
.col-split {
  text-align: right;
  font-family: var(--font-mono);
  font-size: var(--text-xs);
  white-space: nowrap;
  color: var(--color-text-secondary);
}

.status-badge {
  font-size: var(--text-xs);
  font-weight: var(--weight-semibold);
  padding: 2px 6px;
  border-radius: var(--radius-sm);
}
.status-dnf {
  background: rgba(239, 68, 68, 0.1);
  color: #ef4444;
}
.status-dns {
  background: rgba(156, 163, 175, 0.1);
  color: #9ca3af;
}
.status-dq {
  background: rgba(245, 158, 11, 0.1);
  color: #f59e0b;
}
.status-mini {
  font-size: var(--text-xs);
  font-weight: var(--weight-bold);
  color: var(--color-text-muted);
}

.rider-link {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}
.rider-link:hover {
  color: var(--color-accent-text);
}

.result-place.top-3 {
  background: var(--color-accent-light);
}
.result-time-col {
  text-align: right;
}
.time-value {
  font-family: var(--font-mono);
  font-size: var(--text-sm);
}
.time-behind-small {
  font-family: var(--font-mono);
  font-size: var(--text-xs);
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

@media (max-width: 599px) {
  .info-card-content {
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-sm);
  }
  .info-card-title {
    font-size: var(--text-base);
  }
  .info-card-stats {
    justify-content: center;
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
  }
  .filter-row {
    grid-template-columns: 1fr;
    gap: var(--space-sm);
  }
}
</style>
