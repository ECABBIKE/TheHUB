<?php
/**
 * TheHUB V3.5 - Event Detail (Calendar View)
 * Based on V2 event-results.php but with V3.5 design
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /calendar');
    exit;
}

require_once HUB_V3_ROOT . '/components/icons.php';

$pdo = hub_db();

// Get event ID from multiple sources for robustness
$eventId = $pageInfo['params']['id'] ?? null;

// Fallback: try to get ID from URL path
if (!$eventId && isset($_SERVER['REQUEST_URI'])) {
    if (preg_match('/\/calendar\/(\d+)/', $_SERVER['REQUEST_URI'], $matches)) {
        $eventId = $matches[1];
    }
}

// Fallback: try to get ID from query string
if (!$eventId && isset($_GET['id'])) {
    $eventId = $_GET['id'];
}

// Fallback: try to get from page query param
if (!$eventId && isset($_GET['page'])) {
    $segments = explode('/', trim($_GET['page'], '/'));
    if (count($segments) >= 2 && is_numeric($segments[1])) {
        $eventId = $segments[1];
    }
}

$eventId = (int) $eventId;

if (!$eventId) {
    ?>
    <div class="card" style="padding:var(--space-xl);text-align:center;">
        <h2>Inget event valt</h2>
        <p>Ga tillbaka till <a href="/calendar">kalendern</a> och valj ett event.</p>
    </div>
    <?php
    return;
}

// Fetch event details (same as V2)
$stmt = $pdo->prepare("
    SELECT
        e.*,
        s.name as series_name,
        s.logo as series_logo,
        v.name as venue_name,
        v.city as venue_city
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN venues v ON e.venue_id = v.id
    WHERE e.id = ?
");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    ?>
    <div class="card" style="padding:var(--space-xl);text-align:center;">
        <h2>Event hittades inte</h2>
        <p>Event med ID <?= (int)$eventId ?> finns inte.</p>
        <a href="/calendar" class="btn">← Tillbaka till kalendern</a>
    </div>
    <?php
    return;
}

// Check if event is in the past
$eventDate = strtotime($event['date']);
$isPast = $eventDate < time();

// Fetch registered participants
$regStmt = $pdo->prepare("
    SELECT
        reg.*,
        r.id as rider_id,
        r.firstname,
        r.lastname,
        c.name as club_name,
        reg.category as class_name
    FROM event_registrations reg
    LEFT JOIN riders r ON reg.rider_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE reg.event_id = ?
    ORDER BY reg.category, r.lastname, r.firstname
");
$regStmt->execute([$eventId]);
$registrations = $regStmt->fetchAll(PDO::FETCH_ASSOC);

$totalRegistrations = count($registrations);

// Group by class
$regByClass = [];
foreach ($registrations as $reg) {
    $className = $reg['class_name'] ?? 'Okänd klass';
    if (!isset($regByClass[$className])) {
        $regByClass[$className] = [];
    }
    $regByClass[$className][] = $reg;
}
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/calendar">← Kalender</a>
    </nav>
</div>

<div class="event-detail">
    <!-- Event Header -->
    <div class="event-hero">
        <div class="event-date-box">
            <span class="event-day"><?= date('j', $eventDate) ?></span>
            <span class="event-month"><?= hub_month_short($eventDate) ?></span>
            <span class="event-year"><?= date('Y', $eventDate) ?></span>
        </div>

        <div class="event-info">
            <h1 class="event-title"><?= htmlspecialchars($event['name']) ?></h1>

            <?php if ($event['series_name']): ?>
                <a href="/series/<?= $event['series_id'] ?>" class="event-series">
                    <?= htmlspecialchars($event['series_name']) ?>
                </a>
            <?php endif; ?>

            <?php if ($event['location'] || $event['venue_city']): ?>
                <p class="event-location">
                    <?= hub_icon('map-pin', 'icon-sm') ?>
                    <?= htmlspecialchars($event['location'] ?? $event['venue_city']) ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="event-status">
            <?php if ($isPast): ?>
                <span class="status-badge past">Avklarat</span>
            <?php else: ?>
                <span class="status-badge upcoming">Kommande</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($event['description']): ?>
    <div class="card">
        <h2>Om eventet</h2>
        <div class="prose"><?= nl2br(htmlspecialchars($event['description'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Participant List -->
    <div class="card">
        <h2>Anmälda (<?= $totalRegistrations ?>)</h2>

        <?php if (empty($registrations)): ?>
            <p class="text-muted">Inga anmälda ännu.</p>
        <?php else: ?>
            <?php foreach ($regByClass as $className => $classRegs): ?>
            <div class="class-section">
                <h3 class="class-title"><?= htmlspecialchars($className) ?> (<?= count($classRegs) ?>)</h3>
                <div class="participant-grid">
                    <?php foreach ($classRegs as $reg): ?>
                    <a href="/rider/<?= $reg['rider_id'] ?>" class="participant-item">
                        <span class="participant-avatar">
                            <?= strtoupper(mb_substr($reg['firstname'] ?? '?', 0, 1)) ?>
                        </span>
                        <div class="participant-info">
                            <span class="participant-name">
                                <?= htmlspecialchars(($reg['firstname'] ?? '') . ' ' . ($reg['lastname'] ?? '')) ?>
                            </span>
                            <?php if ($reg['club_name']): ?>
                            <span class="participant-club"><?= htmlspecialchars($reg['club_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($isPast): ?>
    <div class="card">
        <a href="/event/<?= $eventId ?>" class="btn btn-primary btn-lg">
            <?= hub_icon('trending-up', 'icon') ?> Visa resultat
        </a>
    </div>
    <?php endif; ?>
</div>

