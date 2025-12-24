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
    // DEBUG: Performance timer (remove after testing)
    $debugStartTime = microtime(true);

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

    // DEBUG: Stop timer
    $debugQueryTime = round((microtime(true) - $debugStartTime) * 1000, 2);

} catch (Exception $e) {
    $error = $e->getMessage();
    $club = null;
    $debugQueryTime = 0;
}

if (!$club) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}
?>

<?php
// Check for club logo - first uploaded file, then URL from database
$clubLogo = null;
$clubLogoDir = dirname(__DIR__) . '/uploads/clubs/';
$clubLogoUrl = '/uploads/clubs/';
foreach (['jpg', 'jpeg', 'png', 'webp', 'svg'] as $ext) {
    if (file_exists($clubLogoDir . $clubId . '.' . $ext)) {
        $clubLogo = $clubLogoUrl . $clubId . '.' . $ext . '?v=' . filemtime($clubLogoDir . $clubId . '.' . $ext);
        break;
    }
}
// Fallback to logo URL from database
if (!$clubLogo && !empty($club['logo'])) {
    $clubLogo = $club['logo'];
}
?>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title text-error">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Club Header Grid: Hero + Achievements side by side on desktop -->
<?php if (function_exists('renderClubAchievements')): ?>
<link rel="stylesheet" href="/assets/css/achievements.css?v=<?= file_exists(dirname(__DIR__) . '/assets/css/achievements.css') ? filemtime(dirname(__DIR__) . '/assets/css/achievements.css') : time() ?>">
<?php endif; ?>

<div class="club-header-grid">
  <!-- Club Hero -->
  <section class="club-hero club-hero--grid">
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

  <!-- Club Achievements -->
  <?php if (function_exists('renderClubAchievements')): ?>
  <section class="club-achievements-grid">
    <?= renderClubAchievements($db, $clubId) ?>
  </section>
  <?php endif; ?>
</div>

<style>
/* Club Header Grid - 50/50 on desktop */
.club-header-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-lg);
  margin-bottom: var(--space-lg);
}

@media (min-width: 1024px) {
  .club-header-grid {
    grid-template-columns: 1fr 1fr;
    align-items: stretch;
  }

  .club-hero--grid {
    height: 100%;
    display: flex;
    flex-direction: column;
  }

  .club-hero--grid .hero-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
  }

  .club-achievements-grid {
    height: 100%;
  }

  .club-achievements-grid .achievement-card {
    height: 100%;
    margin-bottom: 0;
  }
}

/* Make achievements card fill height */
.club-achievements-grid .achievement-card {
  display: flex;
  flex-direction: column;
}

.club-achievements-grid .achievement-badges {
  flex: 1;
}
</style>

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
        <tr onclick="window.location='/rider/<?= $member['id'] ?>'" class="cursor-pointer">
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
