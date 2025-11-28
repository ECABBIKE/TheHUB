<?php
/**
 * Admin Sidebar Navigation
 * Uses hub_icon() for consistent SVG icons
 */
require_once HUB_V3_ROOT . '/components/icons.php';
?>
<aside class="admin-sidebar">
    <div class="admin-sidebar-brand">
        <a href="<?= admin_url() ?>">
            <span class="admin-logo">
                <svg viewBox="0 0 40 40" width="32" height="32">
                    <circle cx="20" cy="20" r="18" fill="currentColor" opacity="0.1"/>
                    <circle cx="20" cy="20" r="18" fill="none" stroke="currentColor" stroke-width="2"/>
                    <text x="20" y="25" text-anchor="middle" fill="currentColor" font-size="12" font-weight="bold">HUB</text>
                </svg>
            </span>
            <span class="admin-title">TheHUB</span>
        </a>
        <span class="admin-badge">Admin</span>
    </div>

    <nav class="admin-nav">
        <div class="admin-nav-section">
            <a href="<?= admin_url() ?>"
               class="admin-nav-item <?= admin_is_active('dashboard') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('layout-dashboard') ?></span>
                Dashboard
            </a>
        </div>

        <div class="admin-nav-section">
            <span class="admin-nav-label">Innehall</span>

            <a href="<?= admin_url('events') ?>"
               class="admin-nav-item <?= admin_is_active('events') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('calendar') ?></span>
                Tavlingar
            </a>

            <a href="<?= admin_url('series') ?>"
               class="admin-nav-item <?= admin_is_active('series') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('trophy') ?></span>
                Serier
            </a>

            <a href="<?= admin_url('riders') ?>"
               class="admin-nav-item <?= admin_is_active('riders') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('users') ?></span>
                Deltagare
            </a>

            <a href="<?= admin_url('clubs') ?>"
               class="admin-nav-item <?= admin_is_active('clubs') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('building') ?></span>
                Klubbar
            </a>

            <a href="<?= admin_url('images') ?>"
               class="admin-nav-item <?= admin_is_active('images') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('image') ?></span>
                Bilder
            </a>
        </div>

        <div class="admin-nav-section">
            <span class="admin-nav-label">System</span>

            <a href="<?= admin_url('config') ?>"
               class="admin-nav-item <?= admin_is_active('config') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('settings') ?></span>
                Konfiguration
            </a>

            <a href="<?= admin_url('import') ?>"
               class="admin-nav-item <?= admin_is_active('import') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('upload') ?></span>
                Import
            </a>

            <a href="<?= admin_url('system') ?>"
               class="admin-nav-item <?= admin_is_active('system') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon"><?= hub_icon('server') ?></span>
                System
            </a>
        </div>
    </nav>

    <div class="admin-sidebar-footer">
        <a href="<?= HUB_V3_URL ?>/" class="admin-nav-item">
            <span class="admin-nav-icon"><?= hub_icon('arrow-left') ?></span>
            Tillbaka till sidan
        </a>

        <a href="<?= HUB_V3_URL ?>/logout" class="admin-nav-item text-error">
            <span class="admin-nav-icon"><?= hub_icon('log-out') ?></span>
            Logga ut
        </a>
    </div>
</aside>
