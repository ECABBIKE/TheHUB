# TheHUB V3.0 – KOMPLETT PROMPT
## Del 2: Komponenter

---

# KOMPONENTER

## /v3/components/head.php

```php
<?php
$pageTitle = 'TheHUB – ' . ucfirst($route['section'] ?? 'Hem');
$themeColor = hub_get_theme() === 'dark' ? '#0A0C14' : '#004A98';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="description" content="TheHUB – Sveriges plattform för gravity cycling">

<title><?= htmlspecialchars($pageTitle) ?></title>

<!-- PWA -->
<meta name="application-name" content="TheHUB">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TheHUB">
<meta name="theme-color" content="<?= $themeColor ?>">

<link rel="manifest" href="<?= HUB_V3_URL ?>/manifest.json">
<link rel="apple-touch-icon" href="<?= HUB_V3_URL ?>/assets/icons/icon-180.png">
<link rel="icon" type="image/svg+xml" href="<?= HUB_V3_URL ?>/assets/icons/favicon.svg">

<!-- CSS -->
<link rel="stylesheet" href="<?= hub_asset('css/reset.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tokens.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/theme.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/layout.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/components.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/navigation.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/calendar.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/results.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/database.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/ranking.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/profile.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/registration.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/pwa.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/utilities.css') ?>">
```

---

## /v3/components/header.php

```php
<header class="header" role="banner">
    <a href="<?= HUB_V3_URL ?>/" class="header-brand" aria-label="TheHUB - Gå till startsidan">
        <svg class="header-logo" viewBox="0 0 40 40" aria-hidden="true">
            <circle cx="20" cy="20" r="18" fill="currentColor" opacity="0.1"/>
            <circle cx="20" cy="20" r="18" fill="none" stroke="currentColor" stroke-width="2"/>
            <text x="20" y="25" text-anchor="middle" fill="currentColor" font-size="12" font-weight="bold">HUB</text>
        </svg>
        <span class="header-title">TheHUB</span>
    </a>
    
    <div class="header-actions">
        <?php if ($isLoggedIn && $currentUser): ?>
            <a href="<?= HUB_V3_URL ?>/profile" class="header-user">
                <span class="header-user-name"><?= htmlspecialchars($currentUser['first_name'] ?? '') ?></span>
                <div class="header-user-avatar">
                    <?= strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)) ?>
                </div>
            </a>
        <?php else: ?>
            <a href="<?= HUB_V3_URL ?>/profile/login" class="header-login">Logga in</a>
        <?php endif; ?>
    </div>
</header>
```

---

## /v3/components/nav-bottom.php

```php
<?php
$icons = [
    'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'flag' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
    'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    'trending' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
    'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
];

$navIcons = [
    'calendar' => 'calendar',
    'results' => 'flag', 
    'database' => 'search',
    'ranking' => 'trending',
    'profile' => 'user'
];
?>

<nav class="nav-bottom" role="navigation" aria-label="Huvudnavigering">
    <div class="nav-bottom-inner">
        <?php foreach (HUB_NAV as $item): ?>
            <?php $isActive = hub_is_section_active($item['id']); ?>
            <a href="<?= htmlspecialchars($item['url']) ?>" 
               class="nav-bottom-item <?= $isActive ? 'is-active' : '' ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>
               data-section="<?= $item['id'] ?>">
                <span class="nav-bottom-icon"><?= $icons[$navIcons[$item['id']]] ?></span>
                <span class="nav-bottom-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
```

---

## /v3/components/footer.php

```php
<?php $currentTheme = hub_get_theme(); ?>
<div class="theme-toggle" role="group" aria-label="Välj tema">
    <button type="button" class="theme-toggle-btn" data-theme="light" 
            aria-pressed="<?= $currentTheme === 'light' ? 'true' : 'false' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/>
            <line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/>
            <line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
        </svg>
    </button>
    <button type="button" class="theme-toggle-btn" data-theme="dark"
            aria-pressed="<?= $currentTheme === 'dark' ? 'true' : 'false' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
    </button>
    <button type="button" class="theme-toggle-btn" data-theme="auto"
            aria-pressed="<?= $currentTheme === 'auto' ? 'true' : 'false' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>
        </svg>
    </button>
</div>
```

---

## /v3/components/search-live.php

```php
<?php
/**
 * Live Search - resultat medan du skriver
 * @param string $type - 'riders', 'clubs', 'all'
 * @param string $placeholder
 * @param bool $allowNew - Visa "lägg till ny"
 */
$type = $type ?? 'all';
$placeholder = $placeholder ?? 'Sök...';
$allowNew = $allowNew ?? false;
$inputId = 'search-' . uniqid();
?>

<div class="live-search" data-search-type="<?= $type ?>">
    <div class="live-search-input-wrap">
        <svg class="live-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input 
            type="search" 
            id="<?= $inputId ?>"
            class="live-search-input" 
            placeholder="<?= htmlspecialchars($placeholder) ?>"
            autocomplete="off"
        >
        <button type="button" class="live-search-clear hidden" aria-label="Rensa">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>
    
    <div class="live-search-results hidden" role="listbox"></div>
    
    <?php if ($allowNew): ?>
    <div class="live-search-new hidden">
        <button type="button" class="live-search-new-btn" data-action="add-new">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <span>Lägg till ny: "<span class="live-search-query"></span>"</span>
        </button>
    </div>
    <?php endif; ?>
</div>
```

---

## /v3/components/event-card.php

```php
<?php
/**
 * Event Card
 * @param array $event
 * @param string $context - 'calendar', 'results'
 */
$event = $event ?? [];
$context = $context ?? 'calendar';

$eventId = $event['id'] ?? 0;
$eventName = $event['name'] ?? 'Event';
$eventDate = $event['date'] ?? '';
$eventLocation = $event['location'] ?? '';
$eventSeries = $event['series_name'] ?? '';
$eventFormat = $event['format'] ?? '';
$eventStatus = $event['status'] ?? 'upcoming';
$registrationCount = $event['registration_count'] ?? 0;

$isPast = $eventStatus === 'completed';
$isOpen = $eventStatus === 'open';

$seriesColors = ['GES' => 'ges', 'GDS' => 'downhill', 'GGS' => 'ggs', 'GSS' => 'gss'];
$seriesClass = $seriesColors[$eventSeries] ?? 'default';
?>

<article class="event-card" data-event-id="<?= $eventId ?>">
    <a href="<?= HUB_V3_URL ?>/<?= $isPast ? 'results' : 'calendar' ?>/<?= $eventId ?>" class="event-card-link">
        
        <div class="event-card-date">
            <span class="event-card-day"><?= date('j', strtotime($eventDate)) ?></span>
            <span class="event-card-month"><?= date('M', strtotime($eventDate)) ?></span>
        </div>
        
        <div class="event-card-content">
            <h3 class="event-card-title"><?= htmlspecialchars($eventName) ?></h3>
            
            <?php if ($eventLocation): ?>
            <div class="event-card-meta">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
                </svg>
                <?= htmlspecialchars($eventLocation) ?>
            </div>
            <?php endif; ?>
            
            <div class="event-card-tags">
                <?php if ($eventSeries): ?>
                    <span class="chip chip--<?= $seriesClass ?>"><?= htmlspecialchars($eventSeries) ?></span>
                <?php endif; ?>
                <?php if ($eventFormat): ?>
                    <span class="chip chip--outline"><?= htmlspecialchars($eventFormat) ?></span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="event-card-action">
            <?php if (!$isPast): ?>
                <?php if ($isOpen): ?>
                    <span class="event-card-status event-card-status--open">
                        <span class="status-dot"></span>
                        Anmälan öppen
                    </span>
                    <span class="event-card-count"><?= $registrationCount ?> anmälda</span>
                <?php else: ?>
                    <span class="event-card-status event-card-status--upcoming">Öppnar snart</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="event-card-status event-card-status--completed">Visa resultat →</span>
            <?php endif; ?>
        </div>
    </a>
</article>
```

---

## /v3/components/points-breakdown.php

```php
<?php
/**
 * Poängförklaring - Visar hur ranking-poäng beräknas
 * @param array $breakdown
 */
$breakdown = $breakdown ?? [];
$totalPoints = $breakdown['total'] ?? 0;
$results = $breakdown['results'] ?? [];
?>

<div class="points-breakdown">
    <div class="points-breakdown-header">
        <h3>Poängförklaring</h3>
        <div class="points-breakdown-total">
            <span class="points-value"><?= number_format($totalPoints, 1) ?></span>
            <span class="points-label">Totalt</span>
        </div>
    </div>
    
    <div class="points-breakdown-info">
        <p>Ranking baseras på dina <strong>8 bästa resultat</strong> under de senaste <strong>24 månaderna</strong>.</p>
    </div>
    
    <div class="points-breakdown-formula">
        <h4>Beräkningsformel</h4>
        <code>Poäng = Baspoäng × Fältstorlek × Tidsfaktor</code>
    </div>
    
    <div class="points-breakdown-list">
        <h4>Dina räknade resultat</h4>
        
        <div class="table-wrap">
            <table class="table points-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th class="text-center">Plac.</th>
                        <th class="text-right">Bas</th>
                        <th class="text-right">Fält</th>
                        <th class="text-right">Tid</th>
                        <th class="text-right">Poäng</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $i => $result): ?>
                        <tr class="<?= $i < 8 ? 'is-counted' : 'is-not-counted' ?>">
                            <td>
                                <a href="<?= HUB_V3_URL ?>/results/<?= $result['event_id'] ?>">
                                    <?= htmlspecialchars($result['event_name']) ?>
                                </a>
                                <span class="text-muted text-sm"><?= $result['date'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="placement-badge placement-badge--<?= $result['placement'] <= 3 ? $result['placement'] : 'other' ?>">
                                    <?= $result['placement'] ?>
                                </span>
                            </td>
                            <td class="text-right"><?= $result['base_points'] ?></td>
                            <td class="text-right">
                                ×<?= number_format($result['field_multiplier'], 2) ?>
                                <span class="text-muted text-xs">(<?= $result['field_size'] ?>st)</span>
                            </td>
                            <td class="text-right">
                                ×<?= number_format($result['time_decay'], 2) ?>
                                <span class="text-muted text-xs">(<?= $result['months_ago'] ?>mån)</span>
                            </td>
                            <td class="text-right font-bold"><?= number_format($result['final_points'], 1) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-right"><strong>Summa (topp 8):</strong></td>
                        <td class="text-right font-bold"><?= number_format($totalPoints, 1) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <div class="points-breakdown-legend">
        <h4>Förklaring</h4>
        <dl>
            <dt>Baspoäng</dt>
            <dd>Poäng baserat på placering (1:a=100, 2:a=95, 3:a=90, osv)</dd>
            
            <dt>Fältstorlek</dt>
            <dd>Större fält = högre multipel. Belönar tuffare konkurrens.</dd>
            
            <dt>Tidsfaktor</dt>
            <dd>Nyare resultat väger tyngre. 100% första 6 mån, sedan gradvis minskning.</dd>
        </dl>
    </div>
</div>
```

---

## /v3/components/participant-picker.php

```php
<?php
/**
 * Participant Picker för anmälan
 * Välj flera deltagare för samma betalning
 */
?>

<div class="participant-picker" data-component="participant-picker">
    
    <div class="participant-picker-header">
        <h3>Vem ska anmälas?</h3>
        <p class="text-secondary">Du kan anmäla flera deltagare på samma betalning.</p>
    </div>
    
    <!-- Valda deltagare -->
    <div class="participant-list" id="selected-participants"></div>
    
    <!-- Lägg till -->
    <div class="participant-add">
        <button type="button" class="participant-add-btn" data-action="add-participant">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Lägg till deltagare
        </button>
    </div>
    
    <!-- Add panel -->
    <div class="participant-add-panel hidden" id="participant-add-panel">
        
        <?php if ($isLoggedIn && $currentUser): ?>
        <div class="participant-quick-options">
            <h4>Snabbval</h4>
            
            <!-- Dig själv -->
            <button type="button" class="participant-quick-btn" data-action="add-self" data-rider-id="<?= $currentUser['id'] ?>">
                <div class="participant-avatar"><?= strtoupper(substr($currentUser['first_name'], 0, 1)) ?></div>
                <div class="participant-info">
                    <span class="participant-name"><?= htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?></span>
                    <span class="participant-meta">Dig själv</span>
                </div>
            </button>
            
            <!-- Kopplade barn -->
            <?php foreach (hub_get_linked_children($currentUser['id']) as $child): ?>
            <button type="button" class="participant-quick-btn" data-action="add-rider" data-rider-id="<?= $child['id'] ?>">
                <div class="participant-avatar"><?= strtoupper(substr($child['first_name'], 0, 1)) ?></div>
                <div class="participant-info">
                    <span class="participant-name"><?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?></span>
                    <span class="participant-meta">Kopplat barn</span>
                </div>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Sök befintlig -->
        <div class="participant-search">
            <h4>Sök befintlig åkare</h4>
            <?php 
            $type = 'riders';
            $placeholder = 'Skriv namn...';
            $allowNew = true;
            include __DIR__ . '/search-live.php'; 
            ?>
        </div>
        
        <!-- Lägg till ny -->
        <div class="participant-new hidden" id="participant-new-form">
            <h4>Lägg till ny åkare</h4>
            
            <form class="participant-new-fields">
                <div class="form-row">
                    <div class="form-group">
                        <label>Förnamn *</label>
                        <input type="text" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label>Efternamn *</label>
                        <input type="text" name="last_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Födelsedatum *</label>
                        <input type="date" name="birthdate" required>
                    </div>
                    <div class="form-group">
                        <label>Kön *</label>
                        <select name="gender" required>
                            <option value="">Välj...</option>
                            <option value="M">Man</option>
                            <option value="F">Kvinna</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Klubb</label>
                    <?php 
                    $type = 'clubs';
                    $placeholder = 'Sök klubb...';
                    $allowNew = false;
                    include __DIR__ . '/search-live.php'; 
                    ?>
                </div>
                
                <div class="form-group">
                    <label>E-post</label>
                    <input type="email" name="email">
                    <span class="form-hint">För bekräftelse och inloggning</span>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn--secondary" data-action="cancel-new">Avbryt</button>
                    <button type="submit" class="btn btn--primary">Lägg till</button>
                </div>
            </form>
        </div>
    </div>
</div>
```

---

## /v3/components/woocommerce-modal.php

```php
<?php
/**
 * WooCommerce Payment Modal
 */
?>

<div class="wc-modal hidden" id="wc-modal" role="dialog" aria-modal="true">
    <div class="wc-modal-backdrop" data-action="close-modal"></div>
    
    <div class="wc-modal-container">
        <div class="wc-modal-header">
            <h2>Betalning</h2>
            <button type="button" class="wc-modal-close" data-action="close-modal" aria-label="Stäng">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <div class="wc-modal-content">
            <iframe id="wc-modal-iframe" src=""></iframe>
            <div class="wc-modal-loading" id="wc-modal-loading">
                <div class="spinner"></div>
                <p>Laddar betalning...</p>
            </div>
        </div>
        
        <div class="wc-modal-footer">
            <p class="text-secondary text-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm">
                    <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                Säker betalning via WooCommerce
            </p>
        </div>
    </div>
</div>
```
