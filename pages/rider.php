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
            'icon' => 'trophy',
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
            'icon' => 'trending-up',
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
            'icon' => 'star',
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
            'icon' => 'users',
            'text' => 'Slagit ' . $h2hPercent . '% av motståndare',
            'type' => 'h2h'
        ];
    }

    // Ensure we have at least some highlights
    if (empty($highlights) && $totalStarts > 0) {
        $highlights[] = [
            'icon' => 'bike',
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
$gidNumber = '';
if ($rider['gravity_id']) {
    $gidNumber = preg_replace('/^.*?-?(\d+)$/', '$1', $rider['gravity_id']);
    $gidNumber = ltrim($gidNumber, '0') ?: '0';
}

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

<!-- New Grid Layout -->
<div class="rider-page-grid">
    <!-- Row 1: Profile Card -->
    <div class="card grid-profile">
        <div class="profile-card-content">
            <div class="profile-main">
                <div class="profile-photo-large">
                    <?php if ($profileImage): ?>
                        <img src="<?= htmlspecialchars($profileImage) ?>" alt="<?= $fullName ?>">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h1 class="profile-name-large"><?= $fullName ?></h1>
                    <?php if ($age): ?><span class="profile-age-large"><?= $age ?> år</span><?php endif; ?>
                    <?php if ($rider['club_name']): ?>
                    <a href="/club/<?= $rider['club_id'] ?>" class="profile-club-link"><?= htmlspecialchars($rider['club_name']) ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="profile-footer">
                <?php if ($gidNumber): ?>
                <span class="gid-badge">#<?= htmlspecialchars($gidNumber) ?></span>
                <?php endif; ?>
                <?php if ($hasLicense): ?>
                <span class="badge-item <?= $licenseActive ? 'active' : '' ?>"><?= $licenseActive ? 'Licens OK' : 'Ingen licens' ?></span>
                <?php endif; ?>
                <?php if ($experienceLevel > 1): ?>
                <span class="badge-item experience"><?= $expInfo['name'] ?></span>
                <?php endif; ?>
                <div class="profile-actions-inline">
                    <button type="button" class="btn-icon" onclick="shareProfile(<?= $riderId ?>)" title="Dela profil">
                        <i data-lucide="share-2"></i>
                    </button>
                    <?php if ($isOwnProfile): ?>
                    <a href="/profile/edit" class="btn-icon" title="Redigera">
                        <i data-lucide="pencil"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 1: Ranking Card -->
    <div class="card grid-ranking">
        <h3 class="card-title"><i data-lucide="bar-chart-2"></i> Ranking</h3>
        <?php if ($rankingPosition): ?>
        <div class="ranking-position-large">
            <span class="position-number">#<?= $rankingPosition ?></span>
            <span class="position-label">av totalt rankade</span>
        </div>
        <?php if ($rankingChange != 0): ?>
        <div class="ranking-change <?= $rankingChange > 0 ? 'up' : 'down' ?>">
            <?= $rankingChange > 0 ? '↑' : '↓' ?> <?= abs($rankingChange) ?> positioner sedan start
        </div>
        <?php endif; ?>
        <?php if (!empty($rankingEvents)): ?>
        <button type="button" class="ranking-calc-btn" onclick="openRankingModal()">
            <i data-lucide="calculator"></i>
            <span>Visa uträkning</span>
            <span class="btn-points"><?= number_format($rankingPoints, 1) ?> p</span>
        </button>
        <?php endif; ?>
        <?php else: ?>
        <div class="empty-state-small">
            <i data-lucide="bar-chart-2"></i>
            <span>Ingen ranking ännu</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Row 2: Form Card -->
    <div class="card grid-form">
        <h3 class="card-title"><i data-lucide="trending-up"></i> Form</h3>
        <?php if ($hasCompetitiveResults && !empty($formResults)): ?>
        <div class="form-results-grid">
            <?php foreach ($formResults as $idx => $fr):
                $pos = $fr['position'];
                $posClass = $pos == 1 ? 'gold' : ($pos == 2 ? 'silver' : ($pos == 3 ? 'bronze' : ''));
            ?>
            <div class="form-race-item">
                <span class="form-position <?= $posClass ?>">
                    <?php if ($pos == 1): ?>
                        <img src="/assets/icons/medal-1st.svg" alt="1:a" class="form-medal">
                    <?php elseif ($pos == 2): ?>
                        <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="form-medal">
                    <?php elseif ($pos == 3): ?>
                        <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="form-medal">
                    <?php else: ?>
                        <span class="form-pos-num"><?= $pos ?></span>
                    <?php endif; ?>
                </span>
                <span class="form-event-name"><?= htmlspecialchars(mb_substr($fr['event_name'], 0, 12)) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="form-trend-indicator <?= $formTrend ?>">
            <?php if ($formTrend === 'up'): ?>
            <span class="trend-arrow">↗</span> Stigande form
            <?php elseif ($formTrend === 'down'): ?>
            <span class="trend-arrow">↘</span> Fallande form
            <?php else: ?>
            <span class="trend-arrow">→</span> Stabil form
            <?php endif; ?>
        </div>
        <?php elseif ($motionStarts > 0): ?>
        <div class="motion-info">
            <i data-lucide="bike"></i>
            <span><strong><?= $motionStarts ?></strong> motionsstarter</span>
        </div>
        <?php else: ?>
        <div class="empty-state-small">
            <i data-lucide="flag"></i>
            <span>Inga resultat ännu</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Row 2: Highlights Card -->
    <div class="card grid-highlights">
        <h3 class="card-title"><i data-lucide="star"></i> Highlights</h3>
        <?php if (!empty($highlights)): ?>
        <div class="highlights-list-grid">
            <?php foreach ($highlights as $hl): ?>
            <div class="highlight-row <?= $hl['type'] ?> <?= !empty($hl['active']) ? 'active' : '' ?>">
                <span class="hl-icon"><i data-lucide="<?= htmlspecialchars($hl['icon']) ?>"></i></span>
                <span class="hl-text"><?= $hl['text'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state-small">
            <i data-lucide="clock"></i>
            <span>Bygg statistik genom att tävla!</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Row 3: Series Standings Card -->
    <?php if (!empty($seriesStandings)): ?>
    <div class="card grid-series">
        <h3 class="card-title"><i data-lucide="trophy"></i> Serieställning</h3>
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
                        <details class="events-dropdown series-results-dropdown">
                            <summary class="events-dropdown-header">
                                <span>Resultat i serien</span>
                                <span class="events-count"><?= count($standing['results']) ?> starter</span>
                                <span class="dropdown-arrow">▾</span>
                            </summary>
                            <div class="events-dropdown-content">
                                <?php foreach ($standing['results'] as $result): ?>
                                <a href="/event/<?= $result['event_id'] ?? '' ?>" class="event-dropdown-item">
                                    <span class="event-position <?= $result['status'] === 'finished' && $result['position'] <= 3 ? 'p' . $result['position'] : '' ?>">
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
                                    </span>
                                    <span class="event-date"><?= date('j M', strtotime($result['event_date'])) ?></span>
                                    <span class="event-name"><?= htmlspecialchars($result['event_name']) ?></span>
                                    <span class="event-results"><?= htmlspecialchars($result['class_name']) ?></span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Row 3: Achievements Card -->
    <div class="card grid-achievements">
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
    </div>

    <!-- Row 4: Historical Results (Full Width) -->
    <div class="card grid-results">
        <h3 class="card-title"><i data-lucide="history"></i> Resultathistorik</h3>
        <?php if (empty($results)): ?>
        <div class="empty-state-small">
            <i data-lucide="flag"></i>
            <p>Inga resultat registrerade</p>
        </div>
        <?php else: ?>
        <div class="results-list-compact">
            <?php
            $currentYear = null;
            foreach ($results as $result):
                $resultYear = date('Y', strtotime($result['event_date']));
                if ($resultYear !== $currentYear):
                    $currentYear = $resultYear;
            ?>
            <div class="year-divider">
                <span class="year-label"><?= $currentYear ?></span>
                <span class="year-line"></span>
            </div>
            <?php endif; ?>
            <a href="/event/<?= $result['event_id'] ?>" class="result-row">
                <?php if ($result['is_motion']): ?>
                <span class="result-pos motion">
                    <i data-lucide="check"></i>
                </span>
                <?php else: ?>
                <span class="result-pos <?= $result['status'] === 'finished' && $result['position'] <= 3 ? 'p' . $result['position'] : '' ?>">
                    <?php if ($result['status'] !== 'finished'): ?>
                        <?= strtoupper(substr($result['status'], 0, 3)) ?>
                    <?php elseif ($result['position'] == 1): ?>
                        <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon-sm">
                    <?php elseif ($result['position'] == 2): ?>
                        <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon-sm">
                    <?php elseif ($result['position'] == 3): ?>
                        <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon-sm">
                    <?php else: ?>
                        <?= $result['position'] ?>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
                <span class="result-date"><?= date('j M', strtotime($result['event_date'])) ?></span>
                <span class="result-name"><?= htmlspecialchars($result['event_name']) ?></span>
                <span class="result-series"><?= $result['is_motion'] ? 'Motion' : htmlspecialchars($result['series_name'] ?? '') ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- End rider-page-grid -->

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

// Ranking Modal Functions
function openRankingModal() {
    const modal = document.getElementById('rankingModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeRankingModal() {
    const modal = document.getElementById('rankingModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modal on overlay click
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('rankingModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeRankingModal();
            }
        });
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRankingModal();
    }
});
</script>

<?php if (!empty($rankingEvents)): ?>
<!-- Ranking Calculation Modal -->
<div id="rankingModal" class="ranking-modal-overlay">
    <div class="ranking-modal">
        <div class="ranking-modal-header">
            <h3>
                <i data-lucide="calculator"></i>
                Rankinguträkning
            </h3>
            <button type="button" class="ranking-modal-close" onclick="closeRankingModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="ranking-modal-body">
            <div class="ranking-modal-summary">
                <span class="summary-label">Total poäng</span>
                <span class="summary-value"><?= number_format($rankingPoints, 1) ?> p</span>
            </div>

            <div class="modal-formula-hint">
                <i data-lucide="info"></i>
                Formel: Baspoäng × Fältfaktor × Eventtypfaktor × Tidsfaktor
            </div>

            <div class="modal-events-list">
                <?php foreach ($rankingEvents as $event): ?>
                <div class="modal-event-item">
                    <div class="modal-event-row">
                        <div class="modal-event-info">
                            <span class="modal-event-name"><?= htmlspecialchars($event['event_name'] ?? 'Event') ?></span>
                            <span class="modal-event-date"><?= date('j M Y', strtotime($event['event_date'])) ?></span>
                        </div>
                        <span class="modal-event-points">+<?= number_format($event['weighted_points'] ?? 0, 0) ?></span>
                    </div>
                    <div class="modal-calc-row">
                        <span class="modal-calc-item">
                            <span class="modal-calc-label">Bas:</span>
                            <span class="modal-calc-value"><?= number_format($event['original_points'] ?? 0, 0) ?></span>
                        </span>
                        <span class="modal-calc-op">×</span>
                        <span class="modal-calc-item">
                            <span class="modal-calc-label">Fält:</span>
                            <span class="modal-calc-value <?= ($event['field_multiplier'] ?? 1) < 1 ? 'dim' : '' ?>"><?= number_format(($event['field_multiplier'] ?? 1) * 100, 0) ?>%</span>
                        </span>
                        <span class="modal-calc-op">×</span>
                        <span class="modal-calc-item">
                            <span class="modal-calc-label">Typ:</span>
                            <span class="modal-calc-value <?= ($event['event_level_multiplier'] ?? 1) < 1 ? 'dim' : '' ?>"><?= number_format(($event['event_level_multiplier'] ?? 1) * 100, 0) ?>%</span>
                        </span>
                        <span class="modal-calc-op">×</span>
                        <span class="modal-calc-item">
                            <span class="modal-calc-label">Tid:</span>
                            <span class="modal-calc-value <?= ($event['time_multiplier'] ?? 1) < 1 ? 'dim' : '' ?>"><?= number_format(($event['time_multiplier'] ?? 1) * 100, 0) ?>%</span>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

