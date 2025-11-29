<?php
/**
 * TheHUB Theme Switcher Component
 * Floating button group for light/auto/dark theme selection
 */
?>
<div class="theme-toggle theme-switcher" role="group" aria-label="Valj tema">
    <button type="button" class="theme-toggle-btn theme-btn" data-theme="light" aria-pressed="false" aria-label="Ljust tema" title="Ljust tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="4"/>
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
        </svg>
    </button>
    <button type="button" class="theme-toggle-btn theme-btn" data-theme="auto" aria-pressed="false" aria-label="Automatiskt tema" title="Automatiskt">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect width="20" height="14" x="2" y="3" rx="2"/>
            <line x1="8" x2="16" y1="21" y2="21"/>
            <line x1="12" x2="12" y1="17" y2="21"/>
        </svg>
    </button>
    <button type="button" class="theme-toggle-btn theme-btn" data-theme="dark" aria-pressed="false" aria-label="Morkt tema" title="Morkt tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
        </svg>
    </button>
</div>
