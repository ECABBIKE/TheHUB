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
    // Count potential duplicate riders (same name, regardless of birth year)
    $result = $db->query("
        SELECT COUNT(*) FROM (
            SELECT UPPER(firstname), UPPER(lastname)
            FROM riders
            GROUP BY UPPER(firstname), UPPER(lastname)
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
            <a href="/admin/normalize-names.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/></svg>
                Normalisera namn
            </a>
        </div>
    </div>

    <!-- Fix Birth Years -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/><path d="m9 16 2 2 4-4"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Fixa födelseår</h4>
                <p class="tool-description">Korrigera felaktiga födelseår (t.ex. från felparsade personnummer)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-birth-years.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                Fixa födelseår
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
            <a href="/admin/find-duplicates.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
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
            <a href="/admin/cleanup-duplicates.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3"/></svg>
                Hantera sammanslagning
            </a>
        </div>
    </div>

    <!-- Auto-merge UCI Duplicates -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Auto-merge UCI Dubletter</h4>
                <p class="tool-description">Automatiskt slå ihop deltagare med samma UCI-ID. Behåller den med flest resultat.</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/auto-merge-duplicates.php" class="btn-admin btn-admin-danger flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3"/></svg>
                Auto-merge UCI
            </a>
        </div>
    </div>

    <!-- Fix Corrupted UCI-IDs -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Fixa korrupt UCI-data</h4>
                <p class="tool-description">Rensar UCI-ID från åkare som fått samma ID av misstag vid import.</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-corrupted-uci.php" class="btn-admin btn-admin-danger flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg>
                Fixa korrupt UCI
            </a>
        </div>
    </div>

    <!-- Find Name Duplicates -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Namndubletter</h4>
                <p class="tool-description">Hitta samma namn med olika licens-ID. Per Einar Brovold med både UCI och SWE-ID.</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/find-name-duplicates.php" class="btn-admin btn-admin-warning flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Hitta namndubletter
            </a>
        </div>
    </div>

    <!-- BULK Merge Duplicates -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/></svg>
            </div>
            <div>
                <h4 class="tool-title">BULK: Slå ihop ALLA</h4>
                <p class="tool-description">Slå ihop ALLA dubletter (UCI + namn) med ett klick. Behåller bästa profilen.</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/bulk-merge-duplicates.php" class="btn-admin btn-admin-danger flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3"/></svg>
                BULK MERGE
            </a>
        </div>
    </div>

    <!-- Assign Missing SWE-ID -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Tilldela saknade SWE-ID</h4>
                <p class="tool-description">Ge alla åkare utan licens-ID ett unikt SWE-ID (SWE25XXXXX).</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/assign-missing-swe-id.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Tilldela SWE-ID
            </a>
        </div>
    </div>

    <!-- Merge Specific Riders -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Slå ihop specifika åkare</h4>
                <p class="tool-description">Manuellt välja och slå ihop två specifika deltagare.</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/merge-specific-riders.php" class="btn-admin btn-admin-warning flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="m8 6 4-4 4 4"/><path d="M12 2v10.3"/></svg>
                Slå ihop manuellt
            </a>
        </div>
    </div>

    <!-- Enrich Riders -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Berika åkardata</h4>
                <p class="tool-description">Komplettera åkarprofiler med saknad data.</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/enrich-riders.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Berika åkare
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
            <a href="/admin/cleanup-clubs.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg>
                Rensa klubbar
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">UCI & Licenshantering</h3>

<div class="tools-grid">
    <!-- Fix UCI ID Format (Database) -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Fixa UCI-ID i databasen</h4>
                <p class="tool-description">Skanna och fixa alla felformaterade UCI-ID automatiskt</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-uci-format.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                Skanna &amp; Fixa
            </a>
        </div>
    </div>

    <!-- Fix SWE-ID Format -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Konvertera SWE-ID format</h4>
                <p class="tool-description">Konvertera gamla SWE-ID (SWE-03.235) till nytt format (SWE2500235)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-swe-format.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                Konvertera SWE-ID
            </a>
        </div>
    </div>

    <!-- Fix SWE-ID Prefix -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Fixa SWE-ID prefix</h4>
                <p class="tool-description">Lägg till "SWE" på licensnummer som saknar prefix (2500581 → SWE2500581)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-swe-prefix.php" class="btn-admin btn-admin-warning flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                Fixa prefix
            </a>
        </div>
    </div>

    <!-- Sync Club Membership -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Synka klubbmedlemskap</h4>
                <p class="tool-description">Synka rider_club_seasons med riders.club_id - KRITISKT för klubbmästerskap!</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/sync-club-membership.php" class="btn-admin btn-admin-danger flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                Synka klubbar
            </a>
        </div>
    </div>

    <!-- Format UCI ID (Manual) -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Formatera UCI-ID (manuellt)</h4>
                <p class="tool-description">Konvertera enskilda UCI-ID till standardformat</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/format-uci-id.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/></svg>
                Formatera manuellt
            </a>
        </div>
    </div>

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
            <a href="/admin/search-uci-id.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
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
            <a href="/admin/import-uci.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/></svg>
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
            <a href="/admin/import-gravity-id.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/></svg>
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
            <a href="/admin/clear-event-results.php" class="btn-admin btn-admin-danger flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg>
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
            <a href="/admin/import-history.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 3v5h5"/></svg>
                Visa historik
            </a>
        </div>
    </div>

    <!-- Fix Result Club IDs -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="building-2"></i>
            </div>
            <div>
                <h4 class="tool-title">Fixa klubb-ID i resultat</h4>
                <p class="tool-description">Korrigera saknade eller felaktiga klubbtillhörigheter i resultat</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-result-club-ids.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="building-2" class="icon-sm"></i>
                Fixa klubb-ID
            </a>
        </div>
    </div>

    <!-- Move Class Results -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="move"></i>
            </div>
            <div>
                <h4 class="tool-title">Flytta klassresultat</h4>
                <p class="tool-description">Flytta resultat från en klass till en annan (vid felimport)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/move-class-results.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="move" class="icon-sm"></i>
                Flytta resultat
            </a>
        </div>
    </div>

    <!-- Stage Bonus Points -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="trophy"></i>
            </div>
            <div>
                <h4 class="tool-title">Sträckbonus</h4>
                <p class="tool-description">Ge bonuspoäng till snabbaste på en specifik sträcka (PS/SS)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/stage-bonus-points.php" class="btn-admin btn-admin-primary flex-1">
                <i data-lucide="trophy" class="icon-sm"></i>
                Sträckbonus
            </a>
        </div>
    </div>

    <!-- Fix Time Format -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="clock"></i>
            </div>
            <div>
                <h4 class="tool-title">Fixa tidsformat</h4>
                <p class="tool-description">Korrigera felaktiga tidsformat (t.ex. 0:04:17.45 → 4:17.45)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-time-format.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="clock" class="icon-sm"></i>
                Fixa tider
            </a>
        </div>
    </div>

    <!-- Recalculate All Points -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <i data-lucide="calculator"></i>
            </div>
            <div>
                <h4 class="tool-title">Räkna om alla poäng</h4>
                <p class="tool-description">Beräkna om poäng för alla resultat i systemet</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/recalculate-all-points.php" class="btn-admin btn-admin-danger flex-1">
                <i data-lucide="calculator" class="icon-sm"></i>
                Räkna om poäng
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Import</h3>

<div class="tools-grid">
    <!-- Import Results -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="upload"></i>
            </div>
            <div>
                <h4 class="tool-title">Importera resultat</h4>
                <p class="tool-description">Importera tävlingsresultat från CSV-fil</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-results.php" class="btn-admin btn-admin-primary flex-1">
                <i data-lucide="upload" class="icon-sm"></i>
                Importera resultat
            </a>
        </div>
    </div>

    <!-- Import Riders -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="users"></i>
            </div>
            <div>
                <h4 class="tool-title">Importera deltagare</h4>
                <p class="tool-description">Importera deltagare från CSV-fil</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-riders.php" class="btn-admin btn-admin-primary flex-1">
                <i data-lucide="users" class="icon-sm"></i>
                Importera deltagare
            </a>
        </div>
    </div>

    <!-- Import Clubs -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="building"></i>
            </div>
            <div>
                <h4 class="tool-title">Importera klubbar</h4>
                <p class="tool-description">Importera klubbar från CSV-fil</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-clubs.php" class="btn-admin btn-admin-secondary flex-1">
                <i data-lucide="building" class="icon-sm"></i>
                Importera klubbar
            </a>
        </div>
    </div>

    <!-- Import Events -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="calendar"></i>
            </div>
            <div>
                <h4 class="tool-title">Importera event</h4>
                <p class="tool-description">Importera event/tävlingar från CSV-fil</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-events.php" class="btn-admin btn-admin-secondary flex-1">
                <i data-lucide="calendar" class="icon-sm"></i>
                Importera event
            </a>
        </div>
    </div>

    <!-- All Import Tools -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="folder-input"></i>
            </div>
            <div>
                <h4 class="tool-title">Alla import-verktyg</h4>
                <p class="tool-description">Översikt över alla tillgängliga import-funktioner</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import.php" class="btn-admin btn-admin-secondary flex-1">
                <i data-lucide="folder-input" class="icon-sm"></i>
                Visa alla
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
            <a href="/admin/point-scales.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/></svg>
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
            <a href="/admin/series.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/></svg>
                Hantera serier
            </a>
        </div>
    </div>

    <!-- Fix Series Points -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Fixa seriepoäng</h4>
                <p class="tool-description">Diagnostisera och beräkna om seriesammanställningar</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-series-points.php" class="btn-admin btn-admin-warning flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0"/></svg>
                Fixa seriepoäng
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
            <a href="/admin/rebuild-stats.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M21.5 2v6h-6M2.5 22v-6h6"/></svg>
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
            <a href="/admin/diagnose-series.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Visa diagnostik
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Debug & Diagnostik</h3>

<div class="tools-grid">
    <!-- Debug Achievements -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="bug"></i>
            </div>
            <div>
                <h4 class="tool-title">Debug: Utmärkelser</h4>
                <p class="tool-description">Felsök och verifiera beräkning av utmärkelser och achievements</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/debug-achievements.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="bug" class="icon-sm"></i>
                Debug utmärkelser
            </a>
        </div>
    </div>

    <!-- Debug Series Points -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="chart-bar"></i>
            </div>
            <div>
                <h4 class="tool-title">Debug: Seriepoäng</h4>
                <p class="tool-description">Felsök och verifiera seriepoäng och standings</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/debug-series-points.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="chart-bar" class="icon-sm"></i>
                Debug seriepoäng
            </a>
        </div>
    </div>

    <!-- Test DB -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="database"></i>
            </div>
            <div>
                <h4 class="tool-title">Testa databasanslutning</h4>
                <p class="tool-description">Verifiera att databasanslutningen fungerar korrekt</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/test-db.php" class="btn-admin btn-admin-secondary flex-1">
                <i data-lucide="database" class="icon-sm"></i>
                Testa DB
            </a>
        </div>
    </div>

    <!-- Test Import Debug -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="file-search"></i>
            </div>
            <div>
                <h4 class="tool-title">Debug: Import</h4>
                <p class="tool-description">Felsök CSV-import och kolumnmappning</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/test-import-debug.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="file-search" class="icon-sm"></i>
                Debug import
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Data & Licensverktyg</h3>

<div class="tools-grid">
    <!-- Data Explorer -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="table-2"></i>
            </div>
            <div>
                <h4 class="tool-title">Data Explorer</h4>
                <p class="tool-description">Bläddra och sök i databastabeller, visa statistik</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/data-explorer.php" class="btn-admin btn-admin-primary flex-1">
                <i data-lucide="table-2" class="icon-sm"></i>
                Öppna Data Explorer
            </a>
        </div>
    </div>

    <!-- Check License Numbers -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="id-card"></i>
            </div>
            <div>
                <h4 class="tool-title">Kontrollera licensnummer</h4>
                <p class="tool-description">Verifiera och kontrollera licensnummer i systemet</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/check-license-numbers.php" class="btn-admin btn-admin-secondary flex-1">
                <i data-lucide="id-card" class="icon-sm"></i>
                Kontrollera licenser
            </a>
        </div>
    </div>

    <!-- Verify SWE Licenses -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="shield-check"></i>
            </div>
            <div>
                <h4 class="tool-title">Verifiera SWE-licenser</h4>
                <p class="tool-description">Kontrollera giltighet och format för svenska licenser</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/verify-swe-licenses.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="shield-check" class="icon-sm"></i>
                Verifiera SWE
            </a>
        </div>
    </div>

    <!-- License Class Matrix -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="grid-3x3"></i>
            </div>
            <div>
                <h4 class="tool-title">Licens-klassmatris</h4>
                <p class="tool-description">Visa matris över licenser och klasser för deltagare</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/license-class-matrix.php" class="btn-admin btn-admin-secondary flex-1">
                <i data-lucide="grid-3x3" class="icon-sm"></i>
                Visa matris
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Årshantering & Datakvalitet</h3>

<div class="tools-grid">
    <!-- Yearly Rebuild -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <i data-lucide="calendar-cog"></i>
            </div>
            <div>
                <h4 class="tool-title">Årsombyggnad</h4>
                <p class="tool-description">Komplett arbetsflöde: importera deltagare, lås klubbar, rensa resultat, importera om</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/yearly-rebuild.php" class="btn-admin btn-admin-danger flex-1">
                <i data-lucide="calendar-cog" class="icon-sm"></i>
                Årsombyggnad
            </a>
        </div>
    </div>

    <!-- Yearly Import Review -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="calendar-check"></i>
            </div>
            <div>
                <h4 class="tool-title">Import-granskning</h4>
                <p class="tool-description">Granska alla event per år, hitta problem, lås klubbtillhörigheter</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/yearly-import-review.php" class="btn-admin btn-admin-primary flex-1">
                <i data-lucide="calendar-check" class="icon-sm"></i>
                Årsgranskning
            </a>
        </div>
    </div>

    <!-- Diagnose Club Times -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="clock"></i>
            </div>
            <div>
                <h4 class="tool-title">Diagnostik: Tidsklubbar</h4>
                <p class="tool-description">Hitta klubbar vars namn ser ut som tider (kolumnförskjutning)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/diagnose-club-times.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="search" class="icon-sm"></i>
                Diagnostisera
            </a>
        </div>
    </div>

    <!-- Fix Club Times -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="wrench"></i>
            </div>
            <div>
                <h4 class="tool-title">Fixa tidsklubbar</h4>
                <p class="tool-description">Rensa och fixa klubbar med tidsliknande namn</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/fix-club-times.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="wrench" class="icon-sm"></i>
                Fixa klubbar
            </a>
        </div>
    </div>

    <!-- Diagnose Class Errors -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <i data-lucide="users"></i>
            </div>
            <div>
                <h4 class="tool-title">Diagnostik: Klassfel</h4>
                <p class="tool-description">Hitta åkare som hamnat i fel klass (t.ex. Motion Kids)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/diagnose-class-errors.php" class="btn-admin btn-admin-warning flex-1">
                <i data-lucide="search" class="icon-sm"></i>
                Diagnostisera
            </a>
        </div>
    </div>

    <!-- Fix UCI Conflicts -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <i data-lucide="user-x"></i>
            </div>
            <div>
                <h4 class="tool-title">Fixa UCI-konflikter</h4>
                <p class="tool-description">Hitta och fixa åkare med förväxlade UCI-ID (samma efternamn)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/fix-uci-conflicts.php" class="btn-admin btn-admin-danger flex-1">
                <i data-lucide="user-x" class="icon-sm"></i>
                Fixa UCI
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
            <a href="/admin/clear-cache.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M21.5 2v6h-6"/></svg>
                Rensa cache
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Backup & Reset</h3>

<div class="tools-grid">
    <!-- Backup -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="hard-drive-download"></i>
            </div>
            <div>
                <h4 class="tool-title">Backup</h4>
                <p class="tool-description">Skapa backup av databas och filer</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/backup.php" class="btn-admin btn-admin-primary flex-1">
                <i data-lucide="hard-drive-download" class="icon-sm"></i>
                Skapa backup
            </a>
        </div>
    </div>

    <!-- Reset Data -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <i data-lucide="trash-2"></i>
            </div>
            <div>
                <h4 class="tool-title">Återställ data</h4>
                <p class="tool-description">Nollställ och rensa specifika databastabeller (FARLIGT!)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/reset-data.php" class="btn-admin btn-admin-danger flex-1">
                <i data-lucide="trash-2" class="icon-sm"></i>
                Återställ data
            </a>
        </div>
    </div>

    <!-- Reset Simple -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger">
                <i data-lucide="rotate-ccw"></i>
            </div>
            <div>
                <h4 class="tool-title">Enkel återställning</h4>
                <p class="tool-description">Snabb återställning av vanliga tabeller</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/reset-simple.php" class="btn-admin btn-admin-danger flex-1">
                <i data-lucide="rotate-ccw" class="icon-sm"></i>
                Enkel reset
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
            <a href="/admin/migrations.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/></svg>
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
            <a href="/admin/migrations/debug-migration.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/></svg>
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
            <a href="/admin/migrations/manual-sql-instructions.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/></svg>
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
            <a href="/admin/migrations/populate-series-results.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/></svg>
                Beräkna resultat
            </a>
        </div>
    </div>

    <!-- Add Rider Role (Migration 065) -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Lägg till rider-roll</h4>
                <p class="tool-description">Migration 065: Lägger till rider som giltig användarroll</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/migrations/add-rider-role.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Kör migration
            </a>
        </div>
    </div>

    <!-- Club Points System (Migration 071) -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Klubbpoäng-tabeller</h4>
                <p class="tool-description">Migration 071: Skapar tabeller för klubbpoängsystemet</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/run-migration.php?file=071_club_points_system.sql.php" class="btn-admin btn-admin-warning flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/></svg>
                Kör migration 071
            </a>
        </div>
    </div>

    <!-- Ranking Snapshots System (Migration 072) -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 21h12a2 2 0 0 0 2-2v-2H10v2a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v3h4"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/><path d="m9 9.5 2 2 4-4"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Ranking-tabeller</h4>
                <p class="tool-description">Migration 072: Skapar tabeller för 24-månaders ranking (snapshots)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/run-migration.php?file=072_ranking_snapshots_system.sql.php" class="btn-admin btn-admin-warning flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/></svg>
                Kör migration 072
            </a>
        </div>
    </div>
</div>

<h3 class="section-title">Ranking</h3>

<div class="tools-grid">
    <!-- Ranking Backfill -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Backfill Ranking Snapshots</h4>
                <p class="tool-description">Generera historiska ranking-snapshots för varje event-datum. Krävs för att visa fullständig ranking-graf.</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/ranking-backfill.php" class="btn-admin btn-admin-primary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                Generera snapshots
            </a>
        </div>
    </div>

    <!-- Ranking Settings -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
            </div>
            <div>
                <h4 class="tool-title">Ranking-inställningar</h4>
                <p class="tool-description">Hantera ranking-systemet, beräkna om och se statistik</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/ranking.php" class="btn-admin btn-admin-secondary flex-1">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                Ranking-inställningar
            </a>
        </div>
    </div>

    <!-- Ranking Backfill History -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="history"></i>
            </div>
            <div>
                <h4 class="tool-title">Ranking-historik</h4>
                <p class="tool-description">Visa och hantera historiska ranking-snapshots</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/ranking-backfill-history.php" class="btn-admin btn-admin-secondary flex-1">
                <i data-lucide="history" class="icon-sm"></i>
                Visa historik
            </a>
        </div>
    </div>

    <!-- Ranking Minimal -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon">
                <i data-lucide="list-ordered"></i>
            </div>
            <div>
                <h4 class="tool-title">Minimal ranking</h4>
                <p class="tool-description">Visa enkel ranking-lista utan extra funktionalitet</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/ranking-minimal.php" class="btn-admin btn-admin-secondary flex-1">
                <i data-lucide="list-ordered" class="icon-sm"></i>
                Visa ranking
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
