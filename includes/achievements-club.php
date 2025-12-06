<?php
/**
 * TheHUB Achievements System - Club Badges
 * Hexagonal badge components for club achievements
 *
 * @version 2.0
 * @package TheHUB
 */

// Prevent direct access
if (!defined('DB_HOST') && !defined('THEHUB_LOADED')) {
    define('THEHUB_LOADED', true);
}

/**
 * Get club stats for achievements
 */
function getClubAchievementStats(PDO $pdo, int $club_id): array {
    $stats = [
        'total_starts' => 0,
        'active_members' => 0,
        'total_gold' => 0,
        'total_podiums' => 0,
        'series_wins' => 0,
        'sm_medals' => 0,
        'best_ranking' => null,
        'unique_champions' => 0,
        'champion_names' => [],
        'seasons_active' => 1,
        'first_season_year' => date('Y'),
        'total_members' => 0
    ];

    try {
        // Get total starts and podiums for active members (last 12 months)
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total_starts,
                COUNT(DISTINCT r.id) as active_members,
                SUM(CASE WHEN res.position = 1 THEN 1 ELSE 0 END) as total_gold,
                SUM(CASE WHEN res.position <= 3 THEN 1 ELSE 0 END) as total_podiums
            FROM results res
            JOIN riders r ON res.rider_id = r.id
            JOIN events e ON res.event_id = e.id
            WHERE r.club_id = ?
            AND e.date > DATE_SUB(NOW(), INTERVAL 12 MONTH)
        ");
        $stmt->execute([$club_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $stats['total_starts'] = (int)$result['total_starts'];
            $stats['active_members'] = (int)$result['active_members'];
            $stats['total_gold'] = (int)$result['total_gold'];
            $stats['total_podiums'] = (int)$result['total_podiums'];
        }

        // Get total club members
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM riders WHERE club_id = ? AND active = 1");
        $stmt->execute([$club_id]);
        $stats['total_members'] = (int)$stmt->fetchColumn();

        // Get series wins (from rider_achievements)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as wins, r.firstname, r.lastname
            FROM rider_achievements ra
            JOIN riders r ON ra.rider_id = r.id
            WHERE r.club_id = ?
            AND ra.achievement_type = 'series_champion'
            GROUP BY r.id
        ");
        $stmt->execute([$club_id]);
        $champions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats['series_wins'] = 0;
        $stats['champion_names'] = [];
        foreach ($champions as $champ) {
            $stats['series_wins'] += (int)$champ['wins'];
            $stats['champion_names'][] = $champ['firstname'] . ' ' . $champ['lastname'];
        }
        $stats['unique_champions'] = count($champions);

        // Get SM medals
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as medals
            FROM rider_achievements ra
            JOIN riders r ON ra.rider_id = r.id
            WHERE r.club_id = ?
            AND ra.achievement_type = 'swedish_champion'
        ");
        $stmt->execute([$club_id]);
        $stats['sm_medals'] = (int)$stmt->fetchColumn();

        // Get club's first season (earliest rider registration)
        $stmt = $pdo->prepare("
            SELECT MIN(first_season) FROM riders WHERE club_id = ? AND first_season IS NOT NULL
        ");
        $stmt->execute([$club_id]);
        $firstSeason = $stmt->fetchColumn();
        if ($firstSeason) {
            $stats['first_season_year'] = (int)$firstSeason;
            $stats['seasons_active'] = (int)date('Y') - $stats['first_season_year'] + 1;
        }

        // Get best ranking (from latest series standings)
        $stmt = $pdo->prepare("
            SELECT MIN(ss.position) as best_position
            FROM series_standings ss
            JOIN riders r ON ss.rider_id = r.id
            WHERE r.club_id = ?
            AND ss.year = YEAR(NOW())
        ");
        $stmt->execute([$club_id]);
        $bestRanking = $stmt->fetchColumn();
        if ($bestRanking) {
            $stats['best_ranking'] = (int)$bestRanking;
        }

    } catch (PDOException $e) {
        // Silently fail, use defaults
    }

    return $stats;
}

/**
 * Get club experience level name
 */
function getClubExperienceLevelName(int $level): string {
    $levels = [
        1 => 'Ny Klubb',
        2 => 'Etablerad',
        3 => 'Växande',
        4 => 'Stark',
        5 => 'Elitsklubb',
        6 => 'Legendklubb'
    ];
    return $levels[$level] ?? 'Ny Klubb';
}

/**
 * Calculate club experience level (1-6)
 */
function calculateClubExperienceLevel(array $stats): int {
    // Level 6: 100+ members AND 25+ series wins (hidden until achieved)
    if ($stats['total_members'] >= 100 && $stats['series_wins'] >= 25) {
        return 6;
    }
    // Level 5: 50+ active members OR 10+ series wins
    if ($stats['active_members'] >= 50 || $stats['series_wins'] >= 10) {
        return 5;
    }
    // Level 4: 25+ active members
    if ($stats['active_members'] >= 25) {
        return 4;
    }
    // Level 3: 10+ active members
    if ($stats['active_members'] >= 10) {
        return 3;
    }
    // Level 2: 2+ seasons
    if ($stats['seasons_active'] >= 2) {
        return 2;
    }
    // Level 1: First season
    return 1;
}

/**
 * Get badge level based on thresholds
 */
function getBadgeLevel(int $value, array $thresholds): string {
    if ($value >= ($thresholds[3] ?? PHP_INT_MAX)) return 'diamond';
    if ($value >= ($thresholds[2] ?? PHP_INT_MAX)) return 'gold';
    if ($value >= ($thresholds[1] ?? PHP_INT_MAX)) return 'silver';
    if ($value >= ($thresholds[0] ?? 0)) return 'bronze';
    return '';
}

/**
 * Generate hexagonal badge base for club badges
 * Uses CSS variable for theme compatibility
 */
function getClubHexagonBase(string $accentColor, string $uniqueId = ''): string {
    $glowId = $uniqueId ? "club-glow-{$uniqueId}" : 'club-glow-' . uniqid();
    return <<<SVG
    <defs>
        <filter id="{$glowId}">
            <feGaussianBlur stdDeviation="1" result="blur"/>
            <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
        </filter>
    </defs>
    <circle cx="24" cy="24" r="22" fill="none" stroke="{$accentColor}" stroke-width="1" opacity="0.4"/>
    <path class="hex-bg" d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="var(--badge-hex-bg, #171717)" stroke="{$accentColor}" stroke-width="1.5"/>
    <path d="M24 7 L39 16 L39 32 L24 41 L9 32 L9 16 Z" fill="none" stroke="{$accentColor}" stroke-width="0.5" opacity="0.4"/>
SVG;
}

/**
 * Render club starter badge SVG
 */
function renderClubStarterBadge(): string {
    $base = getClubHexagonBase('#61CE70', 'starter');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="-4" r="6" fill="#61CE70"/>
        <path d="M0 2 L-8 14 L8 14 Z" fill="#61CE70"/>
        <text x="0" y="-1" text-anchor="middle" fill="var(--badge-hex-bg, #171717)" font-size="7" font-weight="bold" font-family="system-ui, sans-serif">GO</text>
    </g>
</svg>
SVG;
}

/**
 * Render club active members badge SVG
 */
function renderClubActiveBadge(): string {
    $base = getClubHexagonBase('#004a98', 'active');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="-6" cy="-4" r="4" fill="#004a98"/>
        <circle cx="6" cy="-4" r="4" fill="#004a98"/>
        <circle cx="0" cy="4" r="4" fill="#004a98"/>
        <ellipse cx="-6" cy="6" rx="5" ry="3" fill="#004a98" opacity="0.6"/>
        <ellipse cx="6" cy="6" rx="5" ry="3" fill="#004a98" opacity="0.6"/>
        <ellipse cx="0" cy="12" rx="5" ry="3" fill="#004a98" opacity="0.6"/>
    </g>
</svg>
SVG;
}

/**
 * Render club gold badge SVG
 */
function renderClubGoldBadge(): string {
    $base = getClubHexagonBase('#FFD700', 'clubgold');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 22)">
        <polygon points="0,-12 3,-4 12,-4 5,2 7,11 0,6 -7,11 -5,2 -12,-4 -3,-4" fill="#FFD700"/>
        <polygon points="0,-8 2,-3 8,-3 3,1 5,7 0,4 -5,7 -3,1 -8,-3 -2,-3" fill="var(--badge-hex-bg, #171717)" opacity="0.2"/>
    </g>
</svg>
SVG;
}

/**
 * Render club podium badge SVG
 */
function renderClubPodiumBadge(): string {
    $glowId = 'club-podium-' . uniqid();
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    <defs>
        <linearGradient id="{$glowId}-grad" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" stop-color="#FFD700"/>
            <stop offset="50%" stop-color="#C0C0C0"/>
            <stop offset="100%" stop-color="#CD7F32"/>
        </linearGradient>
        <filter id="{$glowId}">
            <feGaussianBlur stdDeviation="1" result="blur"/>
            <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
        </filter>
    </defs>
    <circle cx="24" cy="24" r="22" fill="none" stroke="url(#{$glowId}-grad)" stroke-width="1" opacity="0.6"/>
    <path class="hex-bg" d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="var(--badge-hex-bg, #171717)" stroke="url(#{$glowId}-grad)" stroke-width="1.5"/>
    <path d="M24 7 L39 16 L39 32 L24 41 L9 32 L9 16 Z" fill="none" stroke="url(#{$glowId}-grad)" stroke-width="0.5" opacity="0.4"/>
    <g transform="translate(24, 24)">
        <rect x="-12" y="2" width="7" height="10" rx="1" fill="#C0C0C0"/>
        <rect x="-3" y="-4" width="7" height="16" rx="1" fill="#FFD700"/>
        <rect x="6" y="5" width="7" height="7" rx="1" fill="#CD7F32"/>
    </g>
</svg>
SVG;
}

/**
 * Render club series wins badge SVG
 */
function renderClubSeriesWinsBadge(): string {
    $base = getClubHexagonBase('#FFD700', 'serieswins');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 22)">
        <path d="M-6 -8 L6 -8 L5 0 L3 6 L-3 6 L-5 0 Z" fill="#FFD700"/>
        <path d="M-6 -6 Q-10 -6 -10 -2 Q-10 2 -5 1" fill="none" stroke="#FFD700" stroke-width="2"/>
        <path d="M6 -6 Q10 -6 10 -2 Q10 2 5 1" fill="none" stroke="#FFD700" stroke-width="2"/>
        <rect x="-2" y="6" width="4" height="2" fill="#FFD700"/>
        <rect x="-4" y="8" width="8" height="2" rx="1" fill="#FFD700"/>
    </g>
</svg>
SVG;
}

/**
 * Render club SM medals badge SVG
 */
function renderClubSmMedalsBadge(): string {
    $base = getClubHexagonBase('#004a98', 'smmedals');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <rect x="-12" y="-10" width="24" height="14" rx="2" fill="#004a98"/>
        <rect x="-4" y="-10" width="3" height="14" fill="#FFE009"/>
        <rect x="-12" y="-3" width="24" height="3" fill="#FFE009"/>
        <circle cx="0" cy="8" r="5" fill="#FFD700" stroke="#B8860B" stroke-width="1"/>
        <text x="0" y="11" text-anchor="middle" fill="#8B6914" font-size="6" font-weight="bold" font-family="system-ui, sans-serif">SM</text>
    </g>
</svg>
SVG;
}

/**
 * Render club ranking badge SVG
 */
function renderClubRankingBadge(?int $ranking = null): string {
    $base = getClubHexagonBase('#5F1D67', 'ranking');
    $rankText = $ranking ? "#$ranking" : "–";
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <path d="M0 -12 L3 -3 L12 0 L3 3 L0 12 L-3 3 L-12 0 L-3 -3 Z" fill="#5F1D67"/>
        <circle cx="0" cy="0" r="6" fill="var(--badge-hex-bg, #171717)"/>
        <text x="0" y="4" text-anchor="middle" fill="#5F1D67" font-size="8" font-weight="bold" font-family="system-ui, sans-serif">$rankText</text>
    </g>
</svg>
SVG;
}

/**
 * Render club champions badge SVG
 */
function renderClubChampionsBadge(): string {
    $base = getClubHexagonBase('#FFE009', 'champions');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <path d="M-8 2 L-4 -10 L0 2 L4 -10 L8 2" fill="none" stroke="#FFE009" stroke-width="2" stroke-linecap="round"/>
        <circle cx="-4" cy="8" r="4" fill="#FFE009"/>
        <circle cx="4" cy="8" r="4" fill="#FFE009"/>
        <path d="M-4 8 L-4 6 M4 8 L4 6" stroke="var(--badge-hex-bg, #171717)" stroke-width="1"/>
        <circle cx="-4" cy="5" r="1" fill="var(--badge-hex-bg, #171717)"/>
        <circle cx="4" cy="5" r="1" fill="var(--badge-hex-bg, #171717)"/>
    </g>
</svg>
SVG;
}

/**
 * Render club experience icon SVG
 */
function renderClubExperienceIcon(): string {
    return <<<SVG
<svg class="exp-icon" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
    <path d="M2 17L12 22L22 17" fill="none" stroke="currentColor" stroke-width="2"/>
    <path d="M2 12L12 17L22 12" fill="none" stroke="currentColor" stroke-width="2"/>
</svg>
SVG;
}

/**
 * Render complete club achievements section
 */
function renderClubAchievements(PDO $pdo, int $club_id, array $stats = null): string {
    // Get stats if not provided
    if ($stats === null) {
        $stats = getClubAchievementStats($pdo, $club_id);
    }

    // Calculate experience level
    $expLevel = calculateClubExperienceLevel($stats);
    $expLevelName = getClubExperienceLevelName($expLevel);
    $showLegend = ($expLevel === 6);

    ob_start();
    ?>
    <div class="achievements-card">
        <div class="achievements-card-header">
            <h3 class="achievements-card-title">Klubb Achievements</h3>
            <a href="/achievements#club" class="achievements-info-link">ℹ️ Visa alla</a>
        </div>

        <!-- Club Experience Slider -->
        <div class="exp-section">
            <div class="exp-header">
                <div class="exp-label">
                    <?= renderClubExperienceIcon() ?>
                    <span class="exp-title"><?= htmlspecialchars($expLevelName) ?></span>
                </div>
                <span class="exp-since">Sedan <?= (int)$stats['first_season_year'] ?></span>
            </div>
            <div class="exp-slider">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="exp-step<?= $i <= $expLevel ? ' active' : '' ?>"></div>
                <?php endfor; ?>
                <div class="exp-step<?= $showLegend ? '' : ' hidden' ?><?= $expLevel === 6 ? ' active' : '' ?>"></div>
            </div>
            <div class="exp-labels">
                <span class="<?= $expLevel === 1 ? 'active' : '' ?>">Ny</span>
                <span class="<?= $expLevel === 2 ? 'active' : '' ?>">Etablerad</span>
                <span class="<?= $expLevel === 3 ? 'active' : '' ?>">Växande</span>
                <span class="<?= $expLevel === 4 ? 'active' : 'locked' ?>">Stark</span>
                <span class="<?= $expLevel >= 5 ? 'active' : 'locked' ?>">Elit</span>
                <?php if ($showLegend): ?>
                    <span class="<?= $expLevel === 6 ? 'active' : 'locked' ?>">Legend</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Club Badge Grid -->
        <div class="badge-grid club-badge-grid">
            <!-- Starter -->
            <div class="badge-item<?= $stats['total_starts'] === 0 ? ' locked' : '' ?>"
                 data-tooltip="Totalt antal starter"
                 data-level="<?= getBadgeLevel($stats['total_starts'], [10, 50, 100, 500]) ?>">
                <?= renderClubStarterBadge() ?>
                <span class="badge-value<?= $stats['total_starts'] === 0 ? ' empty' : '' ?>"><?= $stats['total_starts'] > 0 ? $stats['total_starts'] : '–' ?></span>
                <span class="badge-label">Starter</span>
            </div>

            <!-- Aktiva medlemmar -->
            <div class="badge-item<?= $stats['active_members'] === 0 ? ' locked' : '' ?>"
                 data-tooltip="Aktiva medlemmar (12 mån)"
                 data-level="<?= getBadgeLevel($stats['active_members'], [5, 15, 30, 50]) ?>">
                <?= renderClubActiveBadge() ?>
                <span class="badge-value<?= $stats['active_members'] === 0 ? ' empty' : '' ?>"><?= $stats['active_members'] > 0 ? $stats['active_members'] : '–' ?></span>
                <span class="badge-label">Aktiva</span>
            </div>

            <!-- Klubb-guld -->
            <div class="badge-item<?= $stats['total_gold'] === 0 ? ' locked' : '' ?>" data-tooltip="Totalt antal guld">
                <?= renderClubGoldBadge() ?>
                <span class="badge-value<?= $stats['total_gold'] === 0 ? ' empty' : '' ?>"><?= $stats['total_gold'] > 0 ? $stats['total_gold'] : '–' ?></span>
                <span class="badge-label">Klubb-guld</span>
            </div>

            <!-- Pallplatser -->
            <div class="badge-item<?= $stats['total_podiums'] === 0 ? ' locked' : '' ?>" data-tooltip="Totalt antal pallplatser">
                <?= renderClubPodiumBadge() ?>
                <span class="badge-value<?= $stats['total_podiums'] === 0 ? ' empty' : '' ?>"><?= $stats['total_podiums'] > 0 ? $stats['total_podiums'] : '–' ?></span>
                <span class="badge-label">Pallplatser</span>
            </div>

            <!-- Seriesegrar -->
            <div class="badge-item<?= $stats['series_wins'] === 0 ? ' locked' : '' ?>" data-tooltip="Medlemmars seriesegrar">
                <?= renderClubSeriesWinsBadge() ?>
                <span class="badge-value<?= $stats['series_wins'] === 0 ? ' empty' : '' ?>"><?= $stats['series_wins'] > 0 ? $stats['series_wins'] : '–' ?></span>
                <span class="badge-label">Seriesegrar</span>
            </div>

            <!-- SM-medaljer -->
            <div class="badge-item<?= $stats['sm_medals'] === 0 ? ' locked' : '' ?>" data-tooltip="SM-medaljer för klubben">
                <?= renderClubSmMedalsBadge() ?>
                <span class="badge-value<?= $stats['sm_medals'] === 0 ? ' empty' : '' ?>"><?= $stats['sm_medals'] > 0 ? $stats['sm_medals'] : '–' ?></span>
                <span class="badge-label">SM-medaljer</span>
            </div>

            <!-- Bästa ranking -->
            <div class="badge-item<?= $stats['best_ranking'] === null ? ' locked' : '' ?><?= $stats['best_ranking'] === 1 ? ' glowing' : '' ?>"
                 data-tooltip="Klubbens bästa ranking"
                 data-glow="<?= $stats['best_ranking'] === 1 ? 'gold' : '' ?>">
                <?= renderClubRankingBadge($stats['best_ranking']) ?>
                <span class="badge-value<?= $stats['best_ranking'] === null ? ' empty' : '' ?>"><?= $stats['best_ranking'] !== null ? '#' . $stats['best_ranking'] : '–' ?></span>
                <span class="badge-label">Ranking</span>
            </div>

            <!-- Mästare i klubben -->
            <div class="badge-item<?= $stats['unique_champions'] === 0 ? ' locked' : '' ?>"
                 data-tooltip="<?= $stats['unique_champions'] > 0 ? htmlspecialchars(implode(', ', $stats['champion_names'])) : 'Unika seriemästare från klubben' ?>">
                <?= renderClubChampionsBadge() ?>
                <span class="badge-value<?= $stats['unique_champions'] === 0 ? ' empty' : '' ?>"><?= $stats['unique_champions'] > 0 ? $stats['unique_champions'] : '–' ?></span>
                <span class="badge-label">Mästare</span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Get all club achievement definitions for explanation page
 */
function getClubAchievementDefinitions(): array {
    return [
        'storlek' => [
            'title' => 'Storlek & Aktivitet',
            'icon' => 'users',
            'badges' => [
                [
                    'id' => 'starter',
                    'name' => 'Starter',
                    'requirement' => 'Totalt antal starter för klubben',
                    'description' => 'Räknar alla starter från klubbens medlemmar. Nivåer: Brons (10), Silver (50), Guld (100), Diamant (500).',
                    'has_counter' => true,
                    'has_levels' => true,
                    'accent' => '#61CE70',
                    'svg_function' => 'renderClubStarterBadge'
                ],
                [
                    'id' => 'active_members',
                    'name' => 'Aktiva Medlemmar',
                    'requirement' => 'Unika åkare som tävlat senaste 12 månader',
                    'description' => 'Räknar medlemmar som deltagit i minst ett lopp senaste året. Nivåer: Brons (5), Silver (15), Guld (30), Diamant (50).',
                    'has_counter' => true,
                    'has_levels' => true,
                    'accent' => '#004a98',
                    'svg_function' => 'renderClubActiveBadge'
                ]
            ]
        ],
        'prestationer' => [
            'title' => 'Prestationer',
            'icon' => 'trophy',
            'badges' => [
                [
                    'id' => 'club_gold',
                    'name' => 'Klubb-guld',
                    'requirement' => 'Totalt antal guld för klubben',
                    'description' => 'Summan av alla förstaplatser för klubbens medlemmar.',
                    'has_counter' => true,
                    'accent' => '#FFD700',
                    'svg_function' => 'renderClubGoldBadge'
                ],
                [
                    'id' => 'podiums',
                    'name' => 'Pallplatser',
                    'requirement' => 'Totalt antal pallplatser för klubben',
                    'description' => 'Summan av alla topp-3 placeringar för klubbens medlemmar.',
                    'has_counter' => true,
                    'accent' => 'gradient',
                    'svg_function' => 'renderClubPodiumBadge'
                ],
                [
                    'id' => 'series_wins',
                    'name' => 'Seriesegrar',
                    'requirement' => 'Antal seriesegrar för klubbens medlemmar',
                    'description' => 'Totalt antal gånger klubbens medlemmar vunnit en serietotal.',
                    'has_counter' => true,
                    'accent' => '#FFD700',
                    'svg_function' => 'renderClubSeriesWinsBadge'
                ],
                [
                    'id' => 'sm_medals',
                    'name' => 'SM-medaljer',
                    'requirement' => 'SM-medaljer för klubben',
                    'description' => 'Totalt antal SM-titlar för klubbens medlemmar.',
                    'has_counter' => true,
                    'accent' => '#004a98',
                    'svg_function' => 'renderClubSmMedalsBadge'
                ]
            ]
        ],
        'special' => [
            'title' => 'Special',
            'icon' => 'star',
            'badges' => [
                [
                    'id' => 'best_ranking',
                    'name' => 'Bästa Ranking',
                    'requirement' => 'Klubbens högsta ranking i serierna',
                    'description' => 'Visar klubbens bästa placering i aktuella serieställningar. Glöder extra om klubben är #1.',
                    'has_counter' => false,
                    'accent' => '#5F1D67',
                    'svg_function' => 'renderClubRankingBadge'
                ],
                [
                    'id' => 'unique_champions',
                    'name' => 'Mästare i Klubben',
                    'requirement' => 'Antal unika seriemästare från klubben',
                    'description' => 'Räknar hur många olika personer som vunnit en serie för klubben. Visar namn på hover.',
                    'has_counter' => true,
                    'accent' => '#FFE009',
                    'svg_function' => 'renderClubChampionsBadge'
                ]
            ]
        ],
        'experience' => [
            'title' => 'Klubb Erfarenhet',
            'icon' => 'layers',
            'badges' => [
                [
                    'id' => 'club_exp_1',
                    'name' => 'Ny Klubb',
                    'requirement' => 'Första säsongen',
                    'description' => 'Klubben har precis börjat på GravitySeries.',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'club_exp_2',
                    'name' => 'Etablerad',
                    'requirement' => '2+ säsonger',
                    'description' => 'Klubben har varit med i minst 2 säsonger.',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'club_exp_3',
                    'name' => 'Växande',
                    'requirement' => '10+ aktiva medlemmar',
                    'description' => 'Klubben har 10 eller fler aktiva medlemmar.',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'club_exp_4',
                    'name' => 'Stark',
                    'requirement' => '25+ aktiva medlemmar',
                    'description' => 'Klubben har 25 eller fler aktiva medlemmar.',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'club_exp_5',
                    'name' => 'Elitsklubb',
                    'requirement' => '50+ aktiva medlemmar ELLER 10+ seriesegrar',
                    'description' => 'Klubben har antingen 50+ aktiva eller 10+ seriesegrar.',
                    'has_counter' => false,
                    'accent' => '#FFD700'
                ],
                [
                    'id' => 'club_exp_6',
                    'name' => 'Legendklubb',
                    'requirement' => '100+ medlemmar OCH 25+ seriesegrar',
                    'description' => 'Endast för de största klubbarna. Dold tills uppnådd.',
                    'has_counter' => false,
                    'accent' => '#FFD700',
                    'hidden' => true
                ]
            ]
        ]
    ];
}
