<?php
/**
 * TheHUB V1.0 - Welcome Page
 * Landing page with login requirement for data access
 */

// Prevent direct access
if (!defined('HUB_ROOT')) {
    header('Location: /');
    exit;
}

// Define page type for sponsor placements
define('HUB_PAGE_TYPE', 'home');

require_once HUB_ROOT . '/components/icons.php';

$isLoggedIn = hub_is_logged_in();

// Check for pending winback campaigns (logged-in users only)
$pendingWinbackCount = 0;
if ($isLoggedIn) {
    try {
        $currentUser = hub_current_user();
        $riderId = $currentUser['id'] ?? 0;
        if ($riderId > 0) {
            $winbackCheck = $pdo->query("SHOW TABLES LIKE 'winback_campaigns'");
            if ($winbackCheck->rowCount() > 0) {
                $campStmt = $pdo->query("SELECT * FROM winback_campaigns WHERE is_active = 1");
                $winbackCampaigns = $campStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($winbackCampaigns as $c) {
                    $brandIds = json_decode($c['brand_ids'] ?? '[]', true) ?: [];
                    $audienceType = $c['audience_type'] ?? 'churned';
                    $placeholders = !empty($brandIds) ? implode(',', array_fill(0, count($brandIds), '?')) : '0';

                    // Check if already responded
                    $respCheck = $pdo->prepare("SELECT id FROM winback_responses WHERE campaign_id = ? AND rider_id = ?");
                    $respCheck->execute([$c['id'], $riderId]);
                    if ($respCheck->fetch()) continue;

                    // Check qualification based on audience type
                    $qualifies = false;
                    if ($audienceType === 'churned') {
                        $sql = "SELECT COUNT(DISTINCT e.id) FROM results r JOIN events e ON r.event_id = e.id JOIN series s ON e.series_id = s.id WHERE r.cyclist_id = ? AND YEAR(e.date) BETWEEN ? AND ?" . (!empty($brandIds) ? " AND s.brand_id IN ($placeholders)" : "");
                        $params = array_merge([$riderId, $c['start_year'], $c['end_year']], $brandIds);
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $historicalCount = (int)$stmt->fetchColumn();

                        $sql2 = "SELECT COUNT(*) FROM results r JOIN events e ON r.event_id = e.id JOIN series s ON e.series_id = s.id WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" . (!empty($brandIds) ? " AND s.brand_id IN ($placeholders)" : "");
                        $params2 = array_merge([$riderId, $c['target_year']], $brandIds);
                        $stmt2 = $pdo->prepare($sql2);
                        $stmt2->execute($params2);
                        $qualifies = ($historicalCount > 0 && (int)$stmt2->fetchColumn() == 0);
                    } elseif ($audienceType === 'active') {
                        $sql = "SELECT COUNT(*) FROM results r JOIN events e ON r.event_id = e.id JOIN series s ON e.series_id = s.id WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" . (!empty($brandIds) ? " AND s.brand_id IN ($placeholders)" : "");
                        $params = array_merge([$riderId, $c['target_year']], $brandIds);
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $qualifies = ((int)$stmt->fetchColumn() > 0);
                    } elseif ($audienceType === 'one_timer') {
                        $sql = "SELECT COUNT(DISTINCT e.id) FROM results r JOIN events e ON r.event_id = e.id JOIN series s ON e.series_id = s.id WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" . (!empty($brandIds) ? " AND s.brand_id IN ($placeholders)" : "");
                        $params = array_merge([$riderId, $c['target_year']], $brandIds);
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $qualifies = ((int)$stmt->fetchColumn() == 1);
                    }
                    if ($qualifies) $pendingWinbackCount++;
                }
            }
        }
    } catch (PDOException $e) {
        $pendingWinbackCount = 0;
    }
}

// Always fetch data - page looks the same for all visitors
$pdo = hub_db();
$riderCount = 0;
$clubCount = 0;
$eventCount = 0;
$seriesCount = 0;
$upcomingEvents = [];
$recentResults = [];

// Load filter setting from database (batch-loaded, no extra query)
$filter = site_setting('public_riders_display', 'with_results');

// Get current statistics - cached in file for 1 hour (expensive COUNT queries)
$cacheFile = HUB_ROOT . '/.welcome-stats-cache.json';
$cacheMaxAge = 3600; // 1 hour
$statsFromCache = false;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached) {
        $riderCount = $cached['rider_count'] ?? 0;
        $clubCount = $cached['club_count'] ?? 0;
        $eventCount = $cached['event_count'] ?? 0;
        $seriesCount = $cached['series_count'] ?? 0;
        $statsFromCache = true;
    }
}

try {
    if (!$statsFromCache) {
        if ($filter === 'with_results') {
            $countsRow = $pdo->query("
                SELECT
                    (SELECT COUNT(DISTINCT r.id) FROM riders r INNER JOIN results res ON r.id = res.cyclist_id) as rider_count,
                    (SELECT COUNT(DISTINCT c.id) FROM clubs c INNER JOIN riders r ON c.id = r.club_id INNER JOIN results res ON r.id = res.cyclist_id) as club_count,
                    (SELECT COUNT(DISTINCT e.id) FROM events e INNER JOIN results r ON e.id = r.event_id) as event_count,
                    (SELECT COUNT(*) FROM series WHERE status = 'active') as series_count
            ")->fetch(PDO::FETCH_ASSOC);
        } else {
            $countsRow = $pdo->query("
                SELECT
                    (SELECT COUNT(*) FROM riders WHERE active = 1) as rider_count,
                    (SELECT COUNT(*) FROM clubs) as club_count,
                    (SELECT COUNT(DISTINCT e.id) FROM events e INNER JOIN results r ON e.id = r.event_id) as event_count,
                    (SELECT COUNT(*) FROM series WHERE status = 'active') as series_count
            ")->fetch(PDO::FETCH_ASSOC);
        }
        $riderCount = $countsRow['rider_count'];
        $clubCount = $countsRow['club_count'];
        $eventCount = $countsRow['event_count'];
        $seriesCount = $countsRow['series_count'];

        // Cache stats to file
        @file_put_contents($cacheFile, json_encode([
            'rider_count' => $riderCount,
            'club_count' => $clubCount,
            'event_count' => $eventCount,
            'series_count' => $seriesCount,
        ]));
    }

    // Upcoming events - next 3 regardless of discipline
    $upcomingEvents = $pdo->query("
        SELECT e.id, e.name, e.date, e.end_date, e.location, e.discipline,
               e.logo as event_logo,
               COALESCE(s2.name, s.name) as series_name,
               COALESCE(sb2.logo, sb.logo, s2.logo, s.logo) as brand_logo
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN series_brands sb ON s.brand_id = sb.id
        LEFT JOIN series_events se ON se.event_id = e.id
        LEFT JOIN series s2 ON se.series_id = s2.id
        LEFT JOIN series_brands sb2 ON s2.brand_id = sb2.id
        WHERE e.date >= CURDATE() AND e.active = 1
        GROUP BY e.id
        ORDER BY e.date ASC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Recent results (events with results from last 30 days)
    $recentResults = $pdo->query("
        SELECT e.id, e.name, e.date, e.location,
               COUNT(DISTINCT r.cyclist_id) as participant_count
        FROM events e
        INNER JOIN results r ON e.id = r.event_id
        WHERE e.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY e.id
        ORDER BY e.date DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Keep defaults on error
}
?>

<?php
// Get homepage logo from branding (ONLY for homepage)
$homepageLogo = getBranding('logos.homepage');
?>
<div class="welcome-page">
    <!-- Big Logo Header -->
    <div class="welcome-header">
        <div class="welcome-logo-large">
            <?php if ($homepageLogo): ?>
            <img src="<?= h($homepageLogo) ?>" alt="TheHUB">
            <?php else: ?>
            <svg viewBox="0 0 200 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="200" height="200" rx="32" fill="var(--color-accent, #004a98)"/>
                <path d="M50 62.5 L100 37.5 L150 62.5 L150 137.5 L100 162.5 L50 137.5 Z" fill="none" stroke="white" stroke-width="5" stroke-linejoin="round"/>
                <path d="M100 37.5 L100 162.5" stroke="white" stroke-width="4" stroke-linecap="round"/>
                <path d="M50 62.5 L150 137.5" stroke="white" stroke-width="4" stroke-linecap="round"/>
                <path d="M150 62.5 L50 137.5" stroke="white" stroke-width="4" stroke-linecap="round"/>
                <circle cx="100" cy="100" r="20" fill="white"/>
            </svg>
            <?php endif; ?>
        </div>
    </div>

    <!-- Global Sponsor: Header Banner -->
    <?= render_global_sponsors('home', 'header_banner', '') ?>

    <!-- Global Sponsor: Content Top -->
    <?= render_global_sponsors('home', 'content_top', '') ?>

    <!-- About Section with Title -->
    <div class="welcome-about text-center">
        <h1 class="welcome-about-title">THEHUB</h1>
        <p class="welcome-about-subtitle">Välkommen till Svensk Gravity</p>
        <p class="welcome-about-desc">Här hittar du kalender, resultat, serieställningar, ranking och databas över åkare och klubbar.</p>
    </div>

    <!-- Stats Row -->
    <div class="welcome-stats">
        <div class="welcome-stat">
            <span class="stat-value"><?= number_format($riderCount) ?></span>
            <span class="stat-label">Åkare</span>
        </div>
        <div class="welcome-stat">
            <span class="stat-value"><?= number_format($clubCount) ?></span>
            <span class="stat-label">Klubbar</span>
        </div>
        <div class="welcome-stat">
            <span class="stat-value"><?= number_format($eventCount) ?></span>
            <span class="stat-label">Tävlingar</span>
        </div>
        <div class="welcome-stat">
            <span class="stat-value"><?= number_format($seriesCount) ?></span>
            <span class="stat-label">Serier</span>
        </div>
    </div>

    <!-- Navigation Grid -->
    <div class="welcome-nav-grid">
        <a href="/calendar" class="welcome-nav-card">
            <?= hub_icon('calendar', 'welcome-nav-icon') ?>
            <h3>Kalender</h3>
            <p>Kommande tävlingar och event</p>
        </a>
        <a href="/results" class="welcome-nav-card">
            <?= hub_icon('flag', 'welcome-nav-icon') ?>
            <h3>Resultat</h3>
            <p>Se alla tävlingsresultat</p>
        </a>
        <a href="/series" class="welcome-nav-card">
            <?= hub_icon('trophy', 'welcome-nav-icon') ?>
            <h3>Serier</h3>
            <p>Tävlingsserier och ställningar</p>
        </a>
        <a href="/ranking" class="welcome-nav-card">
            <?= hub_icon('trending-up', 'welcome-nav-icon') ?>
            <h3>Ranking</h3>
            <p>24 månaders rullande ranking</p>
        </a>
        <a href="/database" class="welcome-nav-card">
            <?= hub_icon('users', 'welcome-nav-icon') ?>
            <h3>Databas</h3>
            <p>Sök åkare och klubbar</p>
        </a>
        <?php if ($isLoggedIn): ?>
        <a href="/profile" class="welcome-nav-card welcome-nav-card--accent">
            <?= hub_icon('user', 'welcome-nav-icon') ?>
            <h3>Min Profil</h3>
            <p>Dina uppgifter & resultat</p>
        </a>
        <?php else: ?>
        <a href="/login" class="welcome-nav-card welcome-nav-card--accent">
            <?= hub_icon('log-in', 'welcome-nav-icon') ?>
            <h3>Logga in</h3>
            <p>Åtkomst till din profil</p>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($pendingWinbackCount > 0): ?>
    <!-- Winback Campaign Notification -->
    <a href="/profile/winback" class="welcome-winback-banner">
        <div class="welcome-winback-content">
            <?= hub_icon('gift', 'welcome-winback-icon') ?>
            <div>
                <strong>Du har en erbjudande som väntar!</strong>
                <span>Svara på en kort enkät och få en rabattkod</span>
            </div>
            <?= hub_icon('chevron-right', 'welcome-winback-arrow') ?>
        </div>
    </a>
    <style>
    .welcome-winback-banner {
        display: block;
        margin: var(--space-lg) 0;
        padding: var(--space-md) var(--space-lg);
        background: linear-gradient(135deg, rgba(55, 212, 214, 0.15), rgba(55, 212, 214, 0.05));
        border: 1px solid var(--color-accent);
        border-radius: var(--radius-md);
        text-decoration: none;
        color: var(--color-text-primary);
        transition: background 0.2s;
    }
    .welcome-winback-banner:hover {
        background: linear-gradient(135deg, rgba(55, 212, 214, 0.25), rgba(55, 212, 214, 0.1));
    }
    .welcome-winback-content {
        display: flex;
        align-items: center;
        gap: var(--space-md);
    }
    .welcome-winback-icon {
        width: 32px;
        height: 32px;
        color: var(--color-accent);
        flex-shrink: 0;
    }
    .welcome-winback-content div {
        flex: 1;
    }
    .welcome-winback-content strong {
        display: block;
        font-family: var(--font-heading-secondary);
        font-size: 1.05rem;
    }
    .welcome-winback-content span {
        color: var(--color-text-secondary);
        font-size: 0.875rem;
    }
    .welcome-winback-arrow {
        width: 20px;
        height: 20px;
        color: var(--color-accent);
        flex-shrink: 0;
    }
    @media (max-width: 767px) {
        .welcome-winback-banner {
            margin-left: -16px;
            margin-right: -16px;
            border-radius: 0;
            border-left: none;
            border-right: none;
            width: calc(100% + 32px);
        }
    }
    </style>
    <?php endif; ?>

    <?php if (!empty($upcomingEvents)): ?>
    <!-- Upcoming Events - 3 cards in a row -->
    <div class="welcome-section">
        <h2 class="welcome-section-title">
            <?= hub_icon('calendar', 'section-icon') ?>
            Kommande tävlingar
        </h2>
        <div class="welcome-upcoming-cards">
            <?php
            foreach ($upcomingEvents as $event):
                // Pick best logo: event logo > brand logo
                $displayLogo = !empty($event['event_logo']) ? $event['event_logo'] : ($event['brand_logo'] ?? '');
                // Format date range
                $startDate = strtotime($event['date']);
                $endDate = !empty($event['end_date']) ? strtotime($event['end_date']) : null;
                if ($endDate && $endDate > $startDate) {
                    if (date('M', $startDate) === date('M', $endDate)) {
                        $dateStr = date('j', $startDate) . '-' . date('j', $endDate) . ' ' . date('M', $endDate);
                    } else {
                        $dateStr = date('j M', $startDate) . ' - ' . date('j M', $endDate);
                    }
                } else {
                    $dateStr = date('j M', $startDate);
                }
            ?>
            <a href="/calendar/<?= $event['id'] ?>" class="welcome-upcoming-card">
                <?php if (!empty($displayLogo)): ?>
                <div class="welcome-card-logo">
                    <img src="<?= htmlspecialchars($displayLogo) ?>" alt="">
                </div>
                <?php endif; ?>
                <div class="welcome-card-body">
                    <h4 class="welcome-card-title"><?= htmlspecialchars($event['name']) ?></h4>
                    <div class="welcome-card-row">
                        <span class="welcome-card-date"><?= hub_icon('calendar', 'icon-xs') ?> <?= $dateStr ?></span>
                        <?php if (!empty($event['location'])): ?>
                        <span class="welcome-card-location"><?= hub_icon('map-pin', 'icon-xs') ?> <?= htmlspecialchars($event['location']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($event['series_name'])): ?>
                    <div class="welcome-card-brand"><?= htmlspecialchars($event['series_name']) ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="/calendar" class="welcome-more-link">
            Visa hela kalendern <?= hub_icon('arrow-right', 'icon-sm') ?>
        </a>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentResults)): ?>
    <!-- Recent Results -->
    <div class="welcome-section">
        <h2 class="welcome-section-title">
            <?= hub_icon('trophy', 'section-icon') ?>
            Senaste resultat
        </h2>
        <div class="welcome-event-list">
            <?php foreach ($recentResults as $event): ?>
            <a href="/event/<?= $event['id'] ?>" class="welcome-event-item">
                <div class="event-date-badge event-date-badge--results">
                    <span class="event-day"><?= date('j', strtotime($event['date'])) ?></span>
                    <span class="event-month"><?= date('M', strtotime($event['date'])) ?></span>
                </div>
                <div class="event-info">
                    <h4><?= htmlspecialchars($event['name']) ?></h4>
                    <p>
                        <span class="event-participants"><?= hub_icon('users', 'icon-xs') ?> <?= $event['participant_count'] ?> deltagare</span>
                        <?php if ($event['location']): ?>
                            <span class="event-location"><?= hub_icon('map-pin', 'icon-xs') ?> <?= htmlspecialchars($event['location']) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <?= hub_icon('chevron-right', 'event-arrow') ?>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="/results" class="welcome-more-link">
            Visa alla resultat <?= hub_icon('arrow-right', 'icon-sm') ?>
        </a>
    </div>
    <?php endif; ?>

    <!-- Global Sponsor: Content Bottom -->
    <?= render_global_sponsors('home', 'content_bottom', 'Tack till våra partners') ?>
</div>
