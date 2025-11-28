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
                <span class="admin-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </span>
                Dashboard
            </a>
        </div>

        <div class="admin-nav-section">
            <span class="admin-nav-label">Innehall</span>

            <a href="<?= admin_url('events') ?>"
               class="admin-nav-item <?= admin_is_active('events') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </span>
                Tavlingar
            </a>

            <a href="<?= admin_url('series') ?>"
               class="admin-nav-item <?= admin_is_active('series') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>
                        <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>
                        <path d="M4 22h16"/>
                        <path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>
                    </svg>
                </span>
                Serier
            </a>

            <a href="<?= admin_url('riders') ?>"
               class="admin-nav-item <?= admin_is_active('riders') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </span>
                Deltagare
            </a>

            <a href="<?= admin_url('clubs') ?>"
               class="admin-nav-item <?= admin_is_active('clubs') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M3 21h18"/>
                        <path d="M9 8h1"/>
                        <path d="M9 12h1"/>
                        <path d="M9 16h1"/>
                        <path d="M14 8h1"/>
                        <path d="M14 12h1"/>
                        <path d="M14 16h1"/>
                        <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                    </svg>
                </span>
                Klubbar
            </a>
        </div>

        <div class="admin-nav-section">
            <span class="admin-nav-label">System</span>

            <a href="<?= admin_url('config') ?>"
               class="admin-nav-item <?= admin_is_active('config') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </span>
                Konfiguration
            </a>

            <a href="<?= admin_url('import') ?>"
               class="admin-nav-item <?= admin_is_active('import') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                </span>
                Import
            </a>

            <a href="<?= admin_url('system') ?>"
               class="admin-nav-item <?= admin_is_active('system') ? 'is-active' : '' ?>">
                <span class="admin-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <rect x="2" y="3" width="20" height="14" rx="2"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                        <line x1="12" y1="17" x2="12" y2="21"/>
                    </svg>
                </span>
                System
            </a>
        </div>
    </nav>

    <div class="admin-sidebar-footer">
        <a href="<?= HUB_V3_URL ?>/" class="admin-nav-item">
            <span class="admin-nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <path d="m12 19-7-7 7-7"/>
                    <path d="M19 12H5"/>
                </svg>
            </span>
            Tillbaka till sidan
        </a>

        <a href="<?= HUB_V3_URL ?>/logout" class="admin-nav-item text-error">
            <span class="admin-nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </span>
            Logga ut
        </a>
    </div>
</aside>
