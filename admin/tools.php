<?php
/**
 * Admin Tools - Simplified & Clean
 * TheHUB V3 - Only essential tools
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get statistics
$stats = [
    'potential_duplicates' => 0,
    'empty_clubs' => 0,
    'total_riders' => 0,
    'total_results' => 0
];

try {
    $result = $db->query("SELECT COUNT(*) FROM (SELECT UPPER(firstname), UPPER(lastname) FROM riders GROUP BY UPPER(firstname), UPPER(lastname) HAVING COUNT(*) > 1) as dups");
    if ($result) $stats['potential_duplicates'] = $result->fetchColumn() ?: 0;

    $result = $db->query("SELECT COUNT(*) FROM clubs c WHERE NOT EXISTS (SELECT 1 FROM riders r WHERE r.club_id = c.id)");
    if ($result) $stats['empty_clubs'] = $result->fetchColumn() ?: 0;

    $result = $db->query("SELECT COUNT(*) FROM riders");
    if ($result) $stats['total_riders'] = $result->fetchColumn() ?: 0;

    $result = $db->query("SELECT COUNT(*) FROM results");
    if ($result) $stats['total_results'] = $result->fetchColumn() ?: 0;
} catch (Exception $e) {
    error_log("Tools stats error: " . $e->getMessage());
}

$page_title = 'Verktyg';
$breadcrumbs = [
    ['label' => 'System', 'url' => '/admin/settings'],
    ['label' => 'Verktyg']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.tools-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-md);
}
.tool-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
}
.tool-card-header {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}
.tool-icon {
    width: 40px;
    height: 40px;
    background: var(--color-accent-light);
    color: var(--color-accent);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.tool-icon svg { width: 20px; height: 20px; }
.tool-icon.warning { background: #FEF3C7; color: #D97706; }
.tool-icon.danger { background: #FEE2E2; color: #DC2626; }
.tool-title { font-weight: 600; margin: 0 0 var(--space-2xs); }
.tool-description { color: var(--color-text-secondary); font-size: var(--text-sm); margin: 0; }
.tool-stat { display: inline-block; padding: var(--space-xs) var(--space-sm); background: var(--color-bg-hover); border-radius: var(--radius-sm); font-size: var(--text-sm); margin-top: var(--space-sm); }
.tool-stat.warning { background: #FEF3C7; color: #D97706; }
.tool-actions { margin-top: var(--space-md); }
.section-title {
    font-size: var(--text-lg);
    font-weight: 600;
    margin: var(--space-xl) 0 var(--space-md);
    padding-bottom: var(--space-sm);
    border-bottom: 2px solid var(--color-border);
}
.section-title:first-child { margin-top: 0; }
</style>

<!-- ========== SÄSONGSHANTERING ========== -->
<h3 class="section-title">Säsongshantering</h3>
<div class="tools-grid">

    <!-- Yearly Rebuild -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="calendar-cog"></i></div>
            <div>
                <h4 class="tool-title">Årsåterställning</h4>
                <p class="tool-description">Återställ data för ny säsong</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/yearly-rebuild.php" class="btn-admin btn-admin-warning">Öppna</a>
        </div>
    </div>

    <!-- Yearly Import Review -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="file-search"></i></div>
            <div>
                <h4 class="tool-title">Granska årsimport</h4>
                <p class="tool-description">Granska och godkänn importerad data</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/yearly-import-review.php" class="btn-admin btn-admin-secondary">Granska</a>
        </div>
    </div>

</div>

<!-- ========== KLUBBAR & ÅKARE ========== -->
<h3 class="section-title">Klubbar & Åkare</h3>
<div class="tools-grid">

    <!-- Sync Club Membership -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="link"></i></div>
            <div>
                <h4 class="tool-title">Synka klubbtillhörighet</h4>
                <p class="tool-description">Återskapa och lås klubbkopplingar</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/sync-club-membership.php" class="btn-admin btn-admin-primary">Synka</a>
        </div>
    </div>

    <!-- Sync Rider Clubs -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="users"></i></div>
            <div>
                <h4 class="tool-title">Synka åkare-klubbar</h4>
                <p class="tool-description">Uppdatera åkarnas klubbtillhörighet</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/sync-rider-clubs.php" class="btn-admin btn-admin-primary">Synka</a>
        </div>
    </div>

    <!-- Fix Result Club IDs -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="wrench"></i></div>
            <div>
                <h4 class="tool-title">Fixa klubb-ID i resultat</h4>
                <p class="tool-description">Korrigera felaktiga klubbreferenser</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-result-club-ids.php" class="btn-admin btn-admin-warning">Fixa</a>
        </div>
    </div>

    <!-- Normalize Names -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="case-sensitive"></i></div>
            <div>
                <h4 class="tool-title">Normalisera namn</h4>
                <p class="tool-description">Rätta stavning och versaler</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/normalize-names.php" class="btn-admin btn-admin-secondary">Normalisera</a>
        </div>
    </div>

    <!-- Search UCI ID -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="globe"></i></div>
            <div>
                <h4 class="tool-title">Sök UCI-ID</h4>
                <p class="tool-description">Hitta och tilldela UCI-ID</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/search-uci-id.php" class="btn-admin btn-admin-secondary">Sök</a>
        </div>
    </div>

    <!-- Fix UCI Conflicts -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="alert-triangle"></i></div>
            <div>
                <h4 class="tool-title">Fixa UCI-konflikter</h4>
                <p class="tool-description">Lös dubbletter och konflikter</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/fix-uci-conflicts.php" class="btn-admin btn-admin-warning">Fixa</a>
        </div>
    </div>

</div>

<!-- ========== DATAHANTERING ========== -->
<h3 class="section-title">Datahantering</h3>
<div class="tools-grid">

    <!-- Data Explorer -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="table-2"></i></div>
            <div>
                <h4 class="tool-title">Data Explorer</h4>
                <p class="tool-description">Bläddra och sök i databastabeller</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/data-explorer.php" class="btn-admin btn-admin-primary">Öppna</a>
        </div>
    </div>

    <!-- Rebuild Stats -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="refresh-cw"></i></div>
            <div>
                <h4 class="tool-title">Uppdatera statistik</h4>
                <p class="tool-description">Räkna om åkarstatistik och utmärkelser</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/rebuild-stats.php" class="btn-admin btn-admin-primary">Kör</a>
        </div>
    </div>

    <!-- Find Duplicates -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="copy"></i></div>
            <div>
                <h4 class="tool-title">Hitta dubbletter</h4>
                <p class="tool-description">Sök och hantera dubbletter</p>
            </div>
        </div>
        <?php if ($stats['potential_duplicates'] > 0): ?>
        <div class="tool-stat warning"><?= number_format($stats['potential_duplicates']) ?> möjliga dubbletter</div>
        <?php endif; ?>
        <div class="tool-actions">
            <a href="/admin/find-duplicates.php" class="btn-admin btn-admin-secondary">Sök</a>
            <a href="/admin/cleanup-duplicates.php" class="btn-admin btn-admin-secondary">Hantera</a>
        </div>
    </div>

    <!-- Cleanup Clubs -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="building"></i></div>
            <div>
                <h4 class="tool-title">Rensa klubbar</h4>
                <p class="tool-description">Ta bort tomma klubbar</p>
            </div>
        </div>
        <?php if ($stats['empty_clubs'] > 0): ?>
        <div class="tool-stat warning"><?= number_format($stats['empty_clubs']) ?> tomma klubbar</div>
        <?php endif; ?>
        <div class="tool-actions">
            <a href="/admin/cleanup-clubs.php" class="btn-admin btn-admin-secondary">Rensa</a>
        </div>
    </div>

</div>

<!-- ========== IMPORT & RESULTAT ========== -->
<h3 class="section-title">Import & Resultat</h3>
<div class="tools-grid">

    <!-- Import Results -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="upload"></i></div>
            <div>
                <h4 class="tool-title">Importera resultat</h4>
                <p class="tool-description">Importera från CSV</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-results.php" class="btn-admin btn-admin-primary">Importera</a>
        </div>
    </div>

    <!-- Clear Event Results -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger"><i data-lucide="trash-2"></i></div>
            <div>
                <h4 class="tool-title">Rensa resultat</h4>
                <p class="tool-description">Ta bort resultat från event</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/clear-event-results.php" class="btn-admin btn-admin-danger">Rensa</a>
        </div>
    </div>

    <!-- Import History -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="history"></i></div>
            <div>
                <h4 class="tool-title">Import-historik</h4>
                <p class="tool-description">Se tidigare importer</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/import-history.php" class="btn-admin btn-admin-secondary">Visa</a>
        </div>
    </div>

    <!-- Recalculate Points -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="calculator"></i></div>
            <div>
                <h4 class="tool-title">Räkna om poäng</h4>
                <p class="tool-description">Beräkna om alla poäng</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/recalculate-all-points.php" class="btn-admin btn-admin-warning">Kör</a>
        </div>
    </div>

</div>

<!-- ========== FELSÖKNING ========== -->
<h3 class="section-title">Felsökning</h3>
<div class="tools-grid">

    <!-- Data Quality -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger"><i data-lucide="search-x"></i></div>
            <div>
                <h4 class="tool-title">Datakvalitetsanalys</h4>
                <p class="tool-description">Hitta korrupt data</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/tools/analyze-data-quality.php" class="btn-admin btn-admin-danger">Analysera</a>
        </div>
    </div>

    <!-- Fix Series Points -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="wrench"></i></div>
            <div>
                <h4 class="tool-title">Fixa seriepoäng</h4>
                <p class="tool-description">Diagnostisera och fixa</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-series-points.php" class="btn-admin btn-admin-warning">Fixa</a>
        </div>
    </div>

    <!-- Fix Time Format -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="clock"></i></div>
            <div>
                <h4 class="tool-title">Fixa tidsformat</h4>
                <p class="tool-description">Korrigera felaktiga tider</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/fix-time-format.php" class="btn-admin btn-admin-warning">Fixa</a>
        </div>
    </div>

    <!-- Diagnose Series -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="stethoscope"></i></div>
            <div>
                <h4 class="tool-title">Diagnostik</h4>
                <p class="tool-description">Serie- och klassdiagnostik</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/diagnose-series.php" class="btn-admin btn-admin-secondary">Serier</a>
            <a href="/admin/tools/diagnose-class-errors.php" class="btn-admin btn-admin-secondary">Klasser</a>
        </div>
    </div>

</div>

<!-- ========== ANALYTICS ========== -->
<h3 class="section-title">Analytics</h3>
<div class="tools-grid">

    <!-- Analytics Setup -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="settings"></i></div>
            <div>
                <h4 class="tool-title">Analytics Setup</h4>
                <p class="tool-description">Skapa tabeller och views (kör först!)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/analytics/setup-governance.php" class="btn-admin btn-admin-warning">1. Governance</a>
            <a href="/analytics/setup-tables.php" class="btn-admin btn-admin-warning">2. Tabeller</a>
        </div>
    </div>

    <!-- Populate Historical -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="database"></i></div>
            <div>
                <h4 class="tool-title">Populate Historical</h4>
                <p class="tool-description">Generera historisk analytics-data</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/analytics-populate.php" class="btn-admin btn-admin-primary">Öppna</a>
        </div>
    </div>

    <!-- Historical Trends -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="trending-up"></i></div>
            <div>
                <h4 class="tool-title">Historiska Trender</h4>
                <p class="tool-description">Analysera trender över flera säsonger</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/analytics-trends.php" class="btn-admin btn-admin-primary">Visa</a>
        </div>
    </div>

    <!-- Analytics Dashboard -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="bar-chart-3"></i></div>
            <div>
                <h4 class="tool-title">Analytics Dashboard</h4>
                <p class="tool-description">KPI-översikt och statistik</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/analytics-dashboard.php" class="btn-admin btn-admin-secondary">Öppna</a>
        </div>
    </div>

    <!-- Analytics Diagnostik -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon warning"><i data-lucide="stethoscope"></i></div>
            <div>
                <h4 class="tool-title">Analytics Diagnostik</h4>
                <p class="tool-description">Felsök datadiskrepanser</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/analytics-diagnose.php" class="btn-admin btn-admin-warning">Diagnostisera</a>
        </div>
    </div>

    <!-- Reset Analytics -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger"><i data-lucide="rotate-ccw"></i></div>
            <div>
                <h4 class="tool-title">Reset Analytics</h4>
                <p class="tool-description">Rensa och kor om berakningar</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/analytics-reset.php" class="btn-admin btn-admin-danger">Reset</a>
        </div>
    </div>

</div>

<!-- ========== SYSTEM ========== -->
<h3 class="section-title">System</h3>
<div class="tools-grid">

    <!-- Clear Cache -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="refresh-cw"></i></div>
            <div>
                <h4 class="tool-title">Rensa cache</h4>
                <p class="tool-description">Töm systemcache</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/clear-cache.php" class="btn-admin btn-admin-secondary">Rensa</a>
        </div>
    </div>

    <!-- Backup -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="hard-drive-download"></i></div>
            <div>
                <h4 class="tool-title">Backup</h4>
                <p class="tool-description">Skapa backup</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/backup.php" class="btn-admin btn-admin-primary">Skapa</a>
        </div>
    </div>

    <!-- Run Migrations -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="database"></i></div>
            <div>
                <h4 class="tool-title">SQL-migrationer</h4>
                <p class="tool-description">Kör SQL-databasmigrationer</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/run-migrations.php" class="btn-admin btn-admin-secondary">Kör</a>
        </div>
    </div>

    <!-- PHP Migrations Browser -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon"><i data-lucide="file-code"></i></div>
            <div>
                <h4 class="tool-title">PHP-migrationer</h4>
                <p class="tool-description">Alla migrationer (SQL + PHP)</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/migrations/migration-browser.php" class="btn-admin btn-admin-secondary">Visa</a>
        </div>
    </div>

    <!-- Reset Data -->
    <div class="tool-card">
        <div class="tool-card-header">
            <div class="tool-icon danger"><i data-lucide="trash-2"></i></div>
            <div>
                <h4 class="tool-title">Återställ data</h4>
                <p class="tool-description">FARLIGT - Rensa tabeller</p>
            </div>
        </div>
        <div class="tool-actions">
            <a href="/admin/reset-data.php" class="btn-admin btn-admin-danger">Återställ</a>
        </div>
    </div>

</div>

<!-- Warning -->
<div class="alert alert-warning" style="margin-top: var(--space-xl);">
    <i data-lucide="alert-triangle"></i>
    <div>
        <strong>Varning:</strong> Vissa verktyg gör permanenta ändringar. Säkerhetskopiera alltid innan!
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
