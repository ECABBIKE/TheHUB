<?php
/**
 * TheHUB Events/Calendar Page Module
 * Can be loaded via SPA (app.php) or directly
 */

$isSpaMode = defined('HUB_ROOT') && isset($pageInfo);

if (!$isSpaMode) {
    require_once __DIR__ . '/../config.php';
    $pageTitle = 'Kalender';
    $pageType = 'public';
    include __DIR__ . '/../includes/layout-header.php';
}

$db = getDB();

// Get filter parameters
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$filterStatus = $_GET['status'] ?? 'upcoming';

// Build query
$where = [];
$params = [];

if ($filterYear) {
    $where[] = "YEAR(e.date) = ?";
    $params[] = $filterYear;
}

if ($filterStatus === 'upcoming') {
    $where[] = "e.date >= CURDATE()";
} elseif ($filterStatus === 'past') {
    $where[] = "e.date < CURDATE()";
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$orderDir = $filterStatus === 'past' ? 'DESC' : 'ASC';

$sql = "SELECT e.*, s.name as series_name, s.logo as series_logo
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        {$whereClause}
        ORDER BY e.date {$orderDir}";

$events = $db->getAll($sql, $params);

// Get available years
$years = $db->getAll("SELECT DISTINCT YEAR(date) as year FROM events WHERE date IS NOT NULL ORDER BY year DESC");
?>

<div class="container">
    <div class="mb-lg">
        <h1 class="text-primary mb-sm">
            <i data-lucide="calendar"></i>
            Kalender
        </h1>
        <p class="text-secondary">
            <?= count($events) ?> <?= $filterStatus === 'upcoming' ? 'kommande' : ($filterStatus === 'past' ? 'avslutade' : '') ?> tavlingar
        </p>
    </div>

    <!-- Filters -->
    <div class="card mb-lg">
        <div class="card-body">
            <form method="GET" action="<?= $isSpaMode ? '/calendar' : '/events.php' ?>" class="flex flex-wrap gap-md items-end">
                <div>
                    <label class="label">Ar</label>
                    <select name="year" class="input" onchange="this.form.submit()">
                        <?php foreach ($years as $y): ?>
                        <option value="<?= $y['year'] ?>" <?= $filterYear == $y['year'] ? 'selected' : '' ?>>
                            <?= $y['year'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label">Status</label>
                    <select name="status" class="input" onchange="this.form.submit()">
                        <option value="upcoming" <?= $filterStatus === 'upcoming' ? 'selected' : '' ?>>Kommande</option>
                        <option value="past" <?= $filterStatus === 'past' ? 'selected' : '' ?>>Avslutade</option>
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Alla</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($events)): ?>
    <div class="card text-center p-xl">
        <i data-lucide="calendar-x" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <h3>Inga tavlingar hittades</h3>
        <p class="text-secondary">Prova att andra filtren ovan.</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
        <?php foreach ($events as $event): ?>
        <a href="<?= $isSpaMode ? '/event/' . $event['id'] : '/event.php?id=' . $event['id'] ?>" class="gs-event-card-link">
            <div class="card card-hover gs-event-card">
                <div class="gs-event-logo">
                    <?php if ($event['series_logo']): ?>
                    <img src="<?= h($event['series_logo']) ?>" alt="<?= h($event['series_name']) ?>">
                    <?php else: ?>
                    <i data-lucide="calendar" style="width: 40px; height: 40px; color: var(--color-text-muted);"></i>
                    <?php endif; ?>
                </div>
                <div class="gs-event-info">
                    <div class="gs-event-date">
                        <i data-lucide="calendar" class="icon-sm"></i>
                        <?= date('d M Y', strtotime($event['date'])) ?>
                    </div>
                    <div class="gs-event-title"><?= h($event['name']) ?></div>
                    <div class="gs-event-meta">
                        <?php if ($event['series_name']): ?>
                        <span><i data-lucide="trophy" class="icon-xs"></i> <?= h($event['series_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($event['location']): ?>
                        <span><i data-lucide="map-pin" class="icon-xs"></i> <?= h($event['location']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.gs-event-card-link { text-decoration: none; color: inherit; display: block; }
.gs-event-card { display: grid; grid-template-columns: 100px 1fr; gap: var(--space-md); padding: var(--space-md); }
.gs-event-logo { display: flex; align-items: center; justify-content: center; background: var(--color-bg-sunken); border-radius: var(--radius-md); padding: var(--space-sm); }
.gs-event-logo img { max-width: 100%; max-height: 60px; object-fit: contain; }
.gs-event-info { display: flex; flex-direction: column; gap: var(--space-xs); }
.gs-event-date { display: inline-flex; align-items: center; gap: var(--space-xs); padding: var(--space-2xs) var(--space-sm); background: var(--color-accent); color: var(--color-text-inverse); border-radius: var(--radius-sm); font-size: var(--text-sm); font-weight: var(--weight-semibold); width: fit-content; }
.gs-event-title { font-size: var(--text-lg); font-weight: var(--weight-bold); color: var(--color-text-primary); }
.gs-event-meta { display: flex; flex-wrap: wrap; gap: var(--space-sm); font-size: var(--text-sm); color: var(--color-text-secondary); }
.gs-event-meta span { display: flex; align-items: center; gap: var(--space-2xs); }
.icon-xs { width: 12px; height: 12px; }
@media (max-width: 640px) {
    .gs-event-card { grid-template-columns: 70px 1fr; gap: var(--space-sm); padding: var(--space-sm); }
    .gs-event-title { font-size: var(--text-md); }
}
</style>

<?php
if (!$isSpaMode) {
    include __DIR__ . '/../includes/layout-footer.php';
}
?>
