<?php
/**
 * V3 Single Club Page - Club profile with members and stats
 */

$db = hub_db();
$clubId = intval($pageInfo['params']['id'] ?? 0);

// Include club achievements system
$achievementsClubPath = dirname(__DIR__) . '/includes/achievements-club.php';
if (file_exists($achievementsClubPath)) {
    require_once $achievementsClubPath;
}

// Include ranking functions
$rankingFunctionsLoaded = false;
$rankingPaths = [
    dirname(__DIR__) . '/includes/ranking_functions.php',
    __DIR__ . '/../includes/ranking_functions.php',
];
foreach ($rankingPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $rankingFunctionsLoaded = true;
        break;
    }
}

// Load filter setting from admin configuration
$publicSettings = require HUB_V3_ROOT . '/config/public_settings.php';
$filter = $publicSettings['public_riders_display'] ?? 'all';

if (!$clubId) {
    header('Location: /riders');
    exit;
}

try {
    // Fetch club details
    $stmt = $db->prepare("SELECT * FROM clubs WHERE id = ?");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        include HUB_V3_ROOT . '/pages/404.php';
        return;
    }

    // Fetch club members based on admin filter setting
    if ($filter === 'with_results') {
        // Show only riders with results
        $stmt = $db->prepare("
            SELECT DISTINCT r.id, r.firstname, r.lastname, r.birth_year, r.gender
            FROM riders r
            INNER JOIN results res ON r.id = res.cyclist_id
            WHERE r.club_id = ? AND r.active = 1
            ORDER BY r.lastname, r.firstname
        ");
    } else {
        // Show all active members
        $stmt = $db->prepare("
            SELECT id, firstname, lastname, birth_year, gender
            FROM riders
            WHERE club_id = ? AND active = 1
            ORDER BY lastname, firstname
        ");
    }
    $stmt->execute([$clubId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // PERFORMANCE FIX: Fetch ALL results for all members in ONE query instead of N+1
    // This reduces database calls from N (one per member) to just 2 queries total

    $memberIds = array_column($members, 'id');
    $memberStats = [];

    if (!empty($memberIds)) {
        // Initialize stats for all members
        foreach ($memberIds as $mid) {
            $memberStats[$mid] = [
                'total_races' => 0,
                'total_points' => 0,
                'podiums' => 0,
                'best_position' => null,
                'results' => []
            ];
        }

        // Query 1: Get all finished results for club members
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $db->prepare("
            SELECT res.id, res.cyclist_id, res.event_id, res.class_id, res.points, res.finish_time
            FROM results res
            WHERE res.cyclist_id IN ($placeholders) AND res.status = 'finished'
        ");
        $stmt->execute($memberIds);
        $allMemberResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Collect unique event/class combinations we need to calculate positions for
        $eventClassCombos = [];
        foreach ($allMemberResults as $res) {
            $key = $res['event_id'] . '|' . $res['class_id'];
            if (!isset($eventClassCombos[$key])) {
                $eventClassCombos[$key] = ['event_id' => $res['event_id'], 'class_id' => $res['class_id']];
            }
            // Store result for later processing
            $memberStats[$res['cyclist_id']]['results'][] = $res;
        }

        // Query 2: Get ALL results for these event/class combos to calculate positions
        // This is needed to know where club members placed relative to everyone
        $allPositionData = [];
        if (!empty($eventClassCombos)) {
            $orConditions = [];
            $params = [];
            foreach ($eventClassCombos as $combo) {
                $orConditions[] = "(event_id = ? AND class_id = ?)";
                $params[] = $combo['event_id'];
                $params[] = $combo['class_id'];
            }

            $stmt = $db->prepare("
                SELECT id, event_id, class_id, cyclist_id, finish_time
                FROM results
                WHERE status = 'finished' AND (" . implode(' OR ', $orConditions) . ")
                ORDER BY event_id, class_id, finish_time
            ");
            $stmt->execute($params);
            $positionResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Build position lookup: event_id|class_id|cyclist_id => position
            $currentEventClass = '';
            $position = 0;
            foreach ($positionResults as $pr) {
                $eventClassKey = $pr['event_id'] . '|' . $pr['class_id'];
                if ($eventClassKey !== $currentEventClass) {
                    $currentEventClass = $eventClassKey;
                    $position = 0;
                }
                $position++;
                $lookupKey = $pr['event_id'] . '|' . $pr['class_id'] . '|' . $pr['cyclist_id'];
                $allPositionData[$lookupKey] = $position;
            }
        }

        // Calculate stats for each member using pre-fetched data
        foreach ($memberStats as $cyclistId => &$stats) {
            $stats['total_races'] = count($stats['results']);
            $stats['total_points'] = array_sum(array_column($stats['results'], 'points'));

            foreach ($stats['results'] as $res) {
                $posKey = $res['event_id'] . '|' . $res['class_id'] . '|' . $cyclistId;
                $classPosition = $allPositionData[$posKey] ?? null;

                if ($classPosition !== null) {
                    if ($classPosition <= 3) {
                        $stats['podiums']++;
                    }
                    if ($stats['best_position'] === null || $classPosition < $stats['best_position']) {
                        $stats['best_position'] = (int)$classPosition;
                    }
                }
            }
            unset($stats['results']); // Free memory
        }
        unset($stats);
    }

    // Assign calculated stats to members
    foreach ($members as &$member) {
        $mid = $member['id'];
        $member['total_races'] = $memberStats[$mid]['total_races'] ?? 0;
        $member['total_points'] = $memberStats[$mid]['total_points'] ?? 0;
        $member['podiums'] = $memberStats[$mid]['podiums'] ?? 0;
        $member['best_position'] = $memberStats[$mid]['best_position'] ?? null;
    }
    unset($member);

    // Sort by points
    usort($members, function($a, $b) {
        if ($b['total_points'] != $a['total_points']) {
            return $b['total_points'] - $a['total_points'];
        }
        return $b['total_races'] - $a['total_races'];
    });

    $totalMembers = count($members);
    $totalRaces = array_sum(array_column($members, 'total_races'));
    $totalPoints = array_sum(array_column($members, 'total_points'));
    $totalPodiums = array_sum(array_column($members, 'podiums'));

    // Get club ranking position
    $clubRankingPosition = null;
    $clubRankingPoints = 0;
    $parentDb = function_exists('getDB') ? getDB() : null;
    if ($rankingFunctionsLoaded && $parentDb && function_exists('getSingleClubRanking')) {
        $clubRanking = getSingleClubRanking($parentDb, $clubId, 'GRAVITY');
        if ($clubRanking) {
            $clubRankingPosition = $clubRanking['ranking_position'] ?? null;
            $clubRankingPoints = $clubRanking['ranking_points'] ?? 0;
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $club = null;
}

if (!$club) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}
?>

<?php
// Check for club logo
$clubLogo = null;
$clubLogoDir = dirname(__DIR__) . '/uploads/clubs/';
$clubLogoUrl = '/uploads/clubs/';
foreach (['jpg', 'jpeg', 'png', 'webp', 'svg'] as $ext) {
    if (file_exists($clubLogoDir . $clubId . '.' . $ext)) {
        $clubLogo = $clubLogoUrl . $clubId . '.' . $ext . '?v=' . filemtime($clubLogoDir . $clubId . '.' . $ext);
        break;
    }
}
?>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Club Hero -->
<section class="club-hero">
  <div class="hero-accent-bar"></div>
  <div class="hero-content">
    <div class="hero-top">
      <div class="club-logo-container">
        <div class="club-logo">
          <?php if ($clubLogo): ?>
            <img src="<?= htmlspecialchars($clubLogo) ?>" alt="<?= htmlspecialchars($club['name']) ?>">
          <?php else: ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
          <?php endif; ?>
        </div>
        <?php if ($clubRankingPosition): ?>
        <div class="ranking-badge">
          <span class="rank-label">Ranking</span>
          <span class="rank-number">#<?= $clubRankingPosition ?></span>
        </div>
        <?php endif; ?>
      </div>

      <div class="hero-info">
        <h1 class="club-name"><?= htmlspecialchars($club['name']) ?></h1>
        <?php if ($club['city']): ?>
        <span class="club-location"><?= htmlspecialchars($club['city']) ?></span>
        <?php endif; ?>
        <div class="club-badges">
          <span class="club-badge"><?= $totalMembers ?> medlemmar</span>
          <?php if ($totalPoints > 0): ?>
          <span class="club-badge club-badge--accent"><?= number_format($totalPoints) ?> poäng</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($club['website'] || $club['email']): ?>
    <div class="hero-contact">
      <?php if ($club['website']): ?>
      <a href="<?= htmlspecialchars($club['website']) ?>" target="_blank" rel="noopener" class="contact-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
          <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
        <span><?= htmlspecialchars(preg_replace('#^https?://#', '', $club['website'])) ?></span>
      </a>
      <?php endif; ?>
      <?php if ($club['email']): ?>
      <a href="mailto:<?= htmlspecialchars($club['email']) ?>" class="contact-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
        <span><?= htmlspecialchars($club['email']) ?></span>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- Club Achievements (includes all stats) -->
<?php if (function_exists('renderClubAchievements')): ?>
<link rel="stylesheet" href="/assets/css/achievements.css?v=<?= file_exists(dirname(__DIR__) . '/assets/css/achievements.css') ? filemtime(dirname(__DIR__) . '/assets/css/achievements.css') : time() ?>">
<section class="mb-lg">
  <?= renderClubAchievements($db, $clubId) ?>
</section>
<?php endif; ?>

<!-- Members -->
<section class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Medlemmar</h2>
      <p class="card-subtitle"><?= $totalMembers ?> aktiva åkare</p>
    </div>
  </div>

  <?php if (empty($members)): ?>
  <div class="empty-state">
    <div class="empty-state-icon">👥</div>
    <p>Inga registrerade medlemmar</p>
  </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="col-rider">Namn</th>
          <th class="text-center">Starter</th>
          <th class="text-center table-col-hide-portrait">Pallplatser</th>
          <th class="text-center">Bästa</th>
          <th class="text-right table-col-hide-portrait">Poäng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $member): ?>
        <tr onclick="window.location='/rider/<?= $member['id'] ?>'" style="cursor:pointer">
          <td class="col-rider">
            <a href="/rider/<?= $member['id'] ?>" class="rider-link">
              <?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?>
            </a>
            <?php if ($member['birth_year']): ?>
              <span class="rider-year"><?= $member['birth_year'] ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <strong><?= $member['total_races'] ?: 0 ?></strong>
          </td>
          <td class="text-center table-col-hide-portrait">
            <?php if ($member['podiums'] > 0): ?>
              <span class="podium-badge">🏆 <?= $member['podiums'] ?></span>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($member['best_position']): ?>
              <?php if ($member['best_position'] == 1): ?>
                <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
              <?php elseif ($member['best_position'] == 2): ?>
                <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
              <?php elseif ($member['best_position'] == 3): ?>
                <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
              <?php else: ?>
                <span class="position-badge">#<?= $member['best_position'] ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td class="text-right table-col-hide-portrait">
            <?php if ($member['total_points'] > 0): ?>
              <span class="points-value"><?= number_format($member['total_points'], 0) ?></span>
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
    <?php foreach ($members as $member): ?>
    <a href="/rider/<?= $member['id'] ?>" class="result-item">
      <div class="result-place">
        <?php if ($member['best_position'] && $member['best_position'] <= 3): ?>
          <?php if ($member['best_position'] == 1): ?>
            <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
          <?php elseif ($member['best_position'] == 2): ?>
            <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
          <?php else: ?>
            <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
          <?php endif; ?>
        <?php else: ?>
          <?= $member['total_races'] ?: 0 ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></div>
        <div class="result-club"><?= $member['total_races'] ?: 0 ?> starter<?= $member['podiums'] > 0 ? ' • 🏆 ' . $member['podiums'] : '' ?></div>
      </div>
      <div class="result-points">
        <div class="points-big"><?= number_format($member['total_points'] ?? 0) ?></div>
        <div class="points-label">poäng</div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<style>
/* Club Hero */
.club-hero {
  background: var(--color-bg-surface);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-md);
  overflow: hidden;
  margin-bottom: var(--space-lg);
}

.club-hero .hero-accent-bar {
  height: 4px;
  background: linear-gradient(90deg, var(--color-accent), #004a98);
}

.club-hero .hero-content {
  padding: var(--space-lg);
}

.club-hero .hero-top {
  display: flex;
  gap: var(--space-lg);
  align-items: center;
}

.club-logo-container {
  flex-shrink: 0;
  position: relative;
}

.ranking-badge {
  position: absolute;
  top: -8px;
  right: -8px;
  background: linear-gradient(135deg, #FFD700, #FFA500);
  color: var(--color-primary, #171717);
  min-width: 44px;
  padding: 6px 8px;
  border-radius: var(--radius-sm);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  box-shadow: var(--shadow-md);
  border: 2px solid var(--color-bg-surface);
}

.ranking-badge .rank-label {
  font-size: 0.55rem;
  text-transform: uppercase;
  opacity: 0.9;
  letter-spacing: 0.02em;
  line-height: 1;
}

.ranking-badge .rank-number {
  font-size: 1rem;
  line-height: 1.1;
}

.club-logo {
  width: 96px;
  height: 96px;
  border-radius: var(--radius-lg);
  background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
  display: flex;
  align-items: center;
  justify-content: center;
  border: 3px solid var(--color-bg-surface);
  box-shadow: var(--shadow-lg);
  overflow: hidden;
}

.club-logo img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.club-logo svg {
  width: 45%;
  height: 45%;
  stroke: #9ca3af;
}

.hero-info {
  flex: 1;
}

.club-name {
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--color-text);
  margin: 0 0 var(--space-xs) 0;
}

.club-location {
  font-size: 0.9rem;
  color: var(--color-text-muted);
  display: block;
  margin-bottom: var(--space-sm);
}

.club-badges {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm);
}

.club-badge {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  background: var(--color-bg-sunken);
  color: var(--color-text);
  font-size: 0.8rem;
  font-weight: 600;
  border-radius: var(--radius-full);
}

.club-badge--accent {
  background: var(--color-accent);
  color: white;
}

.hero-contact {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-md);
  margin-top: var(--space-md);
  padding-top: var(--space-md);
  border-top: 1px solid var(--color-border);
}

.contact-link {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  color: var(--color-text-muted);
  font-size: 0.85rem;
  text-decoration: none;
  transition: color 0.2s ease;
}

.contact-link:hover {
  color: var(--color-accent);
}

.contact-link svg {
  flex-shrink: 0;
}

/* Utilities */
.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.text-muted { color: var(--color-text-muted); }

/* Legacy support */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-md);
}
.stat-card {
  text-align: center;
  padding: var(--space-md);
  background: var(--color-bg-sunken);
  border-radius: var(--radius-md);
}
.stat-card--accent {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}
.stat-card--accent .stat-label {
  color: rgba(255,255,255,0.8);
}
.stat-value {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
}
.stat-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  margin-top: var(--space-2xs);
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
.podium-badge {
  font-size: var(--text-sm);
}
.position-badge {
  font-weight: var(--weight-semibold);
}
.medal-icon {
  width: 24px;
  height: 24px;
  vertical-align: middle;
  display: inline-block;
}
.points-value {
  font-weight: var(--weight-semibold);
  color: var(--color-accent-text);
}

.result-place.top-3 {
  background: var(--color-accent-light);
}
.result-points {
  text-align: right;
}
.points-big {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}
.points-label {
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
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .page-title {
    font-size: var(--text-xl);
  }
  .club-contact {
    flex-direction: column;
    gap: var(--space-sm);
  }
}
</style>
