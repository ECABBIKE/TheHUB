<?php
/**
 * TheHUB Achievements System - Rider Badges
 * Hexagonal badge components with SVG icons
 *
 * @version 2.0
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
    // Default values
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
        'finisher_100' => false
    ];

    // Get achievements from rider_achievements table
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
    } catch (PDOException $e) {
        // Silently fail, use defaults
    }

    // Get rider's first season year
    try {
        $stmt = $pdo->prepare("SELECT first_season FROM riders WHERE id = ?");
        $stmt->execute([$rider_id]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rider && !empty($rider['first_season'])) {
            $stats['first_season_year'] = (int)$rider['first_season'];
            $stats['seasons_active'] = (int)date('Y') - $stats['first_season_year'] + 1;
        }
    } catch (PDOException $e) {
        // Use defaults
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
    // Level 6: 5+ seasons AND series win
    if ($seasons >= 5 && $has_series_win) {
        return 6;
    }
    // Levels 1-5 based on seasons
    return min($seasons, 5);
}

/**
 * Render hexagonal gold badge SVG
 */
function renderGoldBadge(): string {
    return <<<SVG
<svg class="badge-svg" viewBox="0 0 48 48">
    <defs>
        <filter id="gold-glow">
            <feGaussianBlur stdDeviation="1" result="blur"/>
            <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
        </filter>
    </defs>
    <circle cx="24" cy="24" r="22" fill="none" stroke="#FFD700" stroke-width="1" opacity="0.4"/>
    <path d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="#171717" stroke="#FFD700" stroke-width="1.5"/>
    <path d="M24 7 L39 16 L39 32 L24 41 L9 32 L9 16 Z" fill="none" stroke="#FFD700" stroke-width="0.5" opacity="0.4"/>
    <g transform="translate(24, 22)" filter="url(#gold-glow)">
        <polygon points="0,-12 3,-4 12,-4 5,2 7,11 0,6 -7,11 -5,2 -12,-4 -3,-4" fill="#FFD700"/>
        <circle cx="0" cy="0" r="6" fill="#171717"/>
        <text x="0" y="4" text-anchor="middle" fill="#FFD700" font-size="9" font-weight="bold" font-family="system-ui, sans-serif">1</text>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal silver badge SVG
 */
function renderSilverBadge(): string {
    return <<<SVG
<svg class="badge-svg" viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="22" fill="none" stroke="#C0C0C0" stroke-width="1" opacity="0.4"/>
    <path d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="#171717" stroke="#C0C0C0" stroke-width="1.5"/>
    <path d="M24 7 L39 16 L39 32 L24 41 L9 32 L9 16 Z" fill="none" stroke="#C0C0C0" stroke-width="0.5" opacity="0.4"/>
    <g transform="translate(24, 22)">
        <polygon points="0,-12 3,-4 12,-4 5,2 7,11 0,6 -7,11 -5,2 -12,-4 -3,-4" fill="#C0C0C0"/>
        <circle cx="0" cy="0" r="6" fill="#171717"/>
        <text x="0" y="4" text-anchor="middle" fill="#C0C0C0" font-size="9" font-weight="bold" font-family="system-ui, sans-serif">2</text>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal bronze badge SVG
 */
function renderBronzeBadge(): string {
    return <<<SVG
<svg class="badge-svg" viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="22" fill="none" stroke="#CD7F32" stroke-width="1" opacity="0.4"/>
    <path d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="#171717" stroke="#CD7F32" stroke-width="1.5"/>
    <path d="M24 7 L39 16 L39 32 L24 41 L9 32 L9 16 Z" fill="none" stroke="#CD7F32" stroke-width="0.5" opacity="0.4"/>
    <g transform="translate(24, 22)">
        <polygon points="0,-12 3,-4 12,-4 5,2 7,11 0,6 -7,11 -5,2 -12,-4 -3,-4" fill="#CD7F32"/>
        <circle cx="0" cy="0" r="6" fill="#171717"/>
        <text x="0" y="4" text-anchor="middle" fill="#CD7F32" font-size="9" font-weight="bold" font-family="system-ui, sans-serif">3</text>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal hot streak (pallserie) badge SVG
 */
function renderHotStreakBadge(): string {
    return <<<SVG
<svg class="badge-svg" viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="22" fill="none" stroke="#61CE70" stroke-width="1" opacity="0.4"/>
    <path d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="#171717" stroke="#61CE70" stroke-width="1.5"/>
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
    return <<<SVG
<svg class="badge-svg" viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="22" fill="none" stroke="#61CE70" stroke-width="1" opacity="0.4"/>
    <path d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="#171717" stroke="#61CE70" stroke-width="1.5"/>
    <g transform="translate(24, 24)">
        <circle cx="0" cy="0" r="12" fill="none" stroke="#333" stroke-width="2"/>
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
    return <<<SVG
<svg class="badge-svg" viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="22" fill="none" stroke="#EF761F" stroke-width="1" opacity="0.4"/>
    <path d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="#171717" stroke="#EF761F" stroke-width="1.5"/>
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
    return <<<SVG
<svg class="badge-svg" viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="22" fill="none" stroke="#FFD700" stroke-width="1" opacity="0.4"/>
    <path d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="#171717" stroke="#FFD700" stroke-width="1.5"/>
    <g transform="translate(24, 22)">
        <path d="M-8 -10 L8 -10 L6 2 L4 8 L-4 8 L-6 2 Z" fill="#FFD700"/>
        <path d="M-8 -8 Q-14 -8 -14 -2 Q-14 4 -6 2" fill="none" stroke="#FFD700" stroke-width="2.5"/>
        <path d="M8 -8 Q14 -8 14 -2 Q14 4 6 2" fill="none" stroke="#FFD700" stroke-width="2.5"/>
        <rect x="-3" y="8" width="6" height="3" fill="#FFD700"/>
        <rect x="-5" y="11" width="10" height="3" rx="1" fill="#FFD700"/>
        <path d="M0 -6 L1 -3 L4 -3 L2 -1 L3 2 L0 0 L-3 2 L-2 -1 L-4 -3 L-1 -3 Z" fill="#171717"/>
    </g>
</svg>
SVG;
}

/**
 * Render hexagonal SM-vinnare badge SVG
 */
function renderSwedishChampionBadge(): string {
    return <<<SVG
<svg class="badge-svg" viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="22" fill="none" stroke="#004a98" stroke-width="1" opacity="0.4"/>
    <path d="M24 3 L43 14 L43 34 L24 45 L5 34 L5 14 Z" fill="#171717" stroke="#004a98" stroke-width="1.5"/>
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

/**
 * Render complete rider achievements section
 */
function renderRiderAchievements(PDO $pdo, int $rider_id, array $stats = null): string {
    // Get stats if not provided
    if ($stats === null) {
        $stats = getRiderAchievementStats($pdo, $rider_id);
    }

    // Calculate experience level
    $expLevel = calculateExperienceLevel($stats['seasons_active'], $stats['has_series_win']);
    $expLevelName = getExperienceLevelName($expLevel);
    $showLegend = ($expLevel === 6);

    ob_start();
    ?>
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
                <span class="exp-since">Sedan <?= (int)$stats['first_season_year'] ?></span>
            </div>
            <div class="exp-slider">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="exp-step<?= $i <= $expLevel ? ' active' : '' ?>"></div>
                <?php endfor; ?>
                <div class="exp-step<?= $showLegend ? '' : ' hidden' ?><?= $expLevel === 6 ? ' active' : '' ?>"></div>
            </div>
            <div class="exp-labels">
                <span class="<?= $expLevel === 1 ? 'active' : ($expLevel < 1 ? 'locked' : '') ?>">1:a året</span>
                <span class="<?= $expLevel === 2 ? 'active' : ($expLevel < 2 ? 'locked' : '') ?>">2:a året</span>
                <span class="<?= $expLevel === 3 ? 'active' : ($expLevel < 3 ? 'locked' : '') ?>">Erfaren</span>
                <span class="<?= $expLevel === 4 ? 'active' : ($expLevel < 4 ? 'locked' : '') ?>">Expert</span>
                <span class="<?= $expLevel >= 5 ? 'active' : 'locked' ?>">Veteran</span>
                <?php if ($showLegend): ?>
                    <span class="<?= $expLevel === 6 ? 'active' : 'locked' ?>">Legend</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Badge Grid -->
        <div class="badge-grid">
            <!-- Gold -->
            <div class="badge-item<?= $stats['gold'] === 0 ? ' locked' : '' ?>" data-tooltip="1:a plats i ett lopp">
                <?= renderGoldBadge() ?>
                <span class="badge-value<?= $stats['gold'] === 0 ? ' empty' : '' ?>"><?= $stats['gold'] > 0 ? $stats['gold'] : '–' ?></span>
                <span class="badge-label">Guld</span>
            </div>

            <!-- Silver -->
            <div class="badge-item<?= $stats['silver'] === 0 ? ' locked' : '' ?>" data-tooltip="2:a plats i ett lopp">
                <?= renderSilverBadge() ?>
                <span class="badge-value<?= $stats['silver'] === 0 ? ' empty' : '' ?>"><?= $stats['silver'] > 0 ? $stats['silver'] : '–' ?></span>
                <span class="badge-label">Silver</span>
            </div>

            <!-- Bronze -->
            <div class="badge-item<?= $stats['bronze'] === 0 ? ' locked' : '' ?>" data-tooltip="3:e plats i ett lopp">
                <?= renderBronzeBadge() ?>
                <span class="badge-value<?= $stats['bronze'] === 0 ? ' empty' : '' ?>"><?= $stats['bronze'] > 0 ? $stats['bronze'] : '–' ?></span>
                <span class="badge-label">Brons</span>
            </div>

            <!-- Hot Streak / Pallserie -->
            <div class="badge-item<?= $stats['hot_streak'] === 0 ? ' locked' : '' ?>" data-tooltip="3+ pallplatser i rad">
                <?= renderHotStreakBadge() ?>
                <span class="badge-value<?= $stats['hot_streak'] === 0 ? ' empty' : '' ?>"><?= $stats['hot_streak'] > 0 ? $stats['hot_streak'] : '–' ?></span>
                <span class="badge-label">Pallserie</span>
            </div>

            <!-- Fullföljare -->
            <div class="badge-item<?= $stats['series_completed'] === 0 ? ' locked' : '' ?>" data-tooltip="100% fullföljt i en serie">
                <?= renderFinisherBadge() ?>
                <span class="badge-value<?= $stats['series_completed'] === 0 ? ' empty' : '' ?>"><?= $stats['series_completed'] > 0 ? $stats['series_completed'] : '–' ?></span>
                <span class="badge-label">Fullföljt</span>
            </div>

            <!-- Serieledare -->
            <div class="badge-item<?= !$stats['is_serieledare'] ? ' locked' : '' ?>" data-tooltip="Leder en serie">
                <?= renderSeriesLeaderBadge() ?>
                <span class="badge-value<?= !$stats['is_serieledare'] ? ' empty' : '' ?>"><?= $stats['is_serieledare'] ? 'Ja' : '–' ?></span>
                <span class="badge-label">Serieledare</span>
            </div>

            <!-- Seriemästare -->
            <div class="badge-item<?= $stats['series_wins'] === 0 ? ' locked' : '' ?>" data-tooltip="Vunnit en serietotal">
                <?= renderSeriesChampionBadge() ?>
                <span class="badge-value<?= $stats['series_wins'] === 0 ? ' empty' : '' ?>"><?= $stats['series_wins'] > 0 ? $stats['series_wins'] : '–' ?></span>
                <span class="badge-label">Mästare</span>
            </div>

            <!-- SM-vinnare -->
            <div class="badge-item<?= $stats['sm_wins'] === 0 ? ' locked' : '' ?>" data-tooltip="Vunnit ett SM-event">
                <?= renderSwedishChampionBadge() ?>
                <span class="badge-value<?= $stats['sm_wins'] === 0 ? ' empty' : '' ?>"><?= $stats['sm_wins'] > 0 ? $stats['sm_wins'] : '–' ?></span>
                <span class="badge-label">SM-vinnare</span>
            </div>
        </div>
    </div>
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
                [
                    'id' => 'gold',
                    'name' => 'Guld',
                    'requirement' => '1:a plats i ett lopp',
                    'description' => 'Varje gång du vinner ett lopp får du ett guld. Räknaren visar totalt antal guld.',
                    'has_counter' => true,
                    'accent' => '#FFD700',
                    'svg_function' => 'renderGoldBadge'
                ],
                [
                    'id' => 'silver',
                    'name' => 'Silver',
                    'requirement' => '2:a plats i ett lopp',
                    'description' => 'Varje andraplats ger dig ett silver. Räknaren visar totalt antal silver.',
                    'has_counter' => true,
                    'accent' => '#C0C0C0',
                    'svg_function' => 'renderSilverBadge'
                ],
                [
                    'id' => 'bronze',
                    'name' => 'Brons',
                    'requirement' => '3:e plats i ett lopp',
                    'description' => 'Varje tredjeplats ger dig ett brons. Räknaren visar totalt antal brons.',
                    'has_counter' => true,
                    'accent' => '#CD7F32',
                    'svg_function' => 'renderBronzeBadge'
                ]
            ]
        ],
        'prestationer' => [
            'title' => 'Prestationer',
            'icon' => 'trending-up',
            'badges' => [
                [
                    'id' => 'hot_streak',
                    'name' => 'Pallserie',
                    'requirement' => '3+ pallplatser i rad',
                    'description' => 'När du tar 3 eller fler pallplatser i följd får du en pallserie. Räknaren visar antal gånger du uppnått detta.',
                    'has_counter' => true,
                    'accent' => '#61CE70',
                    'svg_function' => 'renderHotStreakBadge'
                ],
                [
                    'id' => 'finisher_100',
                    'name' => 'Fullföljare',
                    'requirement' => '100% fullföljt i en serie',
                    'description' => 'Fullföljt alla deltävlingar i en serie. Du behåller denna badge även om framtida serier inte fullföljs.',
                    'has_counter' => true,
                    'accent' => '#61CE70',
                    'svg_function' => 'renderFinisherBadge'
                ],
                [
                    'id' => 'series_leader',
                    'name' => 'Serieledare',
                    'requirement' => 'Leder en serie efter minst 2 deltävlingar',
                    'description' => 'Visas när du leder en aktiv serie. Försvinner när du inte längre leder.',
                    'has_counter' => false,
                    'accent' => '#EF761F',
                    'svg_function' => 'renderSeriesLeaderBadge'
                ],
                [
                    'id' => 'series_champion',
                    'name' => 'Seriemästare',
                    'requirement' => 'Vunnit en serietotal',
                    'description' => 'Permanent badge för varje serie du vunnit. Räknaren visar antal seriesegrar.',
                    'has_counter' => true,
                    'accent' => '#FFD700',
                    'svg_function' => 'renderSeriesChampionBadge'
                ],
                [
                    'id' => 'swedish_champion',
                    'name' => 'SM-vinnare',
                    'requirement' => 'Vunnit ett SM-event',
                    'description' => 'Permanent badge för varje SM-titel. Räknaren visar antal SM-segrar.',
                    'has_counter' => true,
                    'accent' => '#004a98',
                    'svg_function' => 'renderSwedishChampionBadge'
                ]
            ]
        ],
        'experience' => [
            'title' => 'Erfarenhet',
            'icon' => 'star',
            'badges' => [
                [
                    'id' => 'exp_1',
                    'name' => '1:a året',
                    'requirement' => 'Första säsongen',
                    'description' => 'Du är ny på GravitySeries! Välkommen!',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'exp_2',
                    'name' => '2:a året',
                    'requirement' => 'Andra säsongen',
                    'description' => 'Du har kommit tillbaka för en andra säsong.',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'exp_3',
                    'name' => 'Erfaren',
                    'requirement' => 'Tredje säsongen',
                    'description' => 'Tre säsonger under bältet - du börjar bli erfaren!',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'exp_4',
                    'name' => 'Expert',
                    'requirement' => 'Fjärde säsongen',
                    'description' => 'Fyra säsonger gör dig till en expert på serien.',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'exp_5',
                    'name' => 'Veteran',
                    'requirement' => 'Femte säsongen',
                    'description' => 'Fem säsonger - du är en veteran!',
                    'has_counter' => false,
                    'accent' => '#61CE70'
                ],
                [
                    'id' => 'exp_6',
                    'name' => 'Legend',
                    'requirement' => '5+ säsonger OCH minst 1 serieseger',
                    'description' => 'Endast för de som har minst 5 säsonger OCH vunnit en serie. Dold tills uppnådd.',
                    'has_counter' => false,
                    'accent' => '#FFD700',
                    'hidden' => true
                ]
            ]
        ]
    ];
}
