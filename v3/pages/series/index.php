<?php
/**
 * TheHUB V3.5 - Series List
 * Shows all active competition series
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /v3/series');
    exit;
}

$pdo = hub_db();

// Check if series_events table exists
$useSeriesEvents = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'series_events'");
    $useSeriesEvents = $check->rowCount() > 0;
} catch (Exception $e) {
    $useSeriesEvents = false;
}

// Get all active series with counts
if ($useSeriesEvents) {
    $stmt = $pdo->query("
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
               COUNT(DISTINCT se.event_id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN series_events se2 ON r.event_id = se2.event_id
                WHERE se2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN series_events se ON s.id = se.series_id
        WHERE s.status = 'active'
        GROUP BY s.id
        ORDER BY s.year DESC, s.name ASC
    ");
} else {
    $stmt = $pdo->query("
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
               COUNT(DISTINCT e.id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN events e2 ON r.event_id = e2.id
                WHERE e2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN events e ON s.id = e.series_id
        WHERE s.status = 'active'
        GROUP BY s.id
        ORDER BY s.year DESC, s.name ASC
    ");
}
$seriesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <h1 class="page-title">Tävlingsserier</h1>
    <p class="page-subtitle">Alla GravitySeries och andra tävlingsserier</p>
</div>

<?php if (empty($seriesList)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">🏆</div>
        <h2>Inga serier ännu</h2>
        <p>Det finns inga aktiva tävlingsserier registrerade.</p>
    </div>
<?php else: ?>
    <div class="series-grid">
        <?php foreach ($seriesList as $s): ?>
        <a href="/v3/series/<?= $s['id'] ?>" class="series-card">
            <div class="series-card-logo">
                <?php if ($s['logo']): ?>
                    <img src="<?= htmlspecialchars($s['logo']) ?>" alt="<?= htmlspecialchars($s['name']) ?>">
                <?php else: ?>
                    <span class="series-card-logo-placeholder">🏆</span>
                <?php endif; ?>
            </div>

            <div class="series-card-content">
                <h2 class="series-card-title">
                    <?= htmlspecialchars($s['name']) ?>
                    <?php if ($s['year']): ?>
                        <span class="badge"><?= $s['year'] ?></span>
                    <?php endif; ?>
                </h2>
                <?php if ($s['description']): ?>
                    <p class="series-card-description"><?= htmlspecialchars($s['description']) ?></p>
                <?php endif; ?>

                <div class="series-card-meta">
                    <span><?= $s['event_count'] ?> tävlingar</span>
                    <?php if ($s['participant_count']): ?>
                        <span><?= $s['participant_count'] ?> deltagare</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="series-card-arrow">→</div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.series-grid {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.series-card {
    display: flex;
    align-items: center;
    gap: var(--space-lg);
    padding: var(--space-lg);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: inherit;
    transition: all var(--transition-fast);
}

.series-card:hover {
    border-color: var(--color-accent);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.series-card-logo {
    width: 80px;
    height: 80px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.series-card-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.series-card-logo-placeholder {
    font-size: 2rem;
}

.series-card-content {
    flex: 1;
    min-width: 0;
}

.series-card-title {
    font-size: var(--text-lg);
    font-weight: var(--weight-semibold);
    margin: 0 0 var(--space-xs);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.series-card-title .badge {
    background: var(--color-accent);
    color: white;
    padding: 2px 8px;
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    font-weight: var(--weight-medium);
}

.series-card-description {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin: 0 0 var(--space-sm);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.series-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.series-card-arrow {
    font-size: var(--text-xl);
    color: var(--color-text-muted);
    transition: transform var(--transition-fast);
}

.series-card:hover .series-card-arrow {
    transform: translateX(4px);
    color: var(--color-accent);
}

.empty-state {
    text-align: center;
    padding: var(--space-3xl);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
}

.empty-state-icon {
    font-size: 4rem;
    margin-bottom: var(--space-md);
}

.empty-state h2 {
    margin: 0 0 var(--space-sm);
}

.empty-state p {
    color: var(--color-text-secondary);
    margin: 0;
}

@media (max-width: 600px) {
    .series-card {
        flex-direction: column;
        text-align: center;
    }

    .series-card-meta {
        justify-content: center;
    }

    .series-card-arrow {
        display: none;
    }
}
</style>
