<?php
/**
 * TheHUB V3.5 - Event Detail (Calendar View)
 * Shows event info, registration form, and participant list
 */

$pdo = hub_db();
$eventId = $pageInfo['params']['id'] ?? 0;
$currentUser = hub_current_user();
$linkedChildren = $currentUser ? hub_get_linked_children($currentUser['id']) : [];

// Get event details
$stmt = $pdo->prepare("
    SELECT e.*, s.name as series_name, s.id as series_id
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    WHERE e.id = ?
");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Get event classes
$classStmt = $pdo->prepare("SELECT * FROM event_classes WHERE event_id = ? ORDER BY sort_order, name");
$classStmt->execute([$eventId]);
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

// Get registrations
$regStmt = $pdo->prepare("
    SELECT r.*, ri.firstname, ri.lastname, ri.club_id, c.name as club_name,
           ec.name as class_name
    FROM event_registrations r
    JOIN riders ri ON r.rider_id = ri.id
    LEFT JOIN clubs c ON ri.club_id = c.id
    LEFT JOIN event_classes ec ON r.class_id = ec.id
    WHERE r.event_id = ? AND r.status != 'cancelled'
    ORDER BY ec.sort_order, ri.lastname, ri.firstname
");
$regStmt->execute([$eventId]);
$registrations = $regStmt->fetchAll(PDO::FETCH_ASSOC);

$eventDate = strtotime($event['date']);
$isPast = $eventDate < time();
$isRegistrationOpen = !$isPast && ($event['registration_open'] ?? false);
?>

<div class="page-header">
    <nav class="breadcrumb" aria-label="Brödsmulor">
        <a href="/v3/calendar">Kalender</a>
        <span class="breadcrumb-sep">›</span>
        <span aria-current="page"><?= htmlspecialchars($event['name']) ?></span>
    </nav>
</div>

<div class="event-detail">
    <!-- Event Header -->
    <div class="event-hero">
        <div class="event-date-large">
            <span class="event-day"><?= date('j', $eventDate) ?></span>
            <span class="event-month"><?= strftime('%b', $eventDate) ?></span>
            <span class="event-year"><?= date('Y', $eventDate) ?></span>
        </div>
        <div class="event-info">
            <h1 class="event-title"><?= htmlspecialchars($event['name']) ?></h1>
            <?php if ($event['series_name']): ?>
                <a href="/v3/results/series/<?= $event['series_id'] ?>" class="event-series-link">
                    <?= htmlspecialchars($event['series_name']) ?>
                </a>
            <?php endif; ?>
            <?php if ($event['location']): ?>
                <p class="event-location">📍 <?= htmlspecialchars($event['location']) ?></p>
            <?php endif; ?>
        </div>
        <div class="event-status">
            <?php if ($isPast): ?>
                <span class="status-badge status-completed">Avklarat</span>
            <?php elseif ($isRegistrationOpen): ?>
                <span class="status-badge status-open">Anmälan öppen</span>
            <?php else: ?>
                <span class="status-badge status-upcoming">Kommande</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($event['description']): ?>
        <div class="event-description card">
            <h2>Om eventet</h2>
            <div class="prose"><?= nl2br(htmlspecialchars($event['description'])) ?></div>
        </div>
    <?php endif; ?>

    <!-- Registration Section -->
    <?php if ($isRegistrationOpen): ?>
        <div class="registration-section card" id="registration">
            <h2>Anmälan</h2>

            <?php if (!$currentUser): ?>
                <div class="login-prompt">
                    <p>Logga in för att anmäla dig eller dina barn.</p>
                    <a href="/v3/profile/login?redirect=<?= urlencode('/v3/calendar/' . $eventId) ?>" class="btn btn-primary">
                        Logga in
                    </a>
                </div>
            <?php else: ?>
                <div class="registration-form" data-event-id="<?= $eventId ?>">
                    <!-- Quick add buttons -->
                    <div class="quick-add">
                        <h3>Snabbanmälan</h3>
                        <div class="quick-add-buttons">
                            <button type="button" class="btn btn-outline" data-action="add-self" data-rider-id="<?= $currentUser['id'] ?>">
                                + Mig själv
                            </button>
                            <?php foreach ($linkedChildren as $child): ?>
                                <button type="button" class="btn btn-outline" data-action="add-rider" data-rider-id="<?= $child['id'] ?>">
                                    + <?= htmlspecialchars($child['firstname']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Search for other riders -->
                    <div class="add-other">
                        <h3>Sök åkare</h3>
                        <?php include HUB_V3_ROOT . '/components/search-live.php'; ?>
                    </div>

                    <!-- Selected participants -->
                    <div class="selected-participants" id="selected-participants">
                        <!-- Filled by JS -->
                    </div>

                    <!-- Summary -->
                    <div class="registration-summary" id="registration-summary" style="display: none;">
                        <div class="summary-row">
                            <span>Totalt</span>
                            <strong id="summary-total">0 kr</strong>
                        </div>
                        <button type="button" class="btn btn-primary btn-lg" data-action="checkout">
                            Gå till betalning
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Participants List -->
    <div class="participants-section card">
        <h2>Anmälda (<?= count($registrations) ?>)</h2>

        <?php if (empty($registrations)): ?>
            <p class="text-muted">Inga anmälda ännu.</p>
        <?php else: ?>
            <?php
            // Group by class
            $byClass = [];
            foreach ($registrations as $reg) {
                $className = $reg['class_name'] ?? 'Okänd klass';
                $byClass[$className][] = $reg;
            }
            ?>
            <?php foreach ($byClass as $className => $classRegs): ?>
                <div class="participant-class">
                    <h3 class="class-title"><?= htmlspecialchars($className) ?> (<?= count($classRegs) ?>)</h3>
                    <div class="participant-list">
                        <?php foreach ($classRegs as $reg): ?>
                            <a href="/v3/database/rider/<?= $reg['rider_id'] ?>" class="participant-item">
                                <span class="participant-name">
                                    <?= htmlspecialchars($reg['firstname'] . ' ' . $reg['lastname']) ?>
                                </span>
                                <?php if ($reg['club_name']): ?>
                                    <span class="participant-club"><?= htmlspecialchars($reg['club_name']) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.event-hero {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: var(--space-lg);
    align-items: start;
    padding: var(--space-lg);
    background: var(--color-bg-card);
    border-radius: var(--radius-xl);
    margin-bottom: var(--space-lg);
}
.event-date-large {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--space-md);
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
.event-title {
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    margin-bottom: var(--space-xs);
}
.event-series-link {
    color: var(--color-accent);
    text-decoration: none;
    font-weight: var(--weight-medium);
}
.event-location {
    color: var(--color-text-secondary);
    margin-top: var(--space-sm);
}
.status-badge {
    padding: var(--space-xs) var(--space-md);
    border-radius: var(--radius-full);
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
}
.status-open { background: var(--color-success-bg); color: var(--color-success); }
.status-completed { background: var(--color-text-secondary); color: white; }
.status-upcoming { background: var(--color-accent-light); color: var(--color-accent); }

.card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.card h2 {
    font-size: var(--text-lg);
    margin-bottom: var(--space-md);
}

.quick-add-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
}
.btn-outline {
    background: transparent;
    border: 1px solid var(--color-border);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all var(--transition-fast);
}
.btn-outline:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
}

.participant-class {
    margin-bottom: var(--space-lg);
}
.class-title {
    font-size: var(--text-md);
    color: var(--color-text-secondary);
    margin-bottom: var(--space-sm);
    padding-bottom: var(--space-xs);
    border-bottom: 1px solid var(--color-border);
}
.participant-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: var(--space-xs);
}
.participant-item {
    display: flex;
    flex-direction: column;
    padding: var(--space-sm);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: background var(--transition-fast);
}
.participant-item:hover {
    background: var(--color-bg-hover);
}
.participant-name {
    font-weight: var(--weight-medium);
}
.participant-club {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.login-prompt {
    text-align: center;
    padding: var(--space-xl);
}
.btn-primary {
    background: var(--color-accent);
    color: white;
    border: none;
    padding: var(--space-sm) var(--space-lg);
    border-radius: var(--radius-md);
    font-weight: var(--weight-medium);
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin-top: var(--space-md);
}

@media (max-width: 600px) {
    .event-hero {
        grid-template-columns: 1fr;
        text-align: center;
    }
    .event-date-large {
        margin: 0 auto;
    }
}
</style>
