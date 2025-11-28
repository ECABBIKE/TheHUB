<?php
/**
 * TheHUB V3.5 - Databas
 * Sök åkare och klubbar med live-sök
 */

$pdo = hub_db();
$tab = $_GET['tab'] ?? 'riders';

// Get counts
$riderCount = $pdo->query("SELECT COUNT(*) FROM riders")->fetchColumn();
$clubCount = $pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn();
?>

<div class="page-header">
    <h1 class="page-title">
        <span class="page-icon">🔍</span>
        Databas
    </h1>
    <p class="page-subtitle">Sök bland <?= number_format($riderCount) ?> åkare och <?= number_format($clubCount) ?> klubbar</p>
</div>

<!-- Tabs -->
<div class="tabs" role="tablist">
    <a href="/v3/database?tab=riders"
       class="tab<?= $tab === 'riders' ? ' active' : '' ?>"
       role="tab"
       aria-selected="<?= $tab === 'riders' ? 'true' : 'false' ?>">
        👥 Åkare
    </a>
    <a href="/v3/database?tab=clubs"
       class="tab<?= $tab === 'clubs' ? ' active' : '' ?>"
       role="tab"
       aria-selected="<?= $tab === 'clubs' ? 'true' : 'false' ?>">
        🛡️ Klubbar
    </a>
</div>

<!-- Search -->
<div class="search-section">
    <div class="live-search" data-search-type="<?= $tab ?>">
        <div class="search-input-wrapper">
            <span class="search-icon">🔍</span>
            <input type="text"
                   class="live-search-input"
                   placeholder="Sök <?= $tab === 'riders' ? 'åkare' : 'klubbar' ?>..."
                   autocomplete="off"
                   autofocus>
            <button type="button" class="search-clear hidden" aria-label="Rensa sökning">✕</button>
        </div>
        <div class="live-search-results hidden"></div>
    </div>
</div>

<!-- Quick Browse -->
<div class="browse-section">
    <?php if ($tab === 'riders'): ?>
        <h2>Bläddra A-Ö</h2>
        <div class="alphabet-nav">
            <?php foreach (range('A', 'Ö') as $letter): ?>
                <a href="/v3/riders?letter=<?= $letter ?>" class="letter-link"><?= $letter ?></a>
            <?php endforeach; ?>
        </div>

        <h2>Senast aktiva</h2>
        <?php
        $recentStmt = $pdo->query("
            SELECT r.id, r.first_name, r.last_name, c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            ORDER BY r.updated_at DESC
            LIMIT 20
        ");
        $recentRiders = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="quick-list">
            <?php foreach ($recentRiders as $rider): ?>
                <a href="/v3/database/rider/<?= $rider['id'] ?>" class="quick-item">
                    <span class="quick-item-avatar"><?= strtoupper(substr($rider['first_name'], 0, 1)) ?></span>
                    <div class="quick-item-info">
                        <span class="quick-item-name"><?= htmlspecialchars($rider['first_name'] . ' ' . $rider['last_name']) ?></span>
                        <?php if ($rider['club_name']): ?>
                            <span class="quick-item-meta"><?= htmlspecialchars($rider['club_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <h2>Alla klubbar</h2>
        <?php
        $clubsStmt = $pdo->query("
            SELECT c.id, c.name, COUNT(r.id) as member_count
            FROM clubs c
            LEFT JOIN riders r ON c.id = r.club_id
            GROUP BY c.id
            ORDER BY c.name
        ");
        $clubs = $clubsStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="club-grid">
            <?php foreach ($clubs as $club): ?>
                <a href="/v3/database/club/<?= $club['id'] ?>" class="club-card">
                    <span class="club-avatar"><?= strtoupper(substr($club['name'], 0, 2)) ?></span>
                    <div class="club-info">
                        <span class="club-name"><?= htmlspecialchars($club['name']) ?></span>
                        <span class="club-members"><?= $club['member_count'] ?> medlemmar</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.tabs {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}
.tab {
    padding: var(--space-md) var(--space-lg);
    text-decoration: none;
    color: var(--color-text-secondary);
    font-weight: var(--weight-medium);
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all var(--transition-fast);
}
.tab:hover {
    color: var(--color-text-primary);
}
.tab.active {
    color: var(--color-accent);
    border-bottom-color: var(--color-accent);
}

.search-section {
    margin-bottom: var(--space-xl);
}
.live-search {
    position: relative;
    max-width: 500px;
}
.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.search-icon {
    position: absolute;
    left: var(--space-md);
    pointer-events: none;
}
.live-search-input {
    width: 100%;
    padding: var(--space-md) var(--space-md) var(--space-md) calc(var(--space-md) * 2 + 1.5em);
    font-size: var(--text-lg);
    background: var(--color-bg-card);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-lg);
    color: var(--color-text-primary);
    transition: border-color var(--transition-fast);
}
.live-search-input:focus {
    outline: none;
    border-color: var(--color-accent);
}
.search-clear {
    position: absolute;
    right: var(--space-md);
    background: none;
    border: none;
    color: var(--color-text-secondary);
    cursor: pointer;
    padding: var(--space-xs);
}
.live-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    margin-top: var(--space-xs);
    max-height: 400px;
    overflow-y: auto;
    z-index: 100;
    box-shadow: var(--shadow-lg);
}
.live-search-result {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    cursor: pointer;
    transition: background var(--transition-fast);
}
.live-search-result:hover {
    background: var(--color-bg-hover);
}
.live-search-result-avatar {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-full);
    font-weight: var(--weight-bold);
}
.live-search-result-name {
    font-weight: var(--weight-medium);
}
.live-search-result-meta {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.live-search-empty {
    padding: var(--space-lg);
    text-align: center;
    color: var(--color-text-secondary);
}

.alphabet-nav {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
    margin-bottom: var(--space-xl);
}
.letter-link {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--color-text-primary);
    font-weight: var(--weight-medium);
    transition: all var(--transition-fast);
}
.letter-link:hover {
    background: var(--color-accent);
    color: white;
    border-color: var(--color-accent);
}

.browse-section h2 {
    font-size: var(--text-lg);
    margin-bottom: var(--space-md);
    color: var(--color-text-secondary);
}

.quick-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: var(--space-sm);
}
.quick-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm);
    background: var(--color-bg-card);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: all var(--transition-fast);
}
.quick-item:hover {
    transform: translateX(4px);
    background: var(--color-bg-hover);
}
.quick-item-avatar {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-full);
    font-weight: var(--weight-bold);
}
.quick-item-name {
    font-weight: var(--weight-medium);
}
.quick-item-meta {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.club-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: var(--space-md);
}
.club-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: inherit;
    transition: all var(--transition-fast);
}
.club-card:hover {
    border-color: var(--color-accent);
    transform: translateY(-2px);
}
.club-avatar {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-surface);
    border-radius: var(--radius-md);
    font-weight: var(--weight-bold);
    font-size: var(--text-sm);
}
.club-name {
    font-weight: var(--weight-medium);
}
.club-members {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
</style>

<script src="<?= hub_asset('js/search.js') ?>"></script>
