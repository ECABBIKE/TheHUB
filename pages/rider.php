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
            COALESCE(cls.awards_points, 1) as awards_podiums
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

    // Calculate stats from results if not cached
    $totalStarts = $rider['stats_total_starts'] ?: count($results);
    $finishedRaces = $rider['stats_total_finished'] ?: count(array_filter($results, fn($r) => $r['status'] === 'finished'));
    $wins = $rider['stats_total_wins'] ?: count(array_filter($results, fn($r) => $r['position'] == 1 && $r['status'] === 'finished'));
    $podiums = $rider['stats_total_podiums'] ?: count(array_filter($results, fn($r) => $r['position'] <= 3 && $r['status'] === 'finished'));

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
        1 => ['name' => '1st Year', 'icon' => '⭐'],
        2 => ['name' => '2nd Year', 'icon' => '⭐'],
        3 => ['name' => 'Experienced', 'icon' => '⭐'],
        4 => ['name' => 'Expert', 'icon' => '🌟'],
        5 => ['name' => 'Veteran', 'icon' => '👑']
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
$finisher100 = false;

foreach ($achievements as $ach) {
    switch ($ach['achievement_type']) {
        case 'gold': $achievementCounts['gold'] = (int)$ach['achievement_value']; break;
        case 'silver': $achievementCounts['silver'] = (int)$ach['achievement_value']; break;
        case 'bronze': $achievementCounts['bronze'] = (int)$ach['achievement_value']; break;
        case 'hot_streak': $achievementCounts['hot_streak'] = (int)$ach['achievement_value']; break;
        case 'series_leader': $isSeriesLeader = true; break;
        case 'series_champion': $isSeriesChampion = true; break;
        case 'finisher_100': $finisher100 = true; break;
    }
}

// Calculate finish rate
$finishRate = $totalStarts > 0 ? round(($finishedRaces / $totalStarts) * 100) : 0;
?>

<!-- Profile Hero -->
<section class="profile-hero">
    <div class="hero-accent-bar"></div>
    <div class="hero-content">
        <?php if ($rider['gravity_id']):
            $gidNumber = preg_replace('/^.*?-?(\d+)$/', '$1', $rider['gravity_id']);
            $gidNumber = ltrim($gidNumber, '0') ?: '0';
        ?>
        <div class="gravity-id-badge">
            <span class="gid-label">G-ID</span>
            <span class="gid-number">#<?= htmlspecialchars($gidNumber) ?></span>
        </div>
        <?php endif; ?>

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
                        <span class="rank-number">#<?= $rankingPosition ?></span>
                        <span class="rank-label">Rank</span>
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
                <span class="experience-badge"><?= $expInfo['icon'] ?> <?= $expInfo['name'] ?></span>
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
</section>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $totalStarts ?></div>
        <div class="stat-label">Starter</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $finishedRaces ?></div>
        <div class="stat-label">Fullföljt</div>
    </div>
    <div class="stat-card <?= $wins > 0 ? 'highlight' : '' ?>">
        <div class="stat-value"><?= $wins ?></div>
        <div class="stat-label">Segrar</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $podiums ?></div>
        <div class="stat-label">Pallplatser</div>
    </div>
</div>

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
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Achievements</h2>
            </div>

            <div class="achievements-card">
                <!-- Experience Level -->
                <div class="experience-section">
                    <div class="experience-header">
                        <div class="experience-level">
                            <span class="experience-icon"><?= $expInfo['icon'] ?></span>
                            <div>
                                <div class="experience-title"><?= $expInfo['name'] ?></div>
                                <div class="experience-subtitle"><?= $experienceLevel < 5 ? 'På väg mot nästa nivå!' : 'Maxnivå uppnådd!' ?></div>
                            </div>
                        </div>
                        <?php if ($rider['first_season']): ?>
                        <span class="experience-year">Sedan <?= $rider['first_season'] ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="experience-bar">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="exp-segment <?= $i < $experienceLevel ? 'filled' : ($i == $experienceLevel ? 'current' : '') ?>"></div>
                        <?php endfor; ?>
                    </div>
                    <div class="experience-labels">
                        <span>1st</span>
                        <span>2nd</span>
                        <span>Exp</span>
                        <span>Expert</span>
                        <span>Veteran</span>
                    </div>
                </div>

                <!-- Achievement Badges -->
                <div class="achievements-grid">
                    <div class="achievement <?= $achievementCounts['gold'] > 0 ? 'gold' : 'locked' ?>">
                        <div class="achievement-icon"><img src="/assets/icons/medal-1st.svg" alt="Guld" class="achievement-medal"></div>
                        <span class="achievement-name">Guld</span>
                        <span class="achievement-count"><?= $achievementCounts['gold'] > 0 ? '×' . $achievementCounts['gold'] : 'Låst' ?></span>
                    </div>
                    <div class="achievement <?= $achievementCounts['silver'] > 0 ? 'silver' : 'locked' ?>">
                        <div class="achievement-icon"><img src="/assets/icons/medal-2nd.svg" alt="Silver" class="achievement-medal"></div>
                        <span class="achievement-name">Silver</span>
                        <span class="achievement-count"><?= $achievementCounts['silver'] > 0 ? '×' . $achievementCounts['silver'] : 'Låst' ?></span>
                    </div>
                    <div class="achievement <?= $achievementCounts['bronze'] > 0 ? 'bronze' : 'locked' ?>">
                        <div class="achievement-icon"><img src="/assets/icons/medal-3rd.svg" alt="Brons" class="achievement-medal"></div>
                        <span class="achievement-name">Brons</span>
                        <span class="achievement-count"><?= $achievementCounts['bronze'] > 0 ? '×' . $achievementCounts['bronze'] : 'Låst' ?></span>
                    </div>
                    <div class="achievement <?= $achievementCounts['hot_streak'] >= 3 ? 'fire' : 'locked' ?>">
                        <div class="achievement-icon">🔥</div>
                        <span class="achievement-name">Hot Streak</span>
                        <span class="achievement-count"><?= $achievementCounts['hot_streak'] >= 3 ? $achievementCounts['hot_streak'] . ' raka' : 'Låst' ?></span>
                    </div>
                    <div class="achievement <?= $finishRate == 100 ? '' : 'locked' ?>">
                        <div class="achievement-icon">💯</div>
                        <span class="achievement-name">Fullföljare</span>
                        <span class="achievement-count"><?= $finishRate ?>%</span>
                    </div>
                    <div class="achievement <?= $isSeriesLeader ? '' : 'locked' ?>">
                        <div class="achievement-icon">🎯</div>
                        <span class="achievement-name">Serieledare</span>
                        <span class="achievement-count"><?= $isSeriesLeader ? 'Aktiv' : 'Låst' ?></span>
                    </div>
                    <div class="achievement <?= $isSeriesChampion ? '' : 'locked' ?>">
                        <div class="achievement-icon">👑</div>
                        <span class="achievement-name">Seriemästare</span>
                        <span class="achievement-count"><?= $isSeriesChampion ? 'Vunnen' : 'Låst' ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- All Results -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Alla resultat</h2>
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
</script>

<style>
/* Profile Hero */
.profile-hero {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    margin-bottom: var(--space-lg);
}

.hero-accent-bar {
    height: 4px;
    background: linear-gradient(90deg, var(--color-accent), #004a98);
}

.hero-content {
    padding: var(--space-lg);
    position: relative;
}

/* Gravity ID Badge - Top Right Corner */
.gravity-id-badge {
    position: absolute;
    top: var(--space-md);
    right: var(--space-md);
    display: flex;
    align-items: center;
    gap: 0;
    background: linear-gradient(135deg, #1a1a2e, #16213e);
    border-radius: var(--radius-md);
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.1);
    z-index: 10;
}

.gravity-id-badge .gid-label {
    font-size: 0.65rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.8);
    padding: 8px 10px;
    background: rgba(0, 0, 0, 0.3);
    letter-spacing: 0.5px;
}

.gravity-id-badge .gid-number {
    font-family: var(--font-mono);
    font-size: 1.1rem;
    font-weight: 800;
    color: #FFD700;
    padding: 8px 12px;
    text-shadow: 0 0 10px rgba(255, 215, 0, 0.4);
}

/* Hero Top Row - Three columns */
.hero-top {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: var(--space-lg);
    align-items: center;
    margin-bottom: var(--space-md);
}

.hero-left {
    display: flex;
    align-items: center;
}

.hero-center {
    text-align: center;
}

.hero-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
}

/* Hero Bottom Row */
.hero-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
    gap: var(--space-md);
    flex-wrap: wrap;
}

.profile-badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    align-items: center;
}

.profile-photo-container {
    position: relative;
    flex-shrink: 0;
}

.profile-photo {
    width: 80px;
    height: 80px;
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
    top: -6px;
    right: -6px;
    background: linear-gradient(135deg, #FFD700, #FFA500);
    color: var(--color-primary, #171717);
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    box-shadow: var(--shadow-md);
    border: 2px solid var(--color-bg-surface);
}

.ranking-badge .rank-number {
    font-size: 0.9rem;
    line-height: 1;
}

.ranking-badge .rank-label {
    font-size: 0.4rem;
    text-transform: uppercase;
    opacity: 0.8;
}

.profile-name {
    font-size: 1.5rem;
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
    background: linear-gradient(135deg, rgba(255, 215, 0, 0.25), rgba(255, 180, 0, 0.15));
    color: #92400e;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: var(--radius-full);
    box-shadow: 0 2px 6px rgba(255, 215, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.4);
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

/* Social Links */
.hero-social {
    display: flex;
    gap: var(--space-sm);
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
    flex-wrap: wrap;
}

.social-link {
    width: 40px;
    height: 40px;
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

.social-link svg { width: 18px; height: 18px; }
.social-link.instagram svg { fill: #E4405F; }
.social-link.strava svg { fill: #FC4C02; }
.social-link.facebook svg { fill: #1877F2; }
.social-link.youtube svg { fill: #FF0000; }
.social-link.tiktok svg { fill: #000000; }
.social-link.empty { opacity: 0.4; pointer-events: none; }
.social-link.empty svg { fill: var(--color-text-muted); }

/* Edit Profile Button */
.edit-profile-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    margin-top: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-accent);
    color: white;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: var(--radius-md);
    text-decoration: none;
    transition: all 0.2s ease;
}

.edit-profile-btn:hover {
    background: var(--color-accent);
    opacity: 0.9;
    transform: translateY(-1px);
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

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}

.stat-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg) var(--space-md);
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
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-text);
    line-height: 1;
    margin-bottom: var(--space-xs);
}

.stat-label {
    font-size: 0.7rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

/* Content Layout */
.content-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-xl);
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
    padding: var(--space-md) var(--space-lg);
    background: none;
    border: none;
    font-family: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
    position: relative;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
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
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--color-accent);
}

.series-content { padding: var(--space-lg); }
.series-panel { display: none; }
.series-panel.active { display: block; }

/* Standings */
.standings-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
    gap: var(--space-md);
}

.standings-info { display: flex; align-items: center; gap: var(--space-md); }

.standings-rank { display: flex; align-items: baseline; gap: 4px; }

.rank-position {
    font-family: var(--font-mono);
    font-size: 2.5rem;
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
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-full);
    font-size: 0.85rem;
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

/* Series Stats */
.series-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-lg);
}

.series-stat { text-align: center; }
.series-stat-value { font-family: var(--font-mono); font-size: 1.25rem; font-weight: 700; color: var(--color-text); }
.series-stat-label { font-size: 0.65rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.3px; }

/* Results */
.results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md); }
.results-title { font-size: 0.9rem; font-weight: 700; color: var(--color-text); }
.results-count { font-size: 0.8rem; color: var(--color-text-muted); }

.results-list { display: flex; flex-direction: column; gap: var(--space-sm); }

.result-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: var(--space-md);
    align-items: center;
    padding: var(--space-md);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: all 0.2s ease;
}

.result-item:hover { background: var(--color-bg-surface); box-shadow: var(--shadow-sm); }

.result-position {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-mono);
    font-size: 0.9rem;
    font-weight: 700;
    background: var(--color-bg-surface);
    color: var(--color-text-muted);
    border: 1px solid var(--color-border);
}

.result-position.p1 { background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: #FFD700; color: #92400e; }
.result-position.p2 { background: linear-gradient(135deg, #f3f4f6, #e5e7eb); border-color: #C0C0C0; color: #4b5563; }
.result-position.p3 { background: linear-gradient(135deg, #fed7aa, #fdba74); border-color: #CD7F32; color: #9a3412; }

.medal-icon {
    width: 28px;
    height: 28px;
    display: block;
    margin: 0 auto;
}

.result-info { min-width: 0; }
.result-event-name { font-weight: 600; color: var(--color-text); font-size: 0.9rem; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.result-meta { display: flex; flex-wrap: wrap; gap: var(--space-sm); font-size: 0.75rem; color: var(--color-text-muted); }
.result-time { text-align: right; }
.result-time-value { font-family: var(--font-mono); font-size: 0.95rem; font-weight: 600; color: var(--color-text); }

/* Achievements */
.achievements-card {
    background: var(--color-bg-surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    padding: var(--space-lg);
}

.experience-section {
    margin-bottom: var(--space-lg);
    padding-bottom: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.experience-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-md);
}

.experience-level { display: flex; align-items: center; gap: var(--space-sm); }
.experience-icon { font-size: 1.5rem; }
.experience-title { font-weight: 700; color: var(--color-text); }
.experience-subtitle { font-size: 0.8rem; color: var(--color-text-muted); }
.experience-year { font-family: var(--font-mono); font-size: 0.85rem; color: var(--color-text-muted); background: var(--color-bg-sunken); padding: 4px 10px; border-radius: var(--radius-full); }

.experience-bar { display: flex; gap: var(--space-xs); }

.exp-segment {
    flex: 1;
    height: 8px;
    background: var(--color-bg-sunken);
    border-radius: var(--radius-full);
    position: relative;
    overflow: hidden;
}

.exp-segment.filled::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, var(--color-accent), #8FE89D);
    border-radius: var(--radius-full);
}

.exp-segment.current::after {
    background: linear-gradient(90deg, #FFD700, #FFA500);
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }

.experience-labels {
    display: flex;
    justify-content: space-between;
    margin-top: var(--space-xs);
    font-size: 0.65rem;
    color: var(--color-text-muted);
}

.achievements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: var(--space-md);
}

.achievement {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: var(--space-md);
    background: var(--color-bg-sunken);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    transition: all 0.2s ease;
}

.achievement:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.achievement.locked { opacity: 0.4; filter: grayscale(1); }

.achievement-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: var(--space-sm);
    background: var(--color-bg-surface);
}

.achievement.gold .achievement-icon { background: linear-gradient(135deg, rgba(255,215,0,0.2), rgba(255,165,0,0.1)); box-shadow: 0 0 20px rgba(255,215,0,0.15); }
.achievement.silver .achievement-icon { background: linear-gradient(135deg, rgba(192,192,192,0.2), rgba(169,169,169,0.1)); }
.achievement.bronze .achievement-icon { background: linear-gradient(135deg, rgba(205,127,50,0.2), rgba(184,115,51,0.1)); }
.achievement.fire .achievement-icon { background: linear-gradient(135deg, rgba(239,68,68,0.15), rgba(249,115,22,0.1)); }

.achievement-medal {
    width: 32px;
    height: 32px;
    display: block;
}

.achievement.locked .achievement-medal {
    opacity: 0.5;
    filter: grayscale(1);
}

.achievement-name { font-size: 0.7rem; color: var(--color-text-muted); font-weight: 600; }
.achievement-count { font-family: var(--font-mono); font-size: 0.65rem; color: var(--color-text-muted); margin-top: 2px; }

/* Card & Empty State */
.card { background: var(--color-bg-surface); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); padding: var(--space-lg); }
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

/* Responsive - Tablet */
@media (max-width: 768px) {
    .hero-content {
        padding: var(--space-md);
    }

    .series-content {
        padding: var(--space-md);
    }

    .achievements-card {
        padding: var(--space-md);
    }

    .rank-position {
        font-size: 2rem;
    }
}

/* Responsive - Mobile */
@media (max-width: 599px) {
    /* Gravity ID Badge */
    .gravity-id-badge {
        top: var(--space-sm);
        right: var(--space-sm);
    }

    .gravity-id-badge .gid-label {
        font-size: 0.55rem;
        padding: 5px 6px;
    }

    .gravity-id-badge .gid-number {
        font-size: 0.85rem;
        padding: 5px 8px;
    }

    /* Hero Section */
    .hero-top {
        grid-template-columns: 1fr;
        text-align: center;
        gap: var(--space-md);
        padding-top: var(--space-xl);
    }

    .hero-left {
        justify-content: center;
    }

    .hero-right {
        display: none;
    }

    .hero-bottom {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .profile-badges {
        justify-content: center;
    }

    .hero-social {
        justify-content: center;
    }

    .profile-photo {
        width: 64px;
        height: 64px;
    }

    .profile-name {
        font-size: 1.25rem;
    }

    .ranking-badge {
        width: 30px;
        height: 30px;
        top: -4px;
        right: -4px;
    }

    .ranking-badge .rank-number {
        font-size: 0.8rem;
    }

    .ranking-badge .rank-label {
        display: none;
    }

    /* Stats Grid */
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--space-xs);
    }

    .stat-card {
        padding: var(--space-sm);
    }

    .stat-value {
        font-size: 1.25rem;
    }

    .stat-label {
        font-size: 0.65rem;
    }

    /* Series Tabs */
    .series-nav {
        gap: 0;
    }

    .series-tab {
        padding: var(--space-sm) var(--space-md);
        font-size: 0.75rem;
        flex-direction: column;
        gap: var(--space-xs);
    }

    .series-dot {
        width: 6px;
        height: 6px;
    }

    .series-content {
        padding: var(--space-sm);
    }

    /* Standings */
    .standings-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-sm);
    }

    .rank-position {
        font-size: 1.75rem;
    }

    .standings-trend {
        padding: var(--space-xs) var(--space-sm);
        font-size: 0.75rem;
    }

    /* Series Stats */
    .series-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--space-sm);
        padding: var(--space-sm);
    }

    .series-stat-value {
        font-size: 1rem;
    }

    /* Result Items */
    .result-item {
        grid-template-columns: auto 1fr;
        gap: var(--space-sm);
        padding: var(--space-sm);
    }

    .result-position {
        width: 32px;
        height: 32px;
        font-size: 0.8rem;
    }

    .medal-icon {
        width: 22px;
        height: 22px;
    }

    .result-event-name {
        font-size: 0.8rem;
    }

    .result-meta {
        font-size: 0.7rem;
    }

    .result-time {
        display: none;
    }

    /* Achievements */
    .achievements-card {
        padding: var(--space-sm);
    }

    .experience-section {
        margin-bottom: var(--space-md);
        padding-bottom: var(--space-md);
    }

    .experience-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-sm);
    }

    .achievements-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: var(--space-sm);
    }

    .achievement {
        padding: var(--space-sm);
    }

    .achievement-icon {
        width: 40px;
        height: 40px;
        font-size: 1.25rem;
    }

    .achievement-medal {
        width: 26px;
        height: 26px;
    }

    .achievement-name {
        font-size: 0.6rem;
    }

    /* Social Links */
    .hero-social {
        justify-content: center;
        padding-top: var(--space-sm);
    }

    .social-link {
        width: 36px;
        height: 36px;
    }

    .social-link svg {
        width: 16px;
        height: 16px;
    }

    /* Edit Profile */
    .edit-profile-btn {
        width: 100%;
        justify-content: center;
    }

    .add-social-prompt {
        justify-content: center;
    }

    /* Content Layout */
    .content-layout {
        gap: var(--space-md);
    }

    /* Section Headers */
    .section-title {
        font-size: 0.9rem;
    }

    .section-title::before {
        height: 14px;
        width: 3px;
    }

    /* Cards */
    .card {
        padding: var(--space-md);
    }
}
</style>
