<?php
/**
 * TheHUB V3.5 - Welcome Page
 * Landing page with login requirement for data access
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /');
    exit;
}

require_once HUB_V3_ROOT . '/components/icons.php';

$isLoggedIn = hub_is_logged_in();

// Only fetch data if user is logged in
$riderCount = 0;
$clubCount = 0;
$eventCount = 0;
$seriesCount = 0;
$upcomingEvents = [];
$recentResults = [];

if ($isLoggedIn) {
    $pdo = hub_db();

    // Load filter setting from admin configuration
    $publicSettings = @include(HUB_V3_ROOT . '/config/public_settings.php');
    $filter = $publicSettings['public_riders_display'] ?? 'all';

    // Get current statistics
    try {
        // Total riders - respects admin filter setting
        if ($filter === 'with_results') {
            $riderCount = $pdo->query("
                SELECT COUNT(DISTINCT r.id)
                FROM riders r
                INNER JOIN results res ON r.id = res.cyclist_id
            ")->fetchColumn();

            $clubCount = $pdo->query("
                SELECT COUNT(DISTINCT c.id)
                FROM clubs c
                INNER JOIN riders r ON c.id = r.club_id
                INNER JOIN results res ON r.id = res.cyclist_id
            ")->fetchColumn();
        } else {
            $riderCount = $pdo->query("SELECT COUNT(*) FROM riders WHERE active = 1")->fetchColumn();
            $clubCount = $pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn();
        }

        // Total events with results
        $eventCount = $pdo->query("
            SELECT COUNT(DISTINCT e.id)
            FROM events e
            INNER JOIN results r ON e.id = r.event_id
        ")->fetchColumn();

        // Active series
        $seriesCount = $pdo->query("SELECT COUNT(*) FROM series WHERE status = 'active'")->fetchColumn();

        // Upcoming events
        $upcomingEvents = $pdo->query("
            SELECT e.id, e.name, e.date, e.location, s.name as series_name
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            WHERE e.date >= CURDATE() AND e.active = 1
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
}
?>

<div class="welcome-page">
    <!-- Header with Logo -->
    <div class="welcome-header">
        <div class="welcome-logo">
            <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="80" height="80" rx="16" fill="var(--color-accent, #004a98)"/>
                <path d="M20 25 L40 15 L60 25 L60 55 L40 65 L20 55 Z" fill="none" stroke="white" stroke-width="2.5" stroke-linejoin="round"/>
                <path d="M40 15 L40 65" stroke="white" stroke-width="2" stroke-linecap="round"/>
                <path d="M20 25 L60 55" stroke="white" stroke-width="2" stroke-linecap="round"/>
                <path d="M60 25 L20 55" stroke="white" stroke-width="2" stroke-linecap="round"/>
                <circle cx="40" cy="40" r="8" fill="white"/>
            </svg>
        </div>
        <h1 class="welcome-title">TheHUB</h1>
        <p class="welcome-subtitle">GravitySeries Competition Platform</p>
<?php $versionInfo = function_exists('getVersionInfo') ? getVersionInfo() : null; ?>
        <p class="welcome-version">v<?= APP_VERSION ?><?php if ($versionInfo && $versionInfo['deployment']): ?> [<?= APP_BUILD ?>.<?= str_pad($versionInfo['deployment'], 3, '0', STR_PAD_LEFT) ?>]<?php endif; ?></p>
    </div>

    <!-- About Section -->
    <div class="welcome-about">
        <h2>Välkommen till TheHUB</h2>
        <p>TheHUB är den centrala plattformen för GravitySeries och relaterade tävlingsserier. Här hittar du kalender, resultat, serieställningar, ranking och databas över åkare och klubbar.</p>
        <?php if (!$isLoggedIn): ?>
        <p class="welcome-about-note">Logga in för att se statistik, kommande tävlingar och senaste resultat.</p>
        <?php endif; ?>
    </div>

    <?php if ($isLoggedIn): ?>
    <!-- Stats Row - Only for logged in users -->
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
    <?php else: ?>
    <!-- Login CTA for visitors -->
    <div class="welcome-login-cta">
        <div class="welcome-login-cta-content">
            <?= hub_icon('lock', 'welcome-login-icon') ?>
            <div>
                <h3>Logga in för fullständig åtkomst</h3>
                <p>Se statistik, kommande tävlingar, senaste resultat och hantera din profil.</p>
            </div>
        </div>
        <a href="/login" class="btn btn-primary">Logga in</a>
    </div>
    <?php endif; ?>

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

    <?php if (!empty($upcomingEvents)): ?>
    <!-- Upcoming Events -->
    <div class="welcome-section">
        <h2 class="welcome-section-title">
            <?= hub_icon('calendar', 'section-icon') ?>
            Kommande tävlingar
        </h2>
        <div class="welcome-event-list">
            <?php foreach ($upcomingEvents as $event): ?>
            <a href="/calendar/<?= $event['id'] ?>" class="welcome-event-item">
                <div class="event-date-badge">
                    <span class="event-day"><?= date('j', strtotime($event['date'])) ?></span>
                    <span class="event-month"><?= date('M', strtotime($event['date'])) ?></span>
                </div>
                <div class="event-info">
                    <h4><?= htmlspecialchars($event['name']) ?></h4>
                    <p>
                        <?php if ($event['series_name']): ?>
                            <span class="event-series"><?= htmlspecialchars($event['series_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($event['location']): ?>
                            <span class="event-location"><?= hub_icon('map-pin', 'icon-xs') ?> <?= htmlspecialchars($event['location']) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <?= hub_icon('chevron-right', 'event-arrow') ?>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="/calendar" class="welcome-more-link">
            Visa alla kommande event <?= hub_icon('arrow-right', 'icon-sm') ?>
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
</div>
