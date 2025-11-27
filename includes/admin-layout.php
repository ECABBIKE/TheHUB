<?php
/**
 * Admin Layout med flikar
 * Inkludera detta på ALLA admin-sidor för fliknavigation
 *
 * Användning:
 *   require_once __DIR__ . '/../includes/admin-layout.php';
 *   // Efter <main>:
 *   render_admin_header('Sidtitel', $actions);
 *   // Före </main>:
 *   render_admin_footer();
 */

require_once __DIR__ . '/config/admin-tabs-config.php';
require_once __DIR__ . '/components/admin-tabs.php';

// Hämta nuvarande sida och grupp
$current_page = basename($_SERVER['PHP_SELF']);
$active_group = get_group_for_page($current_page);
$active_tab = $active_group ? get_active_tab($active_group, $current_page) : null;

/**
 * Rendera admin header med flikar
 *
 * @param string|null $title Sidtitel (om null, använder gruppens titel)
 * @param array $actions Knappar att visa i headern
 */
function render_admin_header($title = null, $actions = []) {
    global $ADMIN_TABS, $active_group, $active_tab;

    // Använd gruppens titel om ingen titel angetts
    if (!$title && $active_group && isset($ADMIN_TABS[$active_group])) {
        $title = $ADMIN_TABS[$active_group]['title'];
    }
    ?>

    <?php if ($title): ?>
    <div class="admin-page-header">
        <h1 class="admin-page-header__title"><?= htmlspecialchars($title) ?></h1>
        <?php if (!empty($actions)): ?>
        <div class="admin-page-header__actions">
            <?php foreach ($actions as $action): ?>
                <a href="<?= htmlspecialchars($action['url']) ?>"
                   class="gs-btn <?= $action['class'] ?? 'gs-btn-primary' ?>">
                    <?php if (isset($action['icon'])): ?>
                        <i data-lucide="<?= htmlspecialchars($action['icon']) ?>"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($action['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($active_group && isset($ADMIN_TABS[$active_group])): ?>
        <?php render_admin_tabs($ADMIN_TABS[$active_group]['tabs'], $active_tab); ?>
    <?php endif; ?>

    <div class="admin-tab-content">
    <?php
}

/**
 * Stäng admin content
 */
function render_admin_footer() {
    ?>
    </div><!-- /.admin-tab-content -->
    <?php
}

/**
 * Hämta aktiv grupp (för extern användning)
 *
 * @return string|null
 */
function get_current_admin_group() {
    global $active_group;
    return $active_group;
}

/**
 * Hämta aktiv flik (för extern användning)
 *
 * @return string|null
 */
function get_current_admin_tab() {
    global $active_tab;
    return $active_tab;
}
?>
