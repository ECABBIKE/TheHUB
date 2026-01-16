-- ============================================================================
-- Migration 009: First Season Journey Analysis
-- Version: 3.1.0
-- Created: 2026-01-16
--
-- PURPOSE:
-- Implements first season journey analysis for rookie riders.
-- Tracks patterns during first season that are associated with continued
-- participation or non-return.
--
-- GDPR COMPLIANCE:
-- - Uses "patterns associated with" language (not predictive/causal)
-- - Segments <10 individuals must be hidden/aggregated in UI
-- - No "at-risk" labels - uses "indicators of low continued activity"
-- - All data is aggregated/anonymized for reporting
--
-- TABLES CREATED:
-- - rider_first_season: Individual rider first season metrics
-- - first_season_aggregates: Aggregated cohort statistics (segment ≥10)
-- - first_season_kpi_definitions: KPI metadata for journey analysis
-- ============================================================================

-- ============================================================================
-- TABLE: rider_first_season
-- Stores calculated first season metrics per rider
-- ============================================================================
CREATE TABLE IF NOT EXISTS rider_first_season (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Identity
    rider_id INT NOT NULL,
    cohort_year YEAR NOT NULL,                    -- MIN(season_year) from first event

    -- First Season Activity Metrics
    total_starts INT DEFAULT 0,                   -- COUNT(results) in first season
    total_events INT DEFAULT 0,                   -- COUNT(DISTINCT event_id)
    total_finishes INT DEFAULT 0,                 -- COUNT(results WHERE status='finished')
    total_dnf INT DEFAULT 0,                      -- COUNT(results WHERE status='DNF')
    dnf_rate DECIMAL(5,4) DEFAULT NULL,           -- total_dnf / total_starts

    -- Result Quality
    best_position INT DEFAULT NULL,               -- MIN(position) where finished
    avg_position DECIMAL(6,2) DEFAULT NULL,       -- AVG(position) where finished
    median_position DECIMAL(6,2) DEFAULT NULL,    -- MEDIAN(position) where finished
    result_percentile DECIMAL(5,2) DEFAULT NULL,  -- Avg percentile within class
    podium_count INT DEFAULT 0,                   -- COUNT(position <= 3)
    top10_count INT DEFAULT 0,                    -- COUNT(position <= 10)

    -- Timing Patterns
    first_event_date DATE DEFAULT NULL,
    last_event_date DATE DEFAULT NULL,
    days_in_season INT DEFAULT NULL,              -- DATEDIFF(last, first)
    avg_days_between_starts DECIMAL(6,2) DEFAULT NULL,
    max_gap_days INT DEFAULT NULL,                -- Longest gap between events

    -- Season Distribution
    early_season_starts INT DEFAULT 0,            -- Events in first 1/3 of season
    mid_season_starts INT DEFAULT 0,              -- Events in middle 1/3
    late_season_starts INT DEFAULT 0,             -- Events in last 1/3
    season_spread_score DECIMAL(5,4) DEFAULT NULL,-- How evenly distributed (0-1)

    -- Social Context
    club_id INT DEFAULT NULL,
    club_had_other_rookies TINYINT(1) DEFAULT 0,  -- Were there other rookies in same club?
    club_rookie_count INT DEFAULT 0,              -- How many rookies in club that year
    gender ENUM('M', 'F', 'U') DEFAULT 'U',
    age_at_first_start INT DEFAULT NULL,          -- Calculated from birth_year
    class_id INT DEFAULT NULL,                    -- Primary class raced

    -- Discipline Mix
    primary_discipline VARCHAR(50) DEFAULT NULL,  -- Most common discipline
    discipline_count INT DEFAULT 1,               -- Number of different disciplines

    -- Outcome (for historical analysis)
    returned_year2 TINYINT(1) DEFAULT NULL,       -- Did rider return in year 2?
    returned_year3 TINYINT(1) DEFAULT NULL,       -- Did rider return in year 3?
    total_career_seasons INT DEFAULT NULL,        -- Total seasons active (from stats)
    last_active_year YEAR DEFAULT NULL,           -- Most recent year with activity

    -- Calculated Indicators (NOT predictions - patterns only)
    engagement_score DECIMAL(5,2) DEFAULT NULL,   -- Composite engagement metric
    activity_pattern VARCHAR(50) DEFAULT NULL,    -- 'high_engagement', 'moderate', 'low_engagement'

    -- Metadata
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    snapshot_id INT DEFAULT NULL,
    data_quality_score DECIMAL(5,4) DEFAULT NULL, -- Data completeness (0-1)

    -- Indexes
    INDEX idx_cohort_year (cohort_year),
    INDEX idx_rider (rider_id),
    INDEX idx_club (club_id),
    INDEX idx_returned (returned_year2, returned_year3),
    INDEX idx_pattern (activity_pattern),
    INDEX idx_snapshot (snapshot_id),

    UNIQUE KEY uk_rider_cohort (rider_id, cohort_year),

    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
    FOREIGN KEY (snapshot_id) REFERENCES analytics_snapshots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: first_season_aggregates
-- Aggregated statistics by cohort/segment (minimum 10 riders per segment)
-- Used for reporting to maintain GDPR compliance
-- ============================================================================
CREATE TABLE IF NOT EXISTS first_season_aggregates (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Segment Definition
    cohort_year YEAR NOT NULL,
    segment_type ENUM('overall', 'gender', 'age_group', 'club', 'discipline', 'class', 'engagement_level') NOT NULL,
    segment_value VARCHAR(100) DEFAULT NULL,      -- NULL for 'overall', specific value otherwise

    -- Population (must be ≥10 for display)
    total_riders INT NOT NULL,

    -- Activity Aggregates
    avg_total_starts DECIMAL(6,2) DEFAULT NULL,
    median_total_starts DECIMAL(6,2) DEFAULT NULL,
    avg_total_events DECIMAL(6,2) DEFAULT NULL,
    avg_dnf_rate DECIMAL(5,4) DEFAULT NULL,

    -- Result Aggregates
    avg_result_percentile DECIMAL(5,2) DEFAULT NULL,
    median_result_percentile DECIMAL(5,2) DEFAULT NULL,
    avg_best_position DECIMAL(6,2) DEFAULT NULL,
    pct_with_podium DECIMAL(5,4) DEFAULT NULL,    -- % of segment with at least 1 podium
    pct_with_top10 DECIMAL(5,4) DEFAULT NULL,     -- % with at least 1 top-10

    -- Timing Aggregates
    avg_days_in_season DECIMAL(6,2) DEFAULT NULL,
    avg_gap_between_events DECIMAL(6,2) DEFAULT NULL,
    avg_season_spread DECIMAL(5,4) DEFAULT NULL,

    -- Outcome Aggregates (patterns, not predictions)
    pct_returned_year2 DECIMAL(5,4) DEFAULT NULL, -- % that returned in year 2
    pct_returned_year3 DECIMAL(5,4) DEFAULT NULL, -- % that returned in year 3
    avg_career_seasons DECIMAL(4,2) DEFAULT NULL, -- Avg total seasons for those who started this cohort

    -- Distribution Buckets (for histograms)
    starts_distribution JSON DEFAULT NULL,        -- {"1": 45, "2-3": 30, "4-5": 15, "6+": 10}
    percentile_distribution JSON DEFAULT NULL,    -- {"0-25": 25, "26-50": 25, ...}

    -- Statistical Measures
    return_rate_ci_lower DECIMAL(5,4) DEFAULT NULL,  -- 95% CI lower bound
    return_rate_ci_upper DECIMAL(5,4) DEFAULT NULL,  -- 95% CI upper bound

    -- Metadata
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    snapshot_id INT DEFAULT NULL,

    INDEX idx_cohort_segment (cohort_year, segment_type),
    INDEX idx_segment_value (segment_type, segment_value),
    INDEX idx_snapshot (snapshot_id),

    UNIQUE KEY uk_cohort_segment (cohort_year, segment_type, segment_value, snapshot_id),

    FOREIGN KEY (snapshot_id) REFERENCES analytics_snapshots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: first_season_kpi_definitions
-- Metadata for first season journey KPIs
-- ============================================================================
CREATE TABLE IF NOT EXISTS first_season_kpi_definitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kpi_key VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(200) NOT NULL,
    description TEXT,
    category ENUM('activity', 'results', 'timing', 'social', 'outcome', 'composite') NOT NULL,
    data_type ENUM('integer', 'decimal', 'percentage', 'boolean', 'category') NOT NULL,
    unit VARCHAR(50) DEFAULT NULL,

    -- SQL formula for calculation (documentation)
    formula TEXT DEFAULT NULL,

    -- Interpretation guidance
    higher_is_better TINYINT(1) DEFAULT NULL,     -- NULL = neutral
    typical_range VARCHAR(100) DEFAULT NULL,      -- e.g., "0-100", "1-15"

    -- Flags
    is_heuristic TINYINT(1) DEFAULT 0,            -- Pattern-based, not factual
    requires_minimum_n INT DEFAULT 10,            -- Minimum sample size for reporting
    gdpr_sensitive TINYINT(1) DEFAULT 0,          -- Requires extra care

    -- Display
    display_order INT DEFAULT 0,
    visible_in_ui TINYINT(1) DEFAULT 1,
    visible_in_export TINYINT(1) DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INSERT: KPI Definitions for First Season Journey
-- ============================================================================
INSERT INTO first_season_kpi_definitions
    (kpi_key, display_name, description, category, data_type, unit, formula, higher_is_better, typical_range, is_heuristic, requires_minimum_n, display_order)
VALUES
    -- Activity KPIs
    ('total_starts', 'Antal starter', 'Totalt antal starter under första säsongen', 'activity', 'integer', 'starter', 'COUNT(results) WHERE season = cohort_year', 1, '1-15', 0, 1, 10),
    ('total_events', 'Antal event', 'Antal unika tävlingar deltagna', 'activity', 'integer', 'event', 'COUNT(DISTINCT event_id)', 1, '1-10', 0, 1, 11),
    ('total_finishes', 'Antal målgångar', 'Starter som resulterade i placering', 'activity', 'integer', 'målgångar', 'COUNT(results WHERE status=finished)', 1, '1-15', 0, 1, 12),
    ('dnf_rate', 'DNF-andel', 'Andel starter som inte fullföljdes', 'activity', 'percentage', '%', 'total_dnf / total_starts', 0, '0-30%', 0, 1, 13),

    -- Result KPIs
    ('best_position', 'Bästa placering', 'Bästa uppnådda placering', 'results', 'integer', 'plats', 'MIN(position) WHERE finished', 1, '1-50', 0, 1, 20),
    ('result_percentile', 'Resultat-percentil', 'Genomsnittlig percentil inom klass (högre = bättre relativt andra)', 'results', 'percentage', '%', 'AVG((total_in_class - position) / total_in_class * 100)', 1, '0-100', 0, 1, 21),
    ('podium_count', 'Pallplatser', 'Antal topp-3 placeringar', 'results', 'integer', 'podier', 'COUNT(position <= 3)', 1, '0-5', 0, 1, 22),

    -- Timing KPIs
    ('days_in_season', 'Dagar i säsongen', 'Dagar mellan första och sista start', 'timing', 'integer', 'dagar', 'DATEDIFF(last_event, first_event)', NULL, '0-180', 0, 1, 30),
    ('avg_days_between_starts', 'Snitt dagar mellan starter', 'Genomsnittlig tid mellan tävlingar', 'timing', 'decimal', 'dagar', 'AVG(days between consecutive starts)', 0, '7-60', 0, 1, 31),
    ('season_spread_score', 'Säsongsspridning', 'Hur jämnt fördelat deltagandet var över säsongen (1.0 = helt jämnt)', 'timing', 'decimal', 'score', 'Normalized entropy of monthly distribution', 1, '0-1', 0, 1, 32),

    -- Social KPIs
    ('club_rookie_count', 'Klubbkamrater (rookies)', 'Antal andra förstaårscyklister i samma klubb', 'social', 'integer', 'cyklister', 'COUNT(other rookies in same club same year)', NULL, '0-10', 0, 1, 40),

    -- Outcome KPIs (HEURISTIC - patterns only)
    ('returned_year2', 'Återkom år 2', 'Om cyklisten hade minst 1 start år 2', 'outcome', 'boolean', NULL, 'EXISTS(results WHERE year = cohort_year + 1)', 1, 'ja/nej', 1, 10, 50),
    ('returned_year3', 'Återkom år 3', 'Om cyklisten hade minst 1 start år 3', 'outcome', 'boolean', NULL, 'EXISTS(results WHERE year = cohort_year + 2)', 1, 'ja/nej', 1, 10, 51),
    ('total_career_seasons', 'Totalt antal säsonger', 'Antal säsonger med minst 1 start', 'outcome', 'integer', 'säsonger', 'COUNT(DISTINCT season_year with activity)', 1, '1-10', 1, 10, 52),

    -- Composite KPIs (HEURISTIC)
    ('engagement_score', 'Engagemangsscore', 'Sammansatt mått på första säsongens engagemang (baserat på aktivitet, resultat och tidsspridning)', 'composite', 'decimal', 'score', 'Weighted combination: starts*0.3 + events*0.2 + spread*0.2 + percentile*0.3', 1, '0-100', 1, 10, 60),
    ('activity_pattern', 'Aktivitetsmönster', 'Klassificering baserad på första säsongens beteende', 'composite', 'category', NULL, 'CASE on engagement_score thresholds', NULL, 'high/moderate/low', 1, 10, 61)
;

-- ============================================================================
-- VIEW: first_season_report_safe
-- Pre-filtered view that only shows segments with ≥10 riders (GDPR)
-- ============================================================================
CREATE OR REPLACE VIEW first_season_report_safe AS
SELECT
    fsa.*,
    CASE
        WHEN fsa.total_riders >= 10 THEN fsa.total_riders
        ELSE NULL
    END AS display_count,
    CASE
        WHEN fsa.total_riders >= 10 THEN fsa.pct_returned_year2
        ELSE NULL
    END AS display_return_rate_y2,
    CASE
        WHEN fsa.total_riders >= 10 THEN fsa.pct_returned_year3
        ELSE NULL
    END AS display_return_rate_y3,
    fsa.total_riders >= 10 AS is_displayable
FROM first_season_aggregates fsa;

-- ============================================================================
-- VIEW: cohort_overview
-- Quick overview per cohort year
-- ============================================================================
CREATE OR REPLACE VIEW cohort_overview AS
SELECT
    cohort_year,
    COUNT(*) AS total_rookies,
    AVG(total_starts) AS avg_starts,
    AVG(total_events) AS avg_events,
    AVG(dnf_rate) AS avg_dnf_rate,
    AVG(result_percentile) AS avg_percentile,
    SUM(CASE WHEN returned_year2 = 1 THEN 1 ELSE 0 END) / COUNT(*) AS return_rate_year2,
    SUM(CASE WHEN returned_year3 = 1 THEN 1 ELSE 0 END) / COUNT(*) AS return_rate_year3,
    AVG(engagement_score) AS avg_engagement,
    MAX(calculated_at) AS last_calculated
FROM rider_first_season
GROUP BY cohort_year
ORDER BY cohort_year DESC;

-- ============================================================================
-- INSERT: Analytics KPI audit for new journey KPIs
-- ============================================================================
INSERT INTO analytics_kpi_audit (kpi_key, old_definition, new_definition, change_type, changed_by, rationale)
SELECT
    kpi_key,
    NULL,
    CONCAT(display_name, ': ', description),
    'added',
    'migration_009',
    'First Season Journey module - tracking rookie patterns'
FROM first_season_kpi_definitions
WHERE kpi_key IN ('total_starts', 'returned_year2', 'engagement_score', 'activity_pattern');

-- ============================================================================
-- STORED PROCEDURE: sp_calculate_rider_first_season
-- Calculates first season metrics for a single rider or all riders
-- ============================================================================
DELIMITER //

CREATE PROCEDURE sp_calculate_rider_first_season(
    IN p_rider_id INT,           -- NULL = all riders
    IN p_cohort_year YEAR,       -- NULL = all years
    IN p_snapshot_id INT         -- Current snapshot (optional)
)
BEGIN
    DECLARE v_season_start DATE;
    DECLARE v_season_end DATE;

    -- Default season boundaries (April 1 - October 31)
    SET v_season_start = CONCAT(IFNULL(p_cohort_year, YEAR(CURDATE())), '-04-01');
    SET v_season_end = CONCAT(IFNULL(p_cohort_year, YEAR(CURDATE())), '-10-31');

    -- Insert/Update rider_first_season records
    INSERT INTO rider_first_season (
        rider_id, cohort_year, total_starts, total_events, total_finishes, total_dnf,
        dnf_rate, best_position, avg_position, result_percentile, podium_count, top10_count,
        first_event_date, last_event_date, days_in_season, avg_days_between_starts,
        club_id, gender, age_at_first_start, class_id, primary_discipline,
        returned_year2, returned_year3, total_career_seasons, last_active_year,
        calculated_at, snapshot_id
    )
    SELECT
        r.id AS rider_id,
        fs.cohort_year,
        fs.total_starts,
        fs.total_events,
        fs.total_finishes,
        fs.total_starts - fs.total_finishes AS total_dnf,
        CASE WHEN fs.total_starts > 0
             THEN (fs.total_starts - fs.total_finishes) / fs.total_starts
             ELSE NULL END AS dnf_rate,
        fs.best_position,
        fs.avg_position,
        fs.result_percentile,
        fs.podium_count,
        fs.top10_count,
        fs.first_event_date,
        fs.last_event_date,
        DATEDIFF(fs.last_event_date, fs.first_event_date) AS days_in_season,
        fs.avg_days_between,
        r.club_id,
        r.gender,
        CASE WHEN r.birth_year IS NOT NULL
             THEN fs.cohort_year - r.birth_year
             ELSE NULL END AS age_at_first_start,
        fs.primary_class_id,
        fs.primary_discipline,
        CASE WHEN y2.cnt > 0 THEN 1 ELSE 0 END AS returned_year2,
        CASE WHEN y3.cnt > 0 THEN 1 ELSE 0 END AS returned_year3,
        career.total_seasons,
        career.last_year,
        NOW(),
        p_snapshot_id
    FROM riders r
    INNER JOIN (
        -- First season calculation subquery
        SELECT
            res.cyclist_id,
            MIN(YEAR(e.date)) AS cohort_year,
            COUNT(*) AS total_starts,
            COUNT(DISTINCT res.event_id) AS total_events,
            SUM(CASE WHEN res.status = 'finished' OR res.position IS NOT NULL THEN 1 ELSE 0 END) AS total_finishes,
            MIN(CASE WHEN res.position IS NOT NULL THEN res.position END) AS best_position,
            AVG(CASE WHEN res.position IS NOT NULL THEN res.position END) AS avg_position,
            AVG(res.percentile_in_class) AS result_percentile,
            SUM(CASE WHEN res.position <= 3 THEN 1 ELSE 0 END) AS podium_count,
            SUM(CASE WHEN res.position <= 10 THEN 1 ELSE 0 END) AS top10_count,
            MIN(e.date) AS first_event_date,
            MAX(e.date) AS last_event_date,
            NULL AS avg_days_between,  -- Calculated separately if needed
            (SELECT class_id FROM results r2
             JOIN events e2 ON r2.event_id = e2.id
             WHERE r2.cyclist_id = res.cyclist_id
             AND YEAR(e2.date) = MIN(YEAR(e.date))
             GROUP BY class_id ORDER BY COUNT(*) DESC LIMIT 1) AS primary_class_id,
            (SELECT e3.discipline FROM results r3
             JOIN events e3 ON r3.event_id = e3.id
             WHERE r3.cyclist_id = res.cyclist_id
             AND YEAR(e3.date) = MIN(YEAR(e.date))
             GROUP BY e3.discipline ORDER BY COUNT(*) DESC LIMIT 1) AS primary_discipline
        FROM results res
        JOIN events e ON res.event_id = e.id
        WHERE e.date IS NOT NULL
          AND (p_rider_id IS NULL OR res.cyclist_id = p_rider_id)
        GROUP BY res.cyclist_id
        HAVING cohort_year = IFNULL(p_cohort_year, cohort_year)
    ) fs ON r.id = fs.cyclist_id
    -- Year 2 check
    LEFT JOIN (
        SELECT cyclist_id, COUNT(*) AS cnt
        FROM results res2
        JOIN events e2 ON res2.event_id = e2.id
        GROUP BY cyclist_id, YEAR(e2.date)
    ) y2 ON y2.cyclist_id = r.id
    -- Year 3 check
    LEFT JOIN (
        SELECT cyclist_id, COUNT(*) AS cnt
        FROM results res3
        JOIN events e3 ON res3.event_id = e3.id
        GROUP BY cyclist_id, YEAR(e3.date)
    ) y3 ON y3.cyclist_id = r.id
    -- Career stats
    LEFT JOIN (
        SELECT
            cyclist_id,
            COUNT(DISTINCT YEAR(e.date)) AS total_seasons,
            MAX(YEAR(e.date)) AS last_year
        FROM results res
        JOIN events e ON res.event_id = e.id
        GROUP BY cyclist_id
    ) career ON career.cyclist_id = r.id
    ON DUPLICATE KEY UPDATE
        total_starts = VALUES(total_starts),
        total_events = VALUES(total_events),
        total_finishes = VALUES(total_finishes),
        total_dnf = VALUES(total_dnf),
        dnf_rate = VALUES(dnf_rate),
        best_position = VALUES(best_position),
        avg_position = VALUES(avg_position),
        result_percentile = VALUES(result_percentile),
        podium_count = VALUES(podium_count),
        top10_count = VALUES(top10_count),
        first_event_date = VALUES(first_event_date),
        last_event_date = VALUES(last_event_date),
        days_in_season = VALUES(days_in_season),
        returned_year2 = VALUES(returned_year2),
        returned_year3 = VALUES(returned_year3),
        total_career_seasons = VALUES(total_career_seasons),
        last_active_year = VALUES(last_active_year),
        calculated_at = NOW(),
        snapshot_id = VALUES(snapshot_id);

END //

DELIMITER ;

-- ============================================================================
-- STORED PROCEDURE: sp_calculate_first_season_aggregates
-- Aggregates cohort statistics (only for segments ≥10)
-- ============================================================================
DELIMITER //

CREATE PROCEDURE sp_calculate_first_season_aggregates(
    IN p_cohort_year YEAR,
    IN p_snapshot_id INT
)
BEGIN
    -- Overall aggregates
    INSERT INTO first_season_aggregates (
        cohort_year, segment_type, segment_value, total_riders,
        avg_total_starts, median_total_starts, avg_total_events, avg_dnf_rate,
        avg_result_percentile, pct_with_podium, pct_with_top10,
        avg_days_in_season, avg_season_spread,
        pct_returned_year2, pct_returned_year3, avg_career_seasons,
        calculated_at, snapshot_id
    )
    SELECT
        cohort_year,
        'overall',
        NULL,
        COUNT(*),
        AVG(total_starts),
        NULL, -- Median calculated in PHP
        AVG(total_events),
        AVG(dnf_rate),
        AVG(result_percentile),
        SUM(CASE WHEN podium_count > 0 THEN 1 ELSE 0 END) / COUNT(*),
        SUM(CASE WHEN top10_count > 0 THEN 1 ELSE 0 END) / COUNT(*),
        AVG(days_in_season),
        AVG(season_spread_score),
        AVG(returned_year2),
        AVG(returned_year3),
        AVG(total_career_seasons),
        NOW(),
        p_snapshot_id
    FROM rider_first_season
    WHERE cohort_year = p_cohort_year OR p_cohort_year IS NULL
    GROUP BY cohort_year
    ON DUPLICATE KEY UPDATE
        total_riders = VALUES(total_riders),
        avg_total_starts = VALUES(avg_total_starts),
        avg_total_events = VALUES(avg_total_events),
        avg_dnf_rate = VALUES(avg_dnf_rate),
        avg_result_percentile = VALUES(avg_result_percentile),
        pct_with_podium = VALUES(pct_with_podium),
        pct_with_top10 = VALUES(pct_with_top10),
        avg_days_in_season = VALUES(avg_days_in_season),
        pct_returned_year2 = VALUES(pct_returned_year2),
        pct_returned_year3 = VALUES(pct_returned_year3),
        avg_career_seasons = VALUES(avg_career_seasons),
        calculated_at = NOW(),
        snapshot_id = VALUES(snapshot_id);

    -- Gender aggregates (only if segment ≥10)
    INSERT INTO first_season_aggregates (
        cohort_year, segment_type, segment_value, total_riders,
        avg_total_starts, avg_total_events, avg_dnf_rate,
        avg_result_percentile, pct_returned_year2, pct_returned_year3,
        calculated_at, snapshot_id
    )
    SELECT
        cohort_year,
        'gender',
        gender,
        COUNT(*),
        AVG(total_starts),
        AVG(total_events),
        AVG(dnf_rate),
        AVG(result_percentile),
        AVG(returned_year2),
        AVG(returned_year3),
        NOW(),
        p_snapshot_id
    FROM rider_first_season
    WHERE (cohort_year = p_cohort_year OR p_cohort_year IS NULL)
      AND gender IS NOT NULL
    GROUP BY cohort_year, gender
    HAVING COUNT(*) >= 10
    ON DUPLICATE KEY UPDATE
        total_riders = VALUES(total_riders),
        avg_total_starts = VALUES(avg_total_starts),
        avg_total_events = VALUES(avg_total_events),
        avg_dnf_rate = VALUES(avg_dnf_rate),
        avg_result_percentile = VALUES(avg_result_percentile),
        pct_returned_year2 = VALUES(pct_returned_year2),
        pct_returned_year3 = VALUES(pct_returned_year3),
        calculated_at = NOW(),
        snapshot_id = VALUES(snapshot_id);

    -- Engagement level aggregates
    INSERT INTO first_season_aggregates (
        cohort_year, segment_type, segment_value, total_riders,
        avg_total_starts, avg_total_events, avg_dnf_rate,
        avg_result_percentile, pct_returned_year2, pct_returned_year3,
        calculated_at, snapshot_id
    )
    SELECT
        cohort_year,
        'engagement_level',
        activity_pattern,
        COUNT(*),
        AVG(total_starts),
        AVG(total_events),
        AVG(dnf_rate),
        AVG(result_percentile),
        AVG(returned_year2),
        AVG(returned_year3),
        NOW(),
        p_snapshot_id
    FROM rider_first_season
    WHERE (cohort_year = p_cohort_year OR p_cohort_year IS NULL)
      AND activity_pattern IS NOT NULL
    GROUP BY cohort_year, activity_pattern
    HAVING COUNT(*) >= 10
    ON DUPLICATE KEY UPDATE
        total_riders = VALUES(total_riders),
        avg_total_starts = VALUES(avg_total_starts),
        avg_total_events = VALUES(avg_total_events),
        avg_dnf_rate = VALUES(avg_dnf_rate),
        avg_result_percentile = VALUES(avg_result_percentile),
        pct_returned_year2 = VALUES(pct_returned_year2),
        pct_returned_year3 = VALUES(pct_returned_year3),
        calculated_at = NOW(),
        snapshot_id = VALUES(snapshot_id);

END //

DELIMITER ;

-- ============================================================================
-- Migration complete marker
-- ============================================================================
INSERT INTO analytics_system_config (config_key, config_value, description)
VALUES
    ('first_season_journey_version', '3.1.0', 'First Season Journey module version'),
    ('first_season_min_segment_size', '10', 'Minimum segment size for reporting (GDPR)'),
    ('first_season_enabled', 'true', 'Enable First Season Journey calculations')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- Record migration
INSERT INTO analytics_kpi_audit (kpi_key, old_definition, new_definition, change_type, changed_by, rationale)
VALUES ('_migration_009', NULL, 'First Season Journey tables and procedures', 'migration', 'system', 'Migration 009 completed');
