# TheHUB V3.0 – KOMPLETT PROMPT
## Del 3: Sidor

---

# SIDOR

## /v3/pages/dashboard.php

```php
<?php
/**
 * Dashboard / Startsida
 */

// Hämta data
$upcomingEvents = []; // TODO: hub_get_upcoming_events(3)
$recentResults = [];  // TODO: hub_get_recent_results(3)
$topRanked = [];      // TODO: hub_get_top_ranked(5)
?>

<div class="page-header">
    <h1 class="page-title">Välkommen till TheHUB</h1>
    <p class="page-subtitle">Sveriges plattform för gravity cycling</p>
</div>

<div class="page-grid">
    
    <!-- Kommande events -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Kommande tävlingar</h2>
            <a href="<?= HUB_V3_URL ?>/calendar" class="btn btn--ghost">Visa alla →</a>
        </div>
        
        <div class="event-list">
            <?php if (empty($upcomingEvents)): ?>
                <p class="text-secondary">Inga kommande tävlingar just nu.</p>
            <?php else: ?>
                <?php foreach ($upcomingEvents as $event): ?>
                    <?php include HUB_V3_ROOT . '/components/event-card.php'; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    
    <!-- Senaste resultat -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Senaste resultat</h2>
            <a href="<?= HUB_V3_URL ?>/results" class="btn btn--ghost">Visa alla →</a>
        </div>
        
        <div class="event-list">
            <?php if (empty($recentResults)): ?>
                <p class="text-secondary">Inga resultat ännu.</p>
            <?php else: ?>
                <?php foreach ($recentResults as $event): ?>
                    <?php $context = 'results'; ?>
                    <?php include HUB_V3_ROOT . '/components/event-card.php'; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    
    <!-- Topp ranking -->
    <section class="card grid-full">
        <div class="card-header">
            <h2 class="card-title">Topp Ranking</h2>
            <a href="<?= HUB_V3_URL ?>/ranking" class="btn btn--ghost">Visa ranking →</a>
        </div>
        
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>Åkare</th>
                        <th>Klubb</th>
                        <th class="text-right">Poäng</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topRanked)): ?>
                        <tr><td colspan="4" class="text-center text-secondary">Ingen ranking ännu.</td></tr>
                    <?php else: ?>
                        <?php foreach ($topRanked as $i => $rider): ?>
                        <tr>
                            <td class="text-center">
                                <span class="placement-badge placement-badge--<?= $i < 3 ? ($i+1) : 'other' ?>">
                                    <?= $i + 1 ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= HUB_V3_URL ?>/database/rider/<?= $rider['id'] ?>">
                                    <?= htmlspecialchars($rider['name']) ?>
                                </a>
                            </td>
                            <td class="text-secondary"><?= htmlspecialchars($rider['club_name'] ?? '-') ?></td>
                            <td class="text-right font-bold"><?= number_format($rider['points'], 1) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    
</div>
```

---

## /v3/pages/calendar/index.php

```php
<?php
/**
 * Kalender - Kommande events
 */

$filter = $_GET['filter'] ?? 'upcoming';
$series = $_GET['series'] ?? '';
$month = $_GET['month'] ?? '';

// TODO: Hämta events baserat på filter
$events = []; // hub_get_events($filter, $series, $month)
$seriesList = []; // hub_get_all_series()
?>

<div class="page-header">
    <h1 class="page-title">Kalender</h1>
    <p class="page-subtitle">Kommande tävlingar och anmälan</p>
</div>

<!-- Filter -->
<div class="calendar-filters">
    <button class="calendar-filter <?= $filter === 'upcoming' ? 'is-active' : '' ?>" 
            data-filter="upcoming">Kommande</button>
    <button class="calendar-filter <?= $filter === 'past' ? 'is-active' : '' ?>" 
            data-filter="past">Tidigare</button>
    <?php if ($isLoggedIn): ?>
    <button class="calendar-filter <?= $filter === 'my' ? 'is-active' : '' ?>" 
            data-filter="my">Mina</button>
    <?php endif; ?>
    
    <span class="calendar-filter-divider"></span>
    
    <?php foreach ($seriesList as $s): ?>
    <button class="calendar-filter <?= $series === $s['id'] ? 'is-active' : '' ?>"
            data-series="<?= $s['id'] ?>">
        <?= htmlspecialchars($s['name']) ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- Events -->
<div class="event-list">
    <?php if (empty($events)): ?>
        <div class="empty-state">
            <p>Inga tävlingar hittades.</p>
        </div>
    <?php else: ?>
        <?php foreach ($events as $event): ?>
            <?php include HUB_V3_ROOT . '/components/event-card.php'; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
```

---

## /v3/pages/calendar/event.php

```php
<?php
/**
 * Event-sida med info och anmälan
 */

$eventId = $route['id'] ?? 0;
// TODO: Hämta event-data
$event = []; // hub_get_event($eventId)
$registrations = []; // hub_get_event_registrations($eventId)
$classes = []; // hub_get_event_classes($eventId)

$isOpen = ($event['status'] ?? '') === 'open';
$isPast = ($event['status'] ?? '') === 'completed';
?>

<div class="page-header">
    <a href="<?= HUB_V3_URL ?>/calendar" class="btn btn--ghost mb-sm">← Tillbaka</a>
    <h1 class="page-title"><?= htmlspecialchars($event['name'] ?? 'Event') ?></h1>
    <p class="page-subtitle">
        <?= date('j F Y', strtotime($event['date'] ?? 'now')) ?>
        <?php if ($event['location'] ?? ''): ?>
            • <?= htmlspecialchars($event['location']) ?>
        <?php endif; ?>
    </p>
</div>

<div class="page-grid page-grid--2col">
    
    <!-- Event Info -->
    <section class="card">
        <h2 class="card-title">Information</h2>
        
        <div class="event-info">
            <div class="event-info-row">
                <span class="event-info-label">Datum</span>
                <span><?= date('j F Y', strtotime($event['date'] ?? 'now')) ?></span>
            </div>
            <div class="event-info-row">
                <span class="event-info-label">Plats</span>
                <span><?= htmlspecialchars($event['location'] ?? '-') ?></span>
            </div>
            <div class="event-info-row">
                <span class="event-info-label">Serie</span>
                <span class="chip chip--<?= strtolower($event['series'] ?? 'default') ?>">
                    <?= htmlspecialchars($event['series_name'] ?? '-') ?>
                </span>
            </div>
            <div class="event-info-row">
                <span class="event-info-label">Format</span>
                <span><?= htmlspecialchars($event['format'] ?? '-') ?></span>
            </div>
            <div class="event-info-row">
                <span class="event-info-label">Anmälningsdeadline</span>
                <span><?= date('j F Y', strtotime($event['registration_deadline'] ?? 'now')) ?></span>
            </div>
        </div>
        
        <?php if ($event['description'] ?? ''): ?>
        <div class="event-description mt-lg">
            <h3>Om tävlingen</h3>
            <?= nl2br(htmlspecialchars($event['description'])) ?>
        </div>
        <?php endif; ?>
    </section>
    
    <!-- Anmälan eller Resultat -->
    <?php if (!$isPast && $isOpen): ?>
    <section class="card">
        <h2 class="card-title">Anmälan</h2>
        <p class="text-secondary mb-lg">Anmäl en eller flera deltagare.</p>
        
        <?php include HUB_V3_ROOT . '/components/participant-picker.php'; ?>
        
        <!-- Sammanfattning -->
        <div class="registration-summary mt-lg" id="registration-summary" style="display: none;">
            <h3 class="registration-summary-title">Sammanfattning</h3>
            <div class="registration-participants" id="summary-participants"></div>
            <div class="registration-total">
                <span>Totalt</span>
                <span class="registration-total-value" id="summary-total">0 kr</span>
            </div>
            <button type="button" class="btn btn--primary btn--block btn--lg" data-action="checkout">
                Gå till betalning
            </button>
        </div>
    </section>
    <?php elseif ($isPast): ?>
    <section class="card">
        <h2 class="card-title">Resultat</h2>
        <a href="<?= HUB_V3_URL ?>/results/<?= $eventId ?>" class="btn btn--primary">
            Visa resultat →
        </a>
    </section>
    <?php else: ?>
    <section class="card">
        <h2 class="card-title">Anmälan</h2>
        <p class="text-secondary">Anmälan har inte öppnat ännu.</p>
        <p class="text-sm mt-sm">Öppnar: <?= date('j F Y', strtotime($event['registration_opens'] ?? 'now')) ?></p>
    </section>
    <?php endif; ?>
    
    <!-- Anmälda -->
    <section class="card grid-full">
        <div class="card-header">
            <h2 class="card-title">Anmälda (<?= count($registrations) ?>)</h2>
        </div>
        
        <?php if (empty($registrations)): ?>
            <p class="text-secondary">Inga anmälda ännu.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Namn</th>
                            <th>Klubb</th>
                            <th>Klass</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $i => $reg): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <a href="<?= HUB_V3_URL ?>/database/rider/<?= $reg['rider_id'] ?>">
                                    <?= htmlspecialchars($reg['rider_name']) ?>
                                </a>
                            </td>
                            <td class="text-secondary"><?= htmlspecialchars($reg['club_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($reg['class_name'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    
</div>
```

---

## /v3/pages/results/index.php

```php
<?php
/**
 * Resultat - översikt
 */

$events = []; // TODO: hub_get_completed_events()
$series = []; // TODO: hub_get_series_with_standings()
?>

<div class="page-header">
    <h1 class="page-title">Resultat</h1>
    <p class="page-subtitle">Tävlingsresultat och serietabeller</p>
</div>

<div class="page-grid">
    
    <!-- Senaste resultat -->
    <section class="card grid-full">
        <div class="card-header">
            <h2 class="card-title">Senaste tävlingar</h2>
        </div>
        
        <div class="event-list">
            <?php foreach ($events as $event): ?>
                <?php $context = 'results'; ?>
                <?php include HUB_V3_ROOT . '/components/event-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
    
    <!-- Serietabeller -->
    <?php foreach ($series as $s): ?>
    <section class="card">
        <div class="card-header">
            <h2 class="card-title"><?= htmlspecialchars($s['name']) ?></h2>
            <a href="<?= HUB_V3_URL ?>/results/series/<?= $s['id'] ?>" class="btn btn--ghost">Visa alla →</a>
        </div>
        
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>Åkare</th>
                        <th class="text-right">Poäng</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($s['standings'], 0, 5) as $i => $standing): ?>
                    <tr>
                        <td class="text-center"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($standing['rider_name']) ?></td>
                        <td class="text-right font-bold"><?= $standing['points'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endforeach; ?>
    
</div>
```

---

## /v3/pages/database/index.php

```php
<?php
/**
 * Databas - Sök åkare och klubbar
 */

$tab = $_GET['tab'] ?? 'riders';
?>

<div class="page-header">
    <h1 class="page-title">Databas</h1>
    <p class="page-subtitle">Sök bland åkare och klubbar</p>
</div>

<!-- Flikar -->
<div class="database-tabs">
    <button class="database-tab <?= $tab === 'riders' ? 'is-active' : '' ?>" data-tab="riders">
        Åkare
    </button>
    <button class="database-tab <?= $tab === 'clubs' ? 'is-active' : '' ?>" data-tab="clubs">
        Klubbar
    </button>
</div>

<!-- Sök -->
<div class="database-search mb-lg">
    <?php 
    $type = $tab;
    $placeholder = $tab === 'riders' ? 'Sök åkare...' : 'Sök klubb...';
    $allowNew = false;
    include HUB_V3_ROOT . '/components/search-live.php'; 
    ?>
</div>

<!-- Resultat -->
<div class="database-results" id="database-results">
    <p class="text-secondary text-center">Börja skriva för att söka...</p>
</div>
```

---

## /v3/pages/database/rider.php

```php
<?php
/**
 * Åkarprofil
 */

$riderId = $route['id'] ?? 0;
$rider = hub_get_rider_by_id($riderId);

if (!$rider) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Hämta mer data
$results = []; // hub_get_rider_results($riderId)
$ranking = []; // hub_get_rider_ranking($riderId)
$breakdown = []; // hub_get_rider_points_breakdown($riderId)
?>

<div class="page-header">
    <a href="<?= HUB_V3_URL ?>/database" class="btn btn--ghost mb-sm">← Tillbaka</a>
</div>

<div class="rider-profile-header">
    <div class="rider-profile-avatar">
        <?= strtoupper(substr($rider['first_name'], 0, 1)) ?>
    </div>
    <div class="rider-profile-info">
        <h1 class="rider-profile-name"><?= htmlspecialchars($rider['first_name'] . ' ' . $rider['last_name']) ?></h1>
        <p class="rider-profile-club"><?= htmlspecialchars($rider['club_name'] ?? 'Ingen klubb') ?></p>
        <div class="rider-profile-meta">
            <?php if ($rider['uci_id'] ?? ''): ?>
                <span>UCI: <?= htmlspecialchars($rider['uci_id']) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="page-grid page-grid--2col">
    
    <!-- Stats -->
    <section class="card">
        <h2 class="card-title">Statistik</h2>
        <div class="stats-grid">
            <div class="stat-item">
                <span class="stat-value"><?= $ranking['position'] ?? '-' ?></span>
                <span class="stat-label">Ranking</span>
            </div>
            <div class="stat-item">
                <span class="stat-value"><?= number_format($ranking['points'] ?? 0, 1) ?></span>
                <span class="stat-label">Poäng</span>
            </div>
            <div class="stat-item">
                <span class="stat-value"><?= count($results) ?></span>
                <span class="stat-label">Starter</span>
            </div>
            <div class="stat-item">
                <span class="stat-value"><?= $ranking['wins'] ?? 0 ?></span>
                <span class="stat-label">Vinster</span>
            </div>
        </div>
    </section>
    
    <!-- Poängförklaring -->
    <section class="card">
        <?php include HUB_V3_ROOT . '/components/points-breakdown.php'; ?>
    </section>
    
    <!-- Resultat -->
    <section class="card grid-full">
        <div class="card-header">
            <h2 class="card-title">Resultat</h2>
        </div>
        
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Datum</th>
                        <th class="text-center">Plac.</th>
                        <th class="text-right">Tid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                    <tr>
                        <td>
                            <a href="<?= HUB_V3_URL ?>/results/<?= $result['event_id'] ?>">
                                <?= htmlspecialchars($result['event_name']) ?>
                            </a>
                        </td>
                        <td class="text-secondary"><?= date('Y-m-d', strtotime($result['date'])) ?></td>
                        <td class="text-center">
                            <span class="placement-badge placement-badge--<?= $result['placement'] <= 3 ? $result['placement'] : 'other' ?>">
                                <?= $result['placement'] ?>
                            </span>
                        </td>
                        <td class="text-right font-mono"><?= $result['time'] ?? '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    
</div>
```

---

## /v3/pages/ranking/index.php

```php
<?php
/**
 * Ranking - Huvudsida
 */

$topRiders = []; // hub_get_ranking_riders(20)
$topClubs = []; // hub_get_ranking_clubs(10)
?>

<div class="page-header">
    <h1 class="page-title">Ranking</h1>
    <p class="page-subtitle">24 månaders rullande ranking baserat på dina 8 bästa resultat</p>
</div>

<!-- Tabs -->
<div class="database-tabs mb-lg">
    <a href="<?= HUB_V3_URL ?>/ranking" class="database-tab is-active">Översikt</a>
    <a href="<?= HUB_V3_URL ?>/ranking/riders" class="database-tab">Alla åkare</a>
    <a href="<?= HUB_V3_URL ?>/ranking/clubs" class="database-tab">Klubbar</a>
    <a href="<?= HUB_V3_URL ?>/ranking/events" class="database-tab">Event</a>
</div>

<div class="page-grid page-grid--2col">
    
    <!-- Topp åkare -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Topp 20 Åkare</h2>
            <a href="<?= HUB_V3_URL ?>/ranking/riders" class="btn btn--ghost">Visa alla →</a>
        </div>
        
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>Åkare</th>
                        <th class="text-right">Poäng</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topRiders as $i => $rider): ?>
                    <tr>
                        <td class="text-center">
                            <span class="placement-badge placement-badge--<?= $i < 3 ? ($i+1) : 'other' ?>">
                                <?= $i + 1 ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= HUB_V3_URL ?>/database/rider/<?= $rider['id'] ?>">
                                <?= htmlspecialchars($rider['name']) ?>
                            </a>
                            <span class="text-muted text-sm"><?= htmlspecialchars($rider['club_name'] ?? '') ?></span>
                        </td>
                        <td class="text-right font-bold"><?= number_format($rider['points'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    
    <!-- Topp klubbar -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Topp 10 Klubbar</h2>
            <a href="<?= HUB_V3_URL ?>/ranking/clubs" class="btn btn--ghost">Visa alla →</a>
        </div>
        
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>Klubb</th>
                        <th class="text-right">Poäng</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topClubs as $i => $club): ?>
                    <tr>
                        <td class="text-center"><?= $i + 1 ?></td>
                        <td>
                            <a href="<?= HUB_V3_URL ?>/database/club/<?= $club['id'] ?>">
                                <?= htmlspecialchars($club['name']) ?>
                            </a>
                            <span class="text-muted text-sm"><?= $club['member_count'] ?> åkare</span>
                        </td>
                        <td class="text-right font-bold"><?= number_format($club['points'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    
    <!-- Förklaring -->
    <section class="card grid-full">
        <h2 class="card-title">Hur fungerar ranking?</h2>
        <div class="ranking-explanation">
            <div class="ranking-step">
                <span class="ranking-step-num">1</span>
                <div>
                    <strong>24 månaders period</strong>
                    <p class="text-secondary">Endast resultat från de senaste 24 månaderna räknas.</p>
                </div>
            </div>
            <div class="ranking-step">
                <span class="ranking-step-num">2</span>
                <div>
                    <strong>Topp 8 resultat</strong>
                    <p class="text-secondary">Dina 8 bästa resultat summeras till din totalpoäng.</p>
                </div>
            </div>
            <div class="ranking-step">
                <span class="ranking-step-num">3</span>
                <div>
                    <strong>Fältstorlek spelar roll</strong>
                    <p class="text-secondary">Större startfält ger högre poängmultiplikator.</p>
                </div>
            </div>
            <div class="ranking-step">
                <span class="ranking-step-num">4</span>
                <div>
                    <strong>Nyare = bättre</strong>
                    <p class="text-secondary">Nyare resultat väger tyngre än äldre.</p>
                </div>
            </div>
        </div>
    </section>
    
</div>
```

---

## /v3/pages/profile/index.php

```php
<?php
/**
 * Min Sida - Översikt
 */

if (!$isLoggedIn) {
    header('Location: ' . HUB_V3_URL . '/profile/login');
    exit;
}

$user = $currentUser;
$children = hub_get_linked_children($user['id']);
$adminClubs = hub_get_admin_clubs($user['id']);
$upcomingRegistrations = []; // hub_get_user_upcoming_registrations($user['id'])
$recentResults = []; // hub_get_user_recent_results($user['id'])
?>

<div class="page-header">
    <h1 class="page-title">Min Sida</h1>
</div>

<!-- Profil-kort -->
<div class="profile-card-large mb-lg">
    <div class="profile-avatar-large">
        <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
    </div>
    <div class="profile-info-large">
        <h2><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
        <p class="text-secondary"><?= htmlspecialchars($user['club_name'] ?? 'Ingen klubb') ?></p>
        <a href="<?= HUB_V3_URL ?>/profile/edit" class="btn btn--secondary btn--sm mt-sm">Redigera profil</a>
    </div>
</div>

<div class="page-grid page-grid--2col">
    
    <!-- Snabblänkar -->
    <section class="card">
        <h2 class="card-title">Snabblänkar</h2>
        <div class="quick-links">
            <a href="<?= HUB_V3_URL ?>/profile/registrations" class="quick-link">
                <span class="quick-link-icon">🎫</span>
                <span>Mina anmälningar</span>
            </a>
            <a href="<?= HUB_V3_URL ?>/profile/results" class="quick-link">
                <span class="quick-link-icon">🏆</span>
                <span>Mina resultat</span>
            </a>
            <a href="<?= HUB_V3_URL ?>/profile/receipts" class="quick-link">
                <span class="quick-link-icon">🧾</span>
                <span>Kvitton</span>
            </a>
            <a href="<?= HUB_V3_URL ?>/profile/settings" class="quick-link">
                <span class="quick-link-icon">⚙️</span>
                <span>Inställningar</span>
            </a>
        </div>
    </section>
    
    <!-- Kopplade barn -->
    <?php if (!empty($children)): ?>
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Kopplade barn</h2>
            <a href="<?= HUB_V3_URL ?>/profile/children" class="btn btn--ghost">Hantera →</a>
        </div>
        
        <div class="linked-profiles">
            <?php foreach ($children as $child): ?>
            <div class="linked-profile">
                <div class="linked-profile-avatar">
                    <?= strtoupper(substr($child['first_name'], 0, 1)) ?>
                </div>
                <div class="linked-profile-info">
                    <span class="linked-profile-name"><?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?></span>
                    <span class="linked-profile-meta"><?= date('Y', strtotime($child['birthdate'])) ?></span>
                </div>
                <a href="<?= HUB_V3_URL ?>/database/rider/<?= $child['id'] ?>" class="btn btn--ghost btn--sm">Visa</a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    
    <!-- Klubb-admin -->
    <?php if (!empty($adminClubs)): ?>
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Dina klubbar</h2>
        </div>
        
        <div class="linked-profiles">
            <?php foreach ($adminClubs as $club): ?>
            <div class="linked-profile">
                <div class="linked-profile-avatar">
                    <?= strtoupper(substr($club['name'], 0, 1)) ?>
                </div>
                <div class="linked-profile-info">
                    <span class="linked-profile-name"><?= htmlspecialchars($club['name']) ?></span>
                    <span class="linked-profile-meta">Admin</span>
                </div>
                <a href="<?= HUB_V3_URL ?>/profile/club-admin/<?= $club['id'] ?>" class="btn btn--ghost btn--sm">Redigera</a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    
    <!-- Kommande anmälningar -->
    <section class="card grid-full">
        <div class="card-header">
            <h2 class="card-title">Kommande anmälningar</h2>
            <a href="<?= HUB_V3_URL ?>/profile/registrations" class="btn btn--ghost">Visa alla →</a>
        </div>
        
        <?php if (empty($upcomingRegistrations)): ?>
            <p class="text-secondary">Inga kommande anmälningar.</p>
            <a href="<?= HUB_V3_URL ?>/calendar" class="btn btn--primary mt-md">Hitta tävlingar →</a>
        <?php else: ?>
            <div class="event-list">
                <?php foreach ($upcomingRegistrations as $reg): ?>
                <div class="registration-item">
                    <div class="registration-item-date">
                        <span class="day"><?= date('j', strtotime($reg['event_date'])) ?></span>
                        <span class="month"><?= date('M', strtotime($reg['event_date'])) ?></span>
                    </div>
                    <div class="registration-item-info">
                        <strong><?= htmlspecialchars($reg['event_name']) ?></strong>
                        <span class="text-secondary"><?= htmlspecialchars($reg['rider_name']) ?> – <?= htmlspecialchars($reg['class_name']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    
</div>
```

---

## /v3/pages/profile/login.php

```php
<?php
/**
 * Inloggningssida
 * Kopplar till WooCommerce/WordPress
 */

if ($isLoggedIn) {
    header('Location: ' . HUB_V3_URL . '/profile');
    exit;
}

$error = $_GET['error'] ?? '';
$redirect = $_GET['redirect'] ?? HUB_V3_URL . '/profile';
?>

<div class="login-page">
    <div class="login-card">
        <h1 class="login-title">Logga in</h1>
        <p class="login-subtitle">Logga in för att anmäla dig och se dina resultat.</p>
        
        <?php if ($error): ?>
        <div class="alert alert--error mb-lg">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <form action="/wp-login.php" method="post" class="login-form">
            <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirect) ?>">
            
            <div class="form-group">
                <label for="user_login">E-post eller användarnamn</label>
                <input type="text" id="user_login" name="log" required autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="user_pass">Lösenord</label>
                <input type="password" id="user_pass" name="pwd" required autocomplete="current-password">
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="rememberme" value="forever">
                    <span>Kom ihåg mig</span>
                </label>
            </div>
            
            <button type="submit" class="btn btn--primary btn--block btn--lg">
                Logga in
            </button>
        </form>
        
        <div class="login-footer">
            <a href="/my-account/lost-password/">Glömt lösenord?</a>
            <span>•</span>
            <a href="/my-account/">Skapa konto</a>
        </div>
    </div>
</div>
```

---

## /v3/pages/404.php

```php
<?php
http_response_code(404);
$requested = $route['params']['requested'] ?? '';
?>

<div class="error-page">
    <div class="error-content">
        <span class="error-icon">🚴‍♂️💨</span>
        <h1>404</h1>
        <p class="error-title">Sidan hittades inte</p>
        <p class="error-message">
            Åkaren verkar ha tagit fel spår.
            <?php if ($requested): ?>
                Sidan <code><?= htmlspecialchars($requested) ?></code> finns inte.
            <?php endif; ?>
        </p>
        <div class="error-actions">
            <a href="<?= HUB_V3_URL ?>/" class="btn btn--primary">Till startsidan</a>
            <a href="<?= HUB_V3_URL ?>/calendar" class="btn btn--secondary">Visa kalender</a>
        </div>
    </div>
</div>
```
