<?php
/**
 * AnalyticsEngine
 *
 * Karnklass for all analytics-berakning i TheHUB.
 * Beraknar och lagrar statistik for riders, serier, klubbar och venues.
 *
 * KRITISKT:
 * - Anvander IdentityResolver for ALLA rider-lookups
 * - Skriver ALLTID canonical rider_id till analytics-tabeller
 * - Markerar alla berakningar med calculation_version
 * - Loggar alla korningar till analytics_cron_runs
 *
 * @package TheHUB Analytics
 * @version 1.0
 */

require_once __DIR__ . '/IdentityResolver.php';
require_once __DIR__ . '/sql-runner.php';

class AnalyticsEngine {
    private PDO $pdo;
    private IdentityResolver $identityResolver;
    private string $calculationVersion = 'v1';
    private ?string $currentJobName = null;
    private ?int $currentJobId = null;

    /**
     * Constructor
     *
     * @param PDO $pdo Databasanslutning (INTE global!)
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->identityResolver = new IdentityResolver($pdo);
    }

    // =========================================================================
    // JOBB-HANTERING
    // =========================================================================

    /**
     * Starta ett analytics-jobb (for loggning och las)
     *
     * @param string $jobName Jobbnamn (t.ex. 'yearly-stats')
     * @param string $runKey Unik nyckel (t.ex. '2025')
     * @return int|false Job ID eller false om redan kors
     */
    public function startJob(string $jobName, string $runKey): int|false {
        // Kolla om jobb redan kors (las mot dubbelkorning)
        $stmt = $this->pdo->prepare("
            SELECT id, status FROM analytics_cron_runs
            WHERE job_name = ? AND run_key = ? AND status = 'started'
        ");
        $stmt->execute([$jobName, $runKey]);
        $existing = $stmt->fetch();

        if ($existing) {
            return false; // Redan pagar
        }

        // Skapa nytt jobb
        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_cron_runs (job_name, run_key, status)
            VALUES (?, ?, 'started')
            ON DUPLICATE KEY UPDATE
                status = 'started',
                started_at = CURRENT_TIMESTAMP,
                finished_at = NULL,
                duration_ms = NULL,
                rows_affected = 0,
                log = NULL
        ");
        $stmt->execute([$jobName, $runKey]);

        $this->currentJobName = $jobName;
        $this->currentJobId = (int)$this->pdo->lastInsertId();

        return $this->currentJobId;
    }

    /**
     * Avsluta ett analytics-jobb
     *
     * @param string $status 'success' eller 'failed'
     * @param int $rowsAffected Antal rader paverkade
     * @param array $log Extra loggdata
     */
    public function endJob(string $status, int $rowsAffected = 0, array $log = []): void {
        if (!$this->currentJobId) return;

        $stmt = $this->pdo->prepare("
            UPDATE analytics_cron_runs
            SET status = ?,
                finished_at = CURRENT_TIMESTAMP,
                duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, CURRENT_TIMESTAMP) / 1000,
                rows_affected = ?,
                log = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $rowsAffected,
            json_encode($log, JSON_UNESCAPED_UNICODE),
            $this->currentJobId
        ]);

        $this->currentJobName = null;
        $this->currentJobId = null;
    }

    // =========================================================================
    // ARLIG STATISTIK
    // =========================================================================

    /**
     * Berakna yearly stats for alla riders ett visst ar
     *
     * Fyller i rider_yearly_stats med:
     * - total_events, total_series, total_points
     * - best_position, avg_position
     * - primary_discipline, primary_series_id
     * - is_rookie, is_retained
     *
     * @param int $year Sasong att berakna
     * @return int Antal rader skapade/uppdaterade
     */
    public function calculateYearlyStats(int $year): int {
        $jobId = $this->startJob('yearly-stats', (string)$year);
        if ($jobId === false) {
            logAnalytics($this->pdo, 'warn', "Jobb yearly-stats for $year pagar redan", 'AnalyticsEngine');
            return 0;
        }

        $count = 0;

        try {
            // Hamta alla unika riders som deltog detta ar
            $riders = $this->pdo->query("
                SELECT DISTINCT v.canonical_rider_id as rider_id
                FROM results res
                JOIN events e ON res.event_id = e.id
                JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
                WHERE YEAR(e.date) = $year
            ")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($riders as $riderId) {
                $stats = $this->calculateSingleRiderYearlyStats($riderId, $year);

                if ($stats) {
                    $this->upsertRiderYearlyStats($riderId, $year, $stats);
                    $count++;
                }
            }

            $this->endJob('success', $count, [
                'year' => $year,
                'riders_processed' => count($riders)
            ]);

        } catch (Exception $e) {
            $this->endJob('failed', $count, ['error' => $e->getMessage()]);
            throw $e;
        }

        return $count;
    }

    /**
     * Berakna statistik for en enskild rider ett ar
     */
    private function calculateSingleRiderYearlyStats(int $riderId, int $year): ?array {
        // Total events och poang
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(DISTINCT res.event_id) as total_events,
                COUNT(DISTINCT e.series_id) as total_series,
                SUM(COALESCE(res.points, 0)) as total_points,
                MIN(res.position) as best_position,
                AVG(res.position) as avg_position
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
            WHERE v.canonical_rider_id = ?
              AND YEAR(e.date) = ?
              AND res.position > 0
        ");
        $stmt->execute([$riderId, $year]);
        $basic = $stmt->fetch();

        if (!$basic || $basic['total_events'] == 0) {
            return null;
        }

        // Primary discipline (mest deltaganden)
        $stmt = $this->pdo->prepare("
            SELECT e.discipline, COUNT(*) as cnt
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
            WHERE v.canonical_rider_id = ?
              AND YEAR(e.date) = ?
              AND e.discipline IS NOT NULL
            GROUP BY e.discipline
            ORDER BY cnt DESC
            LIMIT 1
        ");
        $stmt->execute([$riderId, $year]);
        $discipline = $stmt->fetch();

        // Primary series (mest deltaganden)
        $stmt = $this->pdo->prepare("
            SELECT e.series_id, COUNT(*) as cnt
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
            WHERE v.canonical_rider_id = ?
              AND YEAR(e.date) = ?
              AND e.series_id IS NOT NULL
            GROUP BY e.series_id
            ORDER BY cnt DESC
            LIMIT 1
        ");
        $stmt->execute([$riderId, $year]);
        $series = $stmt->fetch();

        // Is rookie? (forsta aret)
        $isRookie = $this->isRookie($riderId, $year) ? 1 : 0;

        // Is retained? (aterkom fran forra aret)
        $isRetained = $this->wasRetained($riderId, $year) ? 1 : 0;

        return [
            'total_events' => (int)$basic['total_events'],
            'total_series' => (int)$basic['total_series'],
            'total_points' => (float)$basic['total_points'],
            'best_position' => $basic['best_position'] ? (int)$basic['best_position'] : null,
            'avg_position' => $basic['avg_position'] ? round($basic['avg_position'], 2) : null,
            'primary_discipline' => $discipline ? $discipline['discipline'] : null,
            'primary_series_id' => $series ? (int)$series['series_id'] : null,
            'is_rookie' => $isRookie,
            'is_retained' => $isRetained
        ];
    }

    /**
     * Upsert rider yearly stats
     */
    private function upsertRiderYearlyStats(int $riderId, int $year, array $stats): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO rider_yearly_stats (
                rider_id, season_year, total_events, total_series, total_points,
                best_position, avg_position, primary_discipline, primary_series_id,
                is_rookie, is_retained, calculation_version, calculated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                total_events = VALUES(total_events),
                total_series = VALUES(total_series),
                total_points = VALUES(total_points),
                best_position = VALUES(best_position),
                avg_position = VALUES(avg_position),
                primary_discipline = VALUES(primary_discipline),
                primary_series_id = VALUES(primary_series_id),
                is_rookie = VALUES(is_rookie),
                is_retained = VALUES(is_retained),
                calculation_version = VALUES(calculation_version),
                calculated_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $riderId,
            $year,
            $stats['total_events'],
            $stats['total_series'],
            $stats['total_points'],
            $stats['best_position'],
            $stats['avg_position'],
            $stats['primary_discipline'],
            $stats['primary_series_id'],
            $stats['is_rookie'],
            $stats['is_retained'],
            $this->calculationVersion
        ]);
    }

    // =========================================================================
    // SERIEDELTAGANDE
    // =========================================================================

    /**
     * Berakna series participation for ett ar
     *
     * @param int $year Sasong
     * @return int Antal rader skapade
     */
    public function calculateSeriesParticipation(int $year): int {
        $jobId = $this->startJob('series-participation', (string)$year);
        if ($jobId === false) {
            return 0;
        }

        $count = 0;

        try {
            // Hamta alla rider-serie kombinationer for aret
            $participations = $this->pdo->query("
                SELECT
                    v.canonical_rider_id as rider_id,
                    e.series_id,
                    COUNT(DISTINCT res.event_id) as events_attended,
                    MIN(e.date) as first_event_date,
                    MAX(e.date) as last_event_date,
                    SUM(COALESCE(res.points, 0)) as total_points
                FROM results res
                JOIN events e ON res.event_id = e.id
                JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
                WHERE YEAR(e.date) = $year
                  AND e.series_id IS NOT NULL
                GROUP BY v.canonical_rider_id, e.series_id
            ")->fetchAll();

            foreach ($participations as $p) {
                // Ar detta riders forsta serie nagonsin?
                $isEntrySeries = $this->isFirstSeriesEver($p['rider_id'], $p['series_id'], $year) ? 1 : 0;

                $stmt = $this->pdo->prepare("
                    INSERT INTO series_participation (
                        rider_id, series_id, season_year, events_attended,
                        first_event_date, last_event_date, total_points,
                        is_entry_series, calculation_version, calculated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE
                        events_attended = VALUES(events_attended),
                        first_event_date = VALUES(first_event_date),
                        last_event_date = VALUES(last_event_date),
                        total_points = VALUES(total_points),
                        is_entry_series = VALUES(is_entry_series),
                        calculation_version = VALUES(calculation_version),
                        calculated_at = CURRENT_TIMESTAMP
                ");

                $stmt->execute([
                    $p['rider_id'],
                    $p['series_id'],
                    $year,
                    $p['events_attended'],
                    $p['first_event_date'],
                    $p['last_event_date'],
                    $p['total_points'],
                    $isEntrySeries,
                    $this->calculationVersion
                ]);

                $count++;
            }

            $this->endJob('success', $count);

        } catch (Exception $e) {
            $this->endJob('failed', $count, ['error' => $e->getMessage()]);
            throw $e;
        }

        return $count;
    }

    // =========================================================================
    // SERIEFLODE (CROSSOVER)
    // =========================================================================

    /**
     * Berakna series crossover for ett ar
     * Hittar riders som deltar i flera serier
     *
     * @param int $year Sasong
     * @return int Antal rader skapade
     */
    public function calculateSeriesCrossover(int $year): int {
        $jobId = $this->startJob('series-crossover', (string)$year);
        if ($jobId === false) {
            return 0;
        }

        $count = 0;

        try {
            // Hitta riders som deltog i minst 2 serier samma ar
            $crossovers = $this->pdo->query("
                SELECT
                    sp1.rider_id,
                    sp1.series_id as from_series_id,
                    sp2.series_id as to_series_id,
                    $year as from_year,
                    $year as to_year,
                    'same_year' as crossover_type
                FROM series_participation sp1
                JOIN series_participation sp2
                    ON sp1.rider_id = sp2.rider_id
                    AND sp1.series_id < sp2.series_id
                    AND sp1.season_year = sp2.season_year
                WHERE sp1.season_year = $year
            ")->fetchAll();

            foreach ($crossovers as $c) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO series_crossover (
                        rider_id, from_series_id, to_series_id,
                        from_year, to_year, crossover_type,
                        calculation_version, calculated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");

                $stmt->execute([
                    $c['rider_id'],
                    $c['from_series_id'],
                    $c['to_series_id'],
                    $c['from_year'],
                    $c['to_year'],
                    $c['crossover_type'],
                    $this->calculationVersion
                ]);

                $count++;
            }

            // Hitta riders som bytte serie fran forriga aret
            $yearChanges = $this->pdo->query("
                SELECT
                    sp1.rider_id,
                    sp1.series_id as from_series_id,
                    sp2.series_id as to_series_id,
                    " . ($year - 1) . " as from_year,
                    $year as to_year,
                    'next_year' as crossover_type
                FROM series_participation sp1
                JOIN series_participation sp2
                    ON sp1.rider_id = sp2.rider_id
                    AND sp1.series_id != sp2.series_id
                WHERE sp1.season_year = " . ($year - 1) . "
                  AND sp2.season_year = $year
            ")->fetchAll();

            foreach ($yearChanges as $c) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO series_crossover (
                        rider_id, from_series_id, to_series_id,
                        from_year, to_year, crossover_type,
                        calculation_version, calculated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");

                $stmt->execute([
                    $c['rider_id'],
                    $c['from_series_id'],
                    $c['to_series_id'],
                    $c['from_year'],
                    $c['to_year'],
                    $c['crossover_type'],
                    $this->calculationVersion
                ]);

                $count++;
            }

            $this->endJob('success', $count);

        } catch (Exception $e) {
            $this->endJob('failed', $count, ['error' => $e->getMessage()]);
            throw $e;
        }

        return $count;
    }

    // =========================================================================
    // KLUBBSTATISTIK
    // =========================================================================

    /**
     * Berakna club yearly stats
     *
     * @param int $year Sasong
     * @return int Antal rader skapade
     */
    public function calculateClubStats(int $year): int {
        $jobId = $this->startJob('club-stats', (string)$year);
        if ($jobId === false) {
            return 0;
        }

        $count = 0;

        try {
            // Berakna per klubb
            $clubs = $this->pdo->query("
                SELECT DISTINCT r.club_id
                FROM riders r
                JOIN v_canonical_riders v ON r.id = v.original_rider_id
                JOIN results res ON res.cyclist_id = v.original_rider_id
                JOIN events e ON res.event_id = e.id
                WHERE r.club_id IS NOT NULL
                  AND YEAR(e.date) = $year
            ")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($clubs as $clubId) {
                $stats = $this->calculateSingleClubYearlyStats($clubId, $year);

                if ($stats) {
                    $this->upsertClubYearlyStats($clubId, $year, $stats);
                    $count++;
                }
            }

            $this->endJob('success', $count);

        } catch (Exception $e) {
            $this->endJob('failed', $count, ['error' => $e->getMessage()]);
            throw $e;
        }

        return $count;
    }

    /**
     * Berakna statistik for en enskild klubb ett ar
     */
    private function calculateSingleClubYearlyStats(int $clubId, int $year): ?array {
        // Aktiva riders i klubben detta ar
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(DISTINCT v.canonical_rider_id) as active_riders,
                COUNT(res.id) as total_events_participation,
                SUM(COALESCE(res.points, 0)) as total_points,
                SUM(CASE WHEN res.position <= 10 THEN 1 ELSE 0 END) as top_10_finishes,
                SUM(CASE WHEN res.position <= 3 THEN 1 ELSE 0 END) as podiums,
                SUM(CASE WHEN res.position = 1 THEN 1 ELSE 0 END) as wins
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
            JOIN riders r ON v.canonical_rider_id = r.id
            WHERE r.club_id = ?
              AND YEAR(e.date) = ?
        ");
        $stmt->execute([$clubId, $year]);
        $basic = $stmt->fetch();

        if (!$basic || $basic['active_riders'] == 0) {
            return null;
        }

        // Nya riders (forsta aret i klubben)
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT rys.rider_id) as new_riders
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE r.club_id = ?
              AND rys.season_year = ?
              AND rys.is_rookie = 1
        ");
        $stmt->execute([$clubId, $year]);
        $newRiders = $stmt->fetchColumn() ?: 0;

        // Retained riders
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT rys.rider_id) as retained
            FROM rider_yearly_stats rys
            JOIN riders r ON rys.rider_id = r.id
            WHERE r.club_id = ?
              AND rys.season_year = ?
              AND rys.is_retained = 1
        ");
        $stmt->execute([$clubId, $year]);
        $retained = $stmt->fetchColumn() ?: 0;

        // Churned (var med forra aret men inte detta)
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT prev.rider_id) as churned
            FROM rider_yearly_stats prev
            JOIN riders r ON prev.rider_id = r.id
            LEFT JOIN rider_yearly_stats curr
                ON prev.rider_id = curr.rider_id
                AND curr.season_year = ?
            WHERE r.club_id = ?
              AND prev.season_year = ?
              AND curr.id IS NULL
        ");
        $stmt->execute([$year, $clubId, $year - 1]);
        $churned = $stmt->fetchColumn() ?: 0;

        // Primary discipline
        $stmt = $this->pdo->prepare("
            SELECT e.discipline, COUNT(*) as cnt
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
            JOIN riders r ON v.canonical_rider_id = r.id
            WHERE r.club_id = ?
              AND YEAR(e.date) = ?
              AND e.discipline IS NOT NULL
            GROUP BY e.discipline
            ORDER BY cnt DESC
            LIMIT 1
        ");
        $stmt->execute([$clubId, $year]);
        $discipline = $stmt->fetch();

        return [
            'active_riders' => (int)$basic['active_riders'],
            'new_riders' => (int)$newRiders,
            'retained_riders' => (int)$retained,
            'churned_riders' => (int)$churned,
            'total_events_participation' => (int)$basic['total_events_participation'],
            'total_points' => (float)$basic['total_points'],
            'top_10_finishes' => (int)$basic['top_10_finishes'],
            'podiums' => (int)$basic['podiums'],
            'wins' => (int)$basic['wins'],
            'primary_discipline' => $discipline ? $discipline['discipline'] : null
        ];
    }

    /**
     * Upsert club yearly stats
     */
    private function upsertClubYearlyStats(int $clubId, int $year, array $stats): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO club_yearly_stats (
                club_id, season_year, active_riders, new_riders, retained_riders,
                churned_riders, total_events_participation, total_points,
                top_10_finishes, podiums, wins, primary_discipline,
                calculation_version, calculated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                active_riders = VALUES(active_riders),
                new_riders = VALUES(new_riders),
                retained_riders = VALUES(retained_riders),
                churned_riders = VALUES(churned_riders),
                total_events_participation = VALUES(total_events_participation),
                total_points = VALUES(total_points),
                top_10_finishes = VALUES(top_10_finishes),
                podiums = VALUES(podiums),
                wins = VALUES(wins),
                primary_discipline = VALUES(primary_discipline),
                calculation_version = VALUES(calculation_version),
                calculated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $clubId,
            $year,
            $stats['active_riders'],
            $stats['new_riders'],
            $stats['retained_riders'],
            $stats['churned_riders'],
            $stats['total_events_participation'],
            $stats['total_points'],
            $stats['top_10_finishes'],
            $stats['podiums'],
            $stats['wins'],
            $stats['primary_discipline'],
            $this->calculationVersion
        ]);
    }

    // =========================================================================
    // VENUE-STATISTIK
    // =========================================================================

    /**
     * Berakna venue yearly stats
     *
     * @param int $year Sasong
     * @return int Antal rader skapade
     */
    public function calculateVenueStats(int $year): int {
        $jobId = $this->startJob('venue-stats', (string)$year);
        if ($jobId === false) {
            return 0;
        }

        $count = 0;

        try {
            $venues = $this->pdo->query("
                SELECT
                    e.venue_id,
                    COUNT(DISTINCT e.id) as total_events,
                    COUNT(res.id) as total_participants,
                    COUNT(DISTINCT v.canonical_rider_id) as unique_riders
                FROM events e
                LEFT JOIN results res ON e.id = res.event_id
                LEFT JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
                WHERE YEAR(e.date) = $year
                  AND e.venue_id IS NOT NULL
                GROUP BY e.venue_id
            ")->fetchAll();

            foreach ($venues as $v) {
                if (!$v['venue_id']) continue;

                // Disciplines pa detta venue
                $disciplines = $this->pdo->prepare("
                    SELECT e.discipline, COUNT(*) as cnt
                    FROM events e
                    WHERE e.venue_id = ?
                      AND YEAR(e.date) = ?
                      AND e.discipline IS NOT NULL
                    GROUP BY e.discipline
                ");
                $disciplines->execute([$v['venue_id'], $year]);
                $disciplineData = [];
                while ($d = $disciplines->fetch()) {
                    $disciplineData[$d['discipline']] = (int)$d['cnt'];
                }

                // Serier som haft event har
                $series = $this->pdo->prepare("
                    SELECT DISTINCT e.series_id
                    FROM events e
                    WHERE e.venue_id = ?
                      AND YEAR(e.date) = ?
                      AND e.series_id IS NOT NULL
                ");
                $series->execute([$v['venue_id'], $year]);
                $seriesHosted = $series->fetchAll(PDO::FETCH_COLUMN);

                $stmt = $this->pdo->prepare("
                    INSERT INTO venue_yearly_stats (
                        venue_id, season_year, total_events, total_participants,
                        unique_riders, avg_participants_per_event, disciplines,
                        series_hosted, calculation_version, calculated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE
                        total_events = VALUES(total_events),
                        total_participants = VALUES(total_participants),
                        unique_riders = VALUES(unique_riders),
                        avg_participants_per_event = VALUES(avg_participants_per_event),
                        disciplines = VALUES(disciplines),
                        series_hosted = VALUES(series_hosted),
                        calculation_version = VALUES(calculation_version),
                        calculated_at = CURRENT_TIMESTAMP
                ");

                $avgPart = $v['total_events'] > 0
                    ? round($v['total_participants'] / $v['total_events'], 2)
                    : 0;

                $stmt->execute([
                    $v['venue_id'],
                    $year,
                    $v['total_events'],
                    $v['total_participants'],
                    $v['unique_riders'],
                    $avgPart,
                    json_encode($disciplineData),
                    json_encode($seriesHosted),
                    $this->calculationVersion
                ]);

                $count++;
            }

            $this->endJob('success', $count);

        } catch (Exception $e) {
            $this->endJob('failed', $count, ['error' => $e->getMessage()]);
            throw $e;
        }

        return $count;
    }

    // =========================================================================
    // HJALPMETODER
    // =========================================================================

    /**
     * Ar detta riders forsta ar?
     */
    private function isRookie(int $riderId, int $year): bool {
        $stmt = $this->pdo->prepare("
            SELECT MIN(YEAR(e.date)) as first_year
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
            WHERE v.canonical_rider_id = ?
        ");
        $stmt->execute([$riderId]);
        $firstYear = $stmt->fetchColumn();

        return $firstYear && (int)$firstYear === $year;
    }

    /**
     * Aterkom fran forriga aret?
     */
    private function wasRetained(int $riderId, int $year): bool {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM results res
            JOIN events e ON res.event_id = e.id
            JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
            WHERE v.canonical_rider_id = ?
              AND YEAR(e.date) = ?
            LIMIT 1
        ");
        $stmt->execute([$riderId, $year - 1]);
        return (bool)$stmt->fetch();
    }

    /**
     * Ar detta riders forsta serie nagonsin?
     */
    private function isFirstSeriesEver(int $riderId, int $seriesId, int $year): bool {
        $stmt = $this->pdo->prepare("
            SELECT MIN(sp.season_year) as first_year
            FROM series_participation sp
            WHERE sp.rider_id = ?
        ");
        $stmt->execute([$riderId]);
        $firstYear = $stmt->fetchColumn();

        // Om ingen tidigare participation, kolla i results
        if (!$firstYear) {
            $stmt = $this->pdo->prepare("
                SELECT MIN(YEAR(e.date)) as first_year, e.series_id as first_series
                FROM results res
                JOIN events e ON res.event_id = e.id
                JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
                WHERE v.canonical_rider_id = ?
                  AND e.series_id IS NOT NULL
                ORDER BY e.date ASC
                LIMIT 1
            ");
            $stmt->execute([$riderId]);
            $first = $stmt->fetch();

            if ($first && $first['first_year']) {
                return (int)$first['first_year'] === $year && (int)$first['first_series'] === $seriesId;
            }
        }

        return false;
    }

    // =========================================================================
    // REFRESH-METODER
    // =========================================================================

    /**
     * Kor alla berakningar for ett ar
     *
     * @param int $year Sasong
     * @return array Resultat per berakning
     */
    public function refreshAllStats(int $year): array {
        $results = [];

        $results['yearly_stats'] = $this->calculateYearlyStats($year);
        $results['series_participation'] = $this->calculateSeriesParticipation($year);
        $results['series_crossover'] = $this->calculateSeriesCrossover($year);
        $results['club_stats'] = $this->calculateClubStats($year);
        $results['venue_stats'] = $this->calculateVenueStats($year);

        return $results;
    }

    /**
     * Hamta lista over tillgangliga ar
     */
    public function getAvailableYears(): array {
        return $this->pdo->query("
            SELECT DISTINCT YEAR(date) as year
            FROM events
            WHERE date IS NOT NULL
            ORDER BY year DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Hamta IdentityResolver
     */
    public function getIdentityResolver(): IdentityResolver {
        return $this->identityResolver;
    }
}
