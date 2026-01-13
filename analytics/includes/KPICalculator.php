<?php
/**
 * KPICalculator
 *
 * Beraknar alla KPI:er baserat pa pre-beraknad data i analytics-tabellerna.
 * Anvands av dashboard och rapportgeneratorn.
 *
 * Huvudkategorier:
 * - Retention & Growth (retention rate, churn rate, growth)
 * - Demographics (alder, kon, disciplin)
 * - Series Flow (feeder conversion, cross-participation, loyalty)
 * - Club (top klubbar, tillvaxt)
 * - Geographic (regioner)
 *
 * @package TheHUB Analytics
 * @version 1.0
 */

class KPICalculator {
    private PDO $pdo;

    /**
     * Constructor
     *
     * @param PDO $pdo Databasanslutning
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // =========================================================================
    // RETENTION & GROWTH
    // =========================================================================

    /**
     * Berakna retention rate (andel som aterkom fran forra aret)
     *
     * @param int $year Ar att berakna for
     * @return float Retention rate i procent (0-100)
     */
    public function getRetentionRate(int $year): float {
        $stmt = $this->pdo->prepare("
            SELECT
                ROUND(
                    COUNT(DISTINCT CASE WHEN curr.rider_id IS NOT NULL THEN prev.rider_id END) * 100.0 /
                    NULLIF(COUNT(DISTINCT prev.rider_id), 0), 1
                ) as retention_rate
            FROM rider_yearly_stats prev
            LEFT JOIN rider_yearly_stats curr
                ON prev.rider_id = curr.rider_id
                AND curr.season_year = prev.season_year + 1
            WHERE prev.season_year = ?
        ");
        $stmt->execute([$year - 1]);
        return (float)($stmt->fetchColumn() ?: 0);
    }

    /**
     * Berakna churn rate (andel som forsvann fran forra aret)
     *
     * @param int $year Ar att berakna for
     * @return float Churn rate i procent (0-100)
     */
    public function getChurnRate(int $year): float {
        return 100 - $this->getRetentionRate($year);
    }

    /**
     * Hamta antal nya riders (rookies)
     *
     * @param int $year Ar
     * @return int Antal nya riders
     */
    public function getNewRidersCount(int $year): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM rider_yearly_stats
            WHERE season_year = ? AND is_rookie = 1
        ");
        $stmt->execute([$year]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Hamta totalt antal aktiva riders
     *
     * @param int $year Ar
     * @return int Antal aktiva riders
     */
    public function getTotalActiveRiders(int $year): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM rider_yearly_stats
            WHERE season_year = ?
        ");
        $stmt->execute([$year]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Berakna tillvaxtrate jamfort med forriga aret
     *
     * @param int $year Ar
     * @return float Tillvaxt i procent (kan vara negativ)
     */
    public function getGrowthRate(int $year): float {
        $current = $this->getTotalActiveRiders($year);
        $previous = $this->getTotalActiveRiders($year - 1);

        if ($previous == 0) return 0;

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Hamta retained riders count
     *
     * @param int $year Ar
     * @return int Antal
     */
    public function getRetainedRidersCount(int $year): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM rider_yearly_stats
            WHERE season_year = ? AND is_retained = 1
        ");
        $stmt->execute([$year]);
        return (int)$stmt->fetchColumn();
    }

    // =========================================================================
    // DEMOGRAPHICS
    // =========================================================================

    /**
     * Berakna genomsnittsalder
     *
     * @param int $year Ar
     * @return float Genomsnittsalder
     */
    public function getAverageAge(int $year): float {
        $stmt = $this->pdo->prepare("
            SELECT AVG($year - r.birth_year) as avg_age
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE rys.season_year = ?
              AND r.birth_year IS NOT NULL
              AND r.birth_year > 1900
        ");
        $stmt->execute([$year]);
        return round((float)($stmt->fetchColumn() ?: 0), 1);
    }

    /**
     * Hamta konsfordelning
     *
     * @param int $year Ar
     * @return array ['M' => X, 'F' => Y, 'unknown' => Z]
     */
    public function getGenderDistribution(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(r.gender, 'unknown') as gender,
                COUNT(*) as count
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE rys.season_year = ?
            GROUP BY COALESCE(r.gender, 'unknown')
        ");
        $stmt->execute([$year]);

        $result = ['M' => 0, 'F' => 0, 'unknown' => 0];
        while ($row = $stmt->fetch()) {
            $g = $row['gender'];
            if ($g === 'M' || $g === 'male' || $g === 'man') {
                $result['M'] += $row['count'];
            } elseif ($g === 'F' || $g === 'female' || $g === 'kvinna' || $g === 'woman') {
                $result['F'] += $row['count'];
            } else {
                $result['unknown'] += $row['count'];
            }
        }
        return $result;
    }

    /**
     * Hamta aldersfordelning i grupper
     *
     * @param int $year Ar
     * @return array Aldersgrupper med antal
     */
    public function getAgeDistribution(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                CASE
                    WHEN $year - r.birth_year < 18 THEN 'Under 18'
                    WHEN $year - r.birth_year BETWEEN 18 AND 25 THEN '18-25'
                    WHEN $year - r.birth_year BETWEEN 26 AND 35 THEN '26-35'
                    WHEN $year - r.birth_year BETWEEN 36 AND 45 THEN '36-45'
                    WHEN $year - r.birth_year BETWEEN 46 AND 55 THEN '46-55'
                    WHEN $year - r.birth_year > 55 THEN 'Over 55'
                    ELSE 'Okand'
                END as age_group,
                COUNT(*) as count
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE rys.season_year = ?
              AND r.birth_year IS NOT NULL
              AND r.birth_year > 1900
            GROUP BY age_group
            ORDER BY
                CASE age_group
                    WHEN 'Under 18' THEN 1
                    WHEN '18-25' THEN 2
                    WHEN '26-35' THEN 3
                    WHEN '36-45' THEN 4
                    WHEN '46-55' THEN 5
                    WHEN 'Over 55' THEN 6
                    ELSE 7
                END
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta disciplinfordelning
     *
     * @param int $year Ar
     * @return array Discipliner med antal
     */
    public function getDisciplineDistribution(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(primary_discipline, 'Okand') as discipline,
                COUNT(*) as count
            FROM rider_yearly_stats
            WHERE season_year = ?
            GROUP BY primary_discipline
            ORDER BY count DESC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // SERIES FLOW (NYCKELFUNKTIONER)
    // =========================================================================

    /**
     * Berakna feeder conversion rate
     * Hur stor andel av deltagare i en regional serie gar vidare till en nationell?
     *
     * @param int $fromSeriesId Ursprungsserie (t.ex. Capital Enduro)
     * @param int $toSeriesId Malserie (t.ex. ESS)
     * @param int $year Ar
     * @return float Conversion rate i procent
     */
    public function getFeederConversionRate(int $fromSeriesId, int $toSeriesId, int $year): float {
        // Antal i from-serie
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT rider_id) FROM series_participation
            WHERE series_id = ? AND season_year = ?
        ");
        $stmt->execute([$fromSeriesId, $year]);
        $fromCount = (int)$stmt->fetchColumn();

        if ($fromCount == 0) return 0;

        // Antal som ocksa deltog i to-serie
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT sp1.rider_id)
            FROM series_participation sp1
            JOIN series_participation sp2
                ON sp1.rider_id = sp2.rider_id
                AND sp2.series_id = ?
                AND sp2.season_year = sp1.season_year
            WHERE sp1.series_id = ?
              AND sp1.season_year = ?
        ");
        $stmt->execute([$toSeriesId, $fromSeriesId, $year]);
        $convertedCount = (int)$stmt->fetchColumn();

        return round($convertedCount / $fromCount * 100, 1);
    }

    /**
     * Berakna cross-participation rate
     * Andel riders som deltar i mer an en serie
     *
     * @param int $year Ar
     * @return float Rate i procent
     */
    public function getCrossParticipationRate(int $year): float {
        // Total aktiva
        $total = $this->getTotalActiveRiders($year);
        if ($total == 0) return 0;

        // Riders med mer an 1 serie
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT rider_id)
            FROM (
                SELECT rider_id, COUNT(DISTINCT series_id) as series_count
                FROM series_participation
                WHERE season_year = ?
                GROUP BY rider_id
                HAVING series_count > 1
            ) as multi_series
        ");
        $stmt->execute([$year]);
        $multiCount = (int)$stmt->fetchColumn();

        return round($multiCount / $total * 100, 1);
    }

    /**
     * Berakna serielojalitet
     * Andel som aterkom till samma serie fran forra aret
     *
     * @param int $seriesId Serie
     * @param int $year Ar
     * @return float Lojalitet i procent
     */
    public function getSeriesLoyaltyRate(int $seriesId, int $year): float {
        // Deltagare forra aret
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT rider_id) FROM series_participation
            WHERE series_id = ? AND season_year = ?
        ");
        $stmt->execute([$seriesId, $year - 1]);
        $prevCount = (int)$stmt->fetchColumn();

        if ($prevCount == 0) return 0;

        // Som ocksa deltog detta ar
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT sp1.rider_id)
            FROM series_participation sp1
            JOIN series_participation sp2
                ON sp1.rider_id = sp2.rider_id
                AND sp2.series_id = sp1.series_id
                AND sp2.season_year = sp1.season_year + 1
            WHERE sp1.series_id = ?
              AND sp1.season_year = ?
        ");
        $stmt->execute([$seriesId, $year - 1]);
        $returnedCount = (int)$stmt->fetchColumn();

        return round($returnedCount / $prevCount * 100, 1);
    }

    /**
     * Berakna exklusivitet for en serie
     * Andel som ENDAST deltar i denna serie (ej andra serier)
     *
     * @param int $seriesId Serie
     * @param int $year Ar
     * @return float Exklusivitet i procent
     */
    public function getExclusivityRate(int $seriesId, int $year): float {
        // Total i serien
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT rider_id) FROM series_participation
            WHERE series_id = ? AND season_year = ?
        ");
        $stmt->execute([$seriesId, $year]);
        $totalInSeries = (int)$stmt->fetchColumn();

        if ($totalInSeries == 0) return 0;

        // Endast denna serie
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT sp1.rider_id)
            FROM series_participation sp1
            WHERE sp1.series_id = ?
              AND sp1.season_year = ?
              AND NOT EXISTS (
                  SELECT 1 FROM series_participation sp2
                  WHERE sp2.rider_id = sp1.rider_id
                    AND sp2.season_year = sp1.season_year
                    AND sp2.series_id != sp1.series_id
              )
        ");
        $stmt->execute([$seriesId, $year]);
        $exclusiveCount = (int)$stmt->fetchColumn();

        return round($exclusiveCount / $totalInSeries * 100, 1);
    }

    /**
     * Hamta entry point distribution
     * Vilken serie borjar nya riders i?
     *
     * @param int $year Ar
     * @return array Serie-fordelning for rookies
     */
    public function getEntryPointDistribution(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id as series_id,
                s.name as series_name,
                COALESCE(s.series_level, 'unknown') as series_level,
                COUNT(DISTINCT sp.rider_id) as rider_count
            FROM series_participation sp
            JOIN series s ON sp.series_id = s.id
            WHERE sp.is_entry_series = 1
              AND sp.season_year = ?
            GROUP BY s.id
            ORDER BY rider_count DESC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Berakna feeder matrix
     * Visar flodet mellan alla serier
     *
     * @param int $year Ar
     * @return array Matrix med from/to/count
     */
    public function calculateFeederMatrix(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                sc.from_series_id,
                s_from.name as from_name,
                COALESCE(s_from.series_level, 'unknown') as from_level,
                sc.to_series_id,
                s_to.name as to_name,
                COALESCE(s_to.series_level, 'unknown') as to_level,
                COUNT(DISTINCT sc.rider_id) as flow_count
            FROM series_crossover sc
            JOIN series s_from ON sc.from_series_id = s_from.id
            JOIN series s_to ON sc.to_series_id = s_to.id
            WHERE sc.from_year = ?
              AND sc.crossover_type = 'same_year'
            GROUP BY sc.from_series_id, sc.to_series_id
            ORDER BY flow_count DESC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta overlapp mellan tva serier
     *
     * @param int $series1 Forsta serie
     * @param int $series2 Andra serie
     * @param int $year Ar
     * @return array [both, only_1, only_2, overlap_pct]
     */
    public function getSeriesOverlap(int $series1, int $series2, int $year): array {
        // Deltagare i serie 1
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT rider_id FROM series_participation
            WHERE series_id = ? AND season_year = ?
        ");
        $stmt->execute([$series1, $year]);
        $set1 = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Deltagare i serie 2
        $stmt->execute([$series2, $year]);
        $set2 = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $both = array_intersect($set1, $set2);
        $only1 = array_diff($set1, $set2);
        $only2 = array_diff($set2, $set1);

        $total = count(array_unique(array_merge($set1, $set2)));

        return [
            'both' => count($both),
            'only_series_1' => count($only1),
            'only_series_2' => count($only2),
            'overlap_percentage' => $total > 0 ? round(count($both) / $total * 100, 1) : 0
        ];
    }

    // =========================================================================
    // CLUB
    // =========================================================================

    /**
     * Hamta top klubbar efter aktiva riders
     *
     * @param int $year Ar
     * @param int $limit Max antal
     * @return array Top klubbar
     */
    public function getTopClubs(int $year, int $limit = 10): array {
        $stmt = $this->pdo->prepare("
            SELECT
                cys.club_id,
                c.name as club_name,
                c.city,
                cys.active_riders,
                cys.total_points,
                cys.wins,
                cys.podiums
            FROM club_yearly_stats cys
            JOIN clubs c ON cys.club_id = c.id
            WHERE cys.season_year = ?
            ORDER BY cys.active_riders DESC
            LIMIT ?
        ");
        $stmt->execute([$year, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta klubbtillvaxt over tid
     *
     * @param int $clubId Klubb
     * @param int $years Antal ar bakot
     * @return array Arlig statistik
     */
    public function getClubGrowth(int $clubId, int $years = 5): array {
        $stmt = $this->pdo->prepare("
            SELECT
                season_year,
                active_riders,
                new_riders,
                retained_riders,
                churned_riders
            FROM club_yearly_stats
            WHERE club_id = ?
            ORDER BY season_year DESC
            LIMIT ?
        ");
        $stmt->execute([$clubId, $years]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // GEOGRAPHIC
    // =========================================================================

    /**
     * Hamta riders per region (baserat pa serie-region)
     *
     * @param int $year Ar
     * @return array Regioner med antal
     */
    public function getRidersByRegion(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(s.region, 'Okand') as region,
                COUNT(DISTINCT sp.rider_id) as rider_count
            FROM series_participation sp
            JOIN series s ON sp.series_id = s.id
            WHERE sp.season_year = ?
            GROUP BY s.region
            ORDER BY rider_count DESC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // TREND-DATA
    // =========================================================================

    /**
     * Hamta tillvaxttrend over tid
     *
     * @param int $years Antal ar
     * @return array Arlig data
     */
    public function getGrowthTrend(int $years = 5): array {
        $stmt = $this->pdo->prepare("
            SELECT
                season_year,
                COUNT(*) as total_riders,
                SUM(is_rookie) as new_riders,
                SUM(is_retained) as retained_riders
            FROM rider_yearly_stats
            GROUP BY season_year
            ORDER BY season_year DESC
            LIMIT ?
        ");
        $stmt->execute([$years]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Hamta retention trend over tid
     *
     * @param int $years Antal ar
     * @return array Arlig retention rate
     */
    public function getRetentionTrend(int $years = 5): array {
        $currentYear = (int)date('Y');
        $result = [];

        for ($y = $currentYear - $years + 1; $y <= $currentYear; $y++) {
            $result[] = [
                'year' => $y,
                'retention_rate' => $this->getRetentionRate($y)
            ];
        }

        return $result;
    }

    // =========================================================================
    // SAMMANFATTNING
    // =========================================================================

    /**
     * Hamta alla nyckeltal for ett ar (for dashboard)
     *
     * @param int $year Ar
     * @return array Alla KPIs
     */
    public function getAllKPIs(int $year): array {
        return [
            'total_riders' => $this->getTotalActiveRiders($year),
            'new_riders' => $this->getNewRidersCount($year),
            'retained_riders' => $this->getRetainedRidersCount($year),
            'retention_rate' => $this->getRetentionRate($year),
            'churn_rate' => $this->getChurnRate($year),
            'growth_rate' => $this->getGrowthRate($year),
            'cross_participation_rate' => $this->getCrossParticipationRate($year),
            'average_age' => $this->getAverageAge($year),
            'gender_distribution' => $this->getGenderDistribution($year)
        ];
    }

    /**
     * Jamfor tva ar
     *
     * @param int $year1 Forsta ar
     * @param int $year2 Andra ar
     * @return array Jamforelsedata
     */
    public function compareYears(int $year1, int $year2): array {
        $kpi1 = $this->getAllKPIs($year1);
        $kpi2 = $this->getAllKPIs($year2);

        $result = [];
        foreach ($kpi1 as $key => $value1) {
            $value2 = $kpi2[$key] ?? null;

            if (is_numeric($value1) && is_numeric($value2)) {
                $diff = $value2 - $value1;
                $diffPct = $value1 != 0 ? round($diff / $value1 * 100, 1) : 0;
                $result[$key] = [
                    'year1' => $value1,
                    'year2' => $value2,
                    'difference' => $diff,
                    'difference_pct' => $diffPct,
                    'trend' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'stable')
                ];
            } else {
                $result[$key] = [
                    'year1' => $value1,
                    'year2' => $value2
                ];
            }
        }

        return $result;
    }
}
