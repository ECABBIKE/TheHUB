<?php
/**
 * V3 Single Rider Page - Redesigned Profile with Achievements & Series Standings
 */

$db = hub_db();
$riderId = intval($pageInfo['params']['id'] ?? 0);

// Check if current user is viewing their own profile
$currentUser = function_exists('hub_current_user') ? hub_current_user() : null;
$isOwnProfile = $currentUser && isset($currentUser['id']) && $currentUser['id'] == $riderId;

// Include rebuild functions for achievements
$rebuildPath = dirname(__DIR__) . '/includes/rebuild-rider-stats.php';
if (file_exists($rebuildPath)) {
    require_once $rebuildPath;
}

// Include new achievements system
$achievementsPath = dirname(__DIR__) . '/includes/achievements.php';
if (file_exists($achievementsPath)) {
    require_once $achievementsPath;
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

if (!$riderId) {
    header('Location: /riders');
    exit;
}

try {
    // Try to fetch rider with new profile fields first
    $rider = null;
    $hasNewColumns = true;

    try {
        $stmt = $db->prepare("
            SELECT
                r.id, r.firstname, r.lastname, r.birth_year, r.gender,
                r.license_number, r.license_type, r.license_year, r.license_valid_until, r.gravity_id, r.active,
                r.social_instagram, r.social_facebook, r.social_strava, r.social_youtube, r.social_tiktok,
                r.stats_total_starts, r.stats_total_finished, r.stats_total_wins, r.stats_total_podiums,
                r.first_season, r.experience_level,
                c.id as club_id, c.name as club_name, c.city as club_city
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.id = ?
        ");
        $stmt->execute([$riderId]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // New columns don't exist yet - use basic query
        $hasNewColumns = false;
    }

    // Fallback to basic query if new columns don't exist
    if (!$rider && !$hasNewColumns) {
        $stmt = $db->prepare("
            SELECT
                r.id, r.firstname, r.lastname, r.birth_year, r.gender,
                r.license_number, r.license_type, r.license_year, r.license_valid_until, r.gravity_id, r.active,
                c.id as club_id, c.name as club_name, c.city as club_city
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.id = ?
        ");
        $stmt->execute([$riderId]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);

        // Set defaults for missing columns
        if ($rider) {
            $rider['social_instagram'] = null;
            $rider['social_facebook'] = null;
            $rider['social_strava'] = null;
            $rider['social_youtube'] = null;
            $rider['social_tiktok'] = null;
            $rider['stats_total_starts'] = 0;
            $rider['stats_total_finished'] = 0;
            $rider['stats_total_wins'] = 0;
            $rider['stats_total_podiums'] = 0;
            $rider['first_season'] = null;
            $rider['experience_level'] = 1;
        }
    }

    if (!$rider) {
        // Show rider-specific not found page
        http_response_code(404);
        ?>
        <div class="page-grid">
            <section class="card grid-full text-center p-lg">
                <div style="font-size:4rem;margin-bottom:var(--space-md)">🚴‍♂️❓</div>
                <h1 class="text-2xl font-bold mb-sm">Åkare hittades inte</h1>
                <p class="text-secondary mb-lg">Åkare med ID <code style="background:var(--color-bg-sunken);padding:2px 6px;border-radius:4px"><?= $riderId ?></code> finns inte i databasen.</p>
                <div class="flex justify-center gap-md">
                    <a href="/database" class="btn btn--primary">Sök åkare</a>
                    <a href="/riders" class="btn btn--secondary">Visa alla åkare</a>
                </div>
            </section>
        </div>
        <?php
        return;
    }

    // Fetch rider's results
    $stmt = $db->prepare("
        SELECT
            res.id, res.finish_time, res.status, res.points, res.position,
            res.event_id, res.class_id,
            e.id as event_id, e.name as event_name, e.date as event_date, e.location,
            s.id as series_id, s.name as series_name,
            cls.display_name as class_name,
            COALESCE(cls.awards_points, 1) as awards_podiums,
            CASE WHEN LOWER(COALESCE(cls.display_name, cls.name, '')) LIKE '%motion%' THEN 1 ELSE 0 END as is_motion
        FROM results res
        JOIN events e ON res.event_id = e.id
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN classes c ON res.class_id = c.id
        LEFT JOIN classes cls ON res.class_id = cls.id
        WHERE res.cyclist_id = ? AND res.status != 'dns'
        ORDER BY e.date DESC
    ");
    $stmt->execute([$riderId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Separate competitive and motion results
    $competitiveResults = array_filter($results, fn($r) => !$r['is_motion']);
    $motionResults = array_filter($results, fn($r) => $r['is_motion']);
    $hasCompetitiveResults = count($competitiveResults) > 0;

    // Motion stats (for highlight section)
    $motionStarts = count($motionResults);
    $motionFinished = count(array_filter($motionResults, fn($r) => $r['status'] === 'finished'));

    // Calculate stats from competitive results only (not motion)
    $totalStarts = count($competitiveResults);
    $finishedRaces = count(array_filter($competitiveResults, fn($r) => $r['status'] === 'finished'));
    $wins = count(array_filter($competitiveResults, fn($r) => $r['position'] == 1 && $r['status'] === 'finished'));
    $podiums = count(array_filter($competitiveResults, fn($r) => $r['position'] <= 3 && $r['status'] === 'finished'));

    // === TREND SECTION DATA ===

    // 1. Form curve - Last 5 races with positions
    $finishedResults = array_filter($results, fn($r) => $r['status'] === 'finished' && $r['position'] > 0);
    $formResults = array_slice($finishedResults, 0, 5);
    $formResults = array_reverse($formResults); // Chronological order (oldest first)

    // Calculate form trend (compare avg of first half vs second half)
    $formTrend = 'stable';
    if (count($formResults) >= 3) {
        $midPoint = (int)(count($formResults) / 2);
        $firstHalf = array_slice($formResults, 0, $midPoint);
        $secondHalf = array_slice($formResults, -$midPoint);

        $firstAvg = count($firstHalf) > 0 ? array_sum(array_column($firstHalf, 'position')) / count($firstHalf) : 0;
        $secondAvg = count($secondHalf) > 0 ? array_sum(array_column($secondHalf, 'position')) / count($secondHalf) : 0;

        // Lower position is better (1st > 2nd > 3rd)
        if ($secondAvg < $firstAvg - 1) {
            $formTrend = 'up'; // Improving (lower positions)
        } elseif ($secondAvg > $firstAvg + 1) {
            $formTrend = 'down'; // Declining (higher positions)
        }
    }

    // 2. Season Highlights calculation
    $highlights = [];

    // Win rate
    $winRate = $totalStarts > 0 ? round(($wins / $totalStarts) * 100) : 0;
    if ($winRate >= 10) {
        $highlights[] = [
            'icon' => '🏆',
            'text' => $winRate . '% win rate',
            'type' => 'winrate'
        ];
    }

    // Podium streak (consecutive top-3 finishes)
    $currentStreak = 0;
    foreach ($results as $r) {
        if ($r['status'] === 'finished' && $r['position'] <= 3) {
            $currentStreak++;
        } else {
            break;
        }
    }
    if ($currentStreak >= 2) {
        $highlights[] = [
            'icon' => '🔥',
            'text' => $currentStreak . ' pallplatser i rad',
            'type' => 'streak',
            'active' => true
        ];
    }

    // Best result
    $bestResult = null;
    foreach ($finishedResults as $r) {
        if ($r['position'] == 1) {
            $bestResult = $r;
            break;
        }
    }
    if (!$bestResult && !empty($finishedResults)) {
        // Find lowest position
        $bestPos = PHP_INT_MAX;
        foreach ($finishedResults as $r) {
            if ($r['position'] < $bestPos) {
                $bestPos = $r['position'];
                $bestResult = $r;
            }
        }
    }
    if ($bestResult) {
        $posText = $bestResult['position'] == 1 ? '1:a' : ($bestResult['position'] == 2 ? '2:a' : ($bestResult['position'] == 3 ? '3:e' : $bestResult['position'] . ':e'));
        $highlights[] = [
            'icon' => '⭐',
            'text' => 'Bästa: ' . $posText . ' ' . htmlspecialchars($bestResult['event_name']),
            'type' => 'best'
        ];
    }

    // Head-to-head statistics (riders beaten percentage)
    // Calculate from results: for each event, count riders with worse position
    $totalRidersBeaten = 0;
    $totalCompetitors = 0;
    foreach ($finishedResults as $r) {
        if (isset($r['event_id'])) {
            // Count riders in same event with worse position
            $eventStmt = $db->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN position > ? THEN 1 ELSE 0 END) as beaten
                FROM results
                WHERE event_id = ? AND status = 'finished' AND cyclist_id != ?
            ");
            $eventStmt->execute([$r['position'], $r['event_id'], $riderId]);
            $eventStats = $eventStmt->fetch(PDO::FETCH_ASSOC);
            if ($eventStats) {
                $totalCompetitors += $eventStats['total'];
                $totalRidersBeaten += $eventStats['beaten'];
            }
        }
    }
    $h2hPercent = $totalCompetitors > 0 ? round(($totalRidersBeaten / $totalCompetitors) * 100) : 0;
    if ($h2hPercent >= 40 && $totalCompetitors >= 5) {
        $highlights[] = [
            'icon' => '⚔️',
            'text' => 'Slagit ' . $h2hPercent . '% av motståndare',
            'type' => 'h2h'
        ];
    }

    // Ensure we have at least some highlights
    if (empty($highlights) && $totalStarts > 0) {
        $highlights[] = [
            'icon' => '🚴',
            'text' => $totalStarts . ' starter denna säsong',
            'type' => 'starts'
        ];
    }

    // 3. Ranking history - Get historical positions from snapshots
    $rankingHistory = [];
    if ($rankingFunctionsLoaded) {
        try {
            // Get ranking snapshots for last 6 months
            $historyStmt = $db->prepare("
                SELECT
                    DATE_FORMAT(snapshot_date, '%Y-%m') as month,
                    DATE_FORMAT(snapshot_date, '%b') as month_short,
                    ranking_position,
                    total_ranking_points
                FROM ranking_snapshots
                WHERE rider_id = ? AND discipline = 'GRAVITY'
                ORDER BY snapshot_date ASC
            ");
            $historyStmt->execute([$riderId]);
            $snapshots = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by month (take latest per month)
            $byMonth = [];
            foreach ($snapshots as $snap) {
                $byMonth[$snap['month']] = $snap;
            }
            $rankingHistory = array_values($byMonth);

            // Limit to last 6 entries
            $rankingHistory = array_slice($rankingHistory, -6);
        } catch (Exception $e) {
            // Ignore errors
        }
    }

    // Calculate ranking change from start
    $rankingChange = 0;
    if (!empty($rankingHistory) && $rankingPosition) {
        $startPosition = $rankingHistory[0]['ranking_position'] ?? $rankingPosition;
        $rankingChange = $startPosition - $rankingPosition; // Positive = improved
    }

    // Calculate age
    $currentYear = date('Y');
    $age = ($rider['birth_year'] && $rider['birth_year'] > 0) ? ($currentYear - $rider['birth_year']) : null;

    // Get achievements
    $achievements = [];
    if (function_exists('getRiderAchievements')) {
        $achievements = getRiderAchievements($db, $riderId);
    }

    // Get social profiles
    $socialProfiles = [];
    if (function_exists('getRiderSocialProfiles')) {
        $socialProfiles = getRiderSocialProfiles($db, $riderId);
    }

    // Get series standings
    $seriesStandings = [];
    if (function_exists('getRiderSeriesStandings')) {
        $seriesStandings = getRiderSeriesStandings($db, $riderId);
    }

    // Get ranking position
    $rankingPosition = null;
    $rankingPoints = 0;
    $parentDb = function_exists('getDB') ? getDB() : null;
    if ($rankingFunctionsLoaded && $parentDb && function_exists('getRiderRankingDetails')) {
        $riderRankingDetails = getRiderRankingDetails($parentDb, $riderId, 'GRAVITY');
        if ($riderRankingDetails) {
            $rankingPoints = $riderRankingDetails['total_ranking_points'] ?? 0;
            $rankingPosition = $riderRankingDetails['ranking_position'] ?? null;
        }
    }

    // Experience level info
    $experienceLevel = $rider['experience_level'] ?? 1;
    $experienceInfo = [
        1 => ['name' => '1:a året', 'class' => 'level-1'],
        2 => ['name' => '2:a året', 'class' => 'level-2'],
        3 => ['name' => 'Erfaren', 'class' => 'level-3'],
        4 => ['name' => 'Expert', 'class' => 'level-4'],
        5 => ['name' => 'Veteran', 'class' => 'level-5'],
        6 => ['name' => 'Legend', 'class' => 'level-6']
    ];
    $expInfo = $experienceInfo[$experienceLevel] ?? $experienceInfo[1];

    // Check for profile image
    $profileImage = null;
    $profileImageDir = dirname(__DIR__) . '/uploads/riders/';
    $profileImageUrl = '/uploads/riders/';
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        if (file_exists($profileImageDir . $riderId . '.' . $ext)) {
            $profileImage = $profileImageUrl . $riderId . '.' . $ext . '?v=' . filemtime($profileImageDir . $riderId . '.' . $ext);
            break;
        }
    }

    // Check license status
    $hasLicense = !empty($rider['license_type']);
    $licenseActive = false;
    if ($hasLicense) {
        if (!empty($rider['license_year']) && $rider['license_year'] >= date('Y')) {
            $licenseActive = true;
        } elseif (!empty($rider['license_valid_until']) && $rider['license_valid_until'] !== '0000-00-00') {
            $licenseActive = strtotime($rider['license_valid_until']) >= strtotime('today');
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $rider = null;
}

if (!$rider) {
    // Show rider-specific error page
    http_response_code(404);
    ?>
    <div class="page-grid">
        <section class="card grid-full text-center p-lg">
            <div style="font-size:4rem;margin-bottom:var(--space-md)">🚴‍♂️❓</div>
            <h1 class="text-2xl font-bold mb-sm">Åkare hittades inte</h1>
            <p class="text-secondary mb-lg">Åkare med ID <code style="background:var(--color-bg-sunken);padding:2px 6px;border-radius:4px"><?= $riderId ?></code> finns inte i databasen.</p>
            <?php if (isset($error)): ?>
            <p class="text-secondary mb-md" style="font-size:0.8rem;color:var(--color-danger)">Fel: <?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            <div class="flex justify-center gap-md">
                <a href="/database" class="btn btn--primary">Sök åkare</a>
                <a href="/riders" class="btn btn--secondary">Visa alla åkare</a>
            </div>
        </section>
    </div>
    <?php
    return;
}

$fullName = htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']);

// Process achievements for display
$achievementCounts = ['gold' => 0, 'silver' => 0, 'bronze' => 0, 'hot_streak' => 0];
$isSeriesLeader = false;
$isSeriesChampion = false;
$isSwedishChampion = false;
$swedishChampionCount = 0;
$seriesChampionCount = 0;
$finisher100 = false;

foreach ($achievements as $ach) {
    switch ($ach['achievement_type']) {
        case 'gold': $achievementCounts['gold'] = (int)$ach['achievement_value']; break;
        case 'silver': $achievementCounts['silver'] = (int)$ach['achievement_value']; break;
        case 'bronze': $achievementCounts['bronze'] = (int)$ach['achievement_value']; break;
        case 'hot_streak': $achievementCounts['hot_streak'] = (int)$ach['achievement_value']; break;
        case 'series_leader': $isSeriesLeader = true; break;
        case 'series_champion':
            $isSeriesChampion = true;
            $seriesChampionCount++; // Count each series championship
            break;
        case 'swedish_champion':
            $isSwedishChampion = true;
            $swedishChampionCount++; // Count each SM title (value is event name, not count)
            break;
        case 'finisher_100': $finisher100 = true; break;
    }
}

// Calculate finish rate
$finishRate = $totalStarts > 0 ? round(($finishedRaces / $totalStarts) * 100) : 0;
?>

<!-- Profile Hero -->
<section class="profile-hero">
    <div class="hero-accent-bar"></div>
    <?php if ($rider['gravity_id']):
        $gidNumber = preg_replace('/^.*?-?(\d+)$/', '$1', $rider['gravity_id']);
        $gidNumber = ltrim($gidNumber, '0') ?: '0';
    ?>
    <div class="gravity-id-badge">
        <span class="gid-label">Gravity ID</span>
        <span class="gid-number">#<?= htmlspecialchars($gidNumber) ?></span>
    </div>
    <?php endif; ?>
    <div class="hero-content">
        <!-- Top row: Photo + Name -->
        <div class="hero-top">
            <div class="hero-left">
                <div class="profile-photo-container">
                    <div class="profile-photo">
                        <?php if ($profileImage): ?>
                            <img src="<?= htmlspecialchars($profileImage) ?>" alt="<?= $fullName ?>">
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <?php if ($rankingPosition): ?>
                    <div class="ranking-badge">
                        <span class="rank-label">Ranking</span>
                        <span class="rank-number">#<?= $rankingPosition ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="hero-center">
                <h1 class="profile-name"><?= $fullName ?></h1>
                <?php if ($age): ?><span class="profile-age"><?= $age ?> år</span><?php endif; ?>
                <?php if ($rider['club_name']): ?>
                <a href="/club/<?= $rider['club_id'] ?>" class="profile-club"><?= htmlspecialchars($rider['club_name']) ?></a>
                <?php endif; ?>
            </div>

            <div class="hero-right"></div>
        </div>

        <!-- Bottom row: Badges + Social -->
        <div class="hero-bottom">
            <div class="profile-badges">
                <?php if ($hasLicense): ?>
                <span class="class-badge"><?= $licenseActive ? '✓' : '✗' ?> <?= htmlspecialchars($rider['license_type']) ?></span>
                <?php endif; ?>
                <?php if ($licenseActive): ?>
                <span class="license-badge">Licens <?= date('Y') ?> ✓</span>
                <?php endif; ?>
                <?php if ($experienceLevel > 1): ?>
                <span class="experience-badge <?= $expInfo['class'] ?>"><?= $expInfo['name'] ?></span>
                <?php endif; ?>
                <?php if ($rider['license_number']): ?>
                <span class="uci-badge">UCI <?= htmlspecialchars($rider['license_number']) ?></span>
                <?php endif; ?>
            </div>

            <div class="hero-social">
            <?php
            $hasSocialLinks = !empty($socialProfiles['instagram']) || !empty($socialProfiles['strava']) ||
                              !empty($socialProfiles['facebook']) || !empty($socialProfiles['youtube']) ||
                              !empty($socialProfiles['tiktok']);
            ?>
            <?php if ($isOwnProfile && !$hasSocialLinks): ?>
            <a href="/profile/edit" class="add-social-prompt" title="Lägg till sociala medier">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="16"/>
                    <line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
                <span>Lägg till sociala medier</span>
            </a>
            <?php else: ?>
            <a href="<?= $socialProfiles['instagram']['url'] ?? '#' ?>" class="social-link instagram <?= empty($socialProfiles['instagram']) ? 'empty' : '' ?>" title="Instagram" <?= !empty($socialProfiles['instagram']) ? 'target="_blank"' : '' ?>>
                <svg viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
            </a>
            <a href="<?= $socialProfiles['strava']['url'] ?? '#' ?>" class="social-link strava <?= empty($socialProfiles['strava']) ? 'empty' : '' ?>" title="Strava" <?= !empty($socialProfiles['strava']) ? 'target="_blank"' : '' ?>>
                <svg viewBox="0 0 24 24"><path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169"/></svg>
            </a>
            <a href="<?= $socialProfiles['facebook']['url'] ?? '#' ?>" class="social-link facebook <?= empty($socialProfiles['facebook']) ? 'empty' : '' ?>" title="Facebook" <?= !empty($socialProfiles['facebook']) ? 'target="_blank"' : '' ?>>
                <svg viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            </a>
            <a href="<?= $socialProfiles['youtube']['url'] ?? '#' ?>" class="social-link youtube <?= empty($socialProfiles['youtube']) ? 'empty' : '' ?>" title="YouTube" <?= !empty($socialProfiles['youtube']) ? 'target="_blank"' : '' ?>>
                <svg viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
            </a>
            <a href="<?= $socialProfiles['tiktok']['url'] ?? '#' ?>" class="social-link tiktok <?= empty($socialProfiles['tiktok']) ? 'empty' : '' ?>" title="TikTok" <?= !empty($socialProfiles['tiktok']) ? 'target="_blank"' : '' ?>>
                <svg viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
            </a>
            <?php endif; ?>
            </div>

            <div class="profile-actions">
                <button type="button" class="share-profile-btn" onclick="shareProfile(<?= $riderId ?>)" title="Dela profil">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="18" cy="5" r="3"/>
                        <circle cx="6" cy="12" r="3"/>
                        <circle cx="18" cy="19" r="3"/>
                        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                        <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                    </svg>
                    Dela
                </button>
                <?php if ($isOwnProfile): ?>
                <a href="/profile/edit" class="edit-profile-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    Redigera
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if ($hasCompetitiveResults): ?>
<!-- Rider Stats Trend Section -->
<div class="rider-stats-trend">
    <div class="stats-row">
        <!-- Form Section -->
        <div class="form-section">
            <h4 class="trend-section-title">Form</h4>
            <?php if (!empty($formResults)): ?>
            <div class="form-results">
                <?php foreach ($formResults as $idx => $fr):
                    $pos = $fr['position'];
                    $posClass = $pos == 1 ? 'gold' : ($pos == 2 ? 'silver' : ($pos == 3 ? 'bronze' : ''));
                    $posEmoji = $pos == 1 ? '🥇' : ($pos == 2 ? '🥈' : ($pos == 3 ? '🥉' : $pos));
                ?>
                <div class="form-race">
                    <span class="form-position <?= $posClass ?>"><?= $posEmoji ?></span>
                    <span class="form-event"><?= htmlspecialchars(mb_substr($fr['event_name'], 0, 10)) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Visual Form Chart -->
            <div class="form-chart">
                <?php
                // Generate SVG points for form curve
                $chartWidth = 200;
                $chartHeight = 50;
                $padding = 15;
                $numResults = count($formResults);

                if ($numResults > 0) {
                    $xStep = $numResults > 1 ? ($chartWidth - $padding * 2) / ($numResults - 1) : 0;
                    $points = [];
                    $circles = [];
                    $maxPos = max(array_column($formResults, 'position'));
                    $minPos = min(array_column($formResults, 'position'));
                    $range = max(1, $maxPos - $minPos);

                    foreach ($formResults as $idx => $fr) {
                        $x = $padding + ($idx * $xStep);
                        // Invert Y so lower positions are higher on chart
                        $y = $padding + (($fr['position'] - $minPos) / $range) * ($chartHeight - $padding * 2);
                        $points[] = "$x,$y";

                        // Circle color based on position
                        $fillColor = $fr['position'] == 1 ? '#FFD700' :
                                    ($fr['position'] == 2 ? '#C0C0C0' :
                                    ($fr['position'] == 3 ? '#CD7F32' : '#7A7A7A'));
                        $circles[] = ['x' => $x, 'y' => $y, 'fill' => $fillColor];
                    }
                    $polyPoints = implode(' ', $points);
                ?>
                <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>">
                    <!-- Trend line -->
                    <polyline
                        points="<?= $polyPoints ?>"
                        fill="none"
                        stroke="var(--color-accent)"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"/>
                    <!-- Data points -->
                    <?php foreach ($circles as $c): ?>
                    <circle cx="<?= $c['x'] ?>" cy="<?= $c['y'] ?>" r="6" fill="<?= $c['fill'] ?>" stroke="white" stroke-width="2"/>
                    <?php endforeach; ?>
                </svg>
                <?php } ?>
            </div>

            <div class="form-trend <?= $formTrend ?>">
                <?php if ($formTrend === 'up'): ?>
                <span class="trend-arrow">↗</span>
                <span class="trend-text">Stigande form</span>
                <?php elseif ($formTrend === 'down'): ?>
                <span class="trend-arrow">↘</span>
                <span class="trend-text">Fallande form</span>
                <?php else: ?>
                <span class="trend-arrow">→</span>
                <span class="trend-text">Stabil form</span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="form-empty">
                <span class="empty-icon">🏁</span>
                <span>Inga resultat ännu</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Highlights Section -->
        <div class="highlights-section">
            <h4 class="trend-section-title">Säsongens Highlights</h4>
            <?php if (!empty($highlights)): ?>
            <div class="highlights-list">
                <?php foreach ($highlights as $hl): ?>
                <div class="highlight-item <?= $hl['type'] ?> <?= !empty($hl['active']) ? 'active' : '' ?>">
                    <span class="highlight-icon"><?= $hl['icon'] ?></span>
                    <span class="highlight-text"><?= $hl['text'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="highlights-empty">
                <span class="empty-icon">⏳</span>
                <span>Bygg din statistik genom att tävla!</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ranking Section -->
    <div class="ranking-section">
        <div class="ranking-header">
            <h4 class="trend-section-title">Ranking</h4>
            <?php if ($rankingPosition): ?>
            <div class="ranking-current">
                <span class="rank-number-large">#<?= $rankingPosition ?></span>
                <?php if ($rankingChange != 0): ?>
                <span class="rank-change <?= $rankingChange > 0 ? 'up' : 'down' ?>">
                    <?= $rankingChange > 0 ? '↑' : '↓' ?><?= abs($rankingChange) ?> från start
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($rankingHistory) && count($rankingHistory) > 1): ?>
        <!-- Ranking Chart -->
        <div class="ranking-chart">
            <?php
            // Calculate chart dimensions
            $chartWidth = 300;
            $chartHeight = 80;
            $padding = 30;
            $numPoints = count($rankingHistory);

            // Find min/max positions for scaling
            $positions = array_column($rankingHistory, 'ranking_position');
            $minRank = min($positions);
            $maxRank = max($positions);
            $rankRange = max(1, $maxRank - $minRank);

            // Add some padding to range
            $minRank = max(1, $minRank - 1);
            $maxRank = $maxRank + 1;
            $rankRange = $maxRank - $minRank;

            $xStep = $numPoints > 1 ? ($chartWidth - $padding * 2) / ($numPoints - 1) : 0;
            $points = [];
            $areaPoints = [];

            foreach ($rankingHistory as $idx => $h) {
                $x = $padding + ($idx * $xStep);
                // Invert Y so rank #1 is at top
                $y = $padding + (($h['ranking_position'] - $minRank) / $rankRange) * ($chartHeight - $padding * 2);
                $points[] = "$x,$y";
                $areaPoints[] = ['x' => $x, 'y' => $y, 'pos' => $h['ranking_position']];
            }

            // Create area fill polygon
            $polyPoints = implode(' ', $points);
            $firstX = $areaPoints[0]['x'];
            $lastX = $areaPoints[count($areaPoints) - 1]['x'];
            $bottomY = $chartHeight - $padding;
            $areaPolygon = $polyPoints . " $lastX,$bottomY $firstX,$bottomY";
            ?>
            <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>">
                <defs>
                    <linearGradient id="rankingGradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--color-accent)"/>
                        <stop offset="100%" stop-color="var(--color-accent)" stop-opacity="0"/>
                    </linearGradient>
                </defs>

                <!-- Grid lines -->
                <line x1="<?= $padding ?>" y1="<?= $padding ?>" x2="<?= $chartWidth - $padding ?>" y2="<?= $padding ?>" stroke="var(--color-border)" stroke-width="1" opacity="0.5"/>
                <line x1="<?= $padding ?>" y1="<?= $chartHeight / 2 ?>" x2="<?= $chartWidth - $padding ?>" y2="<?= $chartHeight / 2 ?>" stroke="var(--color-border)" stroke-width="1" stroke-dasharray="4" opacity="0.3"/>
                <line x1="<?= $padding ?>" y1="<?= $chartHeight - $padding ?>" x2="<?= $chartWidth - $padding ?>" y2="<?= $chartHeight - $padding ?>" stroke="var(--color-border)" stroke-width="1" opacity="0.5"/>

                <!-- Y-axis labels -->
                <text x="5" y="<?= $padding + 4 ?>" font-size="10" fill="var(--color-text-muted)">#<?= $minRank ?></text>
                <text x="5" y="<?= $chartHeight - $padding + 4 ?>" font-size="10" fill="var(--color-text-muted)">#<?= $maxRank ?></text>

                <!-- Area fill -->
                <polygon points="<?= $areaPolygon ?>" fill="url(#rankingGradient)" opacity="0.3"/>

                <!-- Ranking line -->
                <polyline
                    points="<?= $polyPoints ?>"
                    fill="none"
                    stroke="var(--color-accent)"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"/>

                <!-- Current position marker -->
                <?php $lastPoint = end($areaPoints); ?>
                <circle cx="<?= $lastPoint['x'] ?>" cy="<?= $lastPoint['y'] ?>" r="8" fill="var(--color-accent)" stroke="white" stroke-width="2"/>
            </svg>
        </div>

        <!-- Month labels -->
        <div class="ranking-months">
            <?php foreach ($rankingHistory as $h): ?>
            <span><?= $h['month_short'] ?></span>
            <?php endforeach; ?>
        </div>
        <?php elseif ($rankingPosition): ?>
        <!-- Simple progress bar if no history -->
        <div class="ranking-bar-container">
            <?php
            // Get total riders for percentage
            $totalRankedRiders = 100; // Default estimate
            try {
                $countStmt = $db->prepare("SELECT COUNT(DISTINCT rider_id) as cnt FROM ranking_snapshots WHERE discipline = 'GRAVITY'");
                $countStmt->execute();
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                if ($countResult && $countResult['cnt'] > 0) {
                    $totalRankedRiders = $countResult['cnt'];
                }
            } catch (Exception $e) {}
            $rankPercent = max(5, min(100, 100 - (($rankingPosition - 1) / max(1, $totalRankedRiders - 1)) * 100));
            ?>
            <div class="ranking-bar">
                <div class="ranking-fill" style="width: <?= $rankPercent ?>%"></div>
            </div>
            <div class="ranking-labels">
                <span>#<?= $totalRankedRiders ?></span>
                <span>#1</span>
            </div>
        </div>
        <?php else: ?>
        <div class="ranking-empty">
            <span class="empty-icon">📊</span>
            <span>Ingen ranking tillgänglig</span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($motionStarts > 0): ?>
<!-- Highlight Card - Motion/Hobby deltagare -->
<div class="highlight-card">
    <div class="highlight-icon">🚴</div>
    <div class="highlight-content">
        <h3 class="highlight-title">Motion-deltagare</h3>
        <p class="highlight-text">Har deltagit i <strong><?= $motionStarts ?></strong> motion-lopp och fullföljt <strong><?= $motionFinished ?></strong>.</p>
        <p class="highlight-subtext">Motion-klasser är icke-tävlande och ger inga rankingpoäng.</p>
    </div>
</div>
<?php else: ?>
<!-- No results yet -->
<div class="highlight-card">
    <div class="highlight-icon">👋</div>
    <div class="highlight-content">
        <h3 class="highlight-title">Välkommen!</h3>
        <p class="highlight-text">Inga resultat registrerade ännu.</p>
        <p class="highlight-subtext">Anmäl dig till ett event för att komma igång!</p>
    </div>
</div>
<?php endif; ?>

<div class="content-layout">
    <div class="content-main">
        <!-- Series Standings -->
        <?php if (!empty($seriesStandings)): ?>
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Serieställning</h2>
            </div>

            <div class="series-tabs">
                <nav class="series-nav">
                    <?php foreach ($seriesStandings as $idx => $standing): ?>
                    <button class="series-tab <?= $idx === 0 ? 'active' : '' ?>" data-target="series-panel-<?= $idx ?>">
                        <span class="series-dot" style="background: <?= htmlspecialchars($standing['series_color'] ?? 'var(--color-accent)') ?>"></span>
                        <?= htmlspecialchars($standing['series_name']) ?>
                    </button>
                    <?php endforeach; ?>
                </nav>

                <div class="series-content">
                    <?php foreach ($seriesStandings as $idx => $standing): ?>
                    <div class="series-panel <?= $idx === 0 ? 'active' : '' ?>" id="series-panel-<?= $idx ?>">
                        <div class="standings-header">
                            <div class="standings-info">
                                <div class="standings-rank">
                                    <span class="rank-position"><?= $standing['ranking'] ?></span>
                                    <span class="rank-suffix"><?= $standing['ranking'] == 1 ? ':a' : ':e' ?></span>
                                </div>
                                <div class="standings-meta">
                                    av <?= $standing['total_riders'] ?> i <?= htmlspecialchars($standing['class_name']) ?>
                                </div>
                            </div>
                            <?php if ($standing['trend']): ?>
                            <div class="standings-trend <?= $standing['trend']['direction'] ?>">
                                <?php if ($standing['trend']['direction'] === 'up'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 15l-6-6-6 6"/></svg>
                                +<?= $standing['trend']['change'] ?> efter senaste
                                <?php elseif ($standing['trend']['direction'] === 'down'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                                -<?= $standing['trend']['change'] ?> efter senaste
                                <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                Oförändrad
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($standing['gap_to_podium'] !== null): ?>
                        <div class="podium-progress">
                            <div class="progress-header">
                                <span class="progress-label"><?= $standing['ranking'] <= 3 ? ($standing['ranking'] == 1 ? 'Försprång till 2:a' : 'Till ' . ($standing['ranking'] - 1) . ':a plats') : 'Till pallplats' ?></span>
                                <span class="progress-value"><?= $standing['ranking'] == 1 ? '+' : '-' ?><?= abs($standing['gap_to_podium']) ?> poäng</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $standing['ranking'] == 1 ? 100 : min(95, max(10, 100 - abs($standing['gap_to_podium']))) ?>%; background: <?= htmlspecialchars($standing['series_color'] ?? 'var(--color-accent)') ?>"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="series-stats">
                            <div class="series-stat">
                                <div class="series-stat-value"><?= $standing['total_points'] ?></div>
                                <div class="series-stat-label">Poäng</div>
                            </div>
                            <div class="series-stat">
                                <div class="series-stat-value"><?= $standing['events_count'] ?></div>
                                <div class="series-stat-label">Deltävl.</div>
                            </div>
                            <div class="series-stat">
                                <div class="series-stat-value"><?= $standing['wins'] ?></div>
                                <div class="series-stat-label">Segrar</div>
                            </div>
                            <div class="series-stat">
                                <div class="series-stat-value"><?= $standing['podiums'] ?></div>
                                <div class="series-stat-label">Pallplatser</div>
                            </div>
                        </div>

                        <?php if (!empty($standing['results'])): ?>
                        <div class="results-header">
                            <span class="results-title">Resultat i serien</span>
                            <span class="results-count"><?= count($standing['results']) ?> starter</span>
                        </div>
                        <div class="results-list">
                            <?php foreach ($standing['results'] as $result): ?>
                            <a href="/event/<?= $result['event_id'] ?? '' ?>" class="result-item">
                                <div class="result-position <?= $result['position'] <= 3 ? 'p' . $result['position'] : '' ?>">
                                    <?php if ($result['status'] !== 'finished'): ?>
                                        <?= strtoupper(substr($result['status'], 0, 3)) ?>
                                    <?php elseif ($result['position'] == 1): ?>
                                        <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                                    <?php elseif ($result['position'] == 2): ?>
                                        <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                                    <?php elseif ($result['position'] == 3): ?>
                                        <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                                    <?php else: ?>
                                        <?= $result['position'] ?>
                                    <?php endif; ?>
                                </div>
                                <div class="result-info">
                                    <div class="result-event-name"><?= htmlspecialchars($result['event_name']) ?></div>
                                    <div class="result-meta">
                                        <span><?= date('j M Y', strtotime($result['event_date'])) ?></span>
                                        <span>•</span>
                                        <span><?= htmlspecialchars($result['class_name']) ?></span>
                                    </div>
                                </div>
                                <div class="result-time">
                                    <div class="result-time-value"><?= htmlspecialchars($result['time'] ?? '-') ?></div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <!-- Sidebar: Achievements & Results -->
    <aside class="content-sidebar">
        <link rel="stylesheet" href="/assets/css/achievements.css?v=<?= file_exists(dirname(__DIR__) . '/assets/css/achievements.css') ? filemtime(dirname(__DIR__) . '/assets/css/achievements.css') : time() ?>">
        <?php
        // Build stats array for the achievements component
        $riderStats = [
            'gold' => $achievementCounts['gold'],
            'silver' => $achievementCounts['silver'],
            'bronze' => $achievementCounts['bronze'],
            'hot_streak' => $achievementCounts['hot_streak'],
            'series_completed' => $finisher100 ? 1 : 0,
            'is_serieledare' => $isSeriesLeader,
            'series_wins' => $seriesChampionCount,
            'sm_wins' => $swedishChampionCount,
            'seasons_active' => $experienceLevel,
            'has_series_win' => $isSeriesChampion,
            'first_season_year' => $rider['first_season'] ?? date('Y')
        ];

        if (function_exists('renderRiderAchievements')) {
            echo renderRiderAchievements($db, $riderId, $riderStats);
        }
        ?>

        <!-- All Results -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Historiska resultat</h2>
            </div>
            <div class="card results-card">
                <?php if (empty($results)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🏁</div>
                    <p>Inga resultat registrerade</p>
                </div>
                <?php else: ?>
                <div class="results-list compact">
                    <?php foreach (array_slice($results, 0, 10) as $result): ?>
                    <a href="/event/<?= $result['event_id'] ?>" class="result-item">
                        <div class="result-position <?= $result['position'] <= 3 && $result['status'] === 'finished' ? 'p' . $result['position'] : '' ?>">
                            <?php if ($result['status'] !== 'finished'): ?>
                                <?= strtoupper(substr($result['status'], 0, 3)) ?>
                            <?php elseif ($result['position'] == 1): ?>
                                <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                            <?php elseif ($result['position'] == 2): ?>
                                <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                            <?php elseif ($result['position'] == 3): ?>
                                <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                            <?php else: ?>
                                <?= $result['position'] ?>
                            <?php endif; ?>
                        </div>
                        <div class="result-info">
                            <div class="result-event-name"><?= htmlspecialchars($result['event_name']) ?></div>
                            <div class="result-meta">
                                <span><?= date('j M Y', strtotime($result['event_date'])) ?></span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if (count($results) > 10): ?>
                <div class="results-more">
                    <span>+ <?= count($results) - 10 ?> fler resultat</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </aside>
</div>

<script>
// Series Tab Switching
document.querySelectorAll('.series-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.series-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        const targetId = tab.dataset.target;
        document.querySelectorAll('.series-panel').forEach(panel => {
            panel.classList.remove('active');
        });
        document.getElementById(targetId).classList.add('active');
    });
});

// Share Profile Function
function shareProfile(riderId) {
    const shareUrl = window.location.origin + '/rider/' + riderId;
    const imageUrl = window.location.origin + '/api/share-image.php?rider_id=' + riderId;

    // Check if Web Share API is available
    if (navigator.share) {
        navigator.share({
            title: document.title,
            text: 'Kolla in min GravitySeries-profil!',
            url: shareUrl
        }).catch(() => {});
    } else {
        // Show modal with share options
        showShareModal(shareUrl, imageUrl, riderId);
    }
}

function showShareModal(shareUrl, imageUrl, riderId) {
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'share-modal-overlay';
    modal.innerHTML = `
        <div class="share-modal">
            <div class="share-modal-header">
                <h3>Dela profil</h3>
                <button class="share-modal-close" onclick="this.closest('.share-modal-overlay').remove()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="share-modal-body">
                <div class="share-preview">
                    <img src="${imageUrl}" alt="Dela bild" loading="lazy">
                </div>
                <div class="share-options">
                    <a href="${imageUrl}" download="gravityseries-stats.png" class="share-option">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        Ladda ner bild
                    </a>
                    <button onclick="copyToClipboard('${shareUrl}')" class="share-option">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        Kopiera länk
                    </button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    // Close on overlay click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
    });
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        // Show success feedback
        const btn = event.target.closest('.share-option');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Kopierat!';
        setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
    });
}
</script>

<style>
/* Profile Hero */
.profile-hero {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: visible;
    margin-bottom: var(--space-lg);
    position: relative;
}

.hero-accent-bar {
    height: 4px;
    background: linear-gradient(90deg, var(--color-accent), #004a98);
}

.hero-content {
    padding: var(--space-md);
}

/* Gravity ID Badge - Top Right Corner (same style as ranking badge) */
.gravity-id-badge {
    position: absolute;
    top: -10px;
    right: -10px;
    background: linear-gradient(135deg, #FFD700, #FFA500);
    color: var(--color-primary, #171717);
    padding: 8px 12px;
    border-radius: var(--radius-md);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    box-shadow: var(--shadow-lg);
    border: 3px solid var(--color-bg-surface);
    z-index: 10;
}

.gravity-id-badge .gid-label {
    font-size: 0.5rem;
    text-transform: uppercase;
    opacity: 0.8;
    letter-spacing: 0.5px;
    line-height: 1;
    margin-bottom: 2px;
}

.gravity-id-badge .gid-number {
    font-family: var(--font-mono);
    font-size: 1.1rem;
    font-weight: 800;
    line-height: 1;
}

/* Hero Top Row - Mobile first (single column) */
.hero-top {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-md);
    align-items: center;
    text-align: center;
    margin-bottom: var(--space-md);
}

.hero-left {
    display: flex;
    align-items: center;
    justify-content: center;
}

.hero-center {
    text-align: center;
}

.hero-right {
    display: none;
}

/* Hero Bottom Row - Mobile first (stacked) */
.hero-bottom {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
    gap: var(--space-md);
}

.profile-badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    align-items: center;
    justify-content: center;
}

.profile-photo-container {
    position: relative;
    flex-shrink: 0;
}

.profile-photo {
    width: 64px;
    height: 64px;
    border-radius: var(--radius-lg);
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid var(--color-bg-surface);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.profile-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-photo svg {
    width: 45%;
    height: 45%;
    stroke: #9ca3af;
}

.ranking-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: linear-gradient(135deg, #FFD700, #FFA500);
    color: var(--color-primary, #171717);
    width: 30px;
    height: 30px;
    padding: 4px;
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
    display: none;
}

.ranking-badge .rank-number {
    font-size: 0.8rem;
    line-height: 1;
}

.profile-name {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--color-text);
    letter-spacing: -0.02em;
    margin: 0 0 var(--space-xs) 0;
}

.profile-age {
    font-size: 0.9rem;
    color: var(--color-text-muted);
    display: block;
    margin-bottom: var(--space-xs);
}

.profile-club {
    color: #004a98;
    font-weight: 600;
    text-decoration: none;
    font-size: 0.9rem;
}

.profile-club:hover { text-decoration: underline; }

.uci-badge {
    font-family: var(--font-mono);
    font-size: 0.7rem;
    color: var(--color-text-muted);
    background: var(--color-bg-sunken);
    padding: 4px 10px;
    border-radius: var(--radius-full);
}

.class-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: linear-gradient(135deg, #0056b3, #004a98);
    color: white;
    font-size: 0.8rem;
    font-weight: 700;
    border-radius: var(--radius-full);
    box-shadow: 0 2px 6px rgba(0, 74, 152, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.experience-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--color-bg-sunken);
    color: var(--color-text);
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: var(--radius-full);
}

.experience-badge.level-4 {
    background: linear-gradient(135deg, rgba(97, 206, 112, 0.2), rgba(97, 206, 112, 0.1));
    color: #16a34a;
}

.experience-badge.level-5 {
    background: linear-gradient(135deg, rgba(0, 74, 152, 0.2), rgba(0, 74, 152, 0.1));
    color: #004a98;
}

.experience-badge.level-6 {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
}

.license-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    background: linear-gradient(135deg, #6ed67e, #61CE70);
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: var(--radius-full);
    box-shadow: 0 2px 6px rgba(97, 206, 112, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.25);
}

/* Social Links - Mobile first (centered) */
.hero-social {
    display: flex;
    gap: var(--space-sm);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
    flex-wrap: wrap;
    justify-content: center;
}

.social-link {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-md);
    background: var(--color-bg-sunken);
    border: 1px solid var(--color-border);
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.2s ease;
}

.social-link:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.social-link svg { width: 16px; height: 16px; }
.social-link.instagram svg { fill: #E4405F; }
.social-link.strava svg { fill: #FC4C02; }
.social-link.facebook svg { fill: #1877F2; }
.social-link.youtube svg { fill: #FF0000; }
.social-link.tiktok svg { fill: #000000; }
.social-link.empty { opacity: 0.4; pointer-events: none; }
.social-link.empty svg { fill: var(--color-text-muted); }

/* Profile Actions */
.profile-actions {
    display: flex;
    gap: var(--space-sm);
    margin-top: var(--space-md);
}

/* Edit Profile Button */
.edit-profile-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-accent);
    color: white;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: var(--radius-md);
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
}

.edit-profile-btn:hover {
    background: var(--color-accent);
    opacity: 0.9;
    transform: translateY(-1px);
}

/* Share Profile Button */
.share-profile-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken);
    color: var(--color-text);
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: var(--radius-md);
    text-decoration: none;
    transition: all 0.2s ease;
    border: 1px solid var(--color-border);
    cursor: pointer;
    font-family: inherit;
}

.share-profile-btn:hover {
    background: var(--color-bg-surface);
    border-color: var(--color-accent);
    color: var(--color-accent);
    transform: translateY(-1px);
}

/* Share Modal */
.share-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: var(--space-md);
}

.share-modal {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    max-width: 480px;
    width: 100%;
    overflow: hidden;
}

.share-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}

.share-modal-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
}

.share-modal-close {
    background: none;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    color: var(--color-text-muted);
    border-radius: var(--radius-sm);
    transition: all 0.2s ease;
}

.share-modal-close:hover {
    background: var(--color-bg-sunken);
    color: var(--color-text);
}

.share-modal-body {
    padding: var(--space-md);
}

.share-preview {
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    overflow: hidden;
    margin-bottom: var(--space-md);
}

.share-preview img {
    width: 100%;
    height: auto;
    display: block;
}

.share-options {
    display: flex;
    gap: var(--space-sm);
}

.share-option {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: var(--space-md);
    background: var(--color-bg-sunken);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
}

.share-option:hover {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.share-option svg {
    flex-shrink: 0;
}

/* Add Social Prompt */
.add-social-prompt {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken);
    border: 1px dashed var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text-muted);
    font-size: 0.85rem;
    text-decoration: none;
    transition: all 0.2s ease;
}

.add-social-prompt:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
    background: var(--color-accent-light, rgba(97, 206, 112, 0.1));
}

/* Stats Grid - Mobile first (2 columns) */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-xs);
    margin-bottom: var(--space-lg);
}

.stat-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-sm);
    text-align: center;
    transition: all 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card.highlight {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-color: #fbbf24;
    box-shadow: 0 2px 8px rgba(251, 191, 36, 0.25);
}

.stat-card.highlight .stat-value { color: #92400e; }
.stat-card.highlight .stat-label { color: #b45309; }

.stat-value {
    font-family: var(--font-mono);
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-text);
    line-height: 1;
    margin-bottom: var(--space-xs);
}

.stat-label {
    font-size: 0.65rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

/* Content Layout */
.content-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-md);
}

@media (min-width: 1024px) {
    .content-layout {
        grid-template-columns: 1fr 380px;
    }
    .content-sidebar {
        position: sticky;
        top: 80px;
    }
}

/* Sections */
.section { margin-bottom: var(--space-lg); }

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-md);
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--color-text);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0;
}

.section-title::before {
    content: '';
    width: 4px;
    height: 18px;
    background: var(--color-accent);
    border-radius: 2px;
}

/* Series Tabs */
.series-tabs {
    background: var(--color-bg-surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    overflow: hidden;
}

.series-nav {
    display: flex;
    border-bottom: 1px solid var(--color-border);
    overflow-x: auto;
}

.series-tab {
    flex: 1;
    min-width: max-content;
    padding: var(--space-sm) var(--space-md);
    background: none;
    border: none;
    font-family: inherit;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
    position: relative;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-xs);
}

.series-tab:hover { color: var(--color-text); background: var(--color-bg-sunken); }
.series-tab.active { color: var(--color-text); }
.series-tab.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--color-accent);
    border-radius: 3px 3px 0 0;
}

.series-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--color-accent);
}

.series-content { padding: var(--space-sm); }
.series-panel { display: none; }
.series-panel.active { display: block; }

/* Standings */
.standings-header {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    margin-bottom: var(--space-lg);
    gap: var(--space-sm);
}

.standings-info { display: flex; align-items: center; gap: var(--space-md); }

.standings-rank { display: flex; align-items: baseline; gap: 4px; }

.rank-position {
    font-family: var(--font-mono);
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--color-text);
    line-height: 1;
}

.rank-suffix {
    font-size: 1rem;
    color: var(--color-text-muted);
    font-weight: 600;
}

.standings-meta {
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

.standings-trend {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
}

.standings-trend.up { background: rgba(34, 197, 94, 0.1); color: #22c55e; }
.standings-trend.down { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
.standings-trend.same { background: var(--color-bg-sunken); color: var(--color-text-muted); }
.standings-trend svg { width: 16px; height: 16px; }

/* Progress */
.podium-progress { margin-bottom: var(--space-lg); }
.progress-header { display: flex; justify-content: space-between; margin-bottom: var(--space-sm); font-size: 0.8rem; }
.progress-label { color: var(--color-text-muted); }
.progress-value { font-family: var(--font-mono); font-weight: 600; color: var(--color-text); }
.progress-bar { height: 10px; background: var(--color-bg-sunken); border-radius: var(--radius-full); overflow: hidden; }
.progress-fill { height: 100%; border-radius: var(--radius-full); transition: width 0.6s ease; }

/* Series Stats - Mobile first (2 columns) */
.series-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-sm);
    padding: var(--space-sm);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-lg);
}

.series-stat { text-align: center; }
.series-stat-value { font-family: var(--font-mono); font-size: 1rem; font-weight: 700; color: var(--color-text); }
.series-stat-label { font-size: 0.6rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.3px; }

/* Results */
.results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md); }
.results-title { font-size: 0.9rem; font-weight: 700; color: var(--color-text); }
.results-count { font-size: 0.8rem; color: var(--color-text-muted); }

.results-list { display: flex; flex-direction: column; gap: var(--space-sm); }

.result-item {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-sm);
    align-items: center;
    padding: var(--space-sm);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: all 0.2s ease;
}

.result-item:hover { background: var(--color-bg-surface); box-shadow: var(--shadow-sm); }

.result-position {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-mono);
    font-size: 0.8rem;
    font-weight: 700;
    background: var(--color-bg-surface);
    color: var(--color-text-muted);
    border: 1px solid var(--color-border);
}

.result-position.p1 { background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: #FFD700; color: #92400e; }
.result-position.p2 { background: linear-gradient(135deg, #f3f4f6, #e5e7eb); border-color: #C0C0C0; color: #4b5563; }
.result-position.p3 { background: linear-gradient(135deg, #fed7aa, #fdba74); border-color: #CD7F32; color: #9a3412; }

.medal-icon {
    width: 22px;
    height: 22px;
    display: block;
    margin: 0 auto;
}

.result-info { min-width: 0; }
.result-event-name { font-weight: 600; color: var(--color-text); font-size: 0.8rem; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.result-meta { display: flex; flex-wrap: wrap; gap: var(--space-sm); font-size: 0.7rem; color: var(--color-text-muted); }
.result-time { display: none; }
.result-time-value { font-family: var(--font-mono); font-size: 0.95rem; font-weight: 600; color: var(--color-text); }

/* Achievements Card */
.achievements-card {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    padding: var(--space-sm);
}

/* Achievements Grid - 4 columns */
.achievements-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
}

.achievement-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--space-sm);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    transition: all 0.2s ease;
}

.achievement-item.locked {
    opacity: 1;
    background: var(--color-bg-sunken);
}

.achievement-item.locked .achievement-icon {
    color: #c0c0c0;
    opacity: 0.5;
}

.achievement-item.locked .achievement-value {
    color: #c0c0c0;
}

.achievement-item.locked .achievement-label {
    color: #c0c0c0;
}

.achievement-item.unlocked {
    background: linear-gradient(135deg, rgba(255,215,0,0.12), rgba(255,180,0,0.06));
}

.achievement-item.unlocked.gold .achievement-icon { color: #d4a500; }
.achievement-item.unlocked.silver .achievement-icon { color: #7d7d7d; }
.achievement-item.unlocked.bronze .achievement-icon { color: #b87333; }
.achievement-item.unlocked.streak .achievement-icon { color: var(--color-accent); }

.achievement-icon {
    width: 24px;
    height: 24px;
    margin-bottom: var(--space-xs);
    color: var(--color-text-muted);
}

.achievement-value {
    font-family: var(--font-mono);
    font-size: 1rem;
    font-weight: 700;
    color: var(--color-text);
    line-height: 1;
}

.achievement-item.locked .achievement-value {
    color: var(--color-text-muted);
}

.achievement-label {
    font-size: 0.6rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-top: 2px;
}

/* Achievement Stats Row - 3 columns */
.achievement-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
}

.achievement-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: var(--space-sm);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
}

.stat-icon {
    width: 18px;
    height: 18px;
    color: var(--color-text-muted);
    margin-bottom: var(--space-xs);
}

.achievement-stat .stat-value {
    font-family: var(--font-mono);
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--color-text);
}

.achievement-stat .stat-label {
    font-size: 0.6rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.achievement-stat.perfect { background: linear-gradient(135deg, rgba(97,206,112,0.15), rgba(97,206,112,0.08)); }
.achievement-stat.perfect .stat-icon { color: var(--color-accent); }
.achievement-stat.perfect .stat-value { color: var(--color-accent); }

.achievement-stat.active { background: linear-gradient(135deg, rgba(0,74,152,0.15), rgba(0,74,152,0.08)); }
.achievement-stat.active .stat-icon { color: #004a98; }
.achievement-stat.active .stat-value { color: #004a98; }

.achievement-stat.champion { background: linear-gradient(135deg, rgba(255,215,0,0.15), rgba(255,180,0,0.08)); }
.achievement-stat.champion .stat-icon { color: #d4a500; }
.achievement-stat.champion .stat-value { color: #92400e; }

/* Swedish Champion Badge */
.sm-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: linear-gradient(135deg, #006aa7, #004a98);
    color: white;
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
}

.sm-icon {
    width: 18px;
    height: 18px;
}

.sm-text {
    font-size: 0.8rem;
    font-weight: 600;
}

/* Experience Section */
.experience-section {
    padding-bottom: var(--space-md);
    margin-bottom: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}

.experience-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.exp-icon {
    width: 18px;
    height: 18px;
    color: var(--color-text-muted);
}

.experience-title {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--color-text);
}

.experience-title.level-4 { color: #16a34a; }
.experience-title.level-5 { color: #004a98; }
.experience-title.level-6 { color: #d4a500; }

.experience-year {
    margin-left: auto;
    font-family: var(--font-mono);
    font-size: 0.7rem;
    color: var(--color-text-muted);
}

.experience-bar {
    display: flex;
    gap: 3px;
}

.exp-segment {
    flex: 1;
    height: 6px;
    background: var(--color-bg-sunken);
    border-radius: var(--radius-full);
    position: relative;
    overflow: hidden;
}

.exp-segment.filled::after {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--color-accent);
    border-radius: var(--radius-full);
}

.exp-segment.current::after {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--color-accent);
    border-radius: var(--radius-full);
}

/* Sixth segment (Legend) gets gold when filled */
.exp-segment:last-child.filled::after {
    background: linear-gradient(90deg, #d4a500, #ffc107);
}

/* Card & Empty State */
.card { background: var(--color-bg-surface); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); padding: var(--space-md); }
.empty-state { text-align: center; padding: var(--space-2xl); color: var(--color-text-muted); }
.empty-icon { font-size: 48px; margin-bottom: var(--space-md); }

/* Compact Results for Sidebar */
.results-card { padding: var(--space-md); }
.results-card .results-list.compact { gap: var(--space-xs); }
.results-card .results-list.compact .result-item {
    padding: var(--space-sm);
    grid-template-columns: 32px 1fr;
    gap: var(--space-sm);
}
.results-card .results-list.compact .result-position {
    width: 32px;
    height: 32px;
    font-size: 0.8rem;
}
.results-card .results-list.compact .medal-icon {
    width: 20px;
    height: 20px;
}
.results-card .results-list.compact .result-event-name {
    font-size: 0.8rem;
}
.results-card .results-list.compact .result-meta {
    font-size: 0.7rem;
}
.results-more {
    text-align: center;
    padding-top: var(--space-sm);
    margin-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

/* ============================================
   RESPONSIVE - Mobile First (min-width)
   ============================================ */

/* Tablet (600px and up) */
@media (min-width: 600px) {
    .hero-content {
        padding: var(--space-lg);
    }

    .hero-top {
        grid-template-columns: auto 1fr auto;
        text-align: left;
        gap: var(--space-lg);
    }

    .hero-left {
        justify-content: flex-start;
    }

    .hero-right {
        display: flex;
        align-items: center;
        justify-content: flex-end;
    }

    .hero-bottom {
        flex-direction: row;
        justify-content: space-between;
        text-align: left;
    }

    .profile-badges {
        justify-content: flex-start;
    }

    .hero-social {
        justify-content: flex-start;
        padding-top: var(--space-md);
    }

    .profile-photo {
        width: 96px;
        height: 96px;
    }

    .profile-name {
        font-size: 1.5rem;
    }

    .ranking-badge {
        top: -8px;
        right: -8px;
        width: 44px;
        height: auto;
        min-width: 44px;
        padding: 6px 8px;
    }

    .ranking-badge .rank-label {
        display: block;
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

    .social-link {
        width: 40px;
        height: 40px;
    }

    .social-link svg {
        width: 18px;
        height: 18px;
    }

    .stat-card {
        padding: var(--space-lg) var(--space-md);
    }

    .stat-value {
        font-size: 1.5rem;
    }

    .stat-label {
        font-size: 0.7rem;
    }

    .series-tab {
        padding: var(--space-md) var(--space-lg);
        font-size: 0.85rem;
        flex-direction: row;
        gap: var(--space-sm);
    }

    .series-dot {
        width: 8px;
        height: 8px;
    }

    .series-content {
        padding: var(--space-lg);
    }

    .standings-header {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }

    .rank-position {
        font-size: 2rem;
    }

    .standings-trend {
        padding: var(--space-sm) var(--space-md);
        font-size: 0.85rem;
    }

    .series-stats {
        grid-template-columns: repeat(4, 1fr);
        gap: var(--space-md);
        padding: var(--space-md);
    }

    .series-stat-value {
        font-size: 1.25rem;
    }

    .series-stat-label {
        font-size: 0.65rem;
    }

    .result-item {
        grid-template-columns: auto 1fr auto;
        gap: var(--space-md);
        padding: var(--space-md);
    }

    .result-position {
        width: 40px;
        height: 40px;
        font-size: 0.9rem;
    }

    .medal-icon {
        width: 28px;
        height: 28px;
    }

    .result-event-name {
        font-size: 0.9rem;
    }

    .result-meta {
        font-size: 0.75rem;
    }

    .result-time {
        display: block;
        text-align: right;
    }

    .achievements-card {
        padding: var(--space-md);
    }

    .achievement-item {
        padding: var(--space-sm);
    }

    .achievement-icon {
        width: 24px;
        height: 24px;
    }

    .achievement-value {
        font-size: 1rem;
    }

    .achievement-label {
        font-size: 0.6rem;
    }

    .achievement-stat {
        padding: var(--space-sm);
    }

    .stat-icon {
        width: 18px;
        height: 18px;
    }

    .achievement-stat .stat-value {
        font-size: 0.9rem;
    }

    .achievement-stat .stat-label {
        font-size: 0.6rem;
    }

    .content-layout {
        gap: var(--space-xl);
    }

    .card {
        padding: var(--space-lg);
    }

    .edit-profile-btn {
        width: auto;
    }

    .experience-year {
        width: auto;
        margin-left: auto;
    }
}

/* Desktop (900px and up) */
@media (min-width: 900px) {
    .stat-value {
        font-size: 1.75rem;
    }

    .rank-position {
        font-size: 2.5rem;
    }

    .gravity-id-badge {
        top: -10px;
        right: -10px;
        padding: 8px 12px;
    }

    .gravity-id-badge .gid-label {
        font-size: 0.5rem;
    }

    .gravity-id-badge .gid-number {
        font-size: 1.1rem;
    }
}

/* ============================================
   HIGHLIGHT CARD (Motion/No Results)
   ============================================ */

.highlight-card {
    display: flex;
    align-items: center;
    gap: var(--space-lg);
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-sm);
    margin-bottom: var(--space-lg);
}

.highlight-card .highlight-icon {
    font-size: 3rem;
    flex-shrink: 0;
}

.highlight-card .highlight-content {
    flex: 1;
}

.highlight-card .highlight-title {
    font-size: var(--text-lg);
    font-weight: 700;
    margin: 0 0 var(--space-xs) 0;
    color: var(--color-text-primary);
}

.highlight-card .highlight-text {
    font-size: var(--text-md);
    color: var(--color-text-secondary);
    margin: 0 0 var(--space-xs) 0;
}

.highlight-card .highlight-subtext {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    margin: 0;
}

@media (max-width: 599px) {
    .highlight-card {
        flex-direction: column;
        text-align: center;
        padding: var(--space-md);
    }

    .highlight-card .highlight-icon {
        font-size: 2.5rem;
    }
}

/* ============================================
   RIDER STATS TREND SECTION
   ============================================ */

.rider-stats-trend {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}

.stats-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-md);
}

@media (min-width: 600px) {
    .stats-row {
        grid-template-columns: 1fr 1fr;
    }
}

/* Trend Section Cards */
.form-section,
.highlights-section,
.ranking-section {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    padding: var(--space-md);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-sm);
}

.trend-section-title {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--color-text-muted);
    margin: 0 0 var(--space-md) 0;
}

/* Form Section */
.form-results {
    display: flex;
    justify-content: space-between;
    gap: var(--space-xs);
    margin-bottom: var(--space-sm);
}

.form-race {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-xs);
    flex: 1;
    min-width: 0;
}

.form-position {
    font-size: 1.25rem;
    line-height: 1;
}

.form-position.gold { filter: drop-shadow(0 2px 4px rgba(255, 215, 0, 0.4)); }
.form-position.silver { filter: drop-shadow(0 2px 4px rgba(192, 192, 192, 0.4)); }
.form-position.bronze { filter: drop-shadow(0 2px 4px rgba(205, 127, 50, 0.4)); }

.form-event {
    font-size: 0.6rem;
    color: var(--color-text-muted);
    max-width: 50px;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.form-chart {
    height: 50px;
    margin: var(--space-md) 0;
}

.form-chart svg {
    width: 100%;
    height: 100%;
}

.form-trend {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-xs);
    font-size: 0.8rem;
    font-weight: 600;
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
    background: var(--color-bg-sunken);
}

.form-trend.up {
    color: #10B981;
    background: rgba(16, 185, 129, 0.1);
}

.form-trend.down {
    color: #EF4444;
    background: rgba(239, 68, 68, 0.1);
}

.form-trend.stable {
    color: #F59E0B;
    background: rgba(245, 158, 11, 0.1);
}

.trend-arrow {
    font-size: 1rem;
}

.form-empty,
.highlights-empty,
.ranking-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: var(--space-lg);
    color: var(--color-text-muted);
    font-size: 0.85rem;
    text-align: center;
}

.form-empty .empty-icon,
.highlights-empty .empty-icon,
.ranking-empty .empty-icon {
    font-size: 1.5rem;
    opacity: 0.6;
}

/* Highlights Section */
.highlights-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.highlight-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: 0.85rem;
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    transition: transform 0.2s ease;
}

.highlight-item:hover {
    transform: translateX(4px);
}

.highlight-icon {
    font-size: 1.1rem;
    flex-shrink: 0;
}

.highlight-text {
    font-weight: 500;
    color: var(--color-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Active streak special styling */
.highlight-item.streak.active {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.08));
    border: 1px solid rgba(245, 158, 11, 0.3);
}

[data-theme="dark"] .highlight-item.streak.active {
    background: linear-gradient(135deg, rgba(120, 53, 15, 0.4), rgba(146, 64, 14, 0.3));
}

/* Ranking Section */
.ranking-section {
    grid-column: 1 / -1;
}

.ranking-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-md);
    flex-wrap: wrap;
    gap: var(--space-sm);
}

.ranking-current {
    display: flex;
    align-items: baseline;
    gap: var(--space-sm);
}

.rank-number-large {
    font-family: var(--font-mono);
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--color-text);
    line-height: 1;
}

.rank-change {
    font-size: 0.8rem;
    font-weight: 600;
}

.rank-change.up { color: #10B981; }
.rank-change.down { color: #EF4444; }

.ranking-chart {
    height: 80px;
    margin: var(--space-md) 0 var(--space-sm);
}

.ranking-chart svg {
    width: 100%;
    height: 100%;
}

.ranking-months {
    display: flex;
    justify-content: space-between;
    font-size: 0.65rem;
    color: var(--color-text-muted);
    padding: 0 30px;
}

/* Ranking Progress Bar (fallback) */
.ranking-bar-container {
    margin-top: var(--space-md);
}

.ranking-bar {
    position: relative;
    height: 10px;
    background: var(--color-bg-sunken);
    border-radius: var(--radius-full);
    overflow: hidden;
}

.ranking-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--color-accent), #4CAF50);
    border-radius: var(--radius-full);
    transition: width 0.6s ease;
}

.ranking-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.7rem;
    color: var(--color-text-muted);
    margin-top: var(--space-xs);
}

/* Mobile improvements */
@media (max-width: 599px) {
    .rider-stats-trend {
        gap: var(--space-sm);
        margin-bottom: var(--space-md);
        max-width: 100%;
        overflow: hidden;
    }

    .stats-row {
        gap: var(--space-sm);
        max-width: 100%;
    }

    .form-section,
    .highlights-section,
    .ranking-section {
        padding: var(--space-sm);
        border-radius: var(--radius-md);
        max-width: 100%;
        overflow: hidden;
        box-sizing: border-box;
    }

    .trend-section-title {
        font-size: 0.65rem;
        margin-bottom: var(--space-sm);
    }

    /* Form section mobile */
    .form-results {
        gap: 2px;
        margin-bottom: var(--space-xs);
        max-width: 100%;
        overflow: hidden;
    }

    .form-race {
        gap: 2px;
        min-width: 0; /* Allow flex items to shrink */
    }

    .form-position {
        font-size: 1rem;
    }

    .form-event {
        font-size: 0.5rem;
        max-width: 36px;
        word-break: break-all;
    }

    .form-chart {
        height: 40px;
        margin: var(--space-sm) 0;
        max-width: 100%;
        overflow: hidden;
    }

    .form-chart svg {
        max-width: 100%;
        height: auto;
    }

    .form-trend {
        font-size: 0.7rem;
        padding: var(--space-xs);
    }

    .trend-arrow {
        font-size: 0.85rem;
    }

    /* Highlights section mobile */
    .highlights-list {
        gap: var(--space-xs);
        max-width: 100%;
    }

    .highlight-item {
        font-size: 0.75rem;
        padding: var(--space-xs) var(--space-sm);
        gap: var(--space-xs);
        max-width: 100%;
        overflow: hidden;
    }

    .highlight-text {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }

    .highlight-icon {
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    /* Empty states mobile */
    .form-empty,
    .highlights-empty,
    .ranking-empty {
        padding: var(--space-md);
        font-size: 0.75rem;
    }

    .form-empty .empty-icon,
    .highlights-empty .empty-icon,
    .ranking-empty .empty-icon {
        font-size: 1.25rem;
    }

    /* Ranking section mobile */
    .ranking-header {
        flex-direction: column;
        gap: var(--space-xs);
        align-items: flex-start;
        max-width: 100%;
    }

    .rank-number-large {
        font-size: 1.5rem;
    }

    .rank-change {
        font-size: 0.65rem;
    }

    .ranking-chart {
        height: 60px;
        max-width: 100%;
        overflow: hidden;
    }

    .ranking-chart svg {
        max-width: 100%;
        height: auto;
    }

    .ranking-months {
        font-size: 0.55rem;
        max-width: 100%;
        overflow: hidden;
    }

    .ranking-bar-container {
        margin-top: var(--space-sm);
    }

    .ranking-bar {
        height: 6px;
    }
}

/* Tablet+ improvements */
@media (min-width: 600px) {
    .form-section,
    .highlights-section,
    .ranking-section {
        padding: var(--space-lg);
    }

    .trend-section-title {
        font-size: 0.75rem;
    }

    .form-position {
        font-size: 1.5rem;
    }

    .form-event {
        font-size: 0.65rem;
        max-width: 60px;
    }

    .highlight-item {
        padding: var(--space-sm) var(--space-md);
    }

    .rank-number-large {
        font-size: 2rem;
    }

    .ranking-months {
        font-size: 0.7rem;
    }
}

/* Desktop improvements */
@media (min-width: 900px) {
    .rank-number-large {
        font-size: 2.25rem;
    }

    .ranking-chart {
        height: 100px;
    }
}
</style>
