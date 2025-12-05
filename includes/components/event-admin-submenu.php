<?php
/**
 * Event Admin Submenu Component
 * Shows contextual navigation for event-specific admin pages
 *
 * Required: $eventId must be set before including this file
 * Optional: $active_event_tab to highlight current tab
 *
 * Usage:
 *   $eventId = 256;
 *   $active_event_tab = 'payment';
 *   include __DIR__ . '/../includes/components/event-admin-submenu.php';
 */

// Bail if no event ID
if (empty($eventId)) {
    return;
}

// Get event info for submenu (use unique var name to avoid collision)
$_submenu_db = getDB();
$_submenu_event = $_submenu_db->getRow("
    SELECT e.*, s.name as series_name
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    WHERE e.id = ?
", [$eventId]);

if (!$_submenu_event) {
    return;
}

// Define event admin tabs
$event_admin_tabs = [
    [
        'id' => 'edit',
        'label' => 'Info',
        'icon' => 'edit',
        'url' => "/admin/event-edit.php?id={$eventId}",
        'pages' => ['event-edit.php']
    ],
    [
        'id' => 'registrations',
        'label' => 'Anmälda',
        'icon' => 'users',
        'url' => "/admin/event-registrations.php?id={$eventId}",
        'pages' => ['event-registrations.php']
    ],
    [
        'id' => 'payment',
        'label' => 'Betalning',
        'icon' => 'credit-card',
        'url' => "/admin/event-payment.php?id={$eventId}",
        'pages' => ['event-payment.php', 'event-ticketing.php', 'event-pricing.php']
    ],
    [
        'id' => 'tickets',
        'label' => 'Biljetter',
        'icon' => 'ticket',
        'url' => "/admin/event-tickets.php?id={$eventId}",
        'pages' => ['event-tickets.php']
    ],
    [
        'id' => 'orders',
        'label' => 'Ordrar',
        'icon' => 'shopping-cart',
        'url' => "/admin/event-orders.php?id={$eventId}",
        'pages' => ['event-orders.php']
    ],
    [
        'id' => 'results',
        'label' => 'Resultat',
        'icon' => 'trophy',
        'url' => "/admin/edit-results.php?event_id={$eventId}",
        'pages' => ['edit-results.php']
    ],
];

// Determine active tab
$current_page = basename($_SERVER['PHP_SELF']);
$active_tab = $active_event_tab ?? null;

if (!$active_tab) {
    foreach ($event_admin_tabs as $tab) {
        if (in_array($current_page, $tab['pages'])) {
            $active_tab = $tab['id'];
            break;
        }
    }
}

// Format date
$_submenu_eventDate = date('j M Y', strtotime($_submenu_event['date']));
?>
<!-- Event Admin Submenu -->
<div class="event-admin-submenu">
    <div class="event-admin-submenu-container">
        <!-- Back link and event info -->
        <div class="event-admin-header">
            <a href="/admin/events.php" class="event-admin-back" title="Tillbaka till events">
                <i data-lucide="arrow-left"></i>
            </a>
            <div class="event-admin-info">
                <h2 class="event-admin-title"><?= htmlspecialchars($_submenu_event['name']) ?></h2>
                <div class="event-admin-meta">
                    <span><?= $_submenu_eventDate ?></span>
                    <?php if ($_submenu_event['series_name']): ?>
                        <span class="event-admin-series"><?= htmlspecialchars($_submenu_event['series_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <nav class="event-admin-tabs" role="tablist">
            <?php foreach ($event_admin_tabs as $tab): ?>
            <a href="<?= $tab['url'] ?>"
               class="event-admin-tab<?= $active_tab === $tab['id'] ? ' active' : '' ?>"
               role="tab"
               aria-selected="<?= $active_tab === $tab['id'] ? 'true' : 'false' ?>">
                <i data-lucide="<?= $tab['icon'] ?>"></i>
                <span><?= htmlspecialchars($tab['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>

<style>
/* Event Admin Submenu - Mobile First */
.event-admin-submenu {
    background: var(--color-bg-surface, #fff);
    border-bottom: 1px solid var(--color-border, #e5e7eb);
    position: sticky;
    top: 0;
    z-index: 90;
}

.event-admin-submenu-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
}

/* Header row: back + event info */
.event-admin-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    margin-bottom: var(--space-sm, 0.5rem);
}

.event-admin-back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-md, 0.375rem);
    color: var(--color-text-secondary, #6b7280);
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.event-admin-back:hover {
    background: var(--color-bg-hover, rgba(0,0,0,0.04));
    color: var(--color-text-primary, #171717);
}

.event-admin-back i,
.event-admin-back svg {
    width: 20px;
    height: 20px;
}

.event-admin-info {
    min-width: 0;
    flex: 1;
}

.event-admin-title {
    font-size: var(--text-sm, 0.875rem);
    font-weight: var(--weight-semibold, 600);
    color: var(--color-text-primary, #171717);
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.event-admin-meta {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    font-size: var(--text-xs, 0.75rem);
    color: var(--color-text-secondary, #6b7280);
}

.event-admin-series {
    padding: 2px 6px;
    background: var(--color-accent-subtle, rgba(0, 74, 152, 0.1));
    color: var(--color-accent, #004a98);
    border-radius: var(--radius-sm, 0.25rem);
    font-weight: var(--weight-medium, 500);
}

/* Tabs */
.event-admin-tabs {
    display: flex;
    gap: 2px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    margin: 0 calc(var(--space-md, 1rem) * -1);
    padding: 0 var(--space-md, 1rem);
}

.event-admin-tabs::-webkit-scrollbar {
    display: none;
}

.event-admin-tab {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    min-height: 40px;
    padding: 0 var(--space-sm, 0.5rem);
    font-size: var(--text-xs, 0.75rem);
    font-weight: var(--weight-medium, 500);
    color: var(--color-text-secondary, #6b7280);
    text-decoration: none;
    white-space: nowrap;
    border-bottom: 2px solid transparent;
    transition: all 0.15s ease;
    margin-bottom: -1px;
}

.event-admin-tab i,
.event-admin-tab svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.event-admin-tab:hover {
    color: var(--color-text-primary, #171717);
    background: var(--color-bg-hover, rgba(0,0,0,0.04));
}

.event-admin-tab.active {
    color: var(--color-accent, #004a98);
    border-bottom-color: var(--color-accent, #004a98);
    font-weight: var(--weight-semibold, 600);
}

/* Tablet and up */
@media (min-width: 640px) {
    .event-admin-submenu-container {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 var(--space-md, 1rem);
    }

    .event-admin-header {
        margin-bottom: 0;
        flex-shrink: 0;
        max-width: 40%;
    }

    .event-admin-tabs {
        margin: 0;
        padding: 0;
        justify-content: flex-end;
    }

    .event-admin-tab {
        padding: 0 var(--space-md, 1rem);
        font-size: var(--text-sm, 0.875rem);
    }
}

/* Desktop */
@media (min-width: 1024px) {
    .event-admin-title {
        font-size: var(--text-base, 1rem);
    }

    .event-admin-back {
        width: 40px;
        height: 40px;
    }
}
</style>
