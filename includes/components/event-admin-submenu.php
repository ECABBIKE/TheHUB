<?php
/**
 * Event Admin Submenu Component
 * Shows contextual navigation for event-specific admin pages
 *
 * Matches the same design as admin-submenu.php but with event context
 *
 * Required: $eventId must be set before including this file
 */

// Bail if no event ID
if (empty($eventId)) {
    return;
}

// Get event info for submenu (use unique var name to avoid collision)
$_submenu_db = getDB();
$_submenu_event = $_submenu_db->getRow("
    SELECT e.name, e.date, s.name as series_name
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    WHERE e.id = ?
", [$eventId]);

if (!$_submenu_event) {
    return;
}

// Define event admin tabs (simple, no icons - matches admin-submenu style)
$_submenu_tabs = [
    ['id' => 'edit', 'label' => 'Info', 'url' => "/admin/event-edit.php?id={$eventId}", 'pages' => ['event-edit.php']],
    ['id' => 'registrations', 'label' => 'Anmälda', 'url' => "/admin/event-registrations.php?id={$eventId}", 'pages' => ['event-registrations.php']],
    ['id' => 'payment', 'label' => 'Betalning', 'url' => "/admin/event-payment.php?id={$eventId}", 'pages' => ['event-payment.php', 'event-ticketing.php', 'event-pricing.php']],
    ['id' => 'tickets', 'label' => 'Biljetter', 'url' => "/admin/event-tickets.php?id={$eventId}", 'pages' => ['event-tickets.php']],
    ['id' => 'orders', 'label' => 'Ordrar', 'url' => "/admin/event-orders.php?id={$eventId}", 'pages' => ['event-orders.php']],
    ['id' => 'results', 'label' => 'Resultat', 'url' => "/admin/edit-results.php?event_id={$eventId}", 'pages' => ['edit-results.php']],
];

// Determine active tab
$_submenu_current_page = basename($_SERVER['PHP_SELF']);
$_submenu_active_tab = $active_event_tab ?? null;

if (!$_submenu_active_tab) {
    foreach ($_submenu_tabs as $tab) {
        if (in_array($_submenu_current_page, $tab['pages'])) {
            $_submenu_active_tab = $tab['id'];
            break;
        }
    }
}

// Build title: "EVENT: [Name]" or with series
$_submenu_title = htmlspecialchars($_submenu_event['name']);
if ($_submenu_event['series_name']) {
    $_submenu_title .= ' (' . htmlspecialchars($_submenu_event['series_name']) . ')';
}
?>
<!-- Event Admin Submenu - Same style as admin-submenu -->
<div class="admin-submenu">
    <div class="admin-submenu-container">
        <div class="admin-submenu-title-row">
            <a href="/admin/events.php" class="admin-submenu-back" title="Tillbaka till events">
                <i data-lucide="arrow-left"></i>
            </a>
            <h2 class="admin-submenu-title">
                <?= $_submenu_title ?>
            </h2>
        </div>
        <nav class="admin-submenu-tabs" role="tablist">
            <?php foreach ($_submenu_tabs as $tab): ?>
            <a href="<?= $tab['url'] ?>"
               class="admin-submenu-tab<?= $_submenu_active_tab === $tab['id'] ? ' active' : '' ?>"
               role="tab"
               aria-selected="<?= $_submenu_active_tab === $tab['id'] ? 'true' : 'false' ?>">
                <?= htmlspecialchars($tab['label']) ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>

<style>
/* Event submenu uses same classes as admin-submenu, just adds back button */
.admin-submenu-title-row {
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    flex-shrink: 0;
}

.admin-submenu-back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: var(--radius-sm, 0.25rem);
    color: var(--color-text-secondary, #6b7280);
    transition: all 0.15s ease;
}

.admin-submenu-back:hover {
    background: var(--color-bg-hover, rgba(0,0,0,0.04));
    color: var(--color-text-primary, #171717);
}

.admin-submenu-back i,
.admin-submenu-back svg {
    width: 16px;
    height: 16px;
}
</style>
