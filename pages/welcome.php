<?php
/**
 * TheHUB V3.5 - Welcome Page
 * Shown to unauthenticated visitors at root (/)
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /');
    exit;
}

require_once HUB_V3_ROOT . '/components/icons.php';
?>

<div class="welcome-page">
    <div class="welcome-container">
        <div class="welcome-card">
            <!-- Logo -->
            <div class="welcome-logo">
                <svg viewBox="0 0 80 80" class="welcome-logo-icon">
                    <circle cx="40" cy="40" r="36" fill="currentColor" opacity="0.1"/>
                    <circle cx="40" cy="40" r="36" fill="none" stroke="currentColor" stroke-width="3"/>
                    <text x="40" y="48" text-anchor="middle" fill="currentColor" font-size="20" font-weight="bold">HUB</text>
                </svg>
            </div>

            <h1 class="welcome-title">Välkommen till TheHUB</h1>
            <p class="welcome-subtitle">GravitySeries Competition Platform</p>

            <!-- Launch message -->
            <div class="welcome-message">
                <div class="welcome-message-icon">
                    <?= hub_icon('info', 'icon-md') ?>
                </div>
                <div class="welcome-message-content">
                    <h3>V3.5 är under utveckling</h3>
                    <p>
                        Plattformen uppdateras till version 3.5 med förbättrad design,
                        snabbare prestanda och nya funktioner.
                    </p>
                </div>
            </div>

            <!-- Features -->
            <div class="welcome-features">
                <div class="welcome-feature">
                    <?= hub_icon('calendar', 'welcome-feature-icon') ?>
                    <span>Eventkalender</span>
                </div>
                <div class="welcome-feature">
                    <?= hub_icon('trophy', 'welcome-feature-icon') ?>
                    <span>Resultat & Serier</span>
                </div>
                <div class="welcome-feature">
                    <?= hub_icon('trending-up', 'welcome-feature-icon') ?>
                    <span>Ranking</span>
                </div>
                <div class="welcome-feature">
                    <?= hub_icon('users', 'welcome-feature-icon') ?>
                    <span>Åkardatabas</span>
                </div>
            </div>

            <!-- Actions -->
            <div class="welcome-actions">
                <a href="<?= HUB_V3_URL ?>/login" class="btn btn--primary btn--lg">
                    <?= hub_icon('log-in', 'icon-sm') ?>
                    Logga in
                </a>
                <a href="https://gravityseries.se" class="btn btn--secondary btn--lg" target="_blank" rel="noopener">
                    <?= hub_icon('external-link', 'icon-sm') ?>
                    Om GravitySeries
                </a>
            </div>

            <!-- Login required info -->
            <p class="welcome-info">
                <?= hub_icon('lock', 'icon-xs') ?>
                Inloggning krävs för att se innehållet
            </p>
        </div>
    </div>
</div>

<style>
/* ===== WELCOME PAGE ===== */
.welcome-page {
    min-height: 80vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-lg);
}

.welcome-container {
    width: 100%;
    max-width: 500px;
}

.welcome-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: var(--space-xl);
    text-align: center;
    box-shadow: var(--shadow-lg);
}

/* Logo */
.welcome-logo {
    margin-bottom: var(--space-lg);
}

.welcome-logo-icon {
    width: 80px;
    height: 80px;
    color: var(--color-accent);
}

/* Title */
.welcome-title {
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    color: var(--color-text-primary);
    margin: 0 0 var(--space-xs);
}

.welcome-subtitle {
    font-size: var(--text-md);
    color: var(--color-text-secondary);
    margin: 0 0 var(--space-xl);
}

/* Message */
.welcome-message {
    display: flex;
    gap: var(--space-md);
    align-items: flex-start;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-left: 4px solid var(--color-accent);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-xl);
    text-align: left;
}

.welcome-message-icon {
    color: var(--color-accent);
    flex-shrink: 0;
}

.welcome-message-icon .icon-md {
    width: 24px;
    height: 24px;
}

.welcome-message h3 {
    font-size: var(--text-sm);
    font-weight: var(--weight-semibold);
    color: var(--color-text-primary);
    margin: 0 0 var(--space-xs);
}

.welcome-message p {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin: 0;
    line-height: 1.5;
}

/* Features */
.welcome-features {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-sm);
    margin-bottom: var(--space-xl);
}

.welcome-feature {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.welcome-feature-icon {
    width: 18px;
    height: 18px;
    color: var(--color-accent);
}

/* Actions */
.welcome-actions {
    display: flex;
    gap: var(--space-sm);
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: var(--space-lg);
}

.btn--lg {
    padding: var(--space-sm) var(--space-lg);
    font-size: var(--text-md);
}

.btn--primary {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    background: var(--color-accent);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: var(--weight-medium);
    text-decoration: none;
    cursor: pointer;
    transition: opacity var(--transition-fast);
}

.btn--primary:hover {
    opacity: 0.9;
}

.btn--secondary {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    background: transparent;
    color: var(--color-text-primary);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-weight: var(--weight-medium);
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.btn--secondary:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
}

/* Info */
.welcome-info {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    margin: 0;
}

.welcome-info .icon-xs {
    width: 14px;
    height: 14px;
}

@media (max-width: 480px) {
    .welcome-card {
        padding: var(--space-lg);
    }

    .welcome-features {
        grid-template-columns: 1fr;
    }

    .welcome-actions {
        flex-direction: column;
    }

    .welcome-actions .btn--lg {
        width: 100%;
        justify-content: center;
    }
}
</style>
