<?php
require_once __DIR__ . '/config/database.php';

$pageTitle = 'Banner Prototyper';
include __DIR__ . '/components/header.php';
?>

<style>
/* Banner Base */
.prototype-banner {
    width: 100%;
    height: 250px;
    display: flex;
    align-items: center;
    padding: var(--space-2xl) var(--space-xl);
    margin-bottom: var(--space-xl);
    border-radius: var(--radius-lg);
    position: relative;
    overflow: hidden;
    color: white;
}

.banner-content {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    gap: var(--space-lg);
}

.banner-icon {
    width: 80px;
    height: 80px;
    background: rgba(255,255,255,0.15);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    flex-shrink: 0;
}

.banner-text h1 {
    font-size: var(--text-4xl);
    font-weight: var(--weight-bold);
    margin: 0 0 var(--space-xs) 0;
    color: white;
}

.banner-text p {
    font-size: var(--text-lg);
    margin: 0;
    opacity: 0.9;
}

/* Variant 1: Clean Gradient */
.banner-gradient {
    background: linear-gradient(135deg, #004A98 0%, #0066CC 100%);
}

/* Variant 2: Gradient + Diagonal Lines */
.banner-diagonal {
    background: linear-gradient(135deg, #004A98 0%, #0066CC 100%);
}
.banner-diagonal::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 20px,
        rgba(255,255,255,0.03) 20px,
        rgba(255,255,255,0.03) 40px
    );
}

/* Variant 3: Gradient + Dots Pattern */
.banner-dots {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
}
.banner-dots::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: radial-gradient(rgba(255,255,255,0.1) 1px, transparent 1px);
    background-size: 20px 20px;
}

/* Variant 4: Gradient + Grid Pattern */
.banner-grid {
    background: linear-gradient(135deg, #7C3AED 0%, #A78BFA 100%);
}
.banner-grid::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image:
        linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px);
    background-size: 30px 30px;
}

/* Variant 5: Multi-color Gradient */
.banner-multi {
    background: linear-gradient(135deg, #EC4899 0%, #F59E0B 50%, #EF4444 100%);
}

/* Variant 6: Subtle Pattern */
.banner-subtle {
    background: linear-gradient(135deg, #1F2937 0%, #374151 100%);
}
.banner-subtle::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image:
        repeating-linear-gradient(45deg, transparent, transparent 35px, rgba(255,255,255,0.02) 35px, rgba(255,255,255,0.02) 70px),
        repeating-linear-gradient(-45deg, transparent, transparent 35px, rgba(255,255,255,0.02) 35px, rgba(255,255,255,0.02) 70px);
}

/* Variant 7: Accent Color Based */
.banner-accent {
    background: linear-gradient(135deg, var(--color-accent) 0%, color-mix(in srgb, var(--color-accent) 70%, white) 100%);
}

/* Variant 8: Dark with Glow */
.banner-glow {
    background: linear-gradient(135deg, #0A0C14 0%, #1A1D28 100%);
    box-shadow: inset 0 0 100px rgba(59,158,255,0.1);
}

.section-title {
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    margin: var(--space-2xl) 0 var(--space-md) 0;
    padding-bottom: var(--space-sm);
    border-bottom: 2px solid var(--color-border);
}

.variant-label {
    background: var(--color-bg-surface);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
    font-weight: var(--weight-semibold);
    margin-bottom: var(--space-sm);
    display: inline-block;
    border: 1px solid var(--color-border);
}
</style>

<div class="container" style="max-width: 1400px; padding: var(--space-xl);">

    <div class="card" style="margin-bottom: var(--space-xl); padding: var(--space-lg);">
        <h1 style="margin: 0 0 var(--space-sm) 0;">Banner Prototyper</h1>
        <p style="color: var(--color-text-secondary); margin: 0;">
            Testa olika banner-stilar för sidheaders. Alla är 100% CSS - inga bilder behöver laddas upp.
        </p>
    </div>

    <!-- Variant 1 -->
    <div class="variant-label">Variant 1: Ren Gradient (Blå)</div>
    <div class="prototype-banner banner-gradient">
        <div class="banner-content">
            <div class="banner-icon">
                <i data-lucide="trending-up" style="width: 40px; height: 40px;"></i>
            </div>
            <div class="banner-text">
                <h1>RANKING</h1>
                <p>GravitySeries 24 månaders rullande ranking</p>
            </div>
        </div>
    </div>

    <!-- Variant 2 -->
    <div class="variant-label">Variant 2: Gradient + Diagonala Linjer</div>
    <div class="prototype-banner banner-diagonal">
        <div class="banner-content">
            <div class="banner-icon">
                <i data-lucide="trophy" style="width: 40px; height: 40px;"></i>
            </div>
            <div class="banner-text">
                <h1>RESULTAT</h1>
                <p>13 tävlingar med publicerade resultat</p>
            </div>
        </div>
    </div>

    <!-- Variant 3 -->
    <div class="variant-label">Variant 3: Gradient + Dots Mönster (Grön)</div>
    <div class="prototype-banner banner-dots">
        <div class="banner-content">
            <div class="banner-icon">
                <i data-lucide="database" style="width: 40px; height: 40px;"></i>
            </div>
            <div class="banner-text">
                <h1>DATABAS</h1>
                <p>Sök bland åkare och klubbar</p>
            </div>
        </div>
    </div>

    <!-- Variant 4 -->
    <div class="variant-label">Variant 4: Gradient + Grid Mönster (Lila)</div>
    <div class="prototype-banner banner-grid">
        <div class="banner-content">
            <div class="banner-icon">
                <i data-lucide="calendar" style="width: 40px; height: 40px;"></i>
            </div>
            <div class="banner-text">
                <h1>KALENDER</h1>
                <p>Kommande tävlingar och event</p>
            </div>
        </div>
    </div>

    <!-- Variant 5 -->
    <div class="variant-label">Variant 5: Multi-färg Gradient</div>
    <div class="prototype-banner banner-multi">
        <div class="banner-content">
            <div class="banner-icon">
                <i data-lucide="award" style="width: 40px; height: 40px;"></i>
            </div>
            <div class="banner-text">
                <h1>SERIER 2025</h1>
                <p>Alla GravitySeries och andra tävlingsserier</p>
            </div>
        </div>
    </div>

    <!-- Variant 6 -->
    <div class="variant-label">Variant 6: Mörkgrå med Subtilt Mönster</div>
    <div class="prototype-banner banner-subtle">
        <div class="banner-content">
            <div class="banner-icon">
                <i data-lucide="users" style="width: 40px; height: 40px;"></i>
            </div>
            <div class="banner-text">
                <h1>KLUBBAR</h1>
                <p>110 klubbar med registrerade åkare</p>
            </div>
        </div>
    </div>

    <!-- Variant 7 -->
    <div class="variant-label">Variant 7: Baserad på Accent-färg</div>
    <div class="prototype-banner banner-accent">
        <div class="banner-content">
            <div class="banner-icon">
                <i data-lucide="bar-chart" style="width: 40px; height: 40px;"></i>
            </div>
            <div class="banner-text">
                <h1>STATISTIK</h1>
                <p>Detaljerad statistik och analyser</p>
            </div>
        </div>
    </div>

    <!-- Variant 8 -->
    <div class="variant-label">Variant 8: Mörk med Glow-effekt</div>
    <div class="prototype-banner banner-glow">
        <div class="banner-content">
            <div class="banner-icon" style="background: rgba(59,158,255,0.2);">
                <i data-lucide="zap" style="width: 40px; height: 40px; color: #3B9EFF;"></i>
            </div>
            <div class="banner-text">
                <h1>LIVE TIMING</h1>
                <p>Realtidsresultat från pågående tävlingar</p>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: var(--space-2xl); padding: var(--space-lg); background: var(--color-bg-sunken);">
        <h3 style="margin: 0 0 var(--space-sm) 0;">💡 Fördelar med CSS-banners</h3>
        <ul style="margin: 0; padding-left: var(--space-lg); color: var(--color-text-secondary);">
            <li>Inga bilder att ladda upp eller hantera</li>
            <li>Snabbare laddningstid</li>
            <li>Automatiskt responsiv</li>
            <li>Enkelt att ändra färger via CSS-variabler</li>
            <li>Kan använda olika färger per sida/serie</li>
            <li>Konsekvent design över hela sidan</li>
        </ul>
    </div>

</div>

<?php include __DIR__ . '/components/footer.php'; ?>
