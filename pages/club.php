<?php
/**
 * V3.5 Club Profile Page - Large logo, all-time members with year badges
 */

$db = hub_db();
$clubId = intval($pageInfo['params']['id'] ?? 0);

// Include club achievements system
$achievementsClubPath = dirname(__DIR__) . '/includes/achievements-club.php';
if (file_exists($achievementsClubPath)) {
    require_once $achievementsClubPath;
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

if (!$clubId) {
    header('Location: /riders');
    exit;
}

$currentYear = (int)date('Y');

try {
    // Fetch club details
    $stmt = $db->prepare("SELECT * FROM clubs WHERE id = ?");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        include HUB_V3_ROOT . '/pages/404.php';
        return;
    }

    // Get ALL unique members across all years with their membership years AND stats
    $stmt = $db->prepare("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.birth_year,
            r.gender,
            GROUP_CONCAT(DISTINCT rcs.season_year ORDER BY rcs.season_year DESC SEPARATOR ',') as member_years,
            COALESCE(r.stats_total_starts, 0) as total_races,
            COALESCE(r.stats_total_wins, 0) as total_wins,
            COALESCE(r.stats_total_podiums, 0) as total_podiums
        FROM riders r
        INNER JOIN rider_club_seasons rcs ON r.id = rcs.rider_id AND rcs.club_id = ?
        WHERE r.active = 1
        GROUP BY r.id
        ORDER BY r.lastname, r.firstname
    ");
    $stmt->execute([$clubId]);
    $allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also get riders with current club_id but no season records
    $stmt = $db->prepare("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.birth_year,
            r.gender,
            COALESCE(r.stats_total_starts, 0) as total_races,
            COALESCE(r.stats_total_wins, 0) as total_wins,
            COALESCE(r.stats_total_podiums, 0) as total_podiums
        FROM riders r
        LEFT JOIN rider_club_seasons rcs ON r.id = rcs.rider_id AND rcs.club_id = ?
        WHERE r.club_id = ? AND r.active = 1 AND rcs.id IS NULL
        ORDER BY r.lastname, r.firstname
    ");
    $stmt->execute([$clubId, $clubId]);
    $currentMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Merge: add current year to those without season records
    foreach ($currentMembers as $member) {
        $member['member_years'] = (string)$currentYear;
        $allMembers[] = $member;
    }

    // Get unique member IDs
    $memberIds = array_unique(array_column($allMembers, 'id'));
    $uniqueMembers = [];
    foreach ($allMembers as $m) {
        if (!isset($uniqueMembers[$m['id']])) {
            $uniqueMembers[$m['id']] = $m;
        } else {
            // Merge years if duplicate
            $existingYears = explode(',', $uniqueMembers[$m['id']]['member_years']);
            $newYears = explode(',', $m['member_years']);
            $allYears = array_unique(array_merge($existingYears, $newYears));
            rsort($allYears);
            $uniqueMembers[$m['id']]['member_years'] = implode(',', $allYears);
        }
    }
    $members = array_values($uniqueMembers);

    // Get each member's ranking points contribution to this club
    // Uses simplified calculation: sum of weighted points from results where rider was in this club
    // IMPORTANT: Use latest event date as reference (not today's date) to match ranking calculation
    $memberRankingPoints = [];
    if (!empty($memberIds)) {
        try {
            // Get the latest event date with results (same reference as ranking system)
            $latestEventStmt = $db->prepare("
                SELECT MAX(e.date) as latest_date
                FROM events e
                JOIN results r ON r.event_id = e.id
                WHERE r.status = 'finished'
                AND e.discipline IN ('ENDURO', 'DH')
            ");
            $latestEventStmt->execute();
            $latestRow = $latestEventStmt->fetch(PDO::FETCH_ASSOC);
            $referenceDate = $latestRow['latest_date'] ?? date('Y-m-d');

            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $cutoffDate = date('Y-m-d', strtotime($referenceDate . ' -24 months'));
            // Priority for club assignment:
            // 1. r.club_id (explicitly set in results table)
            // 2. rider_club_seasons for the event year (historical club membership)
            // 3. rd.club_id (rider's current club as last fallback)
            // Calculate the 12-month boundary for time decay
            $twelveMoAgo = date('Y-m-d', strtotime($referenceDate . ' -12 months'));
            $stmt = $db->prepare("
                SELECT
                    r.cyclist_id as rider_id,
                    SUM(
                        CASE
                            WHEN COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0
                            THEN COALESCE(r.run_1_points, 0) + COALESCE(r.run_2_points, 0)
                            ELSE COALESCE(r.points, 0)
                        END *
                        CASE
                            WHEN e.date >= ? THEN 1.0
                            ELSE 0.5
                        END
                    ) as ranking_contribution
                FROM results r
                JOIN events e ON r.event_id = e.id
                JOIN riders rd ON r.cyclist_id = rd.id
                LEFT JOIN rider_club_seasons rcs ON rcs.rider_id = rd.id AND rcs.season_year = YEAR(e.date)
                WHERE r.cyclist_id IN ($placeholders)
                AND r.status = 'finished'
                AND COALESCE(r.club_id, rcs.club_id, rd.club_id) = ?
                AND e.date >= ?
                AND e.discipline IN ('ENDURO', 'DH')
                GROUP BY r.cyclist_id
            ");
            $params = array_merge([$twelveMoAgo], $memberIds, [$clubId, $cutoffDate]);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $memberRankingPoints[$row['rider_id']] = (float)$row['ranking_contribution'];
            }
        } catch (Exception $e) {
            // Ignore - just won't show ranking points
        }
    }

    // Add ranking points to members and sort by it
    foreach ($members as &$m) {
        $m['ranking_contribution'] = $memberRankingPoints[$m['id']] ?? 0;
    }
    unset($m);

    // Sort members by ranking contribution (highest first)
    usort($members, function($a, $b) {
        return $b['ranking_contribution'] <=> $a['ranking_contribution'];
    });

    // Get available years for stats
    $stmt = $db->prepare("
        SELECT DISTINCT season_year
        FROM rider_club_seasons
        WHERE club_id = ?
        ORDER BY season_year DESC
    ");
    $stmt->execute([$clubId]);
    $availableYears = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'season_year');

    // Calculate total stats across all years
    $totalUniqueMembers = count($members);

    // Get total results count for this club's members
    if (!empty($memberIds)) {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT res.id) as total_races,
                   SUM(res.points) as total_points
            FROM results res
            WHERE res.cyclist_id IN ($placeholders) AND res.status = 'finished'
        ");
        $stmt->execute($memberIds);
        $statsRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalRaces = (int)($statsRow['total_races'] ?? 0);
        $totalPoints = (int)($statsRow['total_points'] ?? 0);
    } else {
        $totalRaces = 0;
        $totalPoints = 0;
    }

    // Get club ranking position
    $clubRankingPosition = null;
    $clubRankingPoints = 0;
    $clubRidersCount = 0;
    $clubEventsCount = 0;
    $parentDb = function_exists('getDB') ? getDB() : null;
    if ($rankingFunctionsLoaded && $parentDb && function_exists('getSingleClubRanking')) {
        $clubRanking = getSingleClubRanking($parentDb, $clubId, 'GRAVITY');
        if ($clubRanking) {
            $clubRankingPosition = $clubRanking['ranking_position'] ?? null;
            $clubRankingPoints = $clubRanking['total_ranking_points'] ?? 0;
            $clubRidersCount = $clubRanking['riders_count'] ?? 0;
            $clubEventsCount = $clubRanking['events_count'] ?? 0;
        }
    }

    // Get club ranking history from snapshots (for graph)
    $clubRankingHistory = [];
    $clubRankingHistoryFull = []; // All history for "Visa historik"
    $clubRankingHistory24m = [];  // Last 24 months for main chart
    try {
        $historyStmt = $db->prepare("
            SELECT
                snapshot_date,
                DATE_FORMAT(snapshot_date, '%Y-%m') as month,
                DATE_FORMAT(snapshot_date, '%b') as month_short,
                ranking_position,
                total_ranking_points,
                riders_count,
                events_count,
                position_change
            FROM club_ranking_snapshots
            WHERE club_id = ? AND discipline = 'GRAVITY'
            ORDER BY snapshot_date ASC
        ");
        $historyStmt->execute([$clubId]);
        $allSnapshots = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Store full history for "Visa historik" section
        $clubRankingHistoryFull = $allSnapshots;

        // Filter to last 24 months for main chart
        // Use latest snapshot date as reference (not today's date)
        $latestSnapshotDate = !empty($allSnapshots) ? end($allSnapshots)['snapshot_date'] : date('Y-m-d');
        $cutoff24m = date('Y-m-d', strtotime($latestSnapshotDate . ' -24 months'));
        $clubRankingHistory24m = array_filter($allSnapshots, function($snap) use ($cutoff24m) {
            return $snap['snapshot_date'] >= $cutoff24m;
        });
        $clubRankingHistory24m = array_values($clubRankingHistory24m);

        // Group by month for compact display (take latest per month)
        $byMonth = [];
        foreach ($allSnapshots as $snap) {
            $byMonth[$snap['month']] = $snap;
        }
        $clubRankingHistory = array_values($byMonth);
        $clubRankingHistory = array_slice($clubRankingHistory, -6);
    } catch (Exception $e) {
        // Ignore errors
    }

    // Calculate ranking change from start
    $clubRankingChange = 0;
    if (!empty($clubRankingHistory) && $clubRankingPosition) {
        $startPosition = $clubRankingHistory[0]['ranking_position'] ?? $clubRankingPosition;
        $clubRankingChange = $startPosition - $clubRankingPosition; // Positive = improved
    }

    // Count members per year for display
    $membersPerYear = [];
    foreach ($availableYears as $year) {
        $membersPerYear[$year] = 0;
    }
    foreach ($members as $m) {
        $years = explode(',', $m['member_years']);
        foreach ($years as $y) {
            if (isset($membersPerYear[$y])) {
                $membersPerYear[$y]++;
            }
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $club = null;
}

if (!$club) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Check for club logo
$clubLogo = null;
$clubLogoDir = dirname(__DIR__) . '/uploads/clubs/';
$clubLogoUrl = '/uploads/clubs/';
foreach (['jpg', 'jpeg', 'png', 'webp', 'svg'] as $ext) {
    if (file_exists($clubLogoDir . $clubId . '.' . $ext)) {
        $clubLogo = $clubLogoUrl . $clubId . '.' . $ext . '?v=' . filemtime($clubLogoDir . $clubId . '.' . $ext);
        break;
    }
}
if (!$clubLogo && !empty($club['logo'])) {
    $clubLogo = $club['logo'];
}
?>

<link rel="stylesheet" href="/assets/css/pages/club.css?v=<?= file_exists(dirname(__DIR__) . '/assets/css/pages/club.css') ? filemtime(dirname(__DIR__) . '/assets/css/pages/club.css') : time() ?>">

<?php if (isset($error)): ?>
<div class="alert alert-error">
    <i data-lucide="alert-circle"></i>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php
// Prepare ranking chart data for Chart.js - MAIN CHART shows only last 24 months
$hasClubRankingChart = false;
$clubRankingChartLabels = [];
$clubRankingChartData = [];
$swedishMonthsShort = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

// Use 24-month filtered data for main chart
if ($clubRankingPosition && !empty($clubRankingHistory24m) && count($clubRankingHistory24m) >= 2) {
    $hasClubRankingChart = true;
    foreach ($clubRankingHistory24m as $rh) {
        $monthNum = isset($rh['month']) ? (int)date('n', strtotime($rh['month'] . '-01')) - 1 : 0;
        $clubRankingChartLabels[] = ucfirst($swedishMonthsShort[$monthNum % 12] ?? '');
        $clubRankingChartData[] = (int)$rh['ranking_position'];
    }
}

// Generate club initials
$clubInitials = '';
$words = preg_split('/\s+/', $club['name']);
foreach ($words as $word) {
    if (strlen($word) > 0 && ctype_alpha($word[0])) {
        $clubInitials .= strtoupper($word[0]);
    }
    if (strlen($clubInitials) >= 2) break;
}
if (strlen($clubInitials) < 2 && strlen($club['name']) >= 2) {
    $clubInitials = strtoupper(substr($club['name'], 0, 2));
}
?>

<!-- 2-Column Layout (Same as Rider Page) -->
<div class="club-profile-layout">
    <!-- LEFT COLUMN: Ranking, Members -->
    <div class="left-column">

        <!-- RANKING CARD -->
        <?php if ($clubRankingPosition): ?>
        <div class="card club-ranking-card">
            <div class="dashboard-chart-header">
                <div class="dashboard-chart-stats">
                    <div class="dashboard-stat">
                        <span class="dashboard-stat-value dashboard-stat-value--red">#<?= $clubRankingPosition ?></span>
                        <span class="dashboard-stat-label">Position</span>
                    </div>
                    <div class="dashboard-stat">
                        <span class="dashboard-stat-value"><?= number_format($clubRankingPoints, 0) ?></span>
                        <span class="dashboard-stat-label">Poäng</span>
                    </div>
                    <div class="dashboard-stat">
                        <span class="dashboard-stat-value"><?= $clubRidersCount ?></span>
                        <span class="dashboard-stat-label">Åkare</span>
                    </div>
                </div>
            </div>
            <?php if ($hasClubRankingChart): ?>
            <div class="dashboard-chart-body">
                <canvas id="clubRankingChart"></canvas>
            </div>
            <?php endif; ?>
            <div class="dashboard-chart-footer">
                <?php if (count($clubRankingHistoryFull) >= 3): ?>
                <button type="button" class="btn-calc-ranking-inline" onclick="toggleClubHistory()">
                    <i data-lucide="history"></i>
                    <span>Visa historik</span>
                </button>
                <?php endif; ?>
                <a href="/ranking/clubs" class="btn-calc-ranking-inline">
                    <i data-lucide="list"></i>
                    <span>Alla klubbar</span>
                </a>
            </div>
        </div>

        <!-- INLINE HISTORY SECTION (hidden by default) -->
        <?php if (count($clubRankingHistoryFull) >= 3):
            $clubHistoryLabels = [];
            $clubHistoryData = [];
            $clubHistoryPoints = [];
            foreach ($clubRankingHistoryFull as $rh) {
                $date = strtotime($rh['snapshot_date'] ?? $rh['month'] . '-01');
                $clubHistoryLabels[] = date('M Y', $date);
                $clubHistoryData[] = (int)$rh['ranking_position'];
                $clubHistoryPoints[] = (float)($rh['total_ranking_points'] ?? 0);
            }
            $bestClubHistoryPos = !empty($clubHistoryData) ? min($clubHistoryData) : 0;
            $worstClubHistoryPos = !empty($clubHistoryData) ? max($clubHistoryData) : 0;
            $firstClubPos = $clubHistoryData[0] ?? 0;
            $lastClubPos = end($clubHistoryData) ?: 0;
            $clubImprovement = $firstClubPos - $lastClubPos;
        ?>
        <div id="clubHistorySection" class="card club-history-section" style="display: none;">
            <div class="card-header">
                <h3 class="card-title">
                    <i data-lucide="history"></i>
                    Rankinghistorik
                </h3>
                <button type="button" class="btn-close-history" onclick="toggleClubHistory()">
                    <i data-lucide="x"></i>
                </button>
            </div>
            <div class="card-body">
                <div class="history-stats-row">
                    <div class="history-stat">
                        <span class="history-stat-value text-success">#<?= $bestClubHistoryPos ?></span>
                        <span class="history-stat-label">Bästa</span>
                    </div>
                    <div class="history-stat">
                        <span class="history-stat-value">#<?= $worstClubHistoryPos ?></span>
                        <span class="history-stat-label">Sämsta</span>
                    </div>
                    <div class="history-stat">
                        <span class="history-stat-value <?= $clubImprovement > 0 ? 'text-success' : ($clubImprovement < 0 ? 'text-danger' : '') ?>">
                            <?= $clubImprovement > 0 ? '+' : '' ?><?= $clubImprovement ?>
                        </span>
                        <span class="history-stat-label">Utveckling</span>
                    </div>
                    <div class="history-stat">
                        <span class="history-stat-value"><?= count($clubHistoryData) ?></span>
                        <span class="history-stat-label">Datapunkter</span>
                    </div>
                </div>
                <div class="history-chart-container" style="height: 250px;">
                    <canvas id="clubHistoryChart"></canvas>
                </div>
            </div>
        </div>
        <script>
        const clubHistoryChartLabels = <?= json_encode($clubHistoryLabels) ?>;
        const clubHistoryChartData = <?= json_encode($clubHistoryData) ?>;
        const clubHistoryChartPoints = <?= json_encode($clubHistoryPoints) ?>;
        let clubHistoryChartInstance = null;

        function toggleClubHistory() {
            const section = document.getElementById('clubHistorySection');
            if (section.style.display === 'none') {
                section.style.display = 'block';
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                setTimeout(() => initClubHistoryChart(), 100);
                if (typeof lucide !== 'undefined') lucide.createIcons();
            } else {
                section.style.display = 'none';
            }
        }

        function initClubHistoryChart() {
            const ctx = document.getElementById('clubHistoryChart');
            if (!ctx || clubHistoryChartInstance) return;
            clubHistoryChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: clubHistoryChartLabels,
                    datasets: [{
                        label: 'Ranking',
                        data: clubHistoryChartData,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const idx = context.dataIndex;
                                    const points = clubHistoryChartPoints[idx] || 0;
                                    return ['Position: #' + context.raw, 'Poäng: ' + points.toFixed(0)];
                                }
                            }
                        }
                    },
                    scales: {
                        y: { reverse: true, min: 1, title: { display: true, text: 'Position' }, ticks: { stepSize: 1 } },
                        x: { ticks: { maxRotation: 45, minRotation: 45 } }
                    }
                }
            });
        }
        </script>
        <?php endif; ?>
        <?php endif; ?>

        <!-- MEMBERS SECTION -->
        <div class="card club-members-card">
            <div class="section-header">
                <h2 class="section-title">
                    <i data-lucide="users"></i>
                    Alla medlemmar
                </h2>
                <p class="section-subtitle"><?= $totalUniqueMembers ?> unika medlemmar genom åren</p>
            </div>

    <?php if (empty($members)): ?>
    <div class="empty-state">
        <div class="empty-icon"><i data-lucide="users"></i></div>
        <h3>Inga medlemmar registrerade</h3>
        <p>Det finns inga registrerade medlemmar för denna klubb.</p>
    </div>
    <?php else: ?>

    <!-- Desktop Table View -->
    <div class="table-responsive members-table-desktop">
        <table class="table table--striped">
            <thead>
                <tr>
                    <th>Namn</th>
                    <th class="text-right">Poäng</th>
                    <th class="text-center">Race</th>
                    <th class="text-center">Vinster</th>
                    <th class="text-center">Pall</th>
                    <th class="text-right">År</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $member):
                    $years = explode(',', $member['member_years']);
                    sort($years);
                    $yearsStr = implode(', ', $years);
                    $isCurrentMember = in_array($currentYear, $years);
                    $rankingPts = $member['ranking_contribution'] ?? 0;
                ?>
                <tr onclick="window.location='/rider/<?= $member['id'] ?>'" class="cursor-pointer <?= $isCurrentMember ? 'member-current-row' : '' ?>">
                    <td>
                        <div class="member-name-cell">
                            <div class="member-avatar-small">
                                <?= strtoupper(substr($member['firstname'], 0, 1) . substr($member['lastname'], 0, 1)) ?>
                            </div>
                            <div class="member-name-info">
                                <span class="member-name"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></span>
                                <?php if ($member['birth_year']): ?>
                                <span class="member-birth"><?= $member['birth_year'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="text-right">
                        <?php if ($rankingPts > 0): ?>
                        <span class="member-ranking-pts"><?= number_format($rankingPts, 0) ?></span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int)$member['total_races'] ?></td>
                    <td class="text-center"><?= (int)$member['total_wins'] ?></td>
                    <td class="text-center"><?= (int)$member['total_podiums'] ?></td>
                    <td class="text-right member-years-cell"><?= htmlspecialchars($yearsStr) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="members-list-mobile">
        <?php foreach ($members as $member):
            $years = explode(',', $member['member_years']);
            sort($years);
            $yearsStr = implode(', ', $years);
            $isCurrentMember = in_array($currentYear, $years);
            $rankingPts = $member['ranking_contribution'] ?? 0;
        ?>
        <a href="/rider/<?= $member['id'] ?>" class="member-row <?= $isCurrentMember ? 'member-current' : '' ?>">
            <div class="member-avatar-small">
                <?= strtoupper(substr($member['firstname'], 0, 1) . substr($member['lastname'], 0, 1)) ?>
            </div>

            <div class="member-info-mobile">
                <div class="member-name-row">
                    <span class="member-name"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></span>
                    <?php if ($rankingPts > 0): ?>
                    <span class="member-ranking-pts-mobile"><?= number_format($rankingPts, 0) ?> p</span>
                    <?php endif; ?>
                </div>
                <div class="member-stats-row">
                    <span class="stat-mini"><?= (int)$member['total_races'] ?> race</span>
                    <span class="stat-mini"><?= (int)$member['total_wins'] ?> vinst</span>
                    <span class="stat-mini"><?= (int)$member['total_podiums'] ?> pall</span>
                </div>
            </div>

            <div class="member-arrow">
                <i data-lucide="chevron-right"></i>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
        </div><!-- End club-members-card -->

    </div><!-- End left-column -->

    <!-- RIGHT COLUMN: Profile Card -->
    <div class="right-column">

        <!-- CLUB PROFILE CARD - Portrait Style (like rider page) -->
        <div class="card club-profile-card-v4">
            <!-- Square Logo or Initials -->
            <div class="club-logo-hero <?= $clubLogo ? '' : 'initials-bg' ?>">
                <?php if ($clubLogo): ?>
                    <img src="<?= htmlspecialchars($clubLogo) ?>" alt="<?= htmlspecialchars($club['name']) ?>">
                <?php else: ?>
                    <div class="club-initials"><?= htmlspecialchars($clubInitials) ?></div>
                <?php endif; ?>
            </div>

            <div class="club-info-centered">
                <h1 class="club-name-hero"><?= htmlspecialchars($club['name']) ?></h1>
                <?php if ($club['city']): ?>
                <span class="club-subtitle">
                    <i data-lucide="map-pin"></i>
                    <?= htmlspecialchars($club['city']) ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Stats Row -->
            <div class="club-stats-row">
                <div class="club-stat-item">
                    <span class="stat-value"><?= $totalUniqueMembers ?></span>
                    <span class="stat-label">Medlemmar</span>
                </div>
                <div class="club-stat-item">
                    <span class="stat-value"><?= count($availableYears) ?></span>
                    <span class="stat-label">Säsonger</span>
                </div>
                <div class="club-stat-item">
                    <span class="stat-value"><?= $clubEventsCount ?></span>
                    <span class="stat-label">Tävlingar</span>
                </div>
            </div>

            <?php if ($club['website'] || $club['email']): ?>
            <div class="club-contact-links">
                <?php if ($club['website']): ?>
                <a href="<?= htmlspecialchars($club['website']) ?>" target="_blank" rel="noopener" class="contact-link">
                    <i data-lucide="globe"></i>
                    <span>Webbplats</span>
                </a>
                <?php endif; ?>
                <?php if ($club['email']): ?>
                <a href="mailto:<?= htmlspecialchars($club['email']) ?>" class="contact-link">
                    <i data-lucide="mail"></i>
                    <span>E-post</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (function_exists('renderClubAchievements')): ?>
        <div class="club-achievements-section">
            <?= renderClubAchievements($db, $clubId) ?>
        </div>
        <?php endif; ?>

    </div><!-- End right-column -->

</div><!-- End club-profile-layout -->

<?php
// Get detailed achievements for modal
$clubDetailedAchievements = [];
if (function_exists('getClubDetailedAchievements')) {
    $clubDetailedAchievements = getClubDetailedAchievements($db, $clubId);
}
?>

<?php if (!empty($clubDetailedAchievements)): ?>
<link rel="stylesheet" href="/assets/css/achievements.css?v=<?= file_exists(dirname(__DIR__) . '/assets/css/achievements.css') ? filemtime(dirname(__DIR__) . '/assets/css/achievements.css') : time() ?>">
<!-- Club Achievement Details Modal -->
<div id="clubAchievementModal" class="club-modal-overlay">
    <div class="club-modal">
        <div class="club-modal-header">
            <h3 id="clubAchievementModalTitle">
                <i data-lucide="award"></i>
                <span></span>
            </h3>
            <button type="button" class="club-modal-close" id="closeClubModalBtn">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="club-modal-body" id="clubAchievementModalBody">
            <!-- Content populated by JS -->
        </div>
    </div>
</div>

<script>
const clubDetailedAchievements = <?= json_encode($clubDetailedAchievements) ?>;

function openClubAchievementModal(achievementType) {
    const data = clubDetailedAchievements[achievementType];
    if (!data || !data.items || data.items.length === 0) return;

    const modal = document.getElementById('clubAchievementModal');
    const titleSpan = document.querySelector('#clubAchievementModalTitle span');
    const body = document.getElementById('clubAchievementModalBody');

    titleSpan.textContent = data.label;

    let html = '<div class="achievement-details-list">';

    if (achievementType === 'unique_champions') {
        // Special format for unique champions - show rider with total wins
        data.items.forEach(item => {
            const riderName = item.firstname + ' ' + item.lastname;
            const riderId = item.rider_id;
            const wins = item.wins;
            const years = item.years || '';

            html += '<div class="achievement-detail-item">';
            html += `<a href="/rider/${riderId}" class="achievement-detail-link">`;
            html += `<div class="achievement-detail-content">`;
            html += `<span class="achievement-detail-name">${riderName}</span>`;
            html += `<span class="achievement-detail-year">${wins} seger${wins > 1 ? 'ar' : ''} (${years})</span>`;
            html += `</div>`;
            html += `<i data-lucide="chevron-right" class="achievement-detail-arrow"></i></a>`;
            html += '</div>';
        });
    } else {
        // Format for series_champion and swedish_champion
        data.items.forEach(item => {
            const riderName = item.firstname + ' ' + item.lastname;
            const riderId = item.rider_id;
            const year = item.season_year || '';
            const seriesName = item.series_name || item.series_short_name || '';
            const eventName = item.event_name || item.achievement_value || '';
            const eventId = item.event_id;

            html += '<div class="achievement-detail-item">';
            html += `<a href="/rider/${riderId}" class="achievement-detail-link">`;
            html += `<div class="achievement-detail-content">`;
            if (seriesName) {
                html += `<span class="achievement-detail-series">${seriesName}</span>`;
            }
            html += `<span class="achievement-detail-name">${riderName}</span>`;
            html += `<span class="achievement-detail-year">${eventName || year}${year && eventName ? ' (' + year + ')' : ''}</span>`;
            html += `</div>`;
            html += `<i data-lucide="chevron-right" class="achievement-detail-arrow"></i></a>`;
            html += '</div>';
        });
    }

    html += '</div>';

    body.innerHTML = html;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeClubAchievementModal() {
    const modal = document.getElementById('clubAchievementModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Setup event listeners when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('clubAchievementModal');
    const closeBtn = document.getElementById('closeClubModalBtn');

    // Close button click
    if (closeBtn) {
        closeBtn.addEventListener('click', closeClubAchievementModal);
    }

    // Click outside modal to close
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeClubAchievementModal();
        });
    }

    // ESC key to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
            closeClubAchievementModal();
        }
    });

    // Add click handlers to badges with data
    document.querySelectorAll('.badge-item.clickable').forEach(badge => {
        badge.addEventListener('click', function() {
            const type = this.dataset.achievement;
            if (type && clubDetailedAchievements[type]) {
                openClubAchievementModal(type);
            }
        });
    });
});
</script>

<style>
.achievement-details-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}
.achievement-detail-item {
    background: var(--color-bg-secondary);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
.achievement-detail-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md);
    text-decoration: none;
    color: inherit;
    transition: background 0.2s;
}
.achievement-detail-link:hover {
    background: var(--color-bg-hover);
}
.achievement-detail-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.achievement-detail-series {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-secondary);
}
.achievement-detail-name {
    font-weight: 600;
    color: var(--color-text-primary);
}
.achievement-detail-year {
    font-size: 0.85rem;
    color: var(--color-text-secondary);
}
.achievement-detail-arrow {
    width: 20px;
    height: 20px;
    color: var(--color-text-secondary);
}
</style>
<?php endif; ?>

<?php if ($hasClubRankingChart): ?>
<!-- Club Ranking Chart Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const clubRankingCtx = document.getElementById('clubRankingChart');
    if (clubRankingCtx && typeof Chart !== 'undefined') {
        const ctx = clubRankingCtx.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 160);
        gradient.addColorStop(0, 'rgba(239, 68, 68, 0.3)');
        gradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');

        new Chart(clubRankingCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($clubRankingChartLabels) ?>,
                datasets: [{
                    data: <?= json_encode($clubRankingChartData) ?>,
                    borderColor: '#ef4444',
                    backgroundColor: gradient,
                    fill: 'start',
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointBackgroundColor: '#ef4444',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        titleFont: { size: 11 },
                        bodyFont: { size: 11 },
                        padding: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return 'Position: #' + context.raw;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        reverse: true,
                        min: 1,
                        display: true,
                        position: 'left',
                        grid: {
                            color: 'rgba(0, 0, 0, 0.06)',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 10 },
                            color: '#9ca3af',
                            padding: 4,
                            callback: function(value) {
                                return value;
                            }
                        }
                    },
                    x: {
                        display: true,
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: { size: 9 },
                            color: '#9ca3af',
                            maxRotation: 0
                        }
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                }
            }
        });
    }
});
</script>
<?php endif; ?>
