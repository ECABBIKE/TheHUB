<?php
/**
 * TheHUB V3.5 - Event Detail (Calendar View)
 * Based on V2 event-results.php but with V3.5 design
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /v3/calendar');
    exit;
}

$pdo = hub_db();
$eventId = $pageInfo['params']['id'] ?? 0;

if (!$eventId) {
    ?>
    <div class="card" style="padding:var(--space-xl);text-align:center;">
        <h2>Inget event valt</h2>
        <p>Gå tillbaka till <a href="/v3/calendar">kalendern</a> och välj ett event.</p>
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
        <a href="/v3/calendar" class="btn">← Tillbaka till kalendern</a>
    </div>
    <?php
    return;
}

// Check if event is in the past
$eventDate = strtotime($event['date']);
$isPast = $eventDate < time();

// Fetch registered participants (same as V2)
$regStmt = $pdo->prepare("
    SELECT
        reg.*,
        r.id as rider_id,
        r.firstname,
        r.lastname,
        c.name as club_name,
        cls.name as class_name,
        cls.display_name as class_display_name
    FROM event_registrations reg
    LEFT JOIN riders r ON reg.rider_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    LEFT JOIN classes cls ON reg.class_id = cls.id
    WHERE reg.event_id = ?
    ORDER BY cls.sort_order, r.lastname, r.firstname
");
$regStmt->execute([$eventId]);
$registrations = $regStmt->fetchAll(PDO::FETCH_ASSOC);

$totalRegistrations = count($registrations);

// Group by class
$regByClass = [];
foreach ($registrations as $reg) {
    $className = $reg['class_display_name'] ?? $reg['class_name'] ?? 'Okänd klass';
    if (!isset($regByClass[$className])) {
        $regByClass[$className] = [];
    }
    $regByClass[$className][] = $reg;
}
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/v3/calendar">← Kalender</a>
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
                <a href="/v3/series/<?= $event['series_id'] ?>" class="event-series">
                    <?= htmlspecialchars($event['series_name']) ?>
                </a>
            <?php endif; ?>

            <?php if ($event['location'] || $event['venue_city']): ?>
                <p class="event-location">
                    📍 <?= htmlspecialchars($event['location'] ?? $event['venue_city']) ?>
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
                    <a href="/v3/rider/<?= $reg['rider_id'] ?>" class="participant-item">
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
        <a href="/v3/event/<?= $eventId ?>" class="btn btn-primary btn-lg">
            📊 Visa resultat
        </a>
    </div>
    <?php endif; ?>
</div>

<style>
.event-detail {
    max-width: 900px;
}

.event-hero {
    display: flex;
    gap: var(--space-lg);
    padding: var(--space-lg);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-lg);
    border: 1px solid var(--color-border);
}

.event-date-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    background: var(--color-accent);
    border-radius: var(--radius-lg);
    color: white;
    min-width: 80px;
}

.event-day {
    font-size: var(--text-3xl);
    font-weight: var(--weight-bold);
    line-height: 1;
}

.event-month {
    font-size: var(--text-sm);
    text-transform: uppercase;
}

.event-year {
    font-size: var(--text-xs);
    opacity: 0.8;
}

.event-info {
    flex: 1;
}

.event-title {
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    margin: 0 0 var(--space-xs);
}

.event-series {
    display: inline-block;
    color: var(--color-accent);
    text-decoration: none;
    font-weight: var(--weight-medium);
    margin-bottom: var(--space-xs);
}

.event-series:hover {
    text-decoration: underline;
}

.event-location {
    color: var(--color-text-secondary);
    margin: var(--space-sm) 0 0;
}

.event-status {
    flex-shrink: 0;
}

.status-badge {
    display: inline-block;
    padding: var(--space-xs) var(--space-md);
    border-radius: var(--radius-full);
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
}

.status-badge.past {
    background: var(--color-text-secondary);
    color: white;
}

.status-badge.upcoming {
    background: var(--color-success-bg);
    color: var(--color-success);
}

.card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
    border: 1px solid var(--color-border);
}

.card h2 {
    font-size: var(--text-lg);
    margin: 0 0 var(--space-md);
}

.prose {
    color: var(--color-text-secondary);
    line-height: 1.6;
}

.class-section {
    margin-bottom: var(--space-xl);
}

.class-section:last-child {
    margin-bottom: 0;
}

.class-title {
    font-size: var(--text-md);
    color: var(--color-accent);
    margin: 0 0 var(--space-sm);
    padding-bottom: var(--space-xs);
    border-bottom: 2px solid var(--color-accent);
}

.participant-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: var(--space-sm);
}

.participant-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: background var(--transition-fast);
}

.participant-item:hover {
    background: var(--color-bg-hover);
}

.participant-avatar {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-full);
    font-weight: var(--weight-bold);
    font-size: var(--text-sm);
    flex-shrink: 0;
}

.participant-info {
    min-width: 0;
}

.participant-name {
    display: block;
    font-weight: var(--weight-medium);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.participant-club {
    display: block;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-lg);
    border-radius: var(--radius-md);
    text-decoration: none;
    font-weight: var(--weight-medium);
    transition: all var(--transition-fast);
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: var(--color-accent);
    color: white;
}

.btn-primary:hover {
    opacity: 0.9;
}

.btn-lg {
    width: 100%;
    padding: var(--space-md);
    font-size: var(--text-lg);
}

@media (max-width: 600px) {
    .event-hero {
        flex-direction: column;
        text-align: center;
    }

    .event-date-box {
        align-self: center;
    }

    .event-status {
        align-self: center;
    }

    .participant-grid {
        grid-template-columns: 1fr;
    }
}
</style>
