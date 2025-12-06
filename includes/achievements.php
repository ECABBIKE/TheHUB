<?php
/**
 * TheHUB Achievements System - Rider Badges
 * Hexagonal badge components with SVG icons
 * Supports both light and dark themes
 *
 * @version 2.1
 * @package TheHUB
 */

// Prevent direct access
if (!defined('DB_HOST') && !defined('THEHUB_LOADED')) {
    define('THEHUB_LOADED', true);
}

/**
 * Get rider stats for achievements
 */
function getRiderAchievementStats(PDO $pdo, int $rider_id): array {
    $stats = [
        'gold' => 0,
        'silver' => 0,
        'bronze' => 0,
        'hot_streak' => 0,
        'series_completed' => 0,
        'is_serieledare' => false,
        'series_wins' => 0,
        'sm_wins' => 0,
        'seasons_active' => 1,
        'has_series_win' => false,
        'first_season_year' => date('Y'),
        'finisher_100' => false,
        // Fun badges stats
        'total_races' => 0,
        'total_venues' => 0,
        'disciplines' => 0,
        'club_starts' => 0,
        'consecutive_seasons' => 0,
        'is_comeback' => false
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT achievement_type, achievement_value, COUNT(*) as count
            FROM rider_achievements
            WHERE rider_id = ?
            GROUP BY achievement_type, achievement_value
        ");
        $stmt->execute([$rider_id]);
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($achievements as $ach) {
            switch ($ach['achievement_type']) {
                case 'gold':
                    $stats['gold'] = (int)$ach['achievement_value'];
                    break;
                case 'silver':
                    $stats['silver'] = (int)$ach['achievement_value'];
                    break;
                case 'bronze':
                    $stats['bronze'] = (int)$ach['achievement_value'];
                    break;
                case 'hot_streak':
                    $stats['hot_streak'] = (int)$ach['achievement_value'];
                    break;
                case 'series_leader':
                    $stats['is_serieledare'] = true;
                    break;
                case 'series_champion':
                    $stats['series_wins']++;
                    $stats['has_series_win'] = true;
                    break;
                case 'swedish_champion':
                    $stats['sm_wins']++;
                    break;
                case 'finisher_100':
                    $stats['finisher_100'] = true;
                    $stats['series_completed']++;
                    break;
            }
        }

        // Get rider's first season year
        $stmt = $pdo->prepare("SELECT first_season FROM riders WHERE id = ?");
        $stmt->execute([$rider_id]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rider && !empty($rider['first_season'])) {
            $stats['first_season_year'] = (int)$rider['first_season'];
            $stats['seasons_active'] = (int)date('Y') - $stats['first_season_year'] + 1;
        }

        // Get total races for milestone badges
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE cyclist_id = ? AND status = 'finished'");
        $stmt->execute([$rider_id]);
        $stats['total_races'] = (int)$stmt->fetchColumn();

    } catch (PDOException $e) {
        // Silently fail, use defaults
    }

    return $stats;
}

/**
 * Get experience level name
 */
function getExperienceLevelName(int $level): string {
    $levels = [
        1 => '1:a året',
        2 => '2:a året',
        3 => 'Erfaren',
        4 => 'Expert',
        5 => 'Veteran',
        6 => 'Legend'
    ];
    return $levels[$level] ?? '1:a året';
}

/**
 * Calculate experience level (1-6)
 */
function calculateExperienceLevel(int $seasons, bool $has_series_win): int {
    if ($seasons >= 5 && $has_series_win) {
        return 6;
    }
    return min($seasons, 5);
}

// ============================================================================
// HEXAGONAL BADGE SVGs - Theme Compatible
// Uses CSS variable --badge-hex-bg for background (defaults to #171717)
// ============================================================================

/**
 * Generate hexagonal badge base
 */
function getHexagonBase(string $accentColor, string $uniqueId = ''): string {
    $glowId = $uniqueId ? "glow-{$uniqueId}" : 'glow-' . uniqid();
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
 * Render hexagonal gold badge SVG
 */
function renderGoldBadge(): string {
    $base = getHexagonBase('#FFD700', 'gold');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 22)">
        <polygon points="0,-12 3,-4 12,-4 5,2 7,11 0,6 -7,11 -5,2 -12,-4 -3,-4" fill="#FFD700"/>
        <circle cx="0" cy="0" r="6" fill="var(--badge-hex-bg, #171717)"/>
        <text x="0" y="4" text-anchor="middle" fill="#FFD700" font-size="9" font-weight="bold" font-family="system-ui, sans-serif">1</text>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal silver badge SVG
 */
function renderSilverBadge(): string {
    $base = getHexagonBase('#C0C0C0', 'silver');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 22)">
        <polygon points="0,-12 3,-4 12,-4 5,2 7,11 0,6 -7,11 -5,2 -12,-4 -3,-4" fill="#C0C0C0"/>
        <circle cx="0" cy="0" r="6" fill="var(--badge-hex-bg, #171717)"/>
        <text x="0" y="4" text-anchor="middle" fill="#C0C0C0" font-size="9" font-weight="bold" font-family="system-ui, sans-serif">2</text>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal bronze badge SVG
 */
function renderBronzeBadge(): string {
    $base = getHexagonBase('#CD7F32', 'bronze');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 22)">
        <polygon points="0,-12 3,-4 12,-4 5,2 7,11 0,6 -7,11 -5,2 -12,-4 -3,-4" fill="#CD7F32"/>
        <circle cx="0" cy="0" r="6" fill="var(--badge-hex-bg, #171717)"/>
        <text x="0" y="4" text-anchor="middle" fill="#CD7F32" font-size="9" font-weight="bold" font-family="system-ui, sans-serif">3</text>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal hot streak (pallserie) badge SVG
 */
function renderHotStreakBadge(): string {
    $base = getHexagonBase('#61CE70', 'hotstreak');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <rect x="-14" y="2" width="8" height="12" rx="1" fill="#C0C0C0"/>
        <rect x="-4" y="-4" width="8" height="18" rx="1" fill="#FFD700"/>
        <rect x="6" y="6" width="8" height="8" rx="1" fill="#CD7F32"/>
        <path d="M-4 -10 L0 -16 L4 -10" fill="none" stroke="#61CE70" stroke-width="2" stroke-linecap="round"/>
        <path d="M-2 -7 L0 -11 L2 -7" fill="none" stroke="#61CE70" stroke-width="1.5" stroke-linecap="round"/>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal fullföljare badge SVG
 */
function renderFinisherBadge(): string {
    $base = getHexagonBase('#61CE70', 'finisher');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="0" r="12" fill="none" stroke="var(--badge-hex-bg, #333)" stroke-width="2"/>
        <circle cx="0" cy="0" r="12" fill="none" stroke="#61CE70" stroke-width="2" stroke-dasharray="75.4" stroke-dashoffset="0" transform="rotate(-90)"/>
        <path d="M-5 0 L-1 4 L6 -4" fill="none" stroke="#61CE70" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal serieledare badge SVG
 */
function renderSeriesLeaderBadge(): string {
    $base = getHexagonBase('#EF761F', 'leader');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <rect x="-12" y="-6" width="24" height="16" rx="2" fill="none" stroke="#EF761F" stroke-width="1.5"/>
        <path d="M-8 6 L-2 0 L4 4 L10 -4" fill="none" stroke="#EF761F" stroke-width="2" stroke-linecap="round"/>
        <circle cx="10" cy="-4" r="2" fill="#EF761F"/>
        <path d="M6 -12 L12 -8 L6 -4" fill="#EF761F"/>
        <rect x="4" y="-12" width="2" height="10" fill="#EF761F"/>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal seriemästare badge SVG
 */
function renderSeriesChampionBadge(): string {
    $base = getHexagonBase('#FFD700', 'champion');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 22)">
        <path d="M-8 -10 L8 -10 L6 2 L4 8 L-4 8 L-6 2 Z" fill="#FFD700"/>
        <path d="M-8 -8 Q-14 -8 -14 -2 Q-14 4 -6 2" fill="none" stroke="#FFD700" stroke-width="2.5"/>
        <path d="M8 -8 Q14 -8 14 -2 Q14 4 6 2" fill="none" stroke="#FFD700" stroke-width="2.5"/>
        <rect x="-3" y="8" width="6" height="3" fill="#FFD700"/>
        <rect x="-5" y="11" width="10" height="3" rx="1" fill="#FFD700"/>
        <path d="M0 -6 L1 -3 L4 -3 L2 -1 L3 2 L0 0 L-3 2 L-2 -1 L-4 -3 L-1 -3 Z" fill="var(--badge-hex-bg, #171717)"/>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal SM-vinnare badge SVG
 */
function renderSwedishChampionBadge(): string {
    $base = getHexagonBase('#004a98', 'sm');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <rect x="-10" y="-8" width="20" height="16" rx="2" fill="#004a98"/>
        <rect x="-2" y="-8" width="4" height="16" fill="#FFE009"/>
        <rect x="-10" y="-1" width="20" height="4" fill="#FFE009"/>
        <path d="M-6 -12 L-4 -16 L0 -13 L4 -16 L6 -12 L4 -9 L-4 -9 Z" fill="#FFD700"/>
        <rect x="-6" y="2" width="12" height="8" rx="1" fill="#FFD700"/>
        <text x="0" y="8" text-anchor="middle" fill="#004a98" font-size="6" font-weight="bold" font-family="system-ui, sans-serif">SM</text>
    </g>
</svg>
SVG;
}

// ============================================================================
// FUN BADGES - Loyalty & Endurance
// ============================================================================

/**
 * Render Järnman badge - All series in one season
 */
function renderIronmanBadge(): string {
    $base = getHexagonBase('#607D8B', 'ironman');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="-6" r="5" fill="#607D8B"/>
        <path d="M-6 0 L6 0 L4 12 L-4 12 Z" fill="#607D8B"/>
        <path d="M-10 2 L-6 0 L-6 6 Z" fill="#607D8B"/>
        <path d="M10 2 L6 0 L6 6 Z" fill="#607D8B"/>
        <circle cx="0" cy="6" r="4" fill="#FFD700" stroke="#B8860B" stroke-width="0.5"/>
    </g>
</svg>
SVG;
}

/**
 * Render Klubbhjälte badge - 10+ starts for same club
 */
function renderClubHeroBadge(): string {
    $base = getHexagonBase('#004a98', 'clubhero');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <path d="M0 -14 L10 -4 L10 8 L0 14 L-10 8 L-10 -4 Z" fill="none" stroke="#004a98" stroke-width="2"/>
        <path d="M0 -10 L6 -3 L6 5 L0 10 L-6 5 L-6 -3 Z" fill="#004a98"/>
        <path d="M0 -4 L2 0 L0 4 L-2 0 Z" fill="#FFD700"/>
        <circle cx="0" cy="0" r="2" fill="#FFD700"/>
    </g>
</svg>
SVG;
}

/**
 * Render Trogen badge - 3+ consecutive seasons
 */
function renderLoyalBadge(): string {
    $base = getHexagonBase('#61CE70', 'loyal');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <ellipse cx="-6" cy="0" rx="4" ry="6" fill="none" stroke="#61CE70" stroke-width="2"/>
        <ellipse cx="6" cy="0" rx="4" ry="6" fill="none" stroke="#61CE70" stroke-width="2"/>
        <line x1="-2" y1="0" x2="2" y2="0" stroke="#61CE70" stroke-width="3"/>
        <circle cx="-6" cy="0" r="2" fill="#61CE70"/>
        <circle cx="6" cy="0" r="2" fill="#61CE70"/>
    </g>
</svg>
SVG;
}

/**
 * Render Comeback badge - Back after 2+ seasons break
 */
function renderComebackBadge(): string {
    $base = getHexagonBase('#EF761F', 'comeback');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <path d="M0 10 C-8 10 -12 2 -10 -6 C-8 -12 0 -14 0 -14" fill="none" stroke="#EF761F" stroke-width="2"/>
        <path d="M0 10 C8 10 12 2 10 -6 C8 -12 0 -14 0 -14" fill="none" stroke="#EF761F" stroke-width="2"/>
        <path d="M-4 -10 L0 -16 L4 -10" fill="#EF761F"/>
        <path d="M-6 6 L0 -2 L6 6" fill="none" stroke="#FFD700" stroke-width="2" stroke-linecap="round"/>
    </g>
</svg>
SVG;
}

// ============================================================================
// FUN BADGES - Exploration
// ============================================================================

/**
 * Render Allrounder badge - 3+ different disciplines
 */
function renderAllrounderBadge(): string {
    $base = getHexagonBase('#8A9A5B', 'allrounder');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="0" r="10" fill="none" stroke="#8A9A5B" stroke-width="1.5"/>
        <line x1="0" y1="-10" x2="0" y2="-4" stroke="#8A9A5B" stroke-width="2"/>
        <line x1="0" y1="10" x2="0" y2="4" stroke="#8A9A5B" stroke-width="2"/>
        <line x1="-10" y1="0" x2="-4" y2="0" stroke="#8A9A5B" stroke-width="2"/>
        <line x1="10" y1="0" x2="4" y2="0" stroke="#8A9A5B" stroke-width="2"/>
        <circle cx="0" cy="0" r="3" fill="#8A9A5B"/>
        <path d="M0 -2 L2 1 L-2 1 Z" fill="#FFD700"/>
    </g>
</svg>
SVG;
}

/**
 * Render Äventyrare badge - 5+ different venues
 */
function renderAdventurerBadge(): string {
    $base = getHexagonBase('#61CE70', 'adventurer');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <path d="M-8 8 L0 -10 L8 8 Z" fill="none" stroke="#61CE70" stroke-width="2" stroke-linejoin="round"/>
        <path d="M-4 8 L0 0 L4 8" fill="none" stroke="#61CE70" stroke-width="1.5"/>
        <circle cx="4" cy="-4" r="2" fill="#FFD700"/>
        <line x1="4" y1="-2" x2="4" y2="2" stroke="#FFD700" stroke-width="1"/>
    </g>
</svg>
SVG;
}

/**
 * Render Nomad badge - 10+ different venues
 */
function renderNomadBadge(): string {
    $base = getHexagonBase('#FFE009', 'nomad');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <path d="M-10 6 Q0 -8 10 6" fill="none" stroke="#FFE009" stroke-width="2"/>
        <circle cx="-8" cy="6" r="3" fill="#FFE009"/>
        <circle cx="0" cy="-2" r="3" fill="#FFE009"/>
        <circle cx="8" cy="6" r="3" fill="#FFE009"/>
        <path d="M-6 6 L-2 -2 M2 -2 L6 6" stroke="#FFE009" stroke-width="1.5" stroke-dasharray="2 2"/>
    </g>
</svg>
SVG;
}

// ============================================================================
// FUN BADGES - Performance
// ============================================================================

/**
 * Render Raketstart badge - Podium in first ever race
 */
function renderRocketStartBadge(): string {
    $base = getHexagonBase('#EF761F', 'rocket');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <path d="M0 -12 C4 -12 6 -8 6 -4 L6 6 L2 10 L-2 10 L-6 6 L-6 -4 C-6 -8 -4 -12 0 -12 Z" fill="#EF761F"/>
        <circle cx="0" cy="-4" r="3" fill="var(--badge-hex-bg, #171717)"/>
        <path d="M-4 10 L-6 14 L-2 12 M4 10 L6 14 L2 12" stroke="#FFD700" stroke-width="1.5" fill="none"/>
        <path d="M-1 12 L0 16 L1 12" fill="#FFD700"/>
    </g>
</svg>
SVG;
}

/**
 * Render Konsekvent badge - Top 10 in all races of a series
 */
function renderConsistentBadge(): string {
    $base = getHexagonBase('#61CE70', 'consistent');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <line x1="-10" y1="6" x2="10" y2="6" stroke="#61CE70" stroke-width="2"/>
        <line x1="-10" y1="0" x2="10" y2="0" stroke="#61CE70" stroke-width="2"/>
        <line x1="-10" y1="-6" x2="10" y2="-6" stroke="#61CE70" stroke-width="2"/>
        <circle cx="-6" cy="6" r="2" fill="#61CE70"/>
        <circle cx="0" cy="0" r="2" fill="#61CE70"/>
        <circle cx="6" cy="-6" r="2" fill="#61CE70"/>
    </g>
</svg>
SVG;
}

/**
 * Render Förbättrare badge - Improved position 5 races in a row
 */
function renderImproverBadge(): string {
    $base = getHexagonBase('#61CE70', 'improver');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <path d="M-10 8 L-4 4 L0 -2 L4 -6 L10 -10" fill="none" stroke="#61CE70" stroke-width="2.5" stroke-linecap="round"/>
        <path d="M6 -10 L10 -10 L10 -6" fill="none" stroke="#61CE70" stroke-width="2" stroke-linecap="round"/>
        <circle cx="-10" cy="8" r="2" fill="#61CE70"/>
    </g>
</svg>
SVG;
}

// ============================================================================
// FUN BADGES - Milestones
// ============================================================================

/**
 * Render First Race badge
 */
function renderFirstRaceBadge(): string {
    $base = getHexagonBase('#61CE70', 'firstrace');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="-2" r="8" fill="#61CE70"/>
        <path d="M-3 6 L0 0 L3 6" fill="#61CE70"/>
        <path d="M0 -6 L0 2 M-3 -3 L0 -6 L3 -3" stroke="var(--badge-hex-bg, #171717)" stroke-width="2" stroke-linecap="round" fill="none"/>
    </g>
</svg>
SVG;
}

/**
 * Render 10 Races badge
 */
function render10RacesBadge(): string {
    $base = getHexagonBase('#61CE70', 'races10');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="0" r="11" fill="none" stroke="#61CE70" stroke-width="2"/>
        <text x="0" y="5" text-anchor="middle" fill="#61CE70" font-size="12" font-weight="bold" font-family="system-ui, sans-serif">10</text>
    </g>
</svg>
SVG;
}

/**
 * Render 25 Races badge
 */
function render25RacesBadge(): string {
    $base = getHexagonBase('#C0C0C0', 'races25');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="0" r="11" fill="none" stroke="#C0C0C0" stroke-width="2"/>
        <path d="M-8 -12 C-4 -14 4 -14 8 -12" fill="none" stroke="#C0C0C0" stroke-width="1.5"/>
        <path d="M-8 12 C-4 14 4 14 8 12" fill="none" stroke="#C0C0C0" stroke-width="1.5"/>
        <text x="0" y="5" text-anchor="middle" fill="#C0C0C0" font-size="11" font-weight="bold" font-family="system-ui, sans-serif">25</text>
    </g>
</svg>
SVG;
}

/**
 * Render 50 Races badge
 */
function render50RacesBadge(): string {
    $base = getHexagonBase('#FFD700', 'races50');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="0" r="11" fill="none" stroke="#FFD700" stroke-width="2"/>
        <path d="M-6 -12 L0 -15 L6 -12" fill="none" stroke="#FFD700" stroke-width="1.5"/>
        <path d="M-6 12 L0 15 L6 12" fill="none" stroke="#FFD700" stroke-width="1.5"/>
        <circle cx="-10" cy="0" r="1.5" fill="#FFD700"/>
        <circle cx="10" cy="0" r="1.5" fill="#FFD700"/>
        <text x="0" y="5" text-anchor="middle" fill="#FFD700" font-size="11" font-weight="bold" font-family="system-ui, sans-serif">50</text>
    </g>
</svg>
SVG;
}

/**
 * Render 100 Races badge - Legendary
 */
function render100RacesBadge(): string {
    $base = getHexagonBase('#FFD700', 'races100');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="0" r="11" fill="none" stroke="#FFD700" stroke-width="2.5"/>
        <path d="M-8 -10 L0 -14 L8 -10" fill="#FFD700"/>
        <path d="M-8 10 L0 14 L8 10" fill="#FFD700"/>
        <circle cx="-12" cy="0" r="2" fill="#FFD700"/>
        <circle cx="12" cy="0" r="2" fill="#FFD700"/>
        <text x="0" y="4" text-anchor="middle" fill="#FFD700" font-size="9" font-weight="bold" font-family="system-ui, sans-serif">100</text>
    </g>
</svg>
SVG;
}

/**
 * Render Säsongsstartare badge - Participated in season opener
 */
function renderSeasonStarterBadge(): string {
    $base = getHexagonBase('#61CE70', 'starter');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <rect x="-8" y="-10" width="16" height="20" rx="2" fill="none" stroke="#61CE70" stroke-width="1.5"/>
        <line x1="-8" y1="-4" x2="8" y2="-4" stroke="#61CE70" stroke-width="1"/>
        <text x="0" y="4" text-anchor="middle" fill="#61CE70" font-size="8" font-weight="bold" font-family="system-ui, sans-serif">1</text>
        <circle cx="5" cy="-7" r="2" fill="#FFD700"/>
    </g>
</svg>
SVG;
}

/**
 * Render Finisher badge - Participated in season finale
 */
function renderSeasonFinisherBadge(): string {
    $base = getHexagonBase('#61CE70', 'seasonfinish');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <rect x="-6" y="-10" width="4" height="18" fill="#61CE70"/>
        <rect x="2" y="-10" width="4" height="18" fill="var(--badge-hex-bg, #171717)" stroke="#61CE70" stroke-width="1"/>
        <rect x="-6" y="-10" width="4" height="4" fill="var(--badge-hex-bg, #171717)"/>
        <rect x="2" y="-6" width="4" height="4" fill="#61CE70"/>
        <rect x="-6" y="-2" width="4" height="4" fill="var(--badge-hex-bg, #171717)"/>
        <rect x="2" y="2" width="4" height="4" fill="#61CE70"/>
    </g>
</svg>
SVG;
}

/**
 * Render Connected badge - linked social media account
 */
function renderConnectedBadge(): string {
    $base = getHexagonBase('#1DA1F2', 'connected');
    return <<<SVG
<svg class="badge-svg" width="48" height="48" viewBox="0 0 48 48">
    {$base}
    <g transform="translate(24, 24)">
        <circle cx="0" cy="0" r="8" fill="none" stroke="#1DA1F2" stroke-width="2"/>
        <circle cx="-10" cy="0" r="3" fill="#1DA1F2"/>
        <circle cx="10" cy="0" r="3" fill="#1DA1F2"/>
        <circle cx="0" cy="-10" r="3" fill="#1DA1F2"/>
        <line x1="-7" y1="0" x2="7" y2="0" stroke="#1DA1F2" stroke-width="1.5"/>
        <line x1="0" y1="-7" x2="0" y2="0" stroke="#1DA1F2" stroke-width="1.5"/>
        <circle cx="0" cy="0" r="2" fill="#1DA1F2"/>
    </g>
</svg>
SVG;
}

/**
 * Render experience star icon SVG
 */
function renderExperienceIcon(): string {
    return <<<SVG
<svg class="exp-icon" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
</svg>
SVG;
}

// ============================================================================
// RENDER FUNCTIONS
// ============================================================================

/**
 * Render complete rider achievements section
 */
function renderRiderAchievements(PDO $pdo, int $rider_id, array $stats = null): string {
    if ($stats === null) {
        $stats = getRiderAchievementStats($pdo, $rider_id);
    }

    $expLevel = calculateExperienceLevel($stats['seasons_active'] ?? 1, $stats['has_series_win'] ?? false);
    $expLevelName = getExperienceLevelName($expLevel);
    $showLegend = ($expLevel === 6);

    ob_start();
    ?>
    <section class="section">
        <div class="achievements-card">
            <div class="achievements-card-header">
                <h3 class="achievements-card-title">Achievements</h3>
                <a href="/achievements" class="achievements-info-link">ℹ️ Visa alla</a>
            </div>

            <!-- Experience Slider -->
            <div class="exp-section">
                <div class="exp-header">
                    <div class="exp-label">
                        <?= renderExperienceIcon() ?>
                        <span class="exp-title"><?= htmlspecialchars($expLevelName) ?></span>
                    </div>
                    <span class="exp-since">Sedan <?= (int)($stats['first_season_year'] ?? date('Y')) ?></span>
                </div>
                <div class="exp-slider">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="exp-step<?= $i <= $expLevel ? ' active' : '' ?>"></div>
                    <?php endfor; ?>
                    <div class="exp-step<?= $showLegend ? '' : ' hidden' ?><?= $expLevel === 6 ? ' active' : '' ?>"></div>
                </div>
                <div class="exp-labels">
                    <span class="<?= $expLevel === 1 ? 'active' : '' ?>">1:a året</span>
                    <span class="<?= $expLevel === 2 ? 'active' : '' ?>">2:a året</span>
                    <span class="<?= $expLevel === 3 ? 'active' : '' ?>">Erfaren</span>
                    <span class="<?= $expLevel === 4 ? 'active' : '' ?>">Expert</span>
                    <span class="<?= $expLevel >= 5 ? 'active' : '' ?>">Veteran</span>
                    <?php if ($showLegend): ?>
                        <span class="<?= $expLevel === 6 ? 'active' : '' ?>">Legend</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Core Badge Grid -->
            <div class="badge-grid">
                <div class="badge-item<?= ($stats['gold'] ?? 0) === 0 ? ' locked' : '' ?>" data-tooltip="1:a plats i ett lopp">
                    <?= renderGoldBadge() ?>
                    <span class="badge-value<?= ($stats['gold'] ?? 0) === 0 ? ' empty' : '' ?>"><?= ($stats['gold'] ?? 0) > 0 ? $stats['gold'] : '–' ?></span>
                    <span class="badge-label">Guld</span>
                </div>

                <div class="badge-item<?= ($stats['silver'] ?? 0) === 0 ? ' locked' : '' ?>" data-tooltip="2:a plats i ett lopp">
                    <?= renderSilverBadge() ?>
                    <span class="badge-value<?= ($stats['silver'] ?? 0) === 0 ? ' empty' : '' ?>"><?= ($stats['silver'] ?? 0) > 0 ? $stats['silver'] : '–' ?></span>
                    <span class="badge-label">Silver</span>
                </div>

                <div class="badge-item<?= ($stats['bronze'] ?? 0) === 0 ? ' locked' : '' ?>" data-tooltip="3:e plats i ett lopp">
                    <?= renderBronzeBadge() ?>
                    <span class="badge-value<?= ($stats['bronze'] ?? 0) === 0 ? ' empty' : '' ?>"><?= ($stats['bronze'] ?? 0) > 0 ? $stats['bronze'] : '–' ?></span>
                    <span class="badge-label">Brons</span>
                </div>

                <div class="badge-item<?= ($stats['hot_streak'] ?? 0) === 0 ? ' locked' : '' ?>" data-tooltip="3+ pallplatser i rad">
                    <?= renderHotStreakBadge() ?>
                    <span class="badge-value<?= ($stats['hot_streak'] ?? 0) === 0 ? ' empty' : '' ?>"><?= ($stats['hot_streak'] ?? 0) > 0 ? $stats['hot_streak'] : '–' ?></span>
                    <span class="badge-label">Pallserie</span>
                </div>

                <div class="badge-item<?= ($stats['series_completed'] ?? 0) === 0 ? ' locked' : '' ?>" data-tooltip="100% fullföljt i en serie">
                    <?= renderFinisherBadge() ?>
                    <span class="badge-value<?= ($stats['series_completed'] ?? 0) === 0 ? ' empty' : '' ?>"><?= ($stats['series_completed'] ?? 0) > 0 ? $stats['series_completed'] : '–' ?></span>
                    <span class="badge-label">Fullföljt</span>
                </div>

                <div class="badge-item<?= !($stats['is_serieledare'] ?? false) ? ' locked' : '' ?>" data-tooltip="Leder en serie">
                    <?= renderSeriesLeaderBadge() ?>
                    <span class="badge-value<?= !($stats['is_serieledare'] ?? false) ? ' empty' : '' ?>"><?= ($stats['is_serieledare'] ?? false) ? 'Ja' : '–' ?></span>
                    <span class="badge-label">Serieledare</span>
                </div>

                <div class="badge-item<?= ($stats['series_wins'] ?? 0) === 0 ? ' locked' : '' ?>" data-tooltip="Vunnit en serietotal">
                    <?= renderSeriesChampionBadge() ?>
                    <span class="badge-value<?= ($stats['series_wins'] ?? 0) === 0 ? ' empty' : '' ?>"><?= ($stats['series_wins'] ?? 0) > 0 ? $stats['series_wins'] : '–' ?></span>
                    <span class="badge-label">Mästare</span>
                </div>

                <div class="badge-item<?= ($stats['sm_wins'] ?? 0) === 0 ? ' locked' : '' ?>" data-tooltip="Vunnit ett SM-event">
                    <?= renderSwedishChampionBadge() ?>
                    <span class="badge-value<?= ($stats['sm_wins'] ?? 0) === 0 ? ' empty' : '' ?>"><?= ($stats['sm_wins'] ?? 0) > 0 ? $stats['sm_wins'] : '–' ?></span>
                    <span class="badge-label">SM-vinnare</span>
                </div>
            </div>

            <!-- Milestone Badges (if applicable) -->
            <?php
            $totalRaces = $stats['total_races'] ?? 0;
            if ($totalRaces >= 10):
            ?>
            <div class="badge-grid" style="margin-top: var(--space-md, 16px); padding-top: var(--space-md, 16px); border-top: 1px solid var(--achievement-border, #e0e0e0);">
                <div class="badge-item" data-tooltip="Första loppet genomfört">
                    <?= renderFirstRaceBadge() ?>
                    <span class="badge-value">✓</span>
                    <span class="badge-label">Debut</span>
                </div>

                <div class="badge-item<?= $totalRaces < 10 ? ' locked' : '' ?>" data-tooltip="10 lopp genomförda">
                    <?= render10RacesBadge() ?>
                    <span class="badge-value"><?= $totalRaces >= 10 ? '✓' : '–' ?></span>
                    <span class="badge-label">10 lopp</span>
                </div>

                <div class="badge-item<?= $totalRaces < 25 ? ' locked' : '' ?>" data-tooltip="25 lopp genomförda">
                    <?= render25RacesBadge() ?>
                    <span class="badge-value"><?= $totalRaces >= 25 ? '✓' : '–' ?></span>
                    <span class="badge-label">25 lopp</span>
                </div>

                <div class="badge-item<?= $totalRaces < 50 ? ' locked' : '' ?>" data-tooltip="50 lopp genomförda">
                    <?= render50RacesBadge() ?>
                    <span class="badge-value"><?= $totalRaces >= 50 ? '✓' : '–' ?></span>
                    <span class="badge-label">50 lopp</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Get all rider achievement definitions for explanation page
 */
function getRiderAchievementDefinitions(): array {
    return [
        'placeringar' => [
            'title' => 'Placeringar',
            'icon' => 'trophy',
            'badges' => [
                ['id' => 'gold', 'name' => 'Guld', 'requirement' => '1:a plats i ett lopp', 'description' => 'Varje gång du vinner ett lopp får du ett guld.', 'has_counter' => true, 'accent' => '#FFD700', 'svg_function' => 'renderGoldBadge'],
                ['id' => 'silver', 'name' => 'Silver', 'requirement' => '2:a plats i ett lopp', 'description' => 'Varje andraplats ger dig ett silver.', 'has_counter' => true, 'accent' => '#C0C0C0', 'svg_function' => 'renderSilverBadge'],
                ['id' => 'bronze', 'name' => 'Brons', 'requirement' => '3:e plats i ett lopp', 'description' => 'Varje tredjeplats ger dig ett brons.', 'has_counter' => true, 'accent' => '#CD7F32', 'svg_function' => 'renderBronzeBadge']
            ]
        ],
        'prestationer' => [
            'title' => 'Prestationer',
            'icon' => 'trending-up',
            'badges' => [
                ['id' => 'hot_streak', 'name' => 'Pallserie', 'requirement' => '3+ pallplatser i rad', 'description' => 'När du tar 3 eller fler pallplatser i följd.', 'has_counter' => true, 'accent' => '#61CE70', 'svg_function' => 'renderHotStreakBadge'],
                ['id' => 'finisher_100', 'name' => 'Fullföljare', 'requirement' => '100% fullföljt i en serie', 'description' => 'Fullföljt alla deltävlingar i en serie.', 'has_counter' => true, 'accent' => '#61CE70', 'svg_function' => 'renderFinisherBadge'],
                ['id' => 'series_leader', 'name' => 'Serieledare', 'requirement' => 'Leder en serie', 'description' => 'Visas när du leder en aktiv serie.', 'has_counter' => false, 'accent' => '#EF761F', 'svg_function' => 'renderSeriesLeaderBadge'],
                ['id' => 'series_champion', 'name' => 'Seriemästare', 'requirement' => 'Vunnit en serietotal', 'description' => 'Permanent badge för varje serie du vunnit.', 'has_counter' => true, 'accent' => '#FFD700', 'svg_function' => 'renderSeriesChampionBadge'],
                ['id' => 'swedish_champion', 'name' => 'SM-vinnare', 'requirement' => 'Vunnit ett SM-event', 'description' => 'Permanent badge för varje SM-titel.', 'has_counter' => true, 'accent' => '#004a98', 'svg_function' => 'renderSwedishChampionBadge']
            ]
        ],
        'lojalitet' => [
            'title' => 'Lojalitet & Uthållighet',
            'icon' => 'heart',
            'badges' => [
                ['id' => 'ironman', 'name' => 'Järnman', 'requirement' => 'Deltagit i ALLA serier under en säsong', 'description' => 'Du har tävlat i varje serie under ett helt år.', 'has_counter' => true, 'accent' => '#607D8B', 'svg_function' => 'renderIronmanBadge'],
                ['id' => 'club_hero', 'name' => 'Klubbhjälte', 'requirement' => '10+ starter för samma klubb', 'description' => 'Lojalitet mot din klubb belönas.', 'has_counter' => true, 'accent' => '#004a98', 'svg_function' => 'renderClubHeroBadge'],
                ['id' => 'loyal', 'name' => 'Trogen', 'requirement' => '3+ säsonger i rad utan uppehåll', 'description' => 'Du kommer tillbaka säsong efter säsong.', 'has_counter' => true, 'accent' => '#61CE70', 'svg_function' => 'renderLoyalBadge'],
                ['id' => 'comeback', 'name' => 'Veteran Comeback', 'requirement' => 'Tillbaka efter 2+ säsongers uppehåll', 'description' => 'Välkommen tillbaka!', 'has_counter' => false, 'accent' => '#EF761F', 'svg_function' => 'renderComebackBadge']
            ]
        ],
        'utforskning' => [
            'title' => 'Mångfald & Utforskning',
            'icon' => 'compass',
            'badges' => [
                ['id' => 'allrounder', 'name' => 'Allrounder', 'requirement' => 'Tävlat i 3+ olika discipliner', 'description' => 'Enduro, DH, XCO, Dual Slalom - du gör allt!', 'has_counter' => false, 'accent' => '#8A9A5B', 'svg_function' => 'renderAllrounderBadge'],
                ['id' => 'adventurer', 'name' => 'Äventyrare', 'requirement' => 'Tävlat på 5+ olika banor', 'description' => 'Du utforskar nya platser.', 'has_counter' => true, 'accent' => '#61CE70', 'svg_function' => 'renderAdventurerBadge'],
                ['id' => 'nomad', 'name' => 'Nomad', 'requirement' => 'Tävlat på 10+ olika banor', 'description' => 'En riktig resenär!', 'has_counter' => true, 'accent' => '#FFE009', 'svg_function' => 'renderNomadBadge']
            ]
        ],
        'prestation' => [
            'title' => 'Prestationer & Rekord',
            'icon' => 'zap',
            'badges' => [
                ['id' => 'rocket', 'name' => 'Raketstart', 'requirement' => 'Pallplats i första tävlingen', 'description' => 'En imponerande debut!', 'has_counter' => false, 'accent' => '#EF761F', 'svg_function' => 'renderRocketStartBadge'],
                ['id' => 'consistent', 'name' => 'Konsekvent', 'requirement' => 'Topp 10 i alla deltävlingar i en serie', 'description' => 'Stabil prestation genom hela serien.', 'has_counter' => true, 'accent' => '#61CE70', 'svg_function' => 'renderConsistentBadge'],
                ['id' => 'improver', 'name' => 'Förbättrare', 'requirement' => 'Förbättrat placering 5 lopp i rad', 'description' => 'Du blir bättre för varje lopp!', 'has_counter' => true, 'accent' => '#61CE70', 'svg_function' => 'renderImproverBadge']
            ]
        ],
        'milstolpar' => [
            'title' => 'Milstolpar',
            'icon' => 'flag',
            'badges' => [
                ['id' => 'first_race', 'name' => 'Första Loppet', 'requirement' => 'Genomfört första tävlingen', 'description' => 'Välkommen till GravitySeries!', 'has_counter' => false, 'accent' => '#61CE70', 'svg_function' => 'renderFirstRaceBadge'],
                ['id' => 'races_10', 'name' => '10 Lopp', 'requirement' => '10 genomförda lopp', 'description' => 'Du börjar få erfarenhet.', 'has_counter' => false, 'accent' => '#61CE70', 'svg_function' => 'render10RacesBadge'],
                ['id' => 'races_25', 'name' => '25 Lopp', 'requirement' => '25 genomförda lopp', 'description' => 'En erfaren tävlande!', 'has_counter' => false, 'accent' => '#C0C0C0', 'svg_function' => 'render25RacesBadge'],
                ['id' => 'races_50', 'name' => '50 Lopp', 'requirement' => '50 genomförda lopp', 'description' => 'En veteran!', 'has_counter' => false, 'accent' => '#FFD700', 'svg_function' => 'render50RacesBadge'],
                ['id' => 'races_100', 'name' => '100 Lopp', 'requirement' => '100 genomförda lopp', 'description' => 'Legendstatus uppnådd!', 'has_counter' => false, 'accent' => '#FFD700', 'svg_function' => 'render100RacesBadge']
            ]
        ],
        'sasong' => [
            'title' => 'Årstider & Timing',
            'icon' => 'calendar',
            'badges' => [
                ['id' => 'season_starter', 'name' => 'Säsongsstartare', 'requirement' => 'Deltog i säsongens första tävling', 'description' => 'Du var med från start!', 'has_counter' => true, 'accent' => '#61CE70', 'svg_function' => 'renderSeasonStarterBadge'],
                ['id' => 'season_finisher', 'name' => 'Finisher', 'requirement' => 'Deltog i säsongens sista tävling', 'description' => 'Du höll ut till slutet!', 'has_counter' => true, 'accent' => '#61CE70', 'svg_function' => 'renderSeasonFinisherBadge']
            ]
        ],
        'social' => [
            'title' => 'Community',
            'icon' => 'share-2',
            'badges' => [
                ['id' => 'connected', 'name' => 'Connected', 'requirement' => 'Länkat minst ett socialt media-konto', 'description' => 'Du har kopplat din profil till sociala medier.', 'has_counter' => false, 'accent' => '#1DA1F2', 'svg_function' => 'renderConnectedBadge']
            ]
        ],
        'experience' => [
            'title' => 'Erfarenhet',
            'icon' => 'star',
            'badges' => [
                ['id' => 'exp_1', 'name' => '1:a året', 'requirement' => 'Första säsongen', 'description' => 'Du är ny på GravitySeries!', 'has_counter' => false, 'accent' => '#61CE70'],
                ['id' => 'exp_2', 'name' => '2:a året', 'requirement' => 'Andra säsongen', 'description' => 'Du har kommit tillbaka.', 'has_counter' => false, 'accent' => '#61CE70'],
                ['id' => 'exp_3', 'name' => 'Erfaren', 'requirement' => 'Tredje säsongen', 'description' => 'Tre säsonger under bältet.', 'has_counter' => false, 'accent' => '#61CE70'],
                ['id' => 'exp_4', 'name' => 'Expert', 'requirement' => 'Fjärde säsongen', 'description' => 'En expert på serien.', 'has_counter' => false, 'accent' => '#61CE70'],
                ['id' => 'exp_5', 'name' => 'Veteran', 'requirement' => 'Femte säsongen', 'description' => 'Du är en veteran!', 'has_counter' => false, 'accent' => '#61CE70'],
                ['id' => 'exp_6', 'name' => 'Legend', 'requirement' => '5+ säsonger OCH minst 1 serieseger', 'description' => 'Dold tills uppnådd.', 'has_counter' => false, 'accent' => '#FFD700', 'hidden' => true]
            ]
        ]
    ];
}
