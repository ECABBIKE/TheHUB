<?php
/**
 * TheHUB V3.5 - My Results
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();

// Get results
$stmt = $pdo->prepare("
    SELECT res.*, e.name as event_name, e.date as event_date,
           cls.display_name as class_name, s.name as series_name
    FROM results res
    JOIN events e ON res.event_id = e.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    LEFT JOIN series s ON e.series_id = s.id
    WHERE res.cyclist_id = ?
    ORDER BY e.date DESC
");
$stmt->execute([$currentUser['id']]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$totalEvents = count($results);
$podiums = count(array_filter($results, fn($r) => $r['position'] <= 3));
$wins = count(array_filter($results, fn($r) => $r['position'] === 1));
$totalPoints = array_sum(array_column($results, 'points'));
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Mina resultat</span>
    </nav>
    <h1 class="page-title">
        <span class="page-icon">🏁</span>
        Mina resultat
    </h1>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= $totalEvents ?></span>
        <span class="stat-label">Tävlingar</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $wins ?></span>
        <span class="stat-label">Segrar 🥇</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $podiums ?></span>
        <span class="stat-label">Pallplatser</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($totalPoints) ?></span>
        <span class="stat-label">Poäng</span>
    </div>
</div>

<!-- Results List -->
<?php if (empty($results)): ?>
    <div class="empty-state">
        <div class="empty-icon">🏁</div>
        <h3>Inga resultat ännu</h3>
        <p>Dina tävlingsresultat kommer visas här efter att du tävlat.</p>
    </div>
<?php else: ?>
    <div class="results-list">
        <?php
        $currentYear = null;
        foreach ($results as $result):
            $year = date('Y', strtotime($result['event_date']));
            if ($year !== $currentYear):
                $currentYear = $year;
        ?>
            <h2 class="year-header"><?= $year ?></h2>
        <?php endif; ?>

            <a href="/results/<?= $result['event_id'] ?>" class="result-card">
                <div class="result-position">
                    <?php if ($result['position'] <= 3): ?>
                        <?= ['🥇', '🥈', '🥉'][$result['position'] - 1] ?>
                    <?php else: ?>
                        <span class="position-number">#<?= $result['position'] ?></span>
                    <?php endif; ?>
                </div>
                <div class="result-info">
                    <span class="result-event"><?= htmlspecialchars($result['event_name']) ?></span>
                    <span class="result-meta">
                        <?= date('j M', strtotime($result['event_date'])) ?>
                        <?php if ($result['class_name']): ?>
                            • <?= htmlspecialchars($result['class_name']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="result-points">
                    <?php if ($result['points']): ?>
                        +<?= $result['points'] ?>p
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.stat-card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    text-align: center;
}
.stat-value {
    display: block;
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    color: var(--color-accent);
}
.stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.year-header {
    font-size: var(--text-lg);
    color: var(--color-text-secondary);
    margin: var(--space-lg) 0 var(--space-md);
    padding-bottom: var(--space-xs);
    border-bottom: 1px solid var(--color-border);
}
.year-header:first-of-type {
    margin-top: 0;
}

.results-list {
    margin-bottom: var(--space-xl);
}
.result-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-xs);
    text-decoration: none;
    color: inherit;
    transition: all var(--transition-fast);
}
.result-card:hover {
    transform: translateX(4px);
}
.result-position {
    min-width: 48px;
    text-align: center;
    font-size: var(--text-xl);
}
.position-number {
    font-weight: var(--weight-bold);
    color: var(--color-text-secondary);
}
.result-info {
    flex: 1;
}
.result-event {
    display: block;
    font-weight: var(--weight-medium);
}
.result-meta {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.result-points {
    font-weight: var(--weight-semibold);
    color: var(--color-accent);
}

.empty-state {
    text-align: center;
    padding: var(--space-2xl);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
}
.empty-icon {
    font-size: 3rem;
    margin-bottom: var(--space-md);
}

@media (max-width: 600px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
