<?php
/**
 * Admin Tools - Data Management & Cleanup
 * TheHUB V3
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get statistics for tools
$stats = [
    'potential_duplicates' => 0,
    'riders_without_uci' => 0,
    'empty_clubs' => 0,
    'total_riders' => 0,
    'total_results' => 0
];

try {
    // Count potential duplicate riders
    $result = $db->query("
        SELECT COUNT(*) FROM (
            SELECT first_name, last_name, birth_year
            FROM riders
            GROUP BY first_name, last_name, birth_year
            HAVING COUNT(*) > 1
        ) as dups
    ");
    if ($result) $stats['potential_duplicates'] = $result->fetchColumn() ?: 0;

    // Count riders without UCI ID
    $result = $db->query("
        SELECT COUNT(*) FROM riders WHERE uci_id IS NULL OR uci_id = ''
    ");
    if ($result) $stats['riders_without_uci'] = $result->fetchColumn() ?: 0;

    // Count clubs without members
    $result = $db->query("
        SELECT COUNT(*) FROM clubs c
        WHERE NOT EXISTS (SELECT 1 FROM riders r WHERE r.club_id = c.id)
    ");
    if ($result) $stats['empty_clubs'] = $result->fetchColumn() ?: 0;

    // Total riders
    $result = $db->query("SELECT COUNT(*) FROM riders");
    if ($result) $stats['total_riders'] = $result->fetchColumn() ?: 0;

    // Total results
    $result = $db->query("SELECT COUNT(*) FROM results");
    if ($result) $stats['total_results'] = $result->fetchColumn() ?: 0;

} catch (Exception $e) {
    // Stats already initialized to zero above
    error_log("Tools stats error: " . $e->getMessage());
}

// Page config
$page_title = 'Verktyg';
$breadcrumbs = [
    ['label' => 'Inställningar', 'url' => '/admin/settings'],
    ['label' => 'Verktyg']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.tools-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-lg);
}

.tool-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    display: flex;
    flex-direction: column;
}

.tool-card-header {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}

.tool-icon {
    width: 48px;
    height: 48px;
    background: var(--color-accent-light);
    color: var(--color-accent);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.tool-icon svg {
    width: 24px;
    height: 24px;
}

.tool-icon.warning {
    background: #FEF3C7;
    color: #D97706;
}

.tool-icon.danger {
    background: #FEE2E2;
    color: #DC2626;
}

.tool-title {
    font-weight: 600;
    font-size: var(--text-lg);
    margin: 0 0 var(--space-2xs);
}

.tool-description {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    margin: 0;
}

.tool-stats {
    display: flex;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
    padding: var(--space-sm) 0;
    border-top: 1px solid var(--color-border);
    border-bottom: 1px solid var(--color-border);
}

.tool-stat {
    text-align: center;
    flex: 1;
}

.tool-stat-value {
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--color-text-primary);
}

.tool-stat-value.warning {
    color: #D97706;
}

.tool-stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
}

.tool-actions {
    margin-top: auto;
    display: flex;
    gap: var(--space-sm);
}

.section-title {
    font-size: var(--text-lg);
    font-weight: 600;
    margin: var(--space-xl) 0 var(--space-md);
    padding-bottom: var(--space-sm);
    border-bottom: 2px solid var(--color-border);
}

.section-title:first-child {
    margin-top: 0;
}
</style>

<h3 class="section-title">Namnhantering</h3>

<div class="tools-grid">
    <!-- Normalize Names -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Normalisera namn</h4>
                <p class="tool-description">Konvertera namn från VERSALER eller gemener till korrekt versalgemen form</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/normalize-names" class="btn-admin btn-admin-primary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/></svg>
                Normalisera namn
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Dubbletthantering</h3>

<div class="tools-grid">
    <!-- Find Duplicates -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Hitta dubbletter</h4>
                <p class="tool-description">Sök efter potentiella dubbletter bland deltagare</p>
            </div>
        </div>
        <div class="tool-stats">
            <div class="tool-stat">
                <div class="tool-stat-value <?= $stats['potential_duplicates'] > 0 ? 'warning' : '' ?>">
                    <?= number_format($stats['potential_duplicates']) ?>
                </div>
                <div class="tool-stat-label">Möjliga dubbletter</div>
            </div>
            <div class="tool-stat">
                <div class="tool-stat-value"><?= number_format($stats['total_riders']) ?></div>
                <div class="tool-stat-label">Totalt deltagare</div>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/find-duplicates" class="btn-admin btn-admin-primary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Sök dubbletter
            </a>
        </div>
    </div>

    <!-- Merge Duplicates -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Slå ihop dubbletter</h4>
                <p class="tool-description">Kombinera duplicerade deltagarposter och flytta resultat</p>
            </div>
        </div>
        <div class="tool-stats">
            <div class="tool-stat">
                <div class="tool-stat-value"><?= number_format($stats['total_results']) ?></div>
                <div class="tool-stat-label">Totalt resultat</div>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/cleanup-duplicates" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3"/></svg>
                Hantera sammanslagning
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Klubbhantering</h3>

<div class="tools-grid">
    <!-- Cleanup Clubs -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Rensa klubbar</h4>
                <p class="tool-description">Ta bort tomma klubbar och hantera dubbletter</p>
            </div>
        </div>
        <div class="tool-stats">
            <div class="tool-stat">
                <div class="tool-stat-value <?= $stats['empty_clubs'] > 0 ? 'warning' : '' ?>">
                    <?= number_format($stats['empty_clubs']) ?>
                </div>
                <div class="tool-stat-label">Tomma klubbar</div>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/cleanup-clubs" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg>
                Rensa klubbar
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">UCI & Licenshantering</h3>

<div class="tools-grid">
    <!-- Search UCI ID -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Sök UCI-ID</h4>
                <p class="tool-description">Sök efter deltagare med specifikt UCI-ID</p>
            </div>
        </div>
        <div class="tool-stats">
            <div class="tool-stat">
                <div class="tool-stat-value <?= $stats['riders_without_uci'] > 0 ? 'warning' : '' ?>">
                    <?= number_format($stats['riders_without_uci']) ?>
                </div>
                <div class="tool-stat-label">Utan UCI-ID</div>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/search-uci-id" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Sök UCI-ID
            </a>
        </div>
    </div>

    <!-- Import UCI -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Importera UCI-data</h4>
                <p class="tool-description">Importera och uppdatera UCI-ID för deltagare</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-uci" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/></svg>
                Importera UCI
            </a>
        </div>
    </div>

    <!-- Import Gravity ID -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Importera Gravity-ID</h4>
                <p class="tool-description">Koppla SWE-ID till deltagare</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-gravity-id" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/></svg>
                Importera Gravity-ID
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Resultathantering</h3>

<div class="tools-grid">
    <!-- Clear Event Results -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Rensa eventresultat</h4>
                <p class="tool-description">Ta bort alla resultat från ett specifikt event</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/clear-event-results" class="btn-admin btn-admin-danger" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg>
                Rensa resultat
            </a>
        </div>
    </div>

    <!-- Import History -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Import-historik</h4>
                <p class="tool-description">Se tidigare importer och deras status</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-history" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 3v5h5"/></svg>
                Visa historik
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Serier & Poäng</h3>

<div class="tools-grid">
    <!-- Point Scales -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Poängmallar</h4>
                <p class="tool-description">Hantera poängskalor för event och serier</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/point-scales.php" class="btn-admin btn-admin-primary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/></svg>
                Hantera poängmallar
            </a>
        </div>
    </div>

    <!-- Series Events -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Serie-event</h4>
                <p class="tool-description">Koppla event till serier och hantera poängberäkning</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/series" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/></svg>
                Hantera serier
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Statistik & Utmärkelser</h3>

<div class="tools-grid">
    <!-- Rebuild Stats -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Räkna om statistik</h4>
                <p class="tool-description">Uppdatera åkarstatistik och utmärkelser efter resultatimport</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/rebuild-stats.php" class="btn-admin btn-admin-primary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M21.5 2v6h-6M2.5 22v-6h6"/></svg>
                Rebuild statistik
            </a>
        </div>
    </div>

    <!-- Diagnose Series Champions -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M11 8v6"/><path d="M8 11h6"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Diagnos: Seriemästare</h4>
                <p class="tool-description">Se vilka serier som kvalificerar för mästare-beräkning</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/diagnose-series" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Visa diagnostik
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Cache & System</h3>

<div class="tools-grid">
    <!-- Clear Cache -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Rensa cache</h4>
                <p class="tool-description">Töm systemets cachade data</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/clear-cache" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M21.5 2v6h-6"/></svg>
                Rensa cache
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Databasmigrationer</h3>

<div class="tools-grid">
    <!-- Run Migrations -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Databasmigrationer</h4>
                <p class="tool-description">Kör databasmigrationer och uppdatera schema</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/run-migrations.php" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/></svg>
                Kör migrationer
            </a>
        </div>
    </div>

    <!-- Debug Migration -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/><path d="m14.5 4-5 16"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Debug migrering</h4>
                <p class="tool-description">Felsök och testa databasmigrationer</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/migrations/debug-migration.php" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/></svg>
                Debug migrering
            </a>
        </div>
    </div>

    <!-- Manual SQL -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M10 12a1 1 0 0 0-1 1v1a1 1 0 0 1-1 1 1 1 0 0 1 1 1v1a1 1 0 0 0 1 1"/><path d="M14 18a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1 1 1 0 0 1-1-1v-1a1 1 0 0 0-1-1"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Manuella SQL-instruktioner</h4>
                <p class="tool-description">Visa SQL-kommandon för manuell körning</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/migrations/manual-sql-instructions.php" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/></svg>
                Visa SQL
            </a>
        </div>
    </div>

    <!-- Populate Series Results -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Fyll i serieresultat</h4>
                <p class="tool-description">Beräkna och fyll i serieresultat från eventdata</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/migrations/populate-series-results.php" class="btn-admin btn-admin-secondary" style="flex: 1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/></svg>
                Beräkna resultat
            </a>
        </div>
    </div>
</div>

<!-- Warning Box -->
<div class="alert alert-warning" style="margin-top: var(--space-xl);">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>
    <div>
        <strong>Varning:</strong> Vissa av dessa verktyg kan göra permanenta ändringar i databasen.
        Säkerhetskopiera alltid data innan du använder rensnings- eller sammanslagningsverktyg.
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
