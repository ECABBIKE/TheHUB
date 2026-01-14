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
    // UTILITY METHODS
    // =========================================================================

    /**
     * Hamta senaste sasong med data (avslutad sasong)
     *
     * Returnerar det senaste aret som har rider_yearly_stats data.
     * Anvands for att undvika att rakna pagaende sasong som "inaktiv".
     *
     * @return int Senaste ar med data, eller foregaende ar om inget hittas
     */
    public function getLatestSeasonYear(): int {
        $stmt = $this->pdo->query("
            SELECT MAX(season_year) FROM rider_yearly_stats
        ");
        $result = $stmt->fetchColumn();

        if ($result) {
            return (int)$result;
        }

        // Fallback till foregaende ar om ingen data finns
        return (int)date('Y') - 1;
    }

    /**
     * Hamta senaste sasong med tillracklig data
     *
     * Returnerar det senaste aret som har minst X antal riders.
     * Anvands for att sakerstalla att vi har meningsfull data.
     *
     * @param int $minRiders Minimum antal riders for att raknas
     * @return int Senaste ar med tillracklig data
     */
    public function getLatestCompleteSeasonYear(int $minRiders = 100): int {
        $stmt = $this->pdo->prepare("
            SELECT season_year
            FROM rider_yearly_stats
            GROUP BY season_year
            HAVING COUNT(*) >= ?
            ORDER BY season_year DESC
            LIMIT 1
        ");
        $stmt->execute([$minRiders]);
        $result = $stmt->fetchColumn();

        return $result ? (int)$result : $this->getLatestSeasonYear();
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
                    WHEN $year - r.birth_year <= 12 THEN '5-12'
                    WHEN $year - r.birth_year BETWEEN 13 AND 14 THEN '13-14'
                    WHEN $year - r.birth_year BETWEEN 15 AND 16 THEN '15-16'
                    WHEN $year - r.birth_year BETWEEN 17 AND 18 THEN '17-18'
                    WHEN $year - r.birth_year BETWEEN 19 AND 30 THEN '19-30'
                    WHEN $year - r.birth_year BETWEEN 31 AND 35 THEN '31-35'
                    WHEN $year - r.birth_year BETWEEN 36 AND 45 THEN '36-45'
                    WHEN $year - r.birth_year BETWEEN 46 AND 50 THEN '46-50'
                    WHEN $year - r.birth_year > 50 THEN '50+'
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
                    WHEN '5-12' THEN 1
                    WHEN '13-14' THEN 2
                    WHEN '15-16' THEN 3
                    WHEN '17-18' THEN 4
                    WHEN '19-30' THEN 5
                    WHEN '31-35' THEN 6
                    WHEN '36-45' THEN 7
                    WHEN '46-50' THEN 8
                    WHEN '50+' THEN 9
                    ELSE 10
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
    /**
     * Hamta disciplinfordelning baserat pa primary_discipline
     * OBS: Detta visar EN disciplin per rider (den de deltagit i mest)
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

    /**
     * Hamta faktiskt deltagande per disciplin
     * Visar hur manga unika deltagare som deltagit i varje disciplin,
     * baserat pa faktiska resultat (inte primary_discipline).
     * En person kan raknas i flera discipliner om de deltagit i flera.
     */
    public function getDisciplineParticipation(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(e.discipline, 'Okand') as discipline,
                COUNT(DISTINCT res.cyclist_id) as unique_riders,
                COUNT(*) as total_starts
            FROM results res
            JOIN events e ON res.event_id = e.id
            WHERE YEAR(e.date) = ?
            GROUP BY e.discipline
            ORDER BY unique_riders DESC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // ROOKIE ANALYSIS
    // =========================================================================

    /**
     * Hamta aldersfordelning for rookies
     *
     * @param int $year Ar
     * @return array Aldersgrupper med antal
     */
    public function getRookieAgeDistribution(int $year, ?int $seriesId = null): array {
        $seriesFilter = $seriesId !== null ? " AND rys.primary_series_id = ?" : "";
        $stmt = $this->pdo->prepare("
            SELECT
                CASE
                    WHEN $year - r.birth_year <= 12 THEN '5-12'
                    WHEN $year - r.birth_year BETWEEN 13 AND 14 THEN '13-14'
                    WHEN $year - r.birth_year BETWEEN 15 AND 16 THEN '15-16'
                    WHEN $year - r.birth_year BETWEEN 17 AND 18 THEN '17-18'
                    WHEN $year - r.birth_year BETWEEN 19 AND 30 THEN '19-30'
                    WHEN $year - r.birth_year BETWEEN 31 AND 35 THEN '31-35'
                    WHEN $year - r.birth_year BETWEEN 36 AND 45 THEN '36-45'
                    WHEN $year - r.birth_year BETWEEN 46 AND 50 THEN '46-50'
                    WHEN $year - r.birth_year > 50 THEN '50+'
                    ELSE 'Okand'
                END as age_group,
                COUNT(*) as count
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE rys.season_year = ?
              AND rys.is_rookie = 1
              AND r.birth_year IS NOT NULL
              AND r.birth_year > 1900
              $seriesFilter
            GROUP BY age_group
            ORDER BY
                CASE age_group
                    WHEN '5-12' THEN 1
                    WHEN '13-14' THEN 2
                    WHEN '15-16' THEN 3
                    WHEN '17-18' THEN 4
                    WHEN '19-30' THEN 5
                    WHEN '31-35' THEN 6
                    WHEN '36-45' THEN 7
                    WHEN '46-50' THEN 8
                    WHEN '50+' THEN 9
                    ELSE 10
                END
        ");
        $params = [$year];
        if ($seriesId !== null) $params[] = $seriesId;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta klassfordelning for rookies
     * Vilka klasser startar nya deltagare i?
     *
     * @param int $year Ar
     * @return array Klasser med antal
     */
    public function getRookieClassDistribution(int $year, ?int $seriesId = null): array {
        $seriesFilter = $seriesId !== null ? " AND e.series_id = ?" : "";
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(c.name, 'Okand klass') as class_name,
                COUNT(DISTINCT res.cyclist_id) as rookie_count
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN rider_yearly_stats rys ON res.cyclist_id = rys.rider_id AND rys.season_year = ?
            LEFT JOIN classes c ON res.class_id = c.id
            WHERE YEAR(e.date) = ?
              AND rys.is_rookie = 1
              $seriesFilter
            GROUP BY res.class_id, c.name
            ORDER BY rookie_count DESC
        ");
        $params = [$year, $year];
        if ($seriesId !== null) $params[] = $seriesId;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta events med flest rookies
     *
     * @param int $year Ar
     * @param int $limit Max antal events
     * @return array Events med rookie-antal
     */
    public function getEventsWithMostRookies(int $year, int $limit = 20, ?int $seriesId = null): array {
        $seriesFilter = $seriesId !== null ? " AND e.series_id = ?" : "";
        $stmt = $this->pdo->prepare("
            SELECT
                e.id as event_id,
                e.name as event_name,
                e.date as event_date,
                s.name as series_name,
                COUNT(DISTINCT res.cyclist_id) as total_participants,
                COUNT(DISTINCT CASE WHEN rys.is_rookie = 1 THEN res.cyclist_id END) as rookie_count,
                ROUND(
                    COUNT(DISTINCT CASE WHEN rys.is_rookie = 1 THEN res.cyclist_id END) * 100.0 /
                    NULLIF(COUNT(DISTINCT res.cyclist_id), 0), 1
                ) as rookie_percentage
            FROM events e
            JOIN results res ON res.event_id = e.id
            LEFT JOIN series s ON e.series_id = s.id
            LEFT JOIN rider_yearly_stats rys ON res.cyclist_id = rys.rider_id AND rys.season_year = ?
            WHERE YEAR(e.date) = ?
            $seriesFilter
            GROUP BY e.id
            HAVING rookie_count > 0
            ORDER BY rookie_count DESC
            LIMIT ?
        ");
        $params = [$year, $year];
        if ($seriesId !== null) $params[] = $seriesId;
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta komplett lista over alla rookies
     * Med detaljer for profillankningar
     *
     * @param int $year Ar
     * @param int|null $seriesId Filtrera pa serie (optional)
     * @return array Lista med rookies
     */
    public function getRookiesList(int $year, ?int $seriesId = null): array {
        $sql = "
            SELECT
                r.id as rider_id,
                r.firstname,
                r.lastname,
                CASE
                    WHEN r.birth_year IS NOT NULL AND r.birth_year > 1900
                    THEN $year - r.birth_year
                    ELSE NULL
                END as age,
                r.birth_year,
                r.gender,
                c.id as club_id,
                c.name as club_name,
                rys.total_events,
                rys.total_points,
                rys.best_position,
                rys.primary_discipline,
                rys.primary_series_id,
                s.name as series_name
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN series s ON rys.primary_series_id = s.id
            WHERE rys.season_year = ?
              AND rys.is_rookie = 1
        ";
        $params = [$year];

        if ($seriesId !== null) {
            $sql .= " AND rys.primary_series_id = ?";
            $params[] = $seriesId;
        }

        $sql .= " ORDER BY r.lastname, r.firstname";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta serier som har rookies for ett ar
     *
     * @param int $year Ar
     * @return array Serier med rookie-antal
     */
    public function getSeriesWithRookies(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id,
                s.name,
                COUNT(*) as rookie_count
            FROM rider_yearly_stats rys
            JOIN series s ON rys.primary_series_id = s.id
            WHERE rys.season_year = ?
              AND rys.is_rookie = 1
              AND rys.primary_series_id IS NOT NULL
            GROUP BY s.id, s.name
            ORDER BY rookie_count DESC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta rookie-deltagande per disciplin (baserat pa faktiska starter)
     * Till skillnad fran primary_discipline som bara visar EN disciplin per rider,
     * visar denna hur manga rookies som faktiskt deltagit i varje disciplin.
     *
     * @param int $year Ar
     * @return array Discipliner med antal rookies och starter
     */
    public function getRookieDisciplineParticipation(int $year, ?int $seriesId = null): array {
        $seriesFilter = $seriesId !== null ? " AND e.series_id = ?" : "";
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(e.discipline, 'Okand') as discipline,
                COUNT(DISTINCT res.cyclist_id) as rookie_count,
                COUNT(*) as total_starts
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN rider_yearly_stats rys ON res.cyclist_id = rys.rider_id AND rys.season_year = ?
            WHERE YEAR(e.date) = ?
              AND rys.is_rookie = 1
              $seriesFilter
            GROUP BY e.discipline
            ORDER BY rookie_count DESC
        ");
        $params = [$year, $year];
        if ($seriesId !== null) $params[] = $seriesId;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta rookie-trend over flera ar
     * Visar antal nya deltagare, totalt deltagare, och andel per ar
     *
     * @param int $years Antal ar att visa
     * @return array Trenddata per ar
     */
    public function getRookieTrend(int $years = 5): array {
        $currentYear = (int)date('Y');
        $startYear = $currentYear - $years + 1;

        $stmt = $this->pdo->prepare("
            SELECT
                rys.season_year as year,
                COUNT(*) as total_riders,
                SUM(CASE WHEN rys.is_rookie = 1 THEN 1 ELSE 0 END) as rookie_count,
                ROUND(
                    SUM(CASE WHEN rys.is_rookie = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0),
                    1
                ) as rookie_percentage
            FROM rider_yearly_stats rys
            WHERE rys.season_year >= ? AND rys.season_year <= ?
            GROUP BY rys.season_year
            ORDER BY rys.season_year ASC
        ");
        $stmt->execute([$startYear, $currentYear]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta genomsnittsalder for rookies per ar (trend)
     *
     * @param int $years Antal ar
     * @return array Snittålder per ar
     */
    public function getRookieAgeTrend(int $years = 5): array {
        $currentYear = (int)date('Y');
        $startYear = $currentYear - $years + 1;

        $stmt = $this->pdo->prepare("
            SELECT
                rys.season_year as year,
                ROUND(AVG(rys.season_year - r.birth_year), 1) as avg_age,
                COUNT(*) as rookie_count
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE rys.season_year >= ? AND rys.season_year <= ?
              AND rys.is_rookie = 1
              AND r.birth_year IS NOT NULL
              AND r.birth_year > 1900
            GROUP BY rys.season_year
            ORDER BY rys.season_year ASC
        ");
        $stmt->execute([$startYear, $currentYear]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta genomsnittsalder for rookies
     *
     * @param int $year Ar
     * @return float Genomsnittsalder
     */
    public function getRookieAverageAge(int $year, ?int $seriesId = null): float {
        $seriesFilter = $seriesId !== null ? " AND rys.primary_series_id = ?" : "";
        $stmt = $this->pdo->prepare("
            SELECT AVG($year - r.birth_year) as avg_age
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE rys.season_year = ?
              AND rys.is_rookie = 1
              AND r.birth_year IS NOT NULL
              AND r.birth_year > 1900
              $seriesFilter
        ");
        $params = [$year];
        if ($seriesId !== null) $params[] = $seriesId;
        $stmt->execute($params);
        return round((float)($stmt->fetchColumn() ?: 0), 1);
    }

    /**
     * Hamta konsfordelning for rookies
     *
     * @param int $year Ar
     * @return array ['M' => X, 'F' => Y, 'unknown' => Z]
     */
    public function getRookieGenderDistribution(int $year, ?int $seriesId = null): array {
        $seriesFilter = $seriesId !== null ? " AND rys.primary_series_id = ?" : "";
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(r.gender, 'unknown') as gender,
                COUNT(*) as count
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE rys.season_year = ?
              AND rys.is_rookie = 1
              $seriesFilter
            GROUP BY COALESCE(r.gender, 'unknown')
        ");
        $params = [$year];
        if ($seriesId !== null) $params[] = $seriesId;
        $stmt->execute($params);

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
     * Hamta klubbar med flest rookies
     *
     * @param int $year Ar
     * @param int $limit Max antal
     * @return array Klubbar med rookie-antal
     */
    public function getClubsWithMostRookies(int $year, int $limit = 20, ?int $seriesId = null): array {
        $seriesFilter = $seriesId !== null ? " AND rys.primary_series_id = ?" : "";
        $stmt = $this->pdo->prepare("
            SELECT
                c.id as club_id,
                c.name as club_name,
                c.city,
                COUNT(*) as rookie_count
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            JOIN clubs c ON r.club_id = c.id
            WHERE rys.season_year = ?
              AND rys.is_rookie = 1
              $seriesFilter
            GROUP BY c.id
            ORDER BY rookie_count DESC
            LIMIT ?
        ");
        $params = [$year];
        if ($seriesId !== null) $params[] = $seriesId;
        $params[] = $limit;
        $stmt->execute($params);
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
        // Anvand klubbens region, alternativt ryttarens district
        // Fallback till 'Okand' om inget finns
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(
                    NULLIF(c.region, ''),
                    NULLIF(r.district, ''),
                    'Okand'
                ) as region,
                COUNT(DISTINCT rys.rider_id) as rider_count
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE rys.season_year = ?
            GROUP BY region
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

    // =========================================================================
    // CHURN & RETENTION DEEP ANALYSIS
    // =========================================================================

    /**
     * Hamta deltagare som slutat (churned) - deltog forra aret men inte i ar
     *
     * @param int $year Aktuellt ar
     * @param int $limit Max antal att hamta
     * @return array Lista med churned riders
     */
    public function getChurnedRiders(int $year, int $limit = 500): array {
        $stmt = $this->pdo->prepare("
            SELECT
                r.id,
                r.firstname,
                r.lastname,
                r.birth_year,
                r.gender,
                c.name as club_name,
                prev.total_events as last_year_events,
                prev.total_points as last_year_points,
                prev.primary_discipline as last_discipline,
                :year1 - r.birth_year as age,
                (SELECT MIN(rys2.season_year) FROM rider_yearly_stats rys2 WHERE rys2.rider_id = r.id) as first_season,
                (SELECT MAX(rys2.season_year) FROM rider_yearly_stats rys2 WHERE rys2.rider_id = r.id) as last_season,
                (SELECT COUNT(DISTINCT rys2.season_year) FROM rider_yearly_stats rys2 WHERE rys2.rider_id = r.id) as total_seasons
            FROM rider_yearly_stats prev
            JOIN riders r ON prev.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN rider_yearly_stats curr
                ON prev.rider_id = curr.rider_id
                AND curr.season_year = :year2
            WHERE prev.season_year = :prevyear
              AND curr.rider_id IS NULL
            ORDER BY prev.total_events DESC, prev.total_points DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':year1', $year, PDO::PARAM_INT);
        $stmt->bindValue(':year2', $year, PDO::PARAM_INT);
        $stmt->bindValue(':prevyear', $year - 1, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta "one-timers" - deltagare som bara startade 1-2 ggr totalt
     *
     * @param int $year Ar att analysera
     * @param int $maxStarts Max antal starter (default 2)
     * @return array Lista med one-time deltagare
     */
    public function getOneTimers(int $year, int $maxStarts = 2): array {
        $stmt = $this->pdo->prepare("
            SELECT
                r.id,
                r.firstname,
                r.lastname,
                r.birth_year,
                r.gender,
                c.name as club_name,
                :year1 - r.birth_year as age,
                total.total_events,
                total.first_season,
                total.last_season,
                total.disciplines
            FROM (
                SELECT
                    rider_id,
                    SUM(total_events) as total_events,
                    MIN(season_year) as first_season,
                    MAX(season_year) as last_season,
                    GROUP_CONCAT(DISTINCT primary_discipline) as disciplines
                FROM rider_yearly_stats
                GROUP BY rider_id
                HAVING SUM(total_events) <= :maxstarts
            ) total
            JOIN riders r ON total.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE total.last_season <= :year2
            ORDER BY total.last_season DESC, total.total_events DESC
        ");
        $stmt->bindValue(':year1', $year, PDO::PARAM_INT);
        $stmt->bindValue(':year2', $year, PDO::PARAM_INT);
        $stmt->bindValue(':maxstarts', $maxStarts, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta comeback-riders - de som aterkom efter uppehall
     *
     * @param int $year Ar att analysera
     * @param int $minGapYears Minsta antal ars uppehall (default 1)
     * @return array Lista med comeback riders
     */
    public function getComebackRiders(int $year, int $minGapYears = 1): array {
        // Positional params - PDO tillater inte ateranvandning av named params
        $stmt = $this->pdo->prepare("
            SELECT
                r.id,
                r.firstname,
                r.lastname,
                r.birth_year,
                r.gender,
                c.name as club_name,
                ? - r.birth_year as age,
                curr.total_events as current_events,
                curr.primary_discipline,
                prev_max.last_active_before as previous_last_season,
                ? - prev_max.last_active_before as years_away,
                (SELECT MIN(season_year) FROM rider_yearly_stats WHERE rider_id = r.id) as first_season_ever
            FROM rider_yearly_stats curr
            JOIN riders r ON curr.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            JOIN (
                SELECT
                    rider_id,
                    MAX(season_year) as last_active_before
                FROM rider_yearly_stats
                WHERE season_year < ?
                GROUP BY rider_id
                HAVING MAX(season_year) < ? - ?
            ) prev_max ON curr.rider_id = prev_max.rider_id
            WHERE curr.season_year = ?
            ORDER BY years_away DESC, curr.total_events DESC
        ");
        $stmt->execute([$year, $year, $year, $year, $minGapYears, $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta inaktiva riders grupperade efter hur lange de varit borta
     *
     * @param int $year Aktuellt ar
     * @return array Gruppering per antal ar inaktiv
     */
    public function getInactiveByDuration(int $year): array {
        // Positional params - PDO tillater inte ateranvandning av named params
        $stmt = $this->pdo->prepare("
            SELECT
                ? - last_active.last_season as years_inactive,
                COUNT(*) as count,
                AVG(? - r.birth_year) as avg_age
            FROM (
                SELECT
                    rider_id,
                    MAX(season_year) as last_season
                FROM rider_yearly_stats
                WHERE season_year < ?
                GROUP BY rider_id
            ) last_active
            JOIN riders r ON last_active.rider_id = r.id
            LEFT JOIN rider_yearly_stats curr
                ON last_active.rider_id = curr.rider_id
                AND curr.season_year = ?
            WHERE curr.rider_id IS NULL
            GROUP BY years_inactive
            ORDER BY years_inactive ASC
        ");
        $stmt->execute([$year, $year, $year, $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Analysera churn per segment (alder, disciplin, klubb)
     *
     * @param int $year Ar att analysera
     * @return array Segmenterad churn-data
     */
    public function getChurnBySegment(int $year): array {
        $prevYear = $year - 1;

        // Churn per aldersgrupp - positional params for PDO compatibility
        $ageStmt = $this->pdo->prepare("
            SELECT
                CASE
                    WHEN ? - r.birth_year <= 12 THEN '5-12'
                    WHEN ? - r.birth_year BETWEEN 13 AND 14 THEN '13-14'
                    WHEN ? - r.birth_year BETWEEN 15 AND 16 THEN '15-16'
                    WHEN ? - r.birth_year BETWEEN 17 AND 18 THEN '17-18'
                    WHEN ? - r.birth_year BETWEEN 19 AND 30 THEN '19-30'
                    WHEN ? - r.birth_year BETWEEN 31 AND 35 THEN '31-35'
                    WHEN ? - r.birth_year BETWEEN 36 AND 45 THEN '36-45'
                    WHEN ? - r.birth_year BETWEEN 46 AND 50 THEN '46-50'
                    WHEN ? - r.birth_year > 50 THEN '50+'
                    ELSE 'Okand'
                END as age_group,
                COUNT(*) as churned_count,
                (SELECT COUNT(*) FROM rider_yearly_stats rys2
                 JOIN riders r2 ON rys2.rider_id = r2.id
                 WHERE rys2.season_year = ?
                 AND CASE
                    WHEN ? - r2.birth_year <= 12 THEN '5-12'
                    WHEN ? - r2.birth_year BETWEEN 13 AND 14 THEN '13-14'
                    WHEN ? - r2.birth_year BETWEEN 15 AND 16 THEN '15-16'
                    WHEN ? - r2.birth_year BETWEEN 17 AND 18 THEN '17-18'
                    WHEN ? - r2.birth_year BETWEEN 19 AND 30 THEN '19-30'
                    WHEN ? - r2.birth_year BETWEEN 31 AND 35 THEN '31-35'
                    WHEN ? - r2.birth_year BETWEEN 36 AND 45 THEN '36-45'
                    WHEN ? - r2.birth_year BETWEEN 46 AND 50 THEN '46-50'
                    WHEN ? - r2.birth_year > 50 THEN '50+'
                    ELSE 'Okand'
                 END = age_group
                ) as total_in_group
            FROM rider_yearly_stats prev
            JOIN riders r ON prev.rider_id = r.id
            LEFT JOIN rider_yearly_stats curr
                ON prev.rider_id = curr.rider_id
                AND curr.season_year = ?
            WHERE prev.season_year = ?
              AND curr.rider_id IS NULL
            GROUP BY age_group
            ORDER BY churned_count DESC
        ");
        $ageStmt->execute([
            $year, $year, $year, $year, $year, $year, $year, $year, $year,  // outer CASE (9)
            $prevYear,                                                       // subquery year
            $year, $year, $year, $year, $year, $year, $year, $year, $year,  // subquery CASE (9)
            $year,                                                           // curr.season_year
            $prevYear                                                        // prev.season_year
        ]);
        $byAge = $ageStmt->fetchAll(PDO::FETCH_ASSOC);

        // Berakna churn rate per grupp
        foreach ($byAge as &$row) {
            $row['churn_rate'] = $row['total_in_group'] > 0
                ? round($row['churned_count'] / $row['total_in_group'] * 100, 1)
                : 0;
        }

        // Churn per disciplin
        $discStmt = $this->pdo->prepare("
            SELECT
                COALESCE(prev.primary_discipline, 'Okand') as discipline,
                COUNT(*) as churned_count
            FROM rider_yearly_stats prev
            LEFT JOIN rider_yearly_stats curr
                ON prev.rider_id = curr.rider_id
                AND curr.season_year = ?
            WHERE prev.season_year = ?
              AND curr.rider_id IS NULL
            GROUP BY prev.primary_discipline
            ORDER BY churned_count DESC
        ");
        $discStmt->execute([$year, $prevYear]);
        $byDiscipline = $discStmt->fetchAll(PDO::FETCH_ASSOC);

        // Top 10 klubbar med flest churned
        $clubStmt = $this->pdo->prepare("
            SELECT
                COALESCE(c.name, 'Ingen klubb') as club_name,
                COUNT(*) as churned_count
            FROM rider_yearly_stats prev
            JOIN riders r ON prev.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN rider_yearly_stats curr
                ON prev.rider_id = curr.rider_id
                AND curr.season_year = ?
            WHERE prev.season_year = ?
              AND curr.rider_id IS NULL
            GROUP BY c.id, c.name
            ORDER BY churned_count DESC
            LIMIT 10
        ");
        $clubStmt->execute([$year, $prevYear]);
        $byClub = $clubStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'by_age' => $byAge,
            'by_discipline' => $byDiscipline,
            'by_club' => $byClub
        ];
    }

    /**
     * Hamta sammanfattning av inaktiva/churned for att na ut till
     *
     * @param int $year Aktuellt ar
     * @return array Sammanfattande statistik
     */
    public function getChurnSummary(int $year): array {
        // Antal som slutat (forra aret men inte i ar)
        $churnedStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM rider_yearly_stats prev
            LEFT JOIN rider_yearly_stats curr
                ON prev.rider_id = curr.rider_id
                AND curr.season_year = :year
            WHERE prev.season_year = :prevyear
              AND curr.rider_id IS NULL
        ");
        $churnedStmt->bindValue(':year', $year, PDO::PARAM_INT);
        $churnedStmt->bindValue(':prevyear', $year - 1, PDO::PARAM_INT);
        $churnedStmt->execute();
        $churnedLastYear = (int)$churnedStmt->fetchColumn();

        // One-timers totalt
        $oneTimersStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM (
                SELECT rider_id
                FROM rider_yearly_stats
                GROUP BY rider_id
                HAVING SUM(total_events) <= 2
            ) t
        ");
        $oneTimersStmt->execute();
        $oneTimersTotal = (int)$oneTimersStmt->fetchColumn();

        // Comebacks i ar (positional params - PDO tillater inte ateranvandning av named params)
        $comebacksStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM rider_yearly_stats curr
            JOIN (
                SELECT rider_id, MAX(season_year) as last_active
                FROM rider_yearly_stats
                WHERE season_year < ?
                GROUP BY rider_id
                HAVING MAX(season_year) < ? - 1
            ) prev ON curr.rider_id = prev.rider_id
            WHERE curr.season_year = ?
        ");
        $comebacksStmt->execute([$year, $year, $year]);
        $comebacksThisYear = (int)$comebacksStmt->fetchColumn();

        // Inaktiva 2+ ar (ej i ar, senast aktiva for 2+ ar sedan)
        $longInactiveStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM (
                SELECT rider_id, MAX(season_year) as last_season
                FROM rider_yearly_stats
                GROUP BY rider_id
                HAVING MAX(season_year) < ? - 1
            ) inactive
            LEFT JOIN rider_yearly_stats curr
                ON inactive.rider_id = curr.rider_id
                AND curr.season_year = ?
            WHERE curr.rider_id IS NULL
        ");
        $longInactiveStmt->execute([$year, $year]);
        $longInactive = (int)$longInactiveStmt->fetchColumn();

        return [
            'churned_last_year' => $churnedLastYear,
            'one_timers_total' => $oneTimersTotal,
            'comebacks_this_year' => $comebacksThisYear,
            'inactive_2plus_years' => $longInactive,
            'retention_rate' => $this->getRetentionRate($year),
            'churn_rate' => $this->getChurnRate($year)
        ];
    }

    /**
     * Hamta riders att "vinna tillbaka" - sorterade efter potentiell varde
     *
     * @param int $year Aktuellt ar
     * @param int $limit Max antal
     * @return array Lista med riders att kontakta
     */
    public function getWinBackTargets(int $year, int $limit = 100): array {
        $stmt = $this->pdo->prepare("
            SELECT
                r.id,
                r.firstname,
                r.lastname,
                r.birth_year,
                r.gender,
                c.name as club_name,
                :year1 - r.birth_year as age,
                stats.total_seasons,
                stats.total_events_all_time,
                stats.total_points_all_time,
                stats.last_season,
                :year2 - stats.last_season as years_inactive,
                stats.primary_disciplines,
                CASE
                    WHEN stats.total_seasons >= 3 AND stats.total_events_all_time >= 10 THEN 'Hog'
                    WHEN stats.total_seasons >= 2 OR stats.total_events_all_time >= 5 THEN 'Medium'
                    ELSE 'Lag'
                END as priority
            FROM (
                SELECT
                    rider_id,
                    COUNT(DISTINCT season_year) as total_seasons,
                    SUM(total_events) as total_events_all_time,
                    SUM(total_points) as total_points_all_time,
                    MAX(season_year) as last_season,
                    GROUP_CONCAT(DISTINCT primary_discipline) as primary_disciplines
                FROM rider_yearly_stats
                GROUP BY rider_id
            ) stats
            JOIN riders r ON stats.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN rider_yearly_stats curr
                ON stats.rider_id = curr.rider_id
                AND curr.season_year = :year3
            WHERE curr.rider_id IS NULL
              AND stats.last_season >= :year4 - 3
            ORDER BY
                CASE
                    WHEN stats.total_seasons >= 3 AND stats.total_events_all_time >= 10 THEN 1
                    WHEN stats.total_seasons >= 2 OR stats.total_events_all_time >= 5 THEN 2
                    ELSE 3
                END,
                stats.total_events_all_time DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':year1', $year, PDO::PARAM_INT);
        $stmt->bindValue(':year2', $year, PDO::PARAM_INT);
        $stmt->bindValue(':year3', $year, PDO::PARAM_INT);
        $stmt->bindValue(':year4', $year, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // COHORT ANALYSIS (Phase 2)
    // =========================================================================

    /**
     * Hamta cohort retention over tid
     * Foljer en kohort (riders som borjade ar X) och ser hur manga som ar kvar
     *
     * @param int $cohortYear Startaret for kohorten
     * @param int|null $endYear Slutar (default: current year)
     * @return array Retention per ar
     */
    public function getCohortRetention(int $cohortYear, ?int $endYear = null): array {
        $endYear = $endYear ?? (int)date('Y');

        // Hamta alla riders som hade sitt forsta ar = cohortYear
        $stmt = $this->pdo->prepare("
            SELECT rider_id
            FROM rider_yearly_stats
            WHERE rider_id IN (
                SELECT rider_id
                FROM rider_yearly_stats
                GROUP BY rider_id
                HAVING MIN(season_year) = ?
            )
            AND season_year = ?
        ");
        $stmt->execute([$cohortYear, $cohortYear]);
        $cohortRiders = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $cohortSize = count($cohortRiders);
        if ($cohortSize === 0) {
            return [];
        }

        $result = [];

        // For varje ar, rakna hur manga fran kohorten som ar aktiva
        for ($year = $cohortYear; $year <= $endYear; $year++) {
            $yearsFromStart = $year - $cohortYear;

            if (empty($cohortRiders)) {
                $activeCount = 0;
            } else {
                $placeholders = implode(',', array_fill(0, count($cohortRiders), '?'));
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(DISTINCT rider_id)
                    FROM rider_yearly_stats
                    WHERE season_year = ?
                    AND rider_id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$year], $cohortRiders));
                $activeCount = (int)$stmt->fetchColumn();
            }

            $result[] = [
                'year' => $year,
                'years_from_start' => $yearsFromStart,
                'active_count' => $activeCount,
                'cohort_size' => $cohortSize,
                'retention_rate' => round($activeCount / $cohortSize * 100, 1),
            ];
        }

        return $result;
    }

    /**
     * Jamfor flera kohorter
     *
     * @param array $cohortYears Array av ar att jamfora
     * @param int $maxYears Max antal ar att folja varje kohort
     * @return array Jamforelsedata
     */
    public function compareCohorts(array $cohortYears, int $maxYears = 5): array {
        $result = [];

        foreach ($cohortYears as $cohortYear) {
            $endYear = min($cohortYear + $maxYears - 1, (int)date('Y'));
            $retention = $this->getCohortRetention($cohortYear, $endYear);

            if (!empty($retention)) {
                $result[$cohortYear] = [
                    'cohort_year' => $cohortYear,
                    'cohort_size' => $retention[0]['cohort_size'] ?? 0,
                    'retention_data' => $retention,
                ];
            }
        }

        return $result;
    }

    /**
     * Hamta riders i en kohort
     *
     * @param int $cohortYear Kohort-ar
     * @param string $status 'all', 'active', 'churned'
     * @param int|null $asOfYear Ar att kolla status for (default: current)
     * @return array Lista med riders
     */
    public function getCohortRiders(int $cohortYear, string $status = 'all', ?int $asOfYear = null): array {
        $asOfYear = $asOfYear ?? (int)date('Y');

        $sql = "
            SELECT
                r.id as rider_id,
                r.firstname,
                r.lastname,
                r.birth_year,
                r.gender,
                c.name as club_name,
                cohort.first_discipline,
                cohort.total_seasons,
                cohort.last_active_year,
                CASE
                    WHEN cohort.last_active_year >= ? THEN 'active'
                    WHEN ? - cohort.last_active_year = 1 THEN 'soft_churn'
                    WHEN ? - cohort.last_active_year = 2 THEN 'medium_churn'
                    ELSE 'hard_churn'
                END as current_status
            FROM (
                SELECT
                    rider_id,
                    MIN(season_year) as cohort_year,
                    MAX(season_year) as last_active_year,
                    COUNT(DISTINCT season_year) as total_seasons,
                    (SELECT primary_discipline FROM rider_yearly_stats rys2
                     WHERE rys2.rider_id = rys.rider_id
                     ORDER BY season_year ASC LIMIT 1) as first_discipline
                FROM rider_yearly_stats rys
                GROUP BY rider_id
                HAVING cohort_year = ?
            ) cohort
            JOIN riders r ON cohort.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
        ";

        $params = [$asOfYear, $asOfYear, $asOfYear, $cohortYear];

        if ($status === 'active') {
            $sql .= " WHERE cohort.last_active_year >= ?";
            $params[] = $asOfYear;
        } elseif ($status === 'churned') {
            $sql .= " WHERE cohort.last_active_year < ?";
            $params[] = $asOfYear;
        }

        $sql .= " ORDER BY r.lastname, r.firstname";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta kohort-storlek
     *
     * @param int $cohortYear Kohort-ar
     * @return int Antal riders
     */
    public function getCohortSize(int $cohortYear): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT rider_id)
            FROM rider_yearly_stats
            WHERE rider_id IN (
                SELECT rider_id
                FROM rider_yearly_stats
                GROUP BY rider_id
                HAVING MIN(season_year) = ?
            )
            AND season_year = ?
        ");
        $stmt->execute([$cohortYear, $cohortYear]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Hamta genomsnittlig karriarlangd for en kohort
     *
     * @param int $cohortYear Kohort-ar
     * @return float Genomsnittlig antal sasonger
     */
    public function getCohortAverageLifespan(int $cohortYear): float {
        $stmt = $this->pdo->prepare("
            SELECT AVG(seasons) as avg_lifespan
            FROM (
                SELECT
                    rider_id,
                    COUNT(DISTINCT season_year) as seasons
                FROM rider_yearly_stats
                WHERE rider_id IN (
                    SELECT rider_id
                    FROM rider_yearly_stats
                    GROUP BY rider_id
                    HAVING MIN(season_year) = ?
                )
                GROUP BY rider_id
            ) career_lengths
        ");
        $stmt->execute([$cohortYear]);
        return round((float)($stmt->fetchColumn() ?: 0), 2);
    }

    /**
     * Hamta status-breakdown for en kohort
     *
     * @param int $cohortYear Kohort-ar
     * @param int|null $asOfYear Ar att kolla status for
     * @return array Status-fordelning
     */
    public function getCohortStatusBreakdown(int $cohortYear, ?int $asOfYear = null): array {
        $asOfYear = $asOfYear ?? (int)date('Y');

        $stmt = $this->pdo->prepare("
            SELECT
                CASE
                    WHEN last_year >= ? THEN 'active'
                    WHEN ? - last_year = 1 THEN 'soft_churn'
                    WHEN ? - last_year = 2 THEN 'medium_churn'
                    ELSE 'hard_churn'
                END as status,
                COUNT(*) as count
            FROM (
                SELECT
                    rider_id,
                    MAX(season_year) as last_year
                FROM rider_yearly_stats
                WHERE rider_id IN (
                    SELECT rider_id
                    FROM rider_yearly_stats
                    GROUP BY rider_id
                    HAVING MIN(season_year) = ?
                )
                GROUP BY rider_id
            ) rider_careers
            GROUP BY status
            ORDER BY
                CASE status
                    WHEN 'active' THEN 1
                    WHEN 'soft_churn' THEN 2
                    WHEN 'medium_churn' THEN 3
                    ELSE 4
                END
        ");
        $stmt->execute([$asOfYear, $asOfYear, $asOfYear, $cohortYear]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure all statuses are represented
        $statusMap = [
            'active' => 0,
            'soft_churn' => 0,
            'medium_churn' => 0,
            'hard_churn' => 0,
        ];

        foreach ($results as $row) {
            $statusMap[$row['status']] = (int)$row['count'];
        }

        $total = array_sum($statusMap);

        return [
            'cohort_year' => $cohortYear,
            'as_of_year' => $asOfYear,
            'total' => $total,
            'active' => $statusMap['active'],
            'active_pct' => $total > 0 ? round($statusMap['active'] / $total * 100, 1) : 0,
            'soft_churn' => $statusMap['soft_churn'],
            'soft_churn_pct' => $total > 0 ? round($statusMap['soft_churn'] / $total * 100, 1) : 0,
            'medium_churn' => $statusMap['medium_churn'],
            'medium_churn_pct' => $total > 0 ? round($statusMap['medium_churn'] / $total * 100, 1) : 0,
            'hard_churn' => $statusMap['hard_churn'],
            'hard_churn_pct' => $total > 0 ? round($statusMap['hard_churn'] / $total * 100, 1) : 0,
        ];
    }

    /**
     * Hamta tillgangliga kohort-ar
     *
     * @param int $minSize Minsta kohort-storlek att inkludera
     * @return array Lista med ar och storlekar
     */
    public function getAvailableCohorts(int $minSize = 10): array {
        $stmt = $this->pdo->prepare("
            SELECT
                cohort_year,
                COUNT(*) as cohort_size
            FROM (
                SELECT rider_id, MIN(season_year) as cohort_year
                FROM rider_yearly_stats
                GROUP BY rider_id
            ) cohorts
            GROUP BY cohort_year
            HAVING cohort_size >= ?
            ORDER BY cohort_year DESC
        ");
        $stmt->execute([$minSize]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // RIDER JOURNEY (Phase 2)
    // =========================================================================

    /**
     * Hamta en riders kompletta resa/historik
     *
     * @param int $riderId Rider ID
     * @return array Journey-data
     */
    public function getRiderJourney(int $riderId): array {
        // Hamta arlig statistik
        $stmt = $this->pdo->prepare("
            SELECT
                rys.season_year as year,
                rys.total_events,
                rys.total_series,
                rys.total_points,
                rys.best_position,
                rys.avg_position,
                rys.primary_discipline,
                rys.primary_series_id,
                s.name as primary_series_name,
                rys.is_rookie
            FROM rider_yearly_stats rys
            LEFT JOIN series s ON rys.primary_series_id = s.id
            WHERE rys.rider_id = ?
            ORDER BY rys.season_year ASC
        ");
        $stmt->execute([$riderId]);
        $yearlyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($yearlyStats)) {
            return [];
        }

        // Hamta serier per ar
        $stmt = $this->pdo->prepare("
            SELECT
                sp.season_year as year,
                sp.series_id,
                s.name as series_name,
                sp.events_attended,
                sp.total_points
            FROM series_participation sp
            JOIN series s ON sp.series_id = s.id
            WHERE sp.rider_id = ?
            ORDER BY sp.season_year ASC, sp.events_attended DESC
        ");
        $stmt->execute([$riderId]);
        $seriesData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gruppera serier per ar
        $seriesByYear = [];
        foreach ($seriesData as $row) {
            $seriesByYear[$row['year']][] = [
                'series_id' => $row['series_id'],
                'series_name' => $row['series_name'],
                'events' => $row['events_attended'],
                'points' => $row['total_points'],
            ];
        }

        // Bygg journey
        $journey = [];
        $prevDiscipline = null;
        $prevSeriesIds = [];

        foreach ($yearlyStats as $stat) {
            $year = $stat['year'];
            $currentSeriesIds = array_column($seriesByYear[$year] ?? [], 'series_id');

            // Detektera andringar
            $changedDiscipline = $prevDiscipline !== null && $prevDiscipline !== $stat['primary_discipline'];
            $changedSeries = !empty($prevSeriesIds) &&
                             count(array_diff($currentSeriesIds, $prevSeriesIds)) > 0;

            $journey[] = [
                'year' => $year,
                'is_rookie' => (bool)$stat['is_rookie'],
                'total_events' => $stat['total_events'],
                'total_series' => $stat['total_series'],
                'total_points' => $stat['total_points'],
                'best_position' => $stat['best_position'],
                'avg_position' => $stat['avg_position'],
                'primary_discipline' => $stat['primary_discipline'],
                'primary_series' => $stat['primary_series_name'],
                'series' => $seriesByYear[$year] ?? [],
                'changed_discipline' => $changedDiscipline,
                'changed_series' => $changedSeries,
            ];

            $prevDiscipline = $stat['primary_discipline'];
            $prevSeriesIds = $currentSeriesIds;
        }

        return $journey;
    }

    /**
     * Hamta riders progression (hur de utvecklats over tid)
     *
     * @param int $riderId Rider ID
     * @return array Progression-data
     */
    public function getRiderProgression(int $riderId): array {
        $journey = $this->getRiderJourney($riderId);

        if (count($journey) < 2) {
            return [
                'has_progression' => false,
                'years_active' => count($journey),
            ];
        }

        $first = $journey[0];
        $last = $journey[count($journey) - 1];

        // Berakna progression
        $eventsChange = $last['total_events'] - $first['total_events'];
        $pointsChange = $last['total_points'] - $first['total_points'];
        $positionChange = null;

        if ($first['best_position'] && $last['best_position']) {
            $positionChange = $first['best_position'] - $last['best_position']; // Positiv = forbattring
        }

        // Hitta basta sasong
        $bestYear = null;
        $bestPoints = 0;
        foreach ($journey as $year) {
            if ($year['total_points'] > $bestPoints) {
                $bestPoints = $year['total_points'];
                $bestYear = $year['year'];
            }
        }

        return [
            'has_progression' => true,
            'first_year' => $first['year'],
            'last_year' => $last['year'],
            'years_active' => count($journey),
            'total_seasons' => count($journey),
            'first_discipline' => $first['primary_discipline'],
            'current_discipline' => $last['primary_discipline'],
            'discipline_changed' => $first['primary_discipline'] !== $last['primary_discipline'],
            'events_first_year' => $first['total_events'],
            'events_last_year' => $last['total_events'],
            'events_change' => $eventsChange,
            'points_change' => $pointsChange,
            'position_improvement' => $positionChange,
            'best_season_year' => $bestYear,
            'best_season_points' => $bestPoints,
        ];
    }

    /**
     * Hamta liknande riders baserat pa karriarmonster
     *
     * @param int $riderId Rider att hitta liknande for
     * @param int $limit Max antal att returnera
     * @return array Lista med liknande riders
     */
    public function getSimilarRiders(int $riderId, int $limit = 10): array {
        // Hamta basinformation om target rider
        $stmt = $this->pdo->prepare("
            SELECT
                MIN(season_year) as start_year,
                COUNT(DISTINCT season_year) as total_seasons,
                SUM(total_events) as total_events,
                (SELECT primary_discipline FROM rider_yearly_stats
                 WHERE rider_id = ? ORDER BY season_year DESC LIMIT 1) as current_discipline
            FROM rider_yearly_stats
            WHERE rider_id = ?
        ");
        $stmt->execute([$riderId, $riderId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target || !$target['start_year']) {
            return [];
        }

        // Hamta serier for target
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT series_id FROM series_participation WHERE rider_id = ?
        ");
        $stmt->execute([$riderId]);
        $targetSeries = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Definiera buckets
        $tenureBucket = $target['total_seasons'] <= 2 ? 'new' :
                       ($target['total_seasons'] <= 5 ? 'established' : 'veteran');
        $eventBucket = $target['total_events'] <= 10 ? 'casual' :
                      ($target['total_events'] <= 30 ? 'regular' : 'active');

        // Hitta liknande riders
        $stmt = $this->pdo->prepare("
            SELECT
                r.id as rider_id,
                r.firstname,
                r.lastname,
                c.name as club_name,
                stats.start_year,
                stats.total_seasons,
                stats.total_events,
                stats.current_discipline,
                0 as similarity_score
            FROM (
                SELECT
                    rider_id,
                    MIN(season_year) as start_year,
                    COUNT(DISTINCT season_year) as total_seasons,
                    SUM(total_events) as total_events,
                    (SELECT primary_discipline FROM rider_yearly_stats rys2
                     WHERE rys2.rider_id = rys.rider_id
                     ORDER BY season_year DESC LIMIT 1) as current_discipline
                FROM rider_yearly_stats rys
                WHERE rider_id != ?
                GROUP BY rider_id
            ) stats
            JOIN riders r ON stats.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE
                ABS(stats.start_year - ?) <= 2
                OR stats.current_discipline = ?
                OR (stats.total_seasons BETWEEN ? AND ?)
            ORDER BY
                CASE WHEN stats.current_discipline = ? THEN 0 ELSE 1 END,
                ABS(stats.start_year - ?),
                ABS(stats.total_seasons - ?)
            LIMIT ?
        ");

        $tenureMin = max(1, $target['total_seasons'] - 2);
        $tenureMax = $target['total_seasons'] + 2;

        $stmt->execute([
            $riderId,
            $target['start_year'],
            $target['current_discipline'],
            $tenureMin,
            $tenureMax,
            $target['current_discipline'],
            $target['start_year'],
            $target['total_seasons'],
            $limit * 2 // Fetch more to filter
        ]);

        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Score candidates
        foreach ($candidates as &$candidate) {
            $score = 0;

            // Start year similarity (max 20)
            $yearDiff = abs($candidate['start_year'] - $target['start_year']);
            $score += max(0, 20 - $yearDiff * 5);

            // Discipline match (25)
            if ($candidate['current_discipline'] === $target['current_discipline']) {
                $score += 25;
            }

            // Tenure bucket (20)
            $candTenure = $candidate['total_seasons'] <= 2 ? 'new' :
                         ($candidate['total_seasons'] <= 5 ? 'established' : 'veteran');
            if ($candTenure === $tenureBucket) {
                $score += 20;
            }

            // Event bucket (15)
            $candEvents = $candidate['total_events'] <= 10 ? 'casual' :
                         ($candidate['total_events'] <= 30 ? 'regular' : 'active');
            if ($candEvents === $eventBucket) {
                $score += 15;
            }

            $candidate['similarity_score'] = $score;
        }

        // Sort by score and limit
        usort($candidates, fn($a, $b) => $b['similarity_score'] - $a['similarity_score']);

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Hamta senaste aktiva ar for en rider
     *
     * @param int $riderId Rider ID
     * @return int|null Senaste ar eller null
     */
    public function getRiderLastActiveYear(int $riderId): ?int {
        $stmt = $this->pdo->prepare("
            SELECT MAX(season_year) FROM rider_yearly_stats WHERE rider_id = ?
        ");
        $stmt->execute([$riderId]);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }

    // =========================================================================
    // AT-RISK / CHURN PREDICTION (Phase 2)
    // =========================================================================

    /**
     * Hamta at-risk riders for ett ar
     *
     * @param int $year Ar
     * @param int $limit Max antal
     * @return array Lista med at-risk riders
     */
    public function getAtRiskRiders(int $year, int $limit = 100): array {
        // Forst, kolla om vi har cachad data
        $stmt = $this->pdo->prepare("
            SELECT
                rrs.rider_id,
                r.firstname,
                r.lastname,
                c.name as club_name,
                rrs.risk_score,
                rrs.risk_level,
                rrs.factors,
                rrs.declining_events,
                rrs.no_recent_activity,
                rrs.class_downgrade,
                rrs.single_series,
                rrs.low_tenure,
                rrs.high_age_in_class,
                rys.total_events,
                rys.primary_discipline,
                rys.primary_series_id,
                s.name as series_name
            FROM rider_risk_scores rrs
            JOIN riders r ON rrs.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN rider_yearly_stats rys ON rrs.rider_id = rys.rider_id AND rys.season_year = ?
            LEFT JOIN series s ON rys.primary_series_id = s.id
            WHERE rrs.season_year = ?
            ORDER BY rrs.risk_score DESC
            LIMIT ?
        ");

        try {
            $stmt->execute([$year, $year, $limit]);
            $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($cached)) {
                // Decode JSON factors
                foreach ($cached as &$row) {
                    $row['factors'] = $row['factors'] ? json_decode($row['factors'], true) : [];
                }
                return $cached;
            }
        } catch (PDOException $e) {
            // Table might not exist yet
        }

        // Fallback: berakna on-the-fly (langsammare)
        return $this->calculateAtRiskRidersOnTheFly($year, $limit);
    }

    /**
     * Berakna risk on-the-fly (fallback om cache saknas)
     */
    private function calculateAtRiskRidersOnTheFly(int $year, int $limit): array {
        // Hamta riders som var aktiva forra aret
        $stmt = $this->pdo->prepare("
            SELECT
                rys.rider_id,
                r.firstname,
                r.lastname,
                c.name as club_name,
                rys.total_events as current_events,
                prev.total_events as prev_events,
                rys.primary_discipline,
                rys.primary_series_id,
                s.name as series_name,
                (SELECT COUNT(DISTINCT season_year) FROM rider_yearly_stats WHERE rider_id = rys.rider_id) as total_seasons,
                (SELECT COUNT(DISTINCT series_id) FROM series_participation WHERE rider_id = rys.rider_id AND season_year = ?) as series_count
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN rider_yearly_stats prev ON rys.rider_id = prev.rider_id AND prev.season_year = ? - 1
            LEFT JOIN series s ON rys.primary_series_id = s.id
            WHERE rys.season_year = ?
            ORDER BY rys.total_events ASC
            LIMIT ?
        ");
        $stmt->execute([$year, $year, $year, $limit * 2]);
        $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($riders as $rider) {
            $risk = $this->calculateChurnRisk($rider['rider_id'], $year);
            if ($risk['risk_score'] >= 30) { // Only include medium+ risk
                $results[] = array_merge($rider, $risk);
            }
        }

        usort($results, fn($a, $b) => $b['risk_score'] - $a['risk_score']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Berakna churn-risk for en specifik rider
     *
     * @param int $riderId Rider ID
     * @param int $year Ar
     * @return array Risk-data
     */
    public function calculateChurnRisk(int $riderId, int $year): array {
        $factors = [];
        $totalScore = 0;

        // Hamta rider-data
        $stmt = $this->pdo->prepare("
            SELECT
                rys.total_events,
                rys.primary_discipline,
                (SELECT total_events FROM rider_yearly_stats WHERE rider_id = ? AND season_year = ? - 1) as prev_events,
                (SELECT COUNT(DISTINCT season_year) FROM rider_yearly_stats WHERE rider_id = ?) as total_seasons,
                (SELECT COUNT(DISTINCT series_id) FROM series_participation WHERE rider_id = ? AND season_year = ?) as series_count
            FROM rider_yearly_stats rys
            WHERE rys.rider_id = ? AND rys.season_year = ?
        ");
        $stmt->execute([$riderId, $year, $riderId, $riderId, $year, $riderId, $year]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            return ['risk_score' => 0, 'risk_level' => 'unknown', 'factors' => []];
        }

        // Factor 1: Declining events (30 points)
        $decliningEvents = false;
        if ($data['prev_events'] && $data['total_events'] < $data['prev_events']) {
            $decline = ($data['prev_events'] - $data['total_events']) / $data['prev_events'];
            if ($decline >= 0.3) {
                $decliningEvents = true;
                $score = min(30, round($decline * 30));
                $totalScore += $score;
                $factors['declining_events'] = [
                    'score' => $score,
                    'detail' => "Minskade fran {$data['prev_events']} till {$data['total_events']} events",
                ];
            }
        }

        // Factor 2: Single series (10 points)
        $singleSeries = $data['series_count'] <= 1;
        if ($singleSeries) {
            $totalScore += 10;
            $factors['single_series'] = [
                'score' => 10,
                'detail' => 'Deltar endast i en serie',
            ];
        }

        // Factor 3: Low tenure (10 points)
        $lowTenure = $data['total_seasons'] <= 2;
        if ($lowTenure) {
            $totalScore += 10;
            $factors['low_tenure'] = [
                'score' => 10,
                'detail' => "Endast {$data['total_seasons']} sasonger",
            ];
        }

        // Determine risk level
        $riskLevel = 'low';
        if ($totalScore >= 70) {
            $riskLevel = 'critical';
        } elseif ($totalScore >= 50) {
            $riskLevel = 'high';
        } elseif ($totalScore >= 30) {
            $riskLevel = 'medium';
        }

        return [
            'risk_score' => $totalScore,
            'risk_level' => $riskLevel,
            'factors' => $factors,
            'declining_events' => $decliningEvents,
            'single_series' => $singleSeries,
            'low_tenure' => $lowTenure,
            'no_recent_activity' => false,
            'class_downgrade' => false,
            'high_age_in_class' => false,
        ];
    }

    /**
     * Hamta risk-distribution for ett ar
     *
     * @param int $year Ar
     * @return array Distribution per risk-niva
     */
    public function getRiskDistribution(int $year): array {
        // Forst kolla cache
        $stmt = $this->pdo->prepare("
            SELECT
                risk_level,
                COUNT(*) as count
            FROM rider_risk_scores
            WHERE season_year = ?
            GROUP BY risk_level
        ");

        try {
            $stmt->execute([$year]);
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            if (!empty($results)) {
                return [
                    'low' => $results['low'] ?? 0,
                    'medium' => $results['medium'] ?? 0,
                    'high' => $results['high'] ?? 0,
                    'critical' => $results['critical'] ?? 0,
                    'total' => array_sum($results),
                ];
            }
        } catch (PDOException $e) {
            // Table might not exist
        }

        // Return empty if no cache
        return [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
            'total' => 0,
            'cache_missing' => true,
        ];
    }

    // =========================================================================
    // FEEDER TRENDS (Phase 2)
    // =========================================================================

    /**
     * Hamta feeder-trend over tid for ett specifikt flode
     *
     * @param int $fromSeriesId Ursprungsserie
     * @param int $toSeriesId Malserie
     * @param int $years Antal ar
     * @return array Trend-data
     */
    public function getFeederTrend(int $fromSeriesId, int $toSeriesId, int $years = 5): array {
        $currentYear = (int)date('Y');
        $startYear = $currentYear - $years + 1;

        $result = [];

        for ($year = $startYear; $year <= $currentYear; $year++) {
            // Antal i from-serie
            $stmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT rider_id) FROM series_participation
                WHERE series_id = ? AND season_year = ?
            ");
            $stmt->execute([$fromSeriesId, $year]);
            $fromCount = (int)$stmt->fetchColumn();

            // Antal som ocksa deltog i to-serie (same year)
            $stmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT sc.rider_id)
                FROM series_crossover sc
                WHERE sc.from_series_id = ? AND sc.to_series_id = ?
                AND sc.from_year = ? AND sc.crossover_type = 'same_year'
            ");
            $stmt->execute([$fromSeriesId, $toSeriesId, $year]);
            $flowCount = (int)$stmt->fetchColumn();

            $result[] = [
                'year' => $year,
                'from_count' => $fromCount,
                'flow_count' => $flowCount,
                'conversion_rate' => $fromCount > 0 ? round($flowCount / $fromCount * 100, 1) : 0,
            ];
        }

        // Berakna trend
        if (count($result) >= 2) {
            $first = $result[0]['conversion_rate'];
            $last = $result[count($result) - 1]['conversion_rate'];
            $trend = $last - $first;

            foreach ($result as &$r) {
                $r['overall_trend'] = $trend > 1 ? 'growing' : ($trend < -1 ? 'declining' : 'stable');
            }
        }

        return $result;
    }

    /**
     * Hamta oversikt av feeder-trends
     *
     * @param int $year Ar
     * @return array Alla floden med trend-indikatorer
     */
    public function getFeederTrendsOverview(int $year): array {
        $matrix = $this->calculateFeederMatrix($year);
        $prevMatrix = $this->calculateFeederMatrix($year - 1);

        // Skapa lookup for foregaende ar
        $prevLookup = [];
        foreach ($prevMatrix as $flow) {
            $key = $flow['from_series_id'] . '-' . $flow['to_series_id'];
            $prevLookup[$key] = $flow['flow_count'];
        }

        // Lagg till trend-info
        foreach ($matrix as &$flow) {
            $key = $flow['from_series_id'] . '-' . $flow['to_series_id'];
            $prevCount = $prevLookup[$key] ?? 0;

            $change = $flow['flow_count'] - $prevCount;
            $changePct = $prevCount > 0 ? round($change / $prevCount * 100, 1) : ($flow['flow_count'] > 0 ? 100 : 0);

            $flow['prev_count'] = $prevCount;
            $flow['change'] = $change;
            $flow['change_pct'] = $changePct;
            $flow['trend'] = $change > 2 ? 'growing' : ($change < -2 ? 'declining' : 'stable');
        }

        return $matrix;
    }

    /**
     * Hamta framvaxande floden (snabb tillvaxt)
     *
     * @param int $year Ar
     * @param float $minGrowth Minsta tillvaxt i procent
     * @return array Framvaxande floden
     */
    public function getEmergingFlows(int $year, float $minGrowth = 20.0): array {
        $trends = $this->getFeederTrendsOverview($year);

        $emerging = array_filter($trends, function($flow) use ($minGrowth) {
            return $flow['change_pct'] >= $minGrowth && $flow['flow_count'] >= 5;
        });

        usort($emerging, fn($a, $b) => $b['change_pct'] - $a['change_pct']);

        return $emerging;
    }

    // =========================================================================
    // GEOGRAPHIC ANALYSIS (Phase 2)
    // =========================================================================

    /**
     * Hamta regional tillvaxttend
     *
     * @param int $years Antal ar
     * @return array Trend per region
     */
    public function getRegionalGrowthTrend(int $years = 5): array {
        $currentYear = (int)date('Y');
        $startYear = $currentYear - $years + 1;

        $stmt = $this->pdo->prepare("
            SELECT
                rys.season_year as year,
                COALESCE(s.region, 'Okand') as region,
                COUNT(DISTINCT rys.rider_id) as rider_count
            FROM rider_yearly_stats rys
            LEFT JOIN series s ON rys.primary_series_id = s.id
            WHERE rys.season_year >= ? AND rys.season_year <= ?
            GROUP BY rys.season_year, s.region
            ORDER BY rys.season_year ASC, rider_count DESC
        ");
        $stmt->execute([$startYear, $currentYear]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gruppera per region
        $byRegion = [];
        foreach ($results as $row) {
            $byRegion[$row['region']][] = [
                'year' => $row['year'],
                'count' => $row['rider_count'],
            ];
        }

        // Berakna tillvaxt
        $trends = [];
        foreach ($byRegion as $region => $years) {
            $firstYear = $years[0]['count'] ?? 0;
            $lastYear = $years[count($years) - 1]['count'] ?? 0;

            $growth = $firstYear > 0
                ? round(($lastYear - $firstYear) / $firstYear * 100, 1)
                : ($lastYear > 0 ? 100 : 0);

            $trends[$region] = [
                'region' => $region,
                'years' => $years,
                'first_year_count' => $firstYear,
                'last_year_count' => $lastYear,
                'growth_pct' => $growth,
                'trend' => $growth > 5 ? 'growing' : ($growth < -5 ? 'declining' : 'stable'),
            ];
        }

        // Sortera efter senaste antal
        uasort($trends, fn($a, $b) => $b['last_year_count'] - $a['last_year_count']);

        return $trends;
    }

    /**
     * Hamta underservade regioner (lag tackning per capita)
     *
     * @param int $year Ar
     * @return array Regioner sorterade efter tackning
     */
    public function getUnderservedRegions(int $year): array {
        // Hamta riders per region
        $regions = $this->getRidersByRegion($year);

        // Lagg till befolkningsdata fran config
        require_once __DIR__ . '/AnalyticsConfig.php';

        $result = [];
        foreach ($regions as $region) {
            $regionName = $region['region'];
            $population = AnalyticsConfig::SWEDISH_REGIONS[$regionName]['population'] ?? null;

            $ridersPerCapita = null;
            if ($population && $population > 0) {
                $ridersPerCapita = round($region['rider_count'] / $population * 100000, 2);
            }

            $result[] = [
                'region' => $regionName,
                'rider_count' => $region['rider_count'],
                'population' => $population,
                'riders_per_100k' => $ridersPerCapita,
            ];
        }

        // Sortera efter riders per capita (lagst forst)
        usort($result, function($a, $b) {
            if ($a['riders_per_100k'] === null) return 1;
            if ($b['riders_per_100k'] === null) return -1;
            return $a['riders_per_100k'] - $b['riders_per_100k'];
        });

        return $result;
    }

    /**
     * Hamta events per region
     *
     * @param int $year Ar
     * @return array Events per region
     */
    public function getEventsByRegion(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(s.region, v.region, 'Okand') as region,
                COUNT(DISTINCT e.id) as event_count,
                COUNT(DISTINCT res.cyclist_id) as participant_count,
                SUM(CASE WHEN e.discipline = 'Enduro' THEN 1 ELSE 0 END) as enduro_events,
                SUM(CASE WHEN e.discipline = 'DH' THEN 1 ELSE 0 END) as dh_events
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            LEFT JOIN venues v ON e.venue_id = v.id
            LEFT JOIN results res ON e.id = res.event_id
            WHERE YEAR(e.date) = ?
            GROUP BY region
            ORDER BY event_count DESC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // CAREER PATH ANALYSIS - Feeder Pipeline & Series Level Transitions
    // =========================================================================

    /**
     * Hamta "feeder pipeline" - riders som startade regionalt och sen gick till nationellt
     *
     * Returnerar riders vars FORSTA tavling var i en regional serie,
     * och som sedan nagon gang deltog i en nationell serie.
     *
     * @param int|null $maxYear Senaste ar att inkludera (default: senaste data)
     * @return array Pipeline data uppdelat per feeder-serie
     */
    public function getFeederPipeline(?int $maxYear = null): array {
        $maxYear = $maxYear ?? $this->getLatestSeasonYear();

        $stmt = $this->pdo->prepare("
            SELECT
                first_series.series_id as feeder_series_id,
                s.name as feeder_series_name,
                s.region as feeder_region,
                COUNT(DISTINCT first_series.rider_id) as total_starters,
                COUNT(DISTINCT CASE WHEN nat.rider_id IS NOT NULL THEN first_series.rider_id END) as went_national,
                ROUND(
                    COUNT(DISTINCT CASE WHEN nat.rider_id IS NOT NULL THEN first_series.rider_id END) * 100.0 /
                    NULLIF(COUNT(DISTINCT first_series.rider_id), 0),
                1) as conversion_rate
            FROM (
                -- Hitta forsta serien for varje rider (maste vara regional)
                SELECT
                    sp.rider_id,
                    sp.series_id,
                    sp.season_year as first_year
                FROM series_participation sp
                JOIN series ser ON sp.series_id = ser.id
                WHERE ser.series_level = 'regional'
                AND (sp.rider_id, sp.season_year) IN (
                    SELECT rider_id, MIN(season_year)
                    FROM series_participation
                    GROUP BY rider_id
                )
            ) first_series
            JOIN series s ON first_series.series_id = s.id
            LEFT JOIN (
                -- Riders som nagonsin deltog i en nationell serie
                SELECT DISTINCT sp.rider_id
                FROM series_participation sp
                JOIN series ser ON sp.series_id = ser.id
                WHERE ser.series_level = 'national'
                AND sp.season_year <= ?
            ) nat ON first_series.rider_id = nat.rider_id
            WHERE first_series.first_year <= ?
            GROUP BY first_series.series_id, s.name, s.region
            ORDER BY went_national DESC
        ");
        $stmt->execute([$maxYear, $maxYear]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta riders som startade nationellt och sen provade regionalt
     *
     * Returnerar statistik for riders vars FORSTA tavling var i en nationell serie.
     *
     * @param int|null $maxYear Senaste ar att inkludera
     * @return array Flow data
     */
    public function getNationalToRegionalFlow(?int $maxYear = null): array {
        $maxYear = $maxYear ?? $this->getLatestSeasonYear();

        $stmt = $this->pdo->prepare("
            SELECT
                -- Totalt som startade nationellt
                COUNT(DISTINCT first_nat.rider_id) as started_national,

                -- Av dessa: provade regional
                COUNT(DISTINCT CASE WHEN reg.rider_id IS NOT NULL THEN first_nat.rider_id END) as tried_regional,

                -- Av dessa: slutade nationellt men fortsatte regionalt
                COUNT(DISTINCT CASE
                    WHEN reg.rider_id IS NOT NULL
                    AND last_nat.last_national_year < ?
                    AND last_reg.last_regional_year >= ?
                    THEN first_nat.rider_id
                END) as quit_national_kept_regional,

                -- Av dessa: fortsatter bade nationellt och regionalt
                COUNT(DISTINCT CASE
                    WHEN reg.rider_id IS NOT NULL
                    AND last_nat.last_national_year >= ?
                    THEN first_nat.rider_id
                END) as still_both

            FROM (
                -- Riders vars forsta tavling var nationell
                SELECT sp.rider_id, MIN(sp.season_year) as first_year
                FROM series_participation sp
                JOIN series ser ON sp.series_id = ser.id
                WHERE ser.series_level = 'national'
                AND (sp.rider_id, sp.season_year) IN (
                    SELECT rider_id, MIN(season_year)
                    FROM series_participation
                    GROUP BY rider_id
                )
                GROUP BY sp.rider_id
            ) first_nat

            -- Vilka av dessa provade regional nagon gang?
            LEFT JOIN (
                SELECT DISTINCT sp.rider_id
                FROM series_participation sp
                JOIN series ser ON sp.series_id = ser.id
                WHERE ser.series_level = 'regional'
            ) reg ON first_nat.rider_id = reg.rider_id

            -- Senaste ar de tavlade nationellt
            LEFT JOIN (
                SELECT sp.rider_id, MAX(sp.season_year) as last_national_year
                FROM series_participation sp
                JOIN series ser ON sp.series_id = ser.id
                WHERE ser.series_level = 'national'
                GROUP BY sp.rider_id
            ) last_nat ON first_nat.rider_id = last_nat.rider_id

            -- Senaste ar de tavlade regionalt
            LEFT JOIN (
                SELECT sp.rider_id, MAX(sp.season_year) as last_regional_year
                FROM series_participation sp
                JOIN series ser ON sp.series_id = ser.id
                WHERE ser.series_level = 'regional'
                GROUP BY sp.rider_id
            ) last_reg ON first_nat.rider_id = last_reg.rider_id

            WHERE first_nat.first_year <= ?
        ");
        // Anvand maxYear - 1 for "slutade nationellt" (maste ha minst 1 ar utan)
        $stmt->execute([$maxYear, $maxYear - 1, $maxYear - 1, $maxYear]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta detaljerad karriarvags-analys
     *
     * Kategoriserar alla riders baserat pa deras serie-niva-historia:
     * - regional_only: Bara regionalt
     * - national_only: Bara nationellt
     * - regional_to_national: Startade regionalt, gick till nationellt
     * - national_to_regional: Startade nationellt, provade regionalt
     * - mixed: Startade med bada samma ar
     *
     * @param int|null $maxYear Senaste ar
     * @return array Karriarvagar med antal
     */
    public function getCareerPathsAnalysis(?int $maxYear = null): array {
        $maxYear = $maxYear ?? $this->getLatestSeasonYear();

        $stmt = $this->pdo->prepare("
            SELECT
                CASE
                    WHEN first_regional IS NULL AND first_national IS NOT NULL THEN 'national_only'
                    WHEN first_national IS NULL AND first_regional IS NOT NULL THEN 'regional_only'
                    WHEN first_regional < first_national THEN 'regional_to_national'
                    WHEN first_national < first_regional THEN 'national_to_regional'
                    WHEN first_regional = first_national THEN 'started_both'
                    ELSE 'unknown'
                END as career_path,
                COUNT(*) as rider_count
            FROM (
                SELECT
                    r.id as rider_id,
                    reg.first_regional,
                    nat.first_national
                FROM riders r
                LEFT JOIN (
                    SELECT sp.rider_id, MIN(sp.season_year) as first_regional
                    FROM series_participation sp
                    JOIN series ser ON sp.series_id = ser.id
                    WHERE ser.series_level = 'regional'
                    GROUP BY sp.rider_id
                ) reg ON r.id = reg.rider_id
                LEFT JOIN (
                    SELECT sp.rider_id, MIN(sp.season_year) as first_national
                    FROM series_participation sp
                    JOIN series ser ON sp.series_id = ser.id
                    WHERE ser.series_level = 'national'
                    GROUP BY sp.rider_id
                ) nat ON r.id = nat.rider_id
                WHERE reg.first_regional IS NOT NULL OR nat.first_national IS NOT NULL
                AND COALESCE(reg.first_regional, nat.first_national) <= ?
            ) paths
            GROUP BY career_path
            ORDER BY rider_count DESC
        ");
        $stmt->execute([$maxYear]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta forsta-tavlings-statistik per serie (entry points for first-timers)
     *
     * Visar vilka serier som ar "gateway" serier dar riders har sin forsta tavling.
     *
     * @param int $year Ar att analysera
     * @return array Entry point data per serie
     */
    public function getFirstRaceEntryPoints(int $year): array {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id as series_id,
                s.name as series_name,
                s.series_level,
                s.region,
                COUNT(DISTINCT first_race.rider_id) as first_timers,
                -- Av dessa: hur manga fortsatte nasta ar?
                COUNT(DISTINCT CASE WHEN next_year.rider_id IS NOT NULL THEN first_race.rider_id END) as returned_next_year,
                ROUND(
                    COUNT(DISTINCT CASE WHEN next_year.rider_id IS NOT NULL THEN first_race.rider_id END) * 100.0 /
                    NULLIF(COUNT(DISTINCT first_race.rider_id), 0),
                1) as first_year_retention
            FROM (
                -- Riders vars forsta tavlingsdeltagande var detta ar
                SELECT sp.rider_id, sp.series_id
                FROM series_participation sp
                WHERE sp.season_year = ?
                AND (sp.rider_id, sp.season_year) IN (
                    SELECT rider_id, MIN(season_year)
                    FROM series_participation
                    GROUP BY rider_id
                )
            ) first_race
            JOIN series s ON first_race.series_id = s.id
            LEFT JOIN (
                -- Deltog de nasta ar (i nagon serie)?
                SELECT DISTINCT rider_id
                FROM series_participation
                WHERE season_year = ?
            ) next_year ON first_race.rider_id = next_year.rider_id
            GROUP BY s.id, s.name, s.series_level, s.region
            ORDER BY first_timers DESC
        ");
        $stmt->execute([$year, $year + 1]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hamta "graduation" statistik - riders som gick fran regional till national
     *
     * @param int $fromYear Startår
     * @param int $toYear Slutår
     * @return array Årlig graduation data
     */
    public function getGraduationTrend(int $fromYear, int $toYear): array {
        $result = [];

        for ($year = $fromYear; $year <= $toYear; $year++) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT sp.rider_id) as graduated
                FROM series_participation sp
                JOIN series ser ON sp.series_id = ser.id
                WHERE ser.series_level = 'national'
                AND sp.season_year = ?
                AND sp.rider_id IN (
                    -- Riders som tavlade regionalt foregaende ar men INTE nationellt
                    SELECT DISTINCT sp2.rider_id
                    FROM series_participation sp2
                    JOIN series ser2 ON sp2.series_id = ser2.id
                    WHERE ser2.series_level = 'regional'
                    AND sp2.season_year = ?
                    AND sp2.rider_id NOT IN (
                        SELECT DISTINCT sp3.rider_id
                        FROM series_participation sp3
                        JOIN series ser3 ON sp3.series_id = ser3.id
                        WHERE ser3.series_level = 'national'
                        AND sp3.season_year = ?
                    )
                )
            ");
            $stmt->execute([$year, $year - 1, $year - 1]);
            $graduated = (int)$stmt->fetchColumn();

            $result[] = [
                'year' => $year,
                'graduated' => $graduated
            ];
        }

        return $result;
    }
}
