<?php
/**
 * TheHUB V3.5 - Series List
 * Shows all active competition series
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /series');
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
        <a href="/series/<?= $s['id'] ?>" class="series-card">
            <div class="series-card-header">
                <div class="series-card-logo">
                    <?php if ($s['logo']): ?>
                        <img src="<?= htmlspecialchars($s['logo']) ?>" alt="<?= htmlspecialchars($s['name']) ?>">
                    <?php else: ?>
                        <span class="series-card-logo-placeholder">🏆</span>
                    <?php endif; ?>
                </div>
                <?php if ($s['year']): ?>
                    <span class="series-badge"><?= $s['year'] ?></span>
                <?php endif; ?>
            </div>

            <h2 class="series-card-title"><?= htmlspecialchars($s['name']) ?></h2>

            <?php if ($s['description']): ?>
                <p class="series-card-description"><?= htmlspecialchars($s['description']) ?></p>
            <?php endif; ?>

            <div class="series-card-stats">
                <div class="stat">
                    <span class="stat-value"><?= $s['event_count'] ?></span>
                    <span class="stat-label">tävlingar</span>
                </div>
                <?php if ($s['participant_count']): ?>
                <div class="stat">
                    <span class="stat-value"><?= $s['participant_count'] ?></span>
                    <span class="stat-label">deltagare</span>
                </div>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.series-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-md);
}

.series-card {
    display: flex;
    flex-direction: column;
    padding: var(--space-md);
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

.series-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: var(--space-sm);
}

.series-card-logo {
    width: 48px;
    height: 48px;
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
    font-size: 1.5rem;
}

.series-badge {
    background: var(--color-accent);
    color: white;
    padding: 2px 10px;
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
}

.series-card-title {
    font-size: var(--text-md);
    font-weight: var(--weight-semibold);
    margin: 0 0 var(--space-xs);
    line-height: 1.3;
}

.series-card-description {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin: 0 0 var(--space-sm);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex: 1;
}

.series-card-stats {
    display: flex;
    gap: var(--space-lg);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border-light);
    margin-top: auto;
}

.stat {
    display: flex;
    flex-direction: column;
}

.stat-value {
    font-size: var(--text-lg);
    font-weight: var(--weight-bold);
    color: var(--color-accent);
    line-height: 1;
}

.stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
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

/* Tablet: 2 kolumner */
@media (min-width: 600px) and (max-width: 900px) {
    .series-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Desktop: max 3 kolumner, centrerade */
@media (min-width: 901px) {
    .series-grid {
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        max-width: 1000px;
    }
}

/* Mobil: kompaktare kort */
@media (max-width: 599px) {
    .series-grid {
        grid-template-columns: 1fr;
        gap: var(--space-sm);
    }

    .series-card {
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
        padding: var(--space-sm) var(--space-md);
        gap: var(--space-sm);
    }

    .series-card-header {
        margin-bottom: 0;
        flex-shrink: 0;
    }

    .series-card-logo {
        width: 40px;
        height: 40px;
    }

    .series-badge {
        position: absolute;
        top: var(--space-sm);
        right: var(--space-sm);
    }

    .series-card {
        position: relative;
    }

    .series-card-title {
        flex: 1;
        min-width: 0;
        font-size: var(--text-sm);
        margin: 0;
    }

    .series-card-description {
        display: none;
    }

    .series-card-stats {
        width: 100%;
        padding-top: var(--space-xs);
        gap: var(--space-md);
    }

    .stat {
        flex-direction: row;
        align-items: baseline;
        gap: 4px;
    }

    .stat-value {
        font-size: var(--text-sm);
    }

    .stat-label {
        font-size: var(--text-xs);
    }
}
</style>
