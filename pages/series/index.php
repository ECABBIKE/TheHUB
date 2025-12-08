<?php
/**
 * TheHUB V3.5 - Series List
 * Shows all competition series with year selector
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

// Get available years
$yearStmt = $pdo->query("
    SELECT DISTINCT year FROM series
    WHERE status IN ('active', 'completed') AND year IS NOT NULL
    ORDER BY year DESC
");
$availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

// Default year: use query param, or current year if available, else first in list
$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : null;

if (!$selectedYear) {
    // Default to current year if it exists, otherwise newest available
    if (in_array($currentYear, $availableYears)) {
        $selectedYear = $currentYear;
    } elseif (!empty($availableYears)) {
        $selectedYear = $availableYears[0];
    } else {
        $selectedYear = $currentYear;
    }
}

// Get series for selected year
if ($useSeriesEvents) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
               COUNT(DISTINCT se.event_id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN series_events se2 ON r.event_id = se2.event_id
                WHERE se2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN series_events se ON s.id = se.series_id
        WHERE s.status IN ('active', 'completed') AND s.year = ?
        GROUP BY s.id
        ORDER BY s.name ASC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
               COUNT(DISTINCT e.id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN events e2 ON r.event_id = e2.id
                WHERE e2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN events e ON s.id = e.series_id
        WHERE s.status IN ('active', 'completed') AND s.year = ?
        GROUP BY s.id
        ORDER BY s.name ASC
    ");
}
$stmt->execute([$selectedYear]);
$seriesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="award" class="page-icon"></i>
        Tävlingsserier
    </h1>
    <p class="page-subtitle">Alla GravitySeries och andra tävlingsserier</p>
</div>

<!-- Filters -->
<div class="filters-bar">
    <div class="filter-group">
        <label class="filter-label">År</label>
        <select class="filter-select" onchange="window.location=this.value">
            <?php foreach ($availableYears as $year): ?>
            <option value="/series?year=<?= $year ?>" <?= $year == $selectedYear ? 'selected' : '' ?>>
                <?= $year ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if (empty($seriesList)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">🏆</div>
        <h2>Inga serier för <?= $selectedYear ?></h2>
        <p>Det finns inga tävlingsserier registrerade för detta år.</p>
    </div>
<?php else: ?>
    <div class="series-logo-grid">
        <?php foreach ($seriesList as $s): ?>
        <a href="/series/<?= $s['id'] ?>" class="series-logo-card">
            <div class="series-logo-wrapper">
                <?php if ($s['logo']): ?>
                    <img src="<?= htmlspecialchars($s['logo']) ?>" alt="<?= htmlspecialchars($s['name']) ?>" class="series-logo-img">
                <?php else: ?>
                    <div class="series-logo-placeholder">🏆</div>
                <?php endif; ?>
                <span class="series-year-badge"><?= $s['year'] ?></span>
            </div>
            <div class="series-logo-info">
                <h3 class="series-logo-name"><?= htmlspecialchars($s['name']) ?></h3>
                <div class="series-logo-meta">
                    <span><?= $s['event_count'] ?> tävlingar</span>
                    <?php if ($s['participant_count']): ?>
                        <span class="meta-sep">•</span>
                        <span><?= $s['participant_count'] ?> deltagare</span>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
/* Filters Bar */
.filters-bar {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2xs);
    flex: 1;
    min-width: 140px;
    max-width: 200px;
}
.filter-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    font-weight: var(--weight-medium);
}
.filter-select {
    padding: var(--space-sm) var(--space-md);
    padding-right: var(--space-xl);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    transition: border-color var(--transition-fast);
}
.filter-select:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(59, 158, 255, 0.1);
}

.page-header {
    margin-bottom: var(--space-lg);
}
.page-title {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    margin: 0 0 var(--space-xs) 0;
    color: var(--color-text-primary);
}
.page-icon {
    width: 32px;
    height: 32px;
    color: var(--color-accent);
}
.page-subtitle {
    font-size: var(--text-md);
    color: var(--color-text-secondary);
    margin: 0;
}

/* Series Logo Grid */
.series-logo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: var(--space-lg);
    max-width: 900px;
}

.series-logo-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-decoration: none;
    color: inherit;
    transition: transform var(--transition-fast);
}

.series-logo-card:hover {
    transform: translateY(-4px);
}

.series-logo-card:hover .series-logo-wrapper {
    box-shadow: var(--shadow-lg);
    border-color: var(--color-accent);
}

.series-logo-wrapper {
    position: relative;
    width: 120px;
    height: 120px;
    background: var(--color-bg-card);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    transition: all var(--transition-fast);
}

.series-logo-img {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
}

.series-logo-placeholder {
    font-size: 3rem;
}

/* Year badge - like Gravity-ID badge */
.series-year-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    background: linear-gradient(135deg, var(--color-accent) 0%, #00A3E0 100%);
    color: white;
    font-size: var(--text-xs);
    font-weight: var(--weight-bold);
    padding: 4px 10px;
    border-radius: var(--radius-full);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    border: 2px solid var(--color-bg-card);
}

.series-logo-info {
    text-align: center;
    margin-top: var(--space-sm);
    max-width: 100%;
}

.series-logo-name {
    font-size: var(--text-sm);
    font-weight: var(--weight-semibold);
    margin: 0 0 var(--space-2xs);
    line-height: 1.3;
    color: var(--color-text-primary);
}

.series-logo-meta {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    display: flex;
    justify-content: center;
    gap: var(--space-2xs);
    flex-wrap: wrap;
}

.meta-sep {
    color: var(--color-text-muted);
}

/* Empty State */
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

/* Mobile */
@media (max-width: 599px) {
    .filters-bar {
        flex-direction: column;
        padding: var(--space-sm);
        gap: var(--space-sm);
    }
    .filter-group {
        width: 100%;
        max-width: none;
        min-width: 0;
    }
    .filter-select {
        width: 100%;
    }
    .series-logo-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--space-md);
    }

    .series-logo-wrapper {
        width: 100px;
        height: 100px;
    }

    .series-logo-name {
        font-size: var(--text-xs);
    }
}

/* Tablet */
@media (min-width: 600px) and (max-width: 900px) {
    .series-logo-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
</style>
