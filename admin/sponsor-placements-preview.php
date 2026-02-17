<?php
/**
 * Sponsor Placements Preview - Visual Example Page
 * Shows how each placement position looks on a real page
 *
 * Super Admin only
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!hasRole('super_admin')) {
    header('Location: /admin/');
    exit;
}

$page_title = 'Förhandsvisa reklamplatser';
$breadcrumbs = [
    ['label' => 'Sponsorer', 'url' => '/admin/sponsors.php'],
    ['label' => 'Reklamplatser', 'url' => '/admin/sponsor-placements.php'],
    ['label' => 'Förhandsvisa']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
/* Preview page styles */
.preview-intro {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-xl);
}
.preview-intro p {
    color: var(--color-text-secondary);
    margin: var(--space-sm) 0 0;
    font-size: 0.9rem;
    line-height: 1.5;
}

/* The fake page mockup */
.page-mockup {
    background: var(--color-bg-page);
    border: 2px solid var(--color-border-strong);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: var(--space-xl);
}

.mockup-browser-bar {
    background: var(--color-bg-surface);
    border-bottom: 1px solid var(--color-border);
    padding: var(--space-sm) var(--space-md);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.browser-dots {
    display: flex;
    gap: 6px;
}
.browser-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--color-border-strong);
}
.browser-dot.red { background: #ef4444; }
.browser-dot.yellow { background: #fbbf24; }
.browser-dot.green { background: #10b981; }

.browser-url {
    flex: 1;
    background: var(--color-bg-page);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: var(--space-2xs) var(--space-sm);
    font-size: 0.75rem;
    color: var(--color-text-muted);
    font-family: monospace;
}

/* Fake page header */
.mockup-header {
    background: var(--color-bg-card);
    border-bottom: 1px solid var(--color-border);
    padding: var(--space-md) var(--space-lg);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.mockup-logo {
    font-family: var(--font-heading);
    font-size: 1.25rem;
    color: var(--color-accent);
    font-weight: 700;
}
.mockup-nav {
    display: flex;
    gap: var(--space-md);
}
.mockup-nav span {
    color: var(--color-text-secondary);
    font-size: 0.85rem;
}

/* Placement zone - highlighted */
.placement-zone {
    position: relative;
    border: 2px dashed var(--color-accent);
    background: var(--color-accent-light);
    margin: 0;
}
.placement-zone-label {
    position: absolute;
    top: -1px;
    left: var(--space-md);
    background: var(--color-accent);
    color: #000;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px var(--space-sm);
    border-radius: 0 0 var(--radius-sm) var(--radius-sm);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    z-index: 2;
}

/* Fake content blocks */
.mockup-content {
    padding: var(--space-lg);
    max-width: 1200px;
    margin: 0 auto;
}

.mockup-page-title {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    color: var(--color-text-primary);
    margin-bottom: var(--space-md);
}

.content-placeholder {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}
.content-placeholder-line {
    height: 12px;
    background: var(--color-border);
    border-radius: 4px;
    margin-bottom: var(--space-xs);
    opacity: 0.5;
}
.content-placeholder-line:last-child {
    width: 60%;
}
.content-placeholder-line.short { width: 40%; }
.content-placeholder-line.medium { width: 75%; }

/* Mockup footer */
.mockup-footer {
    background: var(--color-bg-card);
    border-top: 1px solid var(--color-border);
    padding: var(--space-lg);
    text-align: center;
    color: var(--color-text-muted);
    font-size: 0.8rem;
}

/* Sponsor mockup items */
.sponsor-mock-banner {
    width: 100%;
    aspect-ratio: 8/1;
    background: linear-gradient(135deg, var(--color-bg-card) 0%, var(--color-bg-surface) 50%, var(--color-bg-card) 100%);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text-muted);
    font-size: 0.85rem;
    font-weight: 500;
    margin: var(--space-sm) var(--space-lg);
    overflow: hidden;
}
.sponsor-mock-banner .size-label {
    font-family: monospace;
    font-size: 0.7rem;
    color: var(--color-text-muted);
    opacity: 0.8;
}

.sponsor-mock-grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-lg);
}
.sponsor-mock-logo {
    flex: 1 1 calc(20% - var(--space-sm));
    min-width: 120px;
    aspect-ratio: 4/1;
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--color-text-muted);
    font-size: 0.7rem;
    text-align: center;
    gap: 2px;
}

.sponsor-mock-inline {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-xs) var(--space-md);
}
.sponsor-mock-inline-item {
    height: 36px;
    width: 120px;
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text-muted);
    font-size: 0.65rem;
}

.sponsor-mock-footer-grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-lg);
    justify-content: center;
}
.sponsor-mock-footer-item {
    flex: 0 1 calc(16.66% - var(--space-xs));
    min-width: 80px;
    aspect-ratio: 4/1;
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text-muted);
    font-size: 0.6rem;
}

/* Position detail cards */
.position-details {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-lg);
    margin-top: var(--space-xl);
}

.position-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}
.position-card-header {
    background: var(--color-accent-light);
    border-bottom: 1px solid var(--color-border);
    padding: var(--space-md) var(--space-lg);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.position-card-header h3 {
    margin: 0;
    font-size: 1rem;
    color: var(--color-text-primary);
}
.position-card-header code {
    background: var(--color-bg-page);
    padding: 2px var(--space-xs);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    color: var(--color-accent);
}
.position-card-body {
    padding: var(--space-lg);
}
.position-card-body p {
    color: var(--color-text-secondary);
    font-size: 0.9rem;
    line-height: 1.5;
    margin: 0 0 var(--space-md);
}

.spec-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-md);
}
.spec-item {
    background: var(--color-bg-page);
    border-radius: var(--radius-sm);
    padding: var(--space-md);
}
.spec-item-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-muted);
    margin-bottom: var(--space-2xs);
}
.spec-item-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

/* Visual ratio examples */
.ratio-example {
    margin-top: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-page);
    border-radius: var(--radius-sm);
    border: 1px solid var(--color-border);
}
.ratio-example-label {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-bottom: var(--space-xs);
}
.ratio-box {
    background: linear-gradient(135deg, var(--color-accent-light), var(--color-bg-surface));
    border: 2px solid var(--color-accent);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-accent);
    font-weight: 600;
    font-size: 0.85rem;
}

/* Responsive grid example */
.responsive-demo {
    margin-top: var(--space-md);
}
.responsive-demo-row {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-xs);
    align-items: center;
}
.responsive-demo-label {
    width: 60px;
    font-size: 0.7rem;
    color: var(--color-text-muted);
    flex-shrink: 0;
}
.responsive-demo-boxes {
    display: flex;
    gap: 2px;
    flex: 1;
}
.responsive-demo-box {
    height: 20px;
    background: var(--color-accent-light);
    border: 1px solid var(--color-accent);
    border-radius: 2px;
    flex: 1;
}

/* Mobile */
@media (max-width: 767px) {
    .page-mockup {
        margin-left: calc(-1 * var(--space-md));
        margin-right: calc(-1 * var(--space-md));
        border-radius: 0;
        border-left: none;
        border-right: none;
        width: calc(100% + var(--space-md) * 2);
    }
    .position-card {
        margin-left: calc(-1 * var(--space-md));
        margin-right: calc(-1 * var(--space-md));
        border-radius: 0;
        border-left: none;
        border-right: none;
        width: calc(100% + var(--space-md) * 2);
    }
    .sponsor-mock-logo {
        flex: 1 1 calc(50% - var(--space-sm));
    }
    .sponsor-mock-footer-item {
        flex: 0 1 calc(33.33% - var(--space-xs));
    }
}
</style>

<!-- Intro -->
<div class="preview-intro">
    <h2 style="margin: 0; font-size: 1.125rem;">Så här ser reklamplatserna ut</h2>
    <p>
        Nedan visas en simulerad sida med alla tillgängliga sponsorpositioner markerade.
        Varje position har en streckad ram och en etikett som visar var sponsorerna visas.
        Längre ner finns detaljerad information om varje position.
    </p>
    <p>
        <a href="/admin/sponsor-placements.php" class="btn btn-secondary" style="margin-top: var(--space-sm);">
            <i data-lucide="arrow-left"></i> Tillbaka till reklamplatser
        </a>
    </p>
</div>

<!-- ===== PAGE MOCKUP ===== -->
<h2 style="margin-bottom: var(--space-md);">Simulerad sida</h2>

<div class="page-mockup">
    <!-- Browser bar -->
    <div class="mockup-browser-bar">
        <div class="browser-dots">
            <span class="browser-dot red"></span>
            <span class="browser-dot yellow"></span>
            <span class="browser-dot green"></span>
        </div>
        <div class="browser-url">thehub.gravityseries.se/results</div>
    </div>

    <!-- Fake site header -->
    <div class="mockup-header">
        <div class="mockup-logo">TheHUB</div>
        <div class="mockup-nav">
            <span>Kalender</span>
            <span>Resultat</span>
            <span>Serier</span>
            <span>Ranking</span>
        </div>
    </div>

    <!-- POSITION 1: header_inline -->
    <div class="placement-zone">
        <span class="placement-zone-label">header_inline</span>
        <div class="sponsor-mock-inline">
            <span style="font-size: 0.7rem; color: var(--color-text-muted); white-space: nowrap;">Sponsorer:</span>
            <div class="sponsor-mock-inline-item">Logo 200×36</div>
            <div class="sponsor-mock-inline-item">Logo 200×36</div>
            <div class="sponsor-mock-inline-item">Logo 200×36</div>
        </div>
    </div>

    <!-- Page title -->
    <div class="mockup-content" style="padding-bottom: 0;">
        <div class="mockup-page-title">Resultat</div>
    </div>

    <!-- POSITION 2: header_banner -->
    <div class="placement-zone" style="margin: 0 var(--space-lg);">
        <span class="placement-zone-label">header_banner</span>
        <div class="sponsor-mock-banner">
            <div style="text-align: center;">
                <div>Fullbreddsbanner</div>
                <div class="size-label">1200 × 150 px (8:1)</div>
            </div>
        </div>
    </div>

    <!-- POSITION 3: content_top -->
    <div class="placement-zone" style="margin: var(--space-md) var(--space-lg);">
        <span class="placement-zone-label">content_top</span>
        <div class="sponsor-mock-grid">
            <div class="sponsor-mock-logo"><span>Sponsor 1</span><span>600×150</span></div>
            <div class="sponsor-mock-logo"><span>Sponsor 2</span><span>600×150</span></div>
            <div class="sponsor-mock-logo"><span>Sponsor 3</span><span>600×150</span></div>
            <div class="sponsor-mock-logo"><span>Sponsor 4</span><span>600×150</span></div>
            <div class="sponsor-mock-logo"><span>Sponsor 5</span><span>600×150</span></div>
        </div>
    </div>

    <!-- Fake content -->
    <div class="mockup-content" style="padding-top: 0;">
        <div class="content-placeholder">
            <div class="content-placeholder-line"></div>
            <div class="content-placeholder-line medium"></div>
            <div class="content-placeholder-line"></div>
            <div class="content-placeholder-line short"></div>
        </div>
        <div class="content-placeholder">
            <div class="content-placeholder-line"></div>
            <div class="content-placeholder-line"></div>
            <div class="content-placeholder-line medium"></div>
        </div>
        <div class="content-placeholder">
            <div class="content-placeholder-line short"></div>
            <div class="content-placeholder-line"></div>
            <div class="content-placeholder-line medium"></div>
            <div class="content-placeholder-line"></div>
        </div>
    </div>

    <!-- POSITION 4: content_bottom -->
    <div class="placement-zone" style="margin: 0 var(--space-lg) var(--space-lg);">
        <span class="placement-zone-label">content_bottom</span>
        <div class="sponsor-mock-grid">
            <div class="sponsor-mock-logo"><span>Sponsor 1</span><span>600×150</span></div>
            <div class="sponsor-mock-logo"><span>Sponsor 2</span><span>600×150</span></div>
            <div class="sponsor-mock-logo"><span>Sponsor 3</span><span>600×150</span></div>
            <div class="sponsor-mock-logo"><span>Sponsor 4</span><span>600×150</span></div>
            <div class="sponsor-mock-logo"><span>Sponsor 5</span><span>600×150</span></div>
        </div>
    </div>

    <!-- POSITION 5: footer -->
    <div class="placement-zone">
        <span class="placement-zone-label">footer</span>
        <div style="text-align: center; padding: var(--space-xs) 0 0; font-size: 0.7rem; color: var(--color-text-muted);">Våra partners</div>
        <div class="sponsor-mock-footer-grid">
            <div class="sponsor-mock-footer-item">Logo 1</div>
            <div class="sponsor-mock-footer-item">Logo 2</div>
            <div class="sponsor-mock-footer-item">Logo 3</div>
            <div class="sponsor-mock-footer-item">Logo 4</div>
            <div class="sponsor-mock-footer-item">Logo 5</div>
            <div class="sponsor-mock-footer-item">Logo 6</div>
        </div>
    </div>

    <!-- Fake footer -->
    <div class="mockup-footer">
        TheHUB &copy; 2026 GravitySeries
    </div>
</div>

<!-- ===== DETAIL CARDS PER POSITION ===== -->
<h2 style="margin-bottom: var(--space-md);">Detaljerad specifikation per position</h2>

<div class="position-details">

    <!-- header_inline -->
    <div class="position-card">
        <div class="position-card-header">
            <h3><i data-lucide="menu"></i> Header Inline</h3>
            <code>header_inline</code>
        </div>
        <div class="position-card-body">
            <p>
                Kompakta logotyper i en horisontell rad, placerade direkt under headern.
                Passar för titelsponsorers logotyper som ska synas överallt.
                Transparent bakgrund rekommenderas.
            </p>

            <div class="spec-grid">
                <div class="spec-item">
                    <div class="spec-item-label">Bildformat</div>
                    <div class="spec-item-value">600 × 150 px</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Aspect ratio</div>
                    <div class="spec-item-value">4:1 (auto-skalas)</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Visad storlek</div>
                    <div class="spec-item-value">Max 200 × 36 px</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Filtyp</div>
                    <div class="spec-item-value">PNG / SVG</div>
                </div>
            </div>

            <div class="ratio-example">
                <div class="ratio-example-label">Så här stor visas logon (skalenlig)</div>
                <div class="ratio-box" style="width: 200px; height: 36px;">200 × 36</div>
            </div>
        </div>
    </div>

    <!-- header_banner -->
    <div class="position-card">
        <div class="position-card-header">
            <h3><i data-lucide="image"></i> Header Banner</h3>
            <code>header_banner</code>
        </div>
        <div class="position-card-body">
            <p>
                Fullbreddsbanner som visas under sidrubriken. En enda sponsor tar hela bredden.
                Skalas responsivt från mobil (320px) till desktop (1200px+).
                Perfekt för en titel-sponsorbannerannons.
            </p>

            <div class="spec-grid">
                <div class="spec-item">
                    <div class="spec-item-label">Bildformat</div>
                    <div class="spec-item-value">1200 × 150 px</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Aspect ratio</div>
                    <div class="spec-item-value">8:1</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Visad bredd</div>
                    <div class="spec-item-value">100% av innehållsytan</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Filtyp</div>
                    <div class="spec-item-value">PNG / JPG</div>
                </div>
            </div>

            <div class="ratio-example">
                <div class="ratio-example-label">Proportioner (skalenlig)</div>
                <div class="ratio-box" style="width: 100%; aspect-ratio: 8/1;">1200 × 150 (8:1)</div>
            </div>
        </div>
    </div>

    <!-- content_top -->
    <div class="position-card">
        <div class="position-card-header">
            <h3><i data-lucide="layout-grid"></i> Innehåll Topp</h3>
            <code>content_top</code>
        </div>
        <div class="position-card-body">
            <p>
                Responsivt rutnät med sponsorlogotyper ovanför sidans huvudinnehåll.
                Antal per rad anpassas automatiskt efter skärmstorlek.
            </p>

            <div class="spec-grid">
                <div class="spec-item">
                    <div class="spec-item-label">Bildformat</div>
                    <div class="spec-item-value">600 × 150 px</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Aspect ratio</div>
                    <div class="spec-item-value">4:1</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Max antal</div>
                    <div class="spec-item-value">5 per rad (desktop)</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Filtyp</div>
                    <div class="spec-item-value">PNG / SVG / JPG</div>
                </div>
            </div>

            <div class="responsive-demo">
                <div class="ratio-example-label" style="margin-bottom: var(--space-xs);">Antal per rad per enhet</div>
                <div class="responsive-demo-row">
                    <span class="responsive-demo-label">Desktop</span>
                    <div class="responsive-demo-boxes">
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                    </div>
                    <span style="font-size: 0.7rem; color: var(--color-text-muted); width: 30px; text-align: right;">5 st</span>
                </div>
                <div class="responsive-demo-row">
                    <span class="responsive-demo-label">Tablet</span>
                    <div class="responsive-demo-boxes">
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                    </div>
                    <span style="font-size: 0.7rem; color: var(--color-text-muted); width: 30px; text-align: right;">4 st</span>
                </div>
                <div class="responsive-demo-row">
                    <span class="responsive-demo-label">Mobil</span>
                    <div class="responsive-demo-boxes">
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                    </div>
                    <span style="font-size: 0.7rem; color: var(--color-text-muted); width: 30px; text-align: right;">2 st</span>
                </div>
            </div>

            <div class="ratio-example" style="margin-top: var(--space-md);">
                <div class="ratio-example-label">Proportioner per logo (skalenlig)</div>
                <div class="ratio-box" style="width: 200px; aspect-ratio: 4/1;">600 × 150 (4:1)</div>
            </div>
        </div>
    </div>

    <!-- content_bottom -->
    <div class="position-card">
        <div class="position-card-header">
            <h3><i data-lucide="layout-grid"></i> Innehåll Botten</h3>
            <code>content_bottom</code>
        </div>
        <div class="position-card-body">
            <p>
                Identiskt med <code>content_top</code> men placeras under sidans huvudinnehåll.
                Samma responsiva rutnät och bildformat.
            </p>

            <div class="spec-grid">
                <div class="spec-item">
                    <div class="spec-item-label">Bildformat</div>
                    <div class="spec-item-value">600 × 150 px</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Aspect ratio</div>
                    <div class="spec-item-value">4:1</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Max antal</div>
                    <div class="spec-item-value">5 per rad (desktop)</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Layout</div>
                    <div class="spec-item-value">Identisk med content_top</div>
                </div>
            </div>
        </div>
    </div>

    <!-- footer -->
    <div class="position-card">
        <div class="position-card-header">
            <h3><i data-lucide="panel-bottom"></i> Footer</h3>
            <code>footer</code>
        </div>
        <div class="position-card-body">
            <p>
                Kompakt rutnät i sidfoten med mindre logotyper. Fler per rad än innehållspositionerna.
                Visas via layout-footern på alla sidor som har en footer-placering.
            </p>

            <div class="spec-grid">
                <div class="spec-item">
                    <div class="spec-item-label">Bildformat</div>
                    <div class="spec-item-value">600 × 150 px</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Aspect ratio</div>
                    <div class="spec-item-value">4:1</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Max antal</div>
                    <div class="spec-item-value">6 per rad (desktop)</div>
                </div>
                <div class="spec-item">
                    <div class="spec-item-label">Storlek</div>
                    <div class="spec-item-value">Kompaktare än content</div>
                </div>
            </div>

            <div class="responsive-demo">
                <div class="ratio-example-label" style="margin-bottom: var(--space-xs);">Antal per rad per enhet</div>
                <div class="responsive-demo-row">
                    <span class="responsive-demo-label">Desktop</span>
                    <div class="responsive-demo-boxes">
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                    </div>
                    <span style="font-size: 0.7rem; color: var(--color-text-muted); width: 30px; text-align: right;">6 st</span>
                </div>
                <div class="responsive-demo-row">
                    <span class="responsive-demo-label">Tablet</span>
                    <div class="responsive-demo-boxes">
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                    </div>
                    <span style="font-size: 0.7rem; color: var(--color-text-muted); width: 30px; text-align: right;">5 st</span>
                </div>
                <div class="responsive-demo-row">
                    <span class="responsive-demo-label">Mobil</span>
                    <div class="responsive-demo-boxes">
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                        <div class="responsive-demo-box"></div>
                    </div>
                    <span style="font-size: 0.7rem; color: var(--color-text-muted); width: 30px; text-align: right;">3 st</span>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Sammanfattning -->
<div class="preview-intro" style="margin-top: var(--space-xl);">
    <h3 style="margin: 0 0 var(--space-sm);">Sammanfattning av bildformat</h3>
    <div class="table-responsive" style="margin-top: var(--space-md);">
        <table class="table" style="font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Bildstorlek</th>
                    <th>Ratio</th>
                    <th>Desktop</th>
                    <th>Tablet</th>
                    <th>Mobil</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>header_inline</strong></td>
                    <td>600 × 150</td>
                    <td>4:1</td>
                    <td>Max 200px bred</td>
                    <td>Max 120px bred</td>
                    <td>Max 120px bred</td>
                </tr>
                <tr>
                    <td><strong>header_banner</strong></td>
                    <td>1200 × 150</td>
                    <td>8:1</td>
                    <td>100% bredd</td>
                    <td>100% bredd</td>
                    <td>100% bredd</td>
                </tr>
                <tr>
                    <td><strong>content_top</strong></td>
                    <td>600 × 150</td>
                    <td>4:1</td>
                    <td>5 per rad (20%)</td>
                    <td>4 per rad (25%)</td>
                    <td>2 per rad (50%)</td>
                </tr>
                <tr>
                    <td><strong>content_bottom</strong></td>
                    <td>600 × 150</td>
                    <td>4:1</td>
                    <td>5 per rad (20%)</td>
                    <td>4 per rad (25%)</td>
                    <td>2 per rad (50%)</td>
                </tr>
                <tr>
                    <td><strong>footer</strong></td>
                    <td>600 × 150</td>
                    <td>4:1</td>
                    <td>6 per rad (16%)</td>
                    <td>5 per rad (20%)</td>
                    <td>3 per rad (33%)</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
