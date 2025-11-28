<?php
/**
 * Admin - Events List
 */
$pdo = hub_db();

// Handle search/filter
$search = trim($_GET['search'] ?? '');
$year = $_GET['year'] ?? date('Y');
$status = $_GET['status'] ?? '';

// Build query
$where = [];
$params = [];

if ($search) {
    $where[] = "(e.name LIKE :search OR e.location LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($year && $year !== 'all') {
    $where[] = "YEAR(e.date) = :year";
    $params[':year'] = $year;
}

if ($status === 'upcoming') {
    $where[] = "e.date >= CURDATE()";
} elseif ($status === 'past') {
    $where[] = "e.date < CURDATE()";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get events with registration counts
$sql = "
    SELECT e.*,
           COUNT(DISTINCT er.id) as registration_count,
           COUNT(DISTINCT r.id) as result_count
    FROM events e
    LEFT JOIN event_registrations er ON e.id = er.event_id
    LEFT JOIN results r ON e.id = r.event_id
    $whereClause
    GROUP BY e.id
    ORDER BY e.date DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $events = [];
}

// Get available years for filter
$years = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT YEAR(date) as year FROM events ORDER BY year DESC");
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
?>

<div class="admin-events">

    <!-- Toolbar -->
    <div class="admin-toolbar">
        <form method="GET" class="admin-search-form">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Sok tavling..." class="admin-search-input">

            <select name="year" class="admin-select">
                <option value="all" <?= $year === 'all' ? 'selected' : '' ?>>Alla ar</option>
                <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="admin-select">
                <option value="">Alla</option>
                <option value="upcoming" <?= $status === 'upcoming' ? 'selected' : '' ?>>Kommande</option>
                <option value="past" <?= $status === 'past' ? 'selected' : '' ?>>Avslutade</option>
            </select>

            <button type="submit" class="btn">Filtrera</button>
        </form>

        <a href="<?= admin_url('events/create') ?>" class="btn btn-primary">
            + Ny tavling
        </a>
    </div>

    <!-- Events Table -->
    <div class="admin-card">
        <?php if (empty($events)): ?>
            <p class="text-muted">Inga tavlingar hittades.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Namn</th>
                            <th>Plats</th>
                            <th>Anmalningar</th>
                            <th>Resultat</th>
                            <th>Atgarder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                        <tr>
                            <td>
                                <span class="admin-date <?= strtotime($event['date']) >= strtotime('today') ? 'is-upcoming' : '' ?>">
                                    <?= date('Y-m-d', strtotime($event['date'])) ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= admin_url('events/' . $event['id']) ?>" class="admin-link">
                                    <?= htmlspecialchars($event['name']) ?>
                                </a>
                            </td>
                            <td class="text-secondary"><?= htmlspecialchars($event['location'] ?? '-') ?></td>
                            <td>
                                <span class="badge"><?= $event['registration_count'] ?></span>
                            </td>
                            <td>
                                <?php if ($event['result_count'] > 0): ?>
                                    <span class="badge badge-success"><?= $event['result_count'] ?> starter</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="admin-actions">
                                    <a href="<?= admin_url('events/' . $event['id']) ?>" class="btn btn-ghost btn-sm" title="Redigera">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                        </svg>
                                    </a>
                                    <a href="<?= HUB_V3_URL ?>/calendar/<?= $event['id'] ?>" class="btn btn-ghost btn-sm" title="Visa pa sidan" target="_blank">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                            <polyline points="15 3 21 3 21 9"/>
                                            <line x1="10" y1="14" x2="21" y2="3"/>
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<style>
.admin-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
}

.admin-search-form {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
    flex: 1;
}

.admin-search-input {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: inherit;
    min-width: 200px;
}

.admin-select {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: inherit;
}

.admin-date.is-upcoming {
    color: var(--color-accent);
    font-weight: var(--weight-medium);
}

.admin-link {
    color: inherit;
    text-decoration: none;
    font-weight: var(--weight-medium);
}

.admin-link:hover {
    color: var(--color-accent);
}

.admin-actions {
    display: flex;
    gap: var(--space-xs);
}

.badge-success {
    background: var(--color-success-bg, #dcfce7);
    color: var(--color-success, #16a34a);
}

@media (max-width: 768px) {
    .admin-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .admin-search-form {
        flex-direction: column;
    }

    .admin-search-input,
    .admin-select {
        width: 100%;
    }
}
</style>
