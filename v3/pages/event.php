<?php
/**
 * V3 Event Results Page - Shows results grouped by class
 * Matches V2 event-results.php structure
 */

$db = hub_db();
$eventId = intval($pageInfo['params']['id'] ?? 0);

if (!$eventId) {
    header('Location: /v3/results');
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
            cls.sort_order as class_sort_order
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
        usort($classData['results'], function($a, $b) {
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

        // Calculate positions and time behind
        $position = 0;
        $winnerSeconds = 0;
        foreach ($classData['results'] as &$result) {
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

<nav class="breadcrumb mb-md">
  <a href="/v3/results" class="breadcrumb-link">Resultat</a>
  <span class="breadcrumb-separator">›</span>
  <span class="breadcrumb-current"><?= htmlspecialchars($event['name']) ?></span>
</nav>

<div class="page-header">
  <h1 class="page-title"><?= htmlspecialchars($event['name']) ?></h1>
  <div class="page-meta">
    <?php if ($event['date']): ?>
      <span class="chip"><?= date('j F Y', strtotime($event['date'])) ?></span>
    <?php endif; ?>
    <?php if ($event['location']): ?>
      <span class="chip"><?= htmlspecialchars($event['location']) ?></span>
    <?php endif; ?>
    <span class="chip chip--primary"><?= $totalParticipants ?> deltagare</span>
    <span class="chip"><?= $totalFinished ?> i mål</span>
  </div>
</div>

<?php if ($event['series_id']): ?>
<p class="text-secondary mb-lg">
  Del av <a href="/v3/series/<?= $event['series_id'] ?>" class="link"><?= htmlspecialchars($event['series_name']) ?></a>
</p>
<?php endif; ?>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Class Filter -->
<?php if (count($resultsByClass) > 1): ?>
<section class="card mb-lg">
  <div class="filter-row">
    <span class="filter-label">Klass:</span>
    <a href="/v3/event/<?= $eventId ?>" class="btn <?= $selectedClass === 'all' ? 'btn--primary' : 'btn--ghost' ?>">Alla</a>
    <?php foreach ($resultsByClass as $classKey => $classData): ?>
    <a href="/v3/event/<?= $eventId ?>?class=<?= $classKey ?>"
       class="btn <?= $selectedClass == $classKey ? 'btn--primary' : 'btn--ghost' ?>">
      <?= htmlspecialchars($classData['display_name']) ?>
      <span class="badge-count"><?= count($classData['results']) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (empty($resultsByClass)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🏁</div>
    <p>Inga resultat registrerade ännu</p>
  </div>
</section>
<?php else: ?>

<?php foreach ($resultsByClass as $classKey => $classData):
    // Skip if filtering by class and this isn't the selected one
    if ($selectedClass !== 'all' && $selectedClass != $classKey) continue;
?>
<section class="card mb-lg" id="class-<?= $classKey ?>">
  <div class="card-header">
    <div>
      <h2 class="card-title"><?= htmlspecialchars($classData['display_name']) ?></h2>
      <p class="card-subtitle"><?= count($classData['results']) ?> deltagare</p>
    </div>
  </div>

  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="col-place">#</th>
          <th class="col-rider">Åkare</th>
          <th class="table-col-hide-portrait">Klubb</th>
          <?php if ($hasSplitTimes): ?>
            <?php for ($ss = 1; $ss <= 10; $ss++): ?>
              <?php
              $hasThisSplit = false;
              foreach ($classData['results'] as $r) {
                  if (!empty($r['ss' . $ss])) { $hasThisSplit = true; break; }
              }
              if ($hasThisSplit):
              ?>
              <th class="col-split table-col-hide-portrait">SS<?= $ss ?></th>
              <?php endif; ?>
            <?php endfor; ?>
          <?php endif; ?>
          <th class="col-time">Tid</th>
          <th class="col-gap table-col-hide-portrait">+Tid</th>
          <th class="col-points table-col-hide-portrait">Poäng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($classData['results'] as $result): ?>
        <tr onclick="window.location='/v3/rider/<?= $result['rider_id'] ?>'" style="cursor:pointer">
          <td class="col-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'col-place--' . $result['class_position'] : '' ?>">
            <?php if ($result['status'] !== 'finished'): ?>
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
            <a href="/v3/rider/<?= $result['rider_id'] ?>" class="rider-link">
              <?= htmlspecialchars($result['firstname'] . ' ' . $result['lastname']) ?>
            </a>
            <?php if ($result['license_number']): ?>
              <span class="license-badge"><?= htmlspecialchars($result['license_number']) ?></span>
            <?php endif; ?>
          </td>
          <td class="table-col-hide-portrait text-muted">
            <?php if ($result['club_id']): ?>
              <a href="/v3/club/<?= $result['club_id'] ?>"><?= htmlspecialchars($result['club_name'] ?? '-') ?></a>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <?php if ($hasSplitTimes): ?>
            <?php for ($ss = 1; $ss <= 10; $ss++): ?>
              <?php
              $hasThisSplit = false;
              foreach ($classData['results'] as $r) {
                  if (!empty($r['ss' . $ss])) { $hasThisSplit = true; break; }
              }
              if ($hasThisSplit):
              ?>
              <td class="col-split table-col-hide-portrait">
                <?= !empty($result['ss' . $ss]) ? formatDisplayTime($result['ss' . $ss]) : '-' ?>
              </td>
              <?php endif; ?>
            <?php endfor; ?>
          <?php endif; ?>
          <td class="col-time">
            <?php if ($result['status'] === 'finished' && $result['finish_time']): ?>
              <?= formatDisplayTime($result['finish_time']) ?>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <td class="col-gap table-col-hide-portrait text-muted">
            <?= $result['time_behind'] ?? '-' ?>
          </td>
          <td class="col-points table-col-hide-portrait">
            <?php if ($result['points']): ?>
              <span class="points-value"><?= $result['points'] ?></span>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach ($classData['results'] as $result): ?>
    <a href="/v3/rider/<?= $result['rider_id'] ?>" class="result-item">
      <div class="result-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'top-3' : '' ?>">
        <?php if ($result['status'] !== 'finished'): ?>
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
        <?php endif; ?>
        <?php if ($result['points']): ?>
          <div class="points-small"><?= $result['points'] ?> p</div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php endif; ?>

<style>
.breadcrumb {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}
.breadcrumb-link {
  color: var(--color-text-secondary);
}
.breadcrumb-link:hover {
  color: var(--color-accent-text);
}
.breadcrumb-current {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}

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

.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.text-secondary { color: var(--color-text-secondary); }
.text-muted { color: var(--color-text-muted); }

.link {
  color: var(--color-accent-text);
}
.link:hover {
  text-decoration: underline;
}

.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-xs);
  align-items: center;
}
.filter-label {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin-right: var(--space-xs);
}
.badge-count {
  font-size: var(--text-xs);
  background: var(--color-bg-sunken);
  padding: 1px 6px;
  border-radius: var(--radius-full);
  margin-left: var(--space-2xs);
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
.col-points {
  text-align: right;
}
.points-value {
  font-weight: var(--weight-semibold);
  color: var(--color-accent-text);
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
.license-badge {
  font-family: var(--font-mono);
  font-size: var(--text-xs);
  padding: 1px 4px;
  background: var(--color-bg-sunken);
  border-radius: var(--radius-sm);
  margin-left: var(--space-xs);
  color: var(--color-text-muted);
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
.points-small {
  font-size: var(--text-xs);
  color: var(--color-accent-text);
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
  .filter-row {
    gap: 4px;
  }
  .filter-row .btn {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-xs);
  }
  .page-title {
    font-size: var(--text-xl);
  }
}
</style>
