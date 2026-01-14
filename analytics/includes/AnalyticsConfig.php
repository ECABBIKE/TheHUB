<?php
/**
 * AnalyticsConfig
 *
 * Gemensamma definitioner och konfiguration for analytics-plattformen.
 * Denna fil innehaller konstanter och helpers som anvands over hela systemet.
 *
 * KRITISKT: Alla analytics-komponenter ska anvanda dessa definitioner
 * for att sakerstalla konsekvent datahantering.
 *
 * @package TheHUB Analytics
 * @version 2.0
 */

class AnalyticsConfig {
    // =========================================================================
    // ACTIVE DEFINITION
    // =========================================================================

    /**
     * Definition: "Active ar Y" = rider har minst 1 registrerad start
     * under season_year=Y (oavsett serie)
     */
    public const ACTIVE_MIN_STARTS = 1;

    // =========================================================================
    // EVENT COUNT DEFINITION
    // =========================================================================

    /**
     * Definition: event_count = antal unika event_id dar rider startat
     * under aret (inte antal heat/starter)
     */
    public const EVENT_COUNT_METHOD = 'unique_events'; // 'unique_events' or 'total_starts'

    // =========================================================================
    // CHURN DEFINITIONS
    // =========================================================================

    /**
     * Soft churn: 1 ar inaktiv
     * Medium churn: 2 ar inaktiv
     * Hard churn: 3+ ar inaktiv
     */
    public const SOFT_CHURN_YEARS = 1;
    public const MEDIUM_CHURN_YEARS = 2;
    public const HARD_CHURN_YEARS = 3;

    // =========================================================================
    // CLASS RANKING - Konfigurerbar klass-hierarki
    // =========================================================================

    /**
     * Klass-ranking per sasong for att avgora upgrade/downgrade
     * Hogre rank = hogre niva (Elite ar hogst)
     *
     * OBS: SCF kan andra klasser mellan ar, sa detta maste justeras arligen
     *
     * @var array<int, array<string, int>>
     */
    public const CLASS_RANKING_BY_YEAR = [
        // Default ranking (anvands om specifikt ar saknas)
        'default' => [
            // Elite/Pro
            'Elite' => 100,
            'Elite Herr' => 100,
            'Elite Dam' => 100,
            'Pro' => 95,

            // Senior
            'Senior' => 80,
            'Senior Herr' => 80,
            'Senior Dam' => 80,
            'Open' => 75,

            // Master
            'Master 40+' => 70,
            'Master 50+' => 70,
            'Master' => 70,
            'Veteran' => 65,

            // Junior/U-klasser
            'Junior' => 60,
            'U21' => 55,
            'U19' => 50,
            'U17' => 45,
            'U15' => 40,

            // Sport/Motion
            'Sport' => 30,
            'Motion' => 25,
            'Hobby' => 20,
            'Nybörjare' => 15,

            // Fun/Kids
            'Fun' => 10,
            'Kids' => 5,
        ],

        // Specifika ar kan ha andra definitioner
        2025 => [
            // Inherit from default, override as needed
        ],
        2026 => [
            // Inherit from default, override as needed
        ],
    ];

    /**
     * Hamta klass-ranking for ett specifikt ar
     *
     * @param int $year Sasong
     * @return array<string, int> Klass => Rank mapping
     */
    public static function getClassRanking(int $year): array {
        if (isset(self::CLASS_RANKING_BY_YEAR[$year])) {
            return array_merge(
                self::CLASS_RANKING_BY_YEAR['default'],
                self::CLASS_RANKING_BY_YEAR[$year]
            );
        }
        return self::CLASS_RANKING_BY_YEAR['default'];
    }

    /**
     * Hamta rank for en specifik klass
     *
     * @param string $className Klassnamn
     * @param int $year Sasong
     * @return int|null Rank eller null om okand klass
     */
    public static function getClassRank(string $className, int $year): ?int {
        $ranking = self::getClassRanking($year);

        // Exakt match
        if (isset($ranking[$className])) {
            return $ranking[$className];
        }

        // Fuzzy match (case-insensitive)
        $classNameLower = strtolower(trim($className));
        foreach ($ranking as $name => $rank) {
            if (strtolower($name) === $classNameLower) {
                return $rank;
            }
        }

        return null; // Okand klass
    }

    // =========================================================================
    // RISK FACTORS - Konfigurerbara vikter for At-Risk
    // =========================================================================

    /**
     * Riskfaktorer med vikter for churn-prediktion
     * Totalt = 100 poang max
     */
    public const RISK_FACTORS = [
        'declining_events' => [
            'weight' => 30,
            'description' => 'Minskande antal starter jamfort med tidigare ar',
            'enabled' => true,
        ],
        'no_recent_activity' => [
            'weight' => 25,
            'description' => 'Ingen aktivitet efter sasongens cutoff-datum',
            'enabled' => true,
        ],
        'class_downgrade' => [
            'weight' => 15,
            'description' => 'Har gatt ner i klass',
            'enabled' => true,
        ],
        'single_series' => [
            'weight' => 10,
            'description' => 'Deltar endast i en serie',
            'enabled' => true,
        ],
        'low_tenure' => [
            'weight' => 10,
            'description' => 'Kort karriar (1-2 sasonger)',
            'enabled' => true,
        ],
        'high_age_in_class' => [
            'weight' => 10,
            'description' => 'Hog alder i sin klass',
            'enabled' => true, // Kan disables om birth_year saknas
        ],
    ];

    /**
     * Hamta aktiva riskfaktorer
     *
     * @return array<string, array> Aktiva faktorer
     */
    public static function getActiveRiskFactors(): array {
        return array_filter(self::RISK_FACTORS, fn($f) => $f['enabled']);
    }

    /**
     * Hamta total vikt for aktiva faktorer
     *
     * @return int Total vikt
     */
    public static function getTotalRiskWeight(): int {
        $total = 0;
        foreach (self::getActiveRiskFactors() as $factor) {
            $total += $factor['weight'];
        }
        return $total;
    }

    // =========================================================================
    // SEASON ACTIVITY CUTOFF
    // =========================================================================

    /**
     * Default cutoff-datum for "ingen aktivitet" berakning
     * Efter detta datum raknas rider som potentiellt inaktiv for aret
     */
    public const DEFAULT_SEASON_CUTOFF_MONTH = 7;  // Juli
    public const DEFAULT_SEASON_CUTOFF_DAY = 1;

    /**
     * Serie-specifika cutoff-datum (om serien slutar tidigare)
     */
    public const SERIES_CUTOFF_OVERRIDES = [
        // Format: series_id => ['month' => X, 'day' => Y]
        // Exempel: 5 => ['month' => 6, 'day' => 15], // DH-serien slutar 15 juni
    ];

    /**
     * Hamta cutoff-datum for aktivitetsberakning
     *
     * @param int $year Sasong
     * @param int|null $seriesId Serie (optional)
     * @return string Datum (Y-m-d format)
     */
    public static function getSeasonActivityCutoffDate(int $year, ?int $seriesId = null): string {
        if ($seriesId && isset(self::SERIES_CUTOFF_OVERRIDES[$seriesId])) {
            $override = self::SERIES_CUTOFF_OVERRIDES[$seriesId];
            return sprintf('%d-%02d-%02d', $year, $override['month'], $override['day']);
        }

        return sprintf('%d-%02d-%02d', $year, self::DEFAULT_SEASON_CUTOFF_MONTH, self::DEFAULT_SEASON_CUTOFF_DAY);
    }

    // =========================================================================
    // COHORT DEFINITIONS
    // =========================================================================

    /**
     * Cohort year = MIN(season_year) per canonical rider
     * Detta ar aret da en rider forst deltog i tavlingar
     */
    public const COHORT_MIN_SIZE = 10; // Minsta antal riders for att visa kohort
    public const COHORT_MAX_DISPLAY_YEARS = 10; // Max antal ar att visa i trend

    // =========================================================================
    // GEOGRAPHIC DEFINITIONS
    // =========================================================================

    /**
     * Region mapping for Sverige (lan)
     * Anvands for geografisk analys
     */
    public const SWEDISH_REGIONS = [
        'Stockholm' => ['code' => 'AB', 'population' => 2415139],
        'Uppsala' => ['code' => 'C', 'population' => 395026],
        'Sodermanland' => ['code' => 'D', 'population' => 301542],
        'Ostergotland' => ['code' => 'E', 'population' => 468339],
        'Jonkoping' => ['code' => 'F', 'population' => 366479],
        'Kronoberg' => ['code' => 'G', 'population' => 203527],
        'Kalmar' => ['code' => 'H', 'population' => 246641],
        'Gotland' => ['code' => 'I', 'population' => 60124],
        'Blekinge' => ['code' => 'K', 'population' => 159606],
        'Skane' => ['code' => 'M', 'population' => 1402425],
        'Halland' => ['code' => 'N', 'population' => 340243],
        'Vastra Gotaland' => ['code' => 'O', 'population' => 1751166],
        'Varmland' => ['code' => 'S', 'population' => 282414],
        'Orebro' => ['code' => 'T', 'population' => 305792],
        'Vastmanland' => ['code' => 'U', 'population' => 277052],
        'Dalarna' => ['code' => 'W', 'population' => 286547],
        'Gavleborg' => ['code' => 'X', 'population' => 286547],
        'Vasternorrland' => ['code' => 'Y', 'population' => 245572],
        'Jamtland' => ['code' => 'Z', 'population' => 131830],
        'Vasterbotten' => ['code' => 'AC', 'population' => 274153],
        'Norrbotten' => ['code' => 'BD', 'population' => 250497],
    ];

    /**
     * Hamta region fran ortnamn (enkel matching)
     *
     * @param string $city Ortnamn
     * @return string|null Region eller null om okand
     */
    public static function getRegionFromCity(string $city): ?string {
        // Enkel mapping baserad pa stad
        $cityRegionMap = [
            // Stockholm-regionen
            'stockholm' => 'Stockholm',
            'solna' => 'Stockholm',
            'nacka' => 'Stockholm',
            'taby' => 'Stockholm',
            'sollentuna' => 'Stockholm',

            // Goteborg-regionen
            'goteborg' => 'Vastra Gotaland',
            'molndal' => 'Vastra Gotaland',
            'partille' => 'Vastra Gotaland',

            // Malmo-regionen
            'malmo' => 'Skane',
            'lund' => 'Skane',
            'helsingborg' => 'Skane',

            // Add more as needed...
        ];

        $cityLower = strtolower(trim($city));
        return $cityRegionMap[$cityLower] ?? null;
    }

    // =========================================================================
    // SIMILAR RIDERS HEURISTICS
    // =========================================================================

    /**
     * Konfiguration for "Similar Riders" matchning
     */
    public const SIMILAR_RIDERS_CONFIG = [
        'tenure_buckets' => [
            [1, 2, 'new'],       // 1-2 sasonger = new
            [3, 5, 'established'], // 3-5 sasonger = established
            [6, 99, 'veteran'],  // 6+ sasonger = veteran
        ],
        'event_count_buckets' => [
            [1, 3, 'casual'],    // 1-3 events = casual
            [4, 10, 'regular'],  // 4-10 events = regular
            [11, 99, 'active'],  // 11+ events = active
        ],
        'match_weights' => [
            'start_year' => 20,      // Samma startår
            'discipline' => 25,       // Samma primary discipline
            'tenure_bucket' => 20,    // Samma tenure bucket
            'event_bucket' => 15,     // Samma event count bucket
            'series_overlap' => 20,   // Samma serier
        ],
    ];

    // =========================================================================
    // FEATURE FLAGS
    // =========================================================================

    /**
     * Feature flags for att sla av/pa funktionalitet
     * Anvands nar data saknas eller ar otillracklig
     */
    private static array $featureFlags = [
        'high_age_in_class' => true,
        'no_recent_activity' => true,
        'geographic_analysis' => true,
    ];

    /**
     * Satt feature flag
     *
     * @param string $flag Flag-namn
     * @param bool $enabled Aktiverad
     */
    public static function setFeatureFlag(string $flag, bool $enabled): void {
        self::$featureFlags[$flag] = $enabled;
    }

    /**
     * Kolla om feature ar aktiverad
     *
     * @param string $flag Flag-namn
     * @return bool
     */
    public static function isFeatureEnabled(string $flag): bool {
        return self::$featureFlags[$flag] ?? false;
    }

    /**
     * Hamta alla feature flags
     *
     * @return array<string, bool>
     */
    public static function getFeatureFlags(): array {
        return self::$featureFlags;
    }

    // =========================================================================
    // DATA QUALITY THRESHOLDS
    // =========================================================================

    /**
     * Troskel for datakvalitet - under dessa varanden disables features
     */
    public const DATA_QUALITY_THRESHOLDS = [
        'birth_year_coverage' => 0.5,  // 50% av riders maste ha birth_year
        'club_coverage' => 0.3,        // 30% av riders maste ha club
        'event_date_coverage' => 0.9,  // 90% av events maste ha datum
    ];

    // =========================================================================
    // CALCULATION VERSION
    // =========================================================================

    /**
     * Aktuell berakningsversion
     * Andras vid stora andringar i berakningslogik
     */
    public const CALCULATION_VERSION = 'v2';
}
