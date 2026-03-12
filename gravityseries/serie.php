<?php
/**
 * GravitySeries — Serie-detaljsida
 * /gravityseries/serie/{brand-slug}
 *
 * Visar all information om en tävlingsserie:
 * - Beskrivning, events, ställning, klubbmästerskap
 */

$slug = $_GET['slug'] ?? '';

// Validate slug format
if (!$slug || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404);
    $gsPageTitle = 'Serien hittades inte';
    $gsActiveNav = 'serier';
    require_once __DIR__ . '/includes/gs-header.php';
    echo '<div class="page-hero"><div class="page-hero-stripe"></div><div class="page-hero-inner"><h1 class="page-hero-title">404</h1><p class="page-hero-ingress">Serien du söker finns inte.</p></div></div>';
    echo '<div class="page-content-wrap"><div class="page-content"><p><a href="/gravityseries/">Tillbaka till startsidan</a></p></div></div>';
    require_once __DIR__ . '/includes/gs-footer.php';
    exit;
}

// Connect to DB
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
if (!isset($pdo)) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        die('Databasanslutning misslyckades.');
    }
}

// Find brand by slug
$brand = null;
try {
    $bStmt = $pdo->prepare("SELECT * FROM series_brands WHERE slug = ? AND active = 1 LIMIT 1");
    $bStmt->execute([$slug]);
    $brand = $bStmt->fetch();
} catch (PDOException $e) {}

if (!$brand) {
    http_response_code(404);
    $gsPageTitle = 'Serien hittades inte';
    $gsActiveNav = 'serier';
    require_once __DIR__ . '/includes/gs-header.php';
    echo '<div class="page-hero"><div class="page-hero-stripe"></div><div class="page-hero-inner"><h1 class="page-hero-title">404</h1><p class="page-hero-ingress">Serien du söker finns inte.</p></div></div>';
    echo '<div class="page-content-wrap"><div class="page-content"><p><a href="/gravityseries/#serier">Tillbaka till serierna</a></p></div></div>';
    require_once __DIR__ . '/includes/gs-footer.php';
    exit;
}

// Load current year's series for this brand
$currentSeries = null;
$allYears = [];
try {
    $sStmt = $pdo->prepare("
        SELECT s.id, s.name, s.year, s.description, s.status, s.type, s.format,
            s.count_best_results, s.enable_club_championship,
            COALESCE(s.logo, ?) AS logo
        FROM series s
        WHERE s.brand_id = ?
        ORDER BY s.year DESC
    ");
    $sStmt->execute([$brand['logo'], $brand['id']]);
    $allSeriesYears = $sStmt->fetchAll();

    foreach ($allSeriesYears as $sy) {
        $allYears[] = $sy['year'];
        if ($sy['status'] === 'active' && $sy['year'] == date('Y')) {
            $currentSeries = $sy;
        }
    }
    // Fallback: latest active or first
    if (!$currentSeries && !empty($allSeriesYears)) {
        foreach ($allSeriesYears as $sy) {
            if ($sy['status'] === 'active') { $currentSeries = $sy; break; }
        }
        if (!$currentSeries) $currentSeries = $allSeriesYears[0];
    }
} catch (PDOException $e) {}

$seriesId = $currentSeries ? $currentSeries['id'] : null;
$accentColor = $brand['accent_color'] ?: '#61CE70';

// Load events
$events = [];
if ($seriesId) {
    try {
        $evtStmt = $pdo->prepare("
            SELECT e.id, e.name, e.date, e.location, e.venue_id,
                (SELECT COUNT(DISTINCT er.rider_id) FROM event_registrations er WHERE er.event_id = e.id AND er.status != 'cancelled') AS registered,
                (SELECT COUNT(DISTINCT r.cyclist_id) FROM results r WHERE r.event_id = e.id) AS result_count
            FROM series_events se
            JOIN events e ON se.event_id = e.id
            WHERE se.series_id = ?
            ORDER BY e.date ASC
        ");
        $evtStmt->execute([$seriesId]);
        $events = $evtStmt->fetchAll();
    } catch (PDOException $e) {}
}

// Load standings per class (bulk points fetch)
$standings = []; // class_name => [riders sorted by points]
$classes = [];
if ($seriesId) {
    try {
        // Get classes used in this series
        $clStmt = $pdo->prepare("
            SELECT DISTINCT cl.id, cl.name, cl.display_name, cl.sort_order, cl.gender
            FROM results r
            JOIN series_events se ON r.event_id = se.event_id
            JOIN classes cl ON r.class_id = cl.id
            WHERE se.series_id = ?
            AND r.points > 0
            ORDER BY cl.sort_order ASC, cl.name ASC
        ");
        $clStmt->execute([$seriesId]);
        $classes = $clStmt->fetchAll();

        if (!empty($classes)) {
            // Bulk fetch ALL points for this series
            $ptStmt = $pdo->prepare("
                SELECT r.cyclist_id, r.event_id, r.class_id, r.points, r.position
                FROM results r
                JOIN series_events se ON r.event_id = se.event_id
                WHERE se.series_id = ?
                AND r.points > 0
            ");
            $ptStmt->execute([$seriesId]);
            $allPoints = $ptStmt->fetchAll();

            // Build points map
            $pointsMap = []; // [cyclist_id][event_id][class_id] = points
            foreach ($allPoints as $p) {
                $pointsMap[$p['cyclist_id']][$p['event_id']][$p['class_id']] = $p;
            }

            // Get rider info for all cyclists
            $cyclistIds = array_unique(array_column($allPoints, 'cyclist_id'));
            if (!empty($cyclistIds)) {
                $placeholders = implode(',', array_fill(0, count($cyclistIds), '?'));
                $rdStmt = $pdo->prepare("
                    SELECT r.id, r.firstname, r.lastname, c.name AS club_name
                    FROM riders r
                    LEFT JOIN clubs c ON r.club_id = c.id
                    WHERE r.id IN ({$placeholders})
                ");
                $rdStmt->execute($cyclistIds);
                $ridersMap = [];
                foreach ($rdStmt->fetchAll() as $rd) {
                    $ridersMap[$rd['id']] = $rd;
                }
            }

            $countBest = $currentSeries['count_best_results'] ?? null;

            // Build standings per class
            foreach ($classes as $cl) {
                $classId = $cl['id'];
                $className = $cl['display_name'] ?: $cl['name'];
                $riderTotals = [];

                foreach ($pointsMap as $cycId => $evtData) {
                    $eventPoints = [];
                    foreach ($evtData as $evtId => $classData) {
                        if (isset($classData[$classId])) {
                            $eventPoints[] = (int)$classData[$classId]['points'];
                        }
                    }
                    if (empty($eventPoints)) continue;

                    // Apply count_best_results
                    rsort($eventPoints);
                    if ($countBest && $countBest > 0 && count($eventPoints) > $countBest) {
                        $eventPoints = array_slice($eventPoints, 0, $countBest);
                    }

                    $total = array_sum($eventPoints);
                    $rd = $ridersMap[$cycId] ?? null;
                    if ($rd) {
                        $riderTotals[] = [
                            'id' => $cycId,
                            'name' => $rd['firstname'] . ' ' . $rd['lastname'],
                            'club' => $rd['club_name'] ?? '',
                            'total' => $total,
                            'events_counted' => count($eventPoints),
                        ];
                    }
                }

                usort($riderTotals, fn($a, $b) => $b['total'] - $a['total']);
                if (!empty($riderTotals)) {
                    $standings[$className] = $riderTotals;
                }
            }
        }
    } catch (PDOException $e) {}
}

// Club championship (simplified: sum of rider points per club)
$clubStandings = [];
if ($seriesId) {
    try {
        $clubStmt = $pdo->prepare("
            SELECT c.id AS club_id, c.name AS club_name,
                SUM(r.points) AS total_points,
                COUNT(DISTINCT r.cyclist_id) AS rider_count
            FROM results r
            JOIN series_events se ON r.event_id = se.event_id
            JOIN riders rd ON r.cyclist_id = rd.id
            JOIN clubs c ON rd.club_id = c.id
            WHERE se.series_id = ?
            AND r.points > 0
            GROUP BY c.id, c.name
            ORDER BY total_points DESC
        ");
        $clubStmt->execute([$seriesId]);
        $clubStandings = $clubStmt->fetchAll();
    } catch (PDOException $e) {}
}

// Determine discipline
function _disc($s) {
    $n = strtolower($s['name'] ?? '');
    $t = strtolower($s['type'] ?? '');
    if (strpos($n, 'downhill') !== false || strpos($n, ' dh') !== false || $t === 'dh') return 'Downhill';
    if (strpos($n, 'enduro') !== false || $t === 'enduro') return 'Enduro';
    return 'Enduro';
}
$disc = $currentSeries ? _disc($currentSeries) : 'Enduro';

// First future event
$firstFutureEvent = null;
$today = date('Y-m-d');
foreach ($events as $evt) {
    if ($evt['date'] >= $today) { $firstFutureEvent = $evt; break; }
}

// Set header variables
$gsPageTitle = $brand['name'];
$gsMetaDesc = $brand['description'] ?: ($brand['name'] . ' — tävlingsserie inom GravitySeries');
$gsActiveNav = 'serier';
$gsEditUrl = '/admin/series-brands-edit.php?id=' . (int)$brand['id'];

require_once __DIR__ . '/includes/gs-header.php';

$gsBaseUrl = '/gravityseries';
$chevronSvg = '<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>';

// Swedish date helper
function _svDate($dateStr) {
    $months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    $d = strtotime($dateStr);
    return (int)date('j', $d) . ' ' . $months[(int)date('n', $d) - 1] . ' ' . date('Y', $d);
}
function _svDateShort($dateStr) {
    $months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    $d = strtotime($dateStr);
    return (int)date('j', $d) . ' ' . $months[(int)date('n', $d) - 1];
}
?>

<!-- SERIE HERO -->
<div class="gs-serie-hero" style="--c: <?= htmlspecialchars($accentColor) ?>">
  <div class="gs-serie-hero-bg"></div>
  <div class="gs-serie-hero-inner">
    <a href="<?= $gsBaseUrl ?>/#serier" class="gs-serie-back">&larr; Alla serier</a>
    <div class="gs-serie-hero-badge"><i style="background:<?= htmlspecialchars($accentColor) ?>"></i> <?= htmlspecialchars($disc) ?></div>
    <h1 class="gs-serie-hero-title"><?= htmlspecialchars($brand['name']) ?></h1>
    <?php if ($currentSeries): ?>
      <div class="gs-serie-hero-meta"><?= htmlspecialchars($currentSeries['name']) ?> &middot; <?= htmlspecialchars($disc) ?></div>
    <?php endif; ?>
    <?php if ($brand['description']): ?>
      <p class="gs-serie-hero-desc"><?= htmlspecialchars($brand['description']) ?></p>
    <?php elseif ($currentSeries && $currentSeries['description']): ?>
      <p class="gs-serie-hero-desc"><?= htmlspecialchars(strip_tags($currentSeries['description'])) ?></p>
    <?php endif; ?>
    <div class="gs-serie-hero-stats">
      <div class="gs-serie-hero-stat"><strong><?= count($events) ?></strong><span>Deltävlingar</span></div>
      <div class="gs-serie-hero-stat"><strong><?= count(array_filter($events, fn($e) => $e['date'] < $today)) ?></strong><span>Avgjorda</span></div>
      <div class="gs-serie-hero-stat"><strong><?= count($standings) ?></strong><span>Klasser</span></div>
      <div class="gs-serie-hero-stat"><strong><?= array_sum(array_map(fn($s) => count($s), $standings)) ?></strong><span>Åkare</span></div>
    </div>
  </div>
</div>

<!-- TABS -->
<div class="gs-serie-tabs" style="--c: <?= htmlspecialchars($accentColor) ?>">
  <div class="gs-serie-tabs-inner">
    <button class="gs-serie-tab active" data-tab="events">Tävlingar</button>
    <button class="gs-serie-tab" data-tab="standings">Ställning</button>
    <button class="gs-serie-tab" data-tab="clubs">Klubbmästerskap</button>
  </div>
</div>

<!-- TAB CONTENT -->
<div class="gs-serie-content" style="--c: <?= htmlspecialchars($accentColor) ?>">
  <div class="gs-section">

    <!-- EVENTS TAB -->
    <div class="gs-serie-pane active" id="pane-events">
      <?php if (empty($events)): ?>
        <div class="gs-serie-empty">Inga tävlingar har lagts till ännu.</div>
      <?php else: ?>
        <div class="gs-events-list">
          <?php foreach ($events as $idx => $evt):
              $isDone = $evt['date'] < $today;
              $isNext = (!$isDone && !$firstDone);
              if (!$isDone && !isset($firstDone)) $firstDone = true;
              $statusClass = $isDone ? 'done' : ($evt === $firstFutureEvent ? 'next' : 'upcoming');
          ?>
          <a class="gs-event-row <?= $statusClass ?>" href="https://thehub.gravityseries.se/event/<?= (int)$evt['id'] ?>">
            <div class="gs-event-date">
              <span class="gs-event-day"><?= date('j', strtotime($evt['date'])) ?></span>
              <span class="gs-event-month"><?= _svDateShort($evt['date']) ?></span>
            </div>
            <div class="gs-event-info">
              <div class="gs-event-name"><?= htmlspecialchars($evt['name']) ?></div>
              <div class="gs-event-location">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <?= htmlspecialchars($evt['location'] ?: '—') ?>
              </div>
            </div>
            <div class="gs-event-right">
              <?php if ($isDone && $evt['result_count'] > 0): ?>
                <span class="gs-event-badge results"><?= (int)$evt['result_count'] ?> resultat</span>
              <?php elseif (!$isDone && $evt['registered'] > 0): ?>
                <span class="gs-event-badge registered"><?= (int)$evt['registered'] ?> anmälda</span>
              <?php elseif (!$isDone): ?>
                <span class="gs-event-badge upcoming">Kommande</span>
              <?php endif; ?>
              <?= $chevronSvg ?>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- STANDINGS TAB -->
    <div class="gs-serie-pane" id="pane-standings">
      <?php if (empty($standings)): ?>
        <div class="gs-serie-empty">
          <?php if ($firstFutureEvent): ?>
            Inga resultat ännu — första tävlingen arrangeras <?= _svDate($firstFutureEvent['date']) ?> i <?= htmlspecialchars($firstFutureEvent['location'] ?: $firstFutureEvent['name']) ?>.
          <?php else: ?>
            Inga resultat har registrerats ännu.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php foreach ($standings as $className => $riders): ?>
        <div class="gs-standings-class">
          <div class="gs-standings-header"><?= htmlspecialchars($className) ?></div>
          <div class="gs-standings-table-wrap">
            <table class="gs-standings-table">
              <thead>
                <tr>
                  <th class="gs-st-pos">#</th>
                  <th class="gs-st-name">Namn</th>
                  <th class="gs-st-club">Klubb</th>
                  <th class="gs-st-pts">Poäng</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($riders, 0, 20) as $pos => $rd): ?>
                <tr>
                  <td class="gs-st-pos"><?= $pos + 1 ?></td>
                  <td class="gs-st-name">
                    <a href="https://thehub.gravityseries.se/rider/<?= (int)$rd['id'] ?>"><?= htmlspecialchars($rd['name']) ?></a>
                  </td>
                  <td class="gs-st-club"><?= htmlspecialchars($rd['club'] ?: '—') ?></td>
                  <td class="gs-st-pts"><?= $rd['total'] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (count($riders) > 20): ?>
            <div class="gs-standings-more">
              <a href="https://thehub.gravityseries.se/series/<?= (int)$seriesId ?>">Visa alla <?= count($riders) ?> åkare på TheHUB <?= $chevronSvg ?></a>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($currentSeries['count_best_results']): ?>
          <div class="gs-standings-note">Bästa <?= (int)$currentSeries['count_best_results'] ?> av <?= count($events) ?> deltävlingar räknas.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- CLUBS TAB -->
    <div class="gs-serie-pane" id="pane-clubs">
      <?php if (empty($clubStandings)): ?>
        <div class="gs-serie-empty">
          <?php if ($firstFutureEvent): ?>
            Inga resultat ännu — första tävlingen arrangeras <?= _svDate($firstFutureEvent['date']) ?> i <?= htmlspecialchars($firstFutureEvent['location'] ?: $firstFutureEvent['name']) ?>.
          <?php else: ?>
            Inga klubbresultat har registrerats ännu.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="gs-standings-table-wrap">
          <table class="gs-standings-table gs-clubs-table">
            <thead>
              <tr>
                <th class="gs-st-pos">#</th>
                <th class="gs-st-name">Klubb</th>
                <th class="gs-st-club">Åkare</th>
                <th class="gs-st-pts">Poäng</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($clubStandings as $pos => $club): ?>
              <tr>
                <td class="gs-st-pos"><?= $pos + 1 ?></td>
                <td class="gs-st-name"><?= htmlspecialchars($club['club_name']) ?></td>
                <td class="gs-st-club"><?= (int)$club['rider_count'] ?></td>
                <td class="gs-st-pts"><?= (int)$club['total_points'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- HUB CTA -->
<div class="hub-cta-section" style="margin-top: 0;">
  <div class="hub-cta-inner">
    <div>
      <div class="hub-cta-title">Anmäl dig &amp; se resultat</div>
      <div class="hub-cta-sub">All anmälan och resultat finns på TheHUB.</div>
    </div>
    <a class="hub-cta-btn" href="https://thehub.gravityseries.se<?= $seriesId ? '/series/' . $seriesId : '' ?>">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Öppna TheHUB
    </a>
  </div>
</div>

<!-- TAB SWITCHING -->
<script>
(function() {
  var tabs = document.querySelectorAll('.gs-serie-tab');
  var panes = document.querySelectorAll('.gs-serie-pane');
  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() {
      var target = tab.dataset.tab;
      tabs.forEach(function(t) { t.classList.remove('active'); });
      panes.forEach(function(p) { p.classList.remove('active'); });
      tab.classList.add('active');
      var pane = document.getElementById('pane-' + target);
      if (pane) pane.classList.add('active');
    });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/gs-footer.php'; ?>
