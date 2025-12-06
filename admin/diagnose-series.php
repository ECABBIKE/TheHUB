<?php
/**
 * Diagnose Series Championship Issues
 * Helps identify why series champions aren't being calculated
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$pdo = $db->getPdo();

$currentYear = (int)date('Y');

$page_title = 'Diagnostik: Seriemästare';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Diagnos Seriemästare']
];
include __DIR__ . '/components/unified-layout.php';
?>

<div class="card mb-lg">
    <div class="card-header">
        <h2>🔍 Serie-status Översikt</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">
            <strong>Krav för seriemästare:</strong> Serien måste ha <code>status='completed'</code> ELLER ha events från tidigare år (före <?= $currentYear ?>).
        </p>

        <?php
        // Get all series with their status and year info
        $series = $db->getAll("
            SELECT
                s.id,
                s.name,
                s.year as series_year,
                s.status,
                MIN(YEAR(e.date)) as first_event_year,
                MAX(YEAR(e.date)) as last_event_year,
                COUNT(DISTINCT e.id) as event_count,
                COUNT(DISTINCT r.cyclist_id) as rider_count
            FROM series s
            LEFT JOIN events e ON e.series_id = s.id
            LEFT JOIN results r ON r.event_id = e.id AND r.status = 'finished'
            GROUP BY s.id
            ORDER BY s.year DESC, s.name
        ");
        ?>

        <table class="table table--striped">
            <thead>
                <tr>
                    <th>Serie</th>
                    <th>År (serie)</th>
                    <th>Status</th>
                    <th>Events år</th>
                    <th>Events</th>
                    <th>Åkare</th>
                    <th>Kvalificerar?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($series as $s):
                    $isCompleted = $s['status'] === 'completed';
                    $hasPastEvents = $s['last_event_year'] && $s['last_event_year'] < $currentYear;
                    $qualifies = $isCompleted || $hasPastEvents;
                ?>
                <tr class="<?= $qualifies ? 'bg-success-light' : '' ?>">
                    <td><strong><?= h($s['name']) ?></strong></td>
                    <td><?= $s['series_year'] ?? '<span class="text-warning">NULL</span>' ?></td>
                    <td>
                        <?php if ($isCompleted): ?>
                            <span class="admin-badge admin-badge-success">completed ✓</span>
                        <?php else: ?>
                            <span class="admin-badge admin-badge-secondary"><?= h($s['status'] ?? 'N/A') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['first_event_year']): ?>
                            <?= $s['first_event_year'] ?><?= $s['first_event_year'] != $s['last_event_year'] ? ' - ' . $s['last_event_year'] : '' ?>
                            <?php if ($hasPastEvents): ?>
                                <span class="text-success">✓ historiskt</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-secondary">Inga events</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $s['event_count'] ?></td>
                    <td><?= $s['rider_count'] ?></td>
                    <td>
                        <?php if ($qualifies): ?>
                            <span class="text-success"><strong>JA</strong></span>
                        <?php else: ?>
                            <span class="text-error">NEJ</span>
                            <br><small class="text-secondary">Sätt status='completed' eller vänta tills nästa år</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-lg">
    <div class="card-header">
        <h2>🏆 Potentiella Seriemästare</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">
            Visar ledare per serie/klass som <strong>skulle bli seriemästare</strong> om serien markerades som 'completed'.
        </p>

        <?php
        // Find potential series champions (current leaders in each series/class)
        $potentialChampions = $db->getAll("
            SELECT
                s.id as series_id,
                s.name as series_name,
                COALESCE(s.year, YEAR(MAX(e.date))) as effective_year,
                s.status,
                r.class_id,
                c.display_name as class_name,
                r.cyclist_id,
                CONCAT(rd.first_name, ' ', rd.last_name) as rider_name,
                SUM(r.points) as total_points
            FROM results r
            JOIN events e ON r.event_id = e.id
            JOIN series s ON e.series_id = s.id
            JOIN classes c ON r.class_id = c.id
            JOIN riders rd ON r.cyclist_id = rd.id
            WHERE r.status = 'finished'
            GROUP BY s.id, r.class_id, r.cyclist_id
            HAVING total_points = (
                SELECT MAX(sub_total) FROM (
                    SELECT SUM(r2.points) as sub_total
                    FROM results r2
                    JOIN events e2 ON r2.event_id = e2.id
                    WHERE e2.series_id = s.id
                      AND r2.class_id = r.class_id
                      AND r2.status = 'finished'
                    GROUP BY r2.cyclist_id
                ) as subq
            )
            ORDER BY s.name, c.display_name
            LIMIT 50
        ");

        if (empty($potentialChampions)):
        ?>
            <div class="alert alert-warning">Inga resultat hittades.</div>
        <?php else: ?>
        <table class="table table--striped">
            <thead>
                <tr>
                    <th>Serie</th>
                    <th>År</th>
                    <th>Status</th>
                    <th>Klass</th>
                    <th>Ledare</th>
                    <th>Poäng</th>
                    <th>Skulle bli mästare?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($potentialChampions as $pc):
                    $isCompleted = $pc['status'] === 'completed';
                    $isPast = $pc['effective_year'] < $currentYear;
                    $wouldQualify = $isCompleted || $isPast;
                ?>
                <tr class="<?= $wouldQualify ? 'bg-success-light' : '' ?>">
                    <td><?= h($pc['series_name']) ?></td>
                    <td><?= $pc['effective_year'] ?></td>
                    <td>
                        <span class="admin-badge <?= $isCompleted ? 'admin-badge-success' : 'admin-badge-secondary' ?>">
                            <?= h($pc['status']) ?>
                        </span>
                    </td>
                    <td><?= h($pc['class_name']) ?></td>
                    <td>
                        <a href="/admin/riders/edit/<?= $pc['cyclist_id'] ?>">
                            <?= h($pc['rider_name']) ?>
                        </a>
                    </td>
                    <td><strong><?= $pc['total_points'] ?></strong></td>
                    <td>
                        <?php if ($wouldQualify): ?>
                            <span class="text-success">✓ JA - räknas som mästare</span>
                        <?php else: ?>
                            <span class="text-warning">⏳ Väntar på 'completed' eller nästa år</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-lg">
    <div class="card-header">
        <h2>✅ Existerande Seriemästar-Achievements</h2>
    </div>
    <div class="card-body">
        <?php
        $existingChampions = $db->getAll("
            SELECT
                ra.rider_id,
                CONCAT(r.first_name, ' ', r.last_name) as rider_name,
                ra.achievement_value as series_name,
                ra.season_year,
                ra.earned_at
            FROM rider_achievements ra
            JOIN riders r ON ra.rider_id = r.id
            WHERE ra.achievement_type = 'series_champion'
            ORDER BY ra.season_year DESC, ra.earned_at DESC
            LIMIT 50
        ");

        if (empty($existingChampions)):
        ?>
            <div class="alert alert-warning">
                <strong>Inga seriemästare registrerade!</strong><br>
                Detta beror troligen på att ingen serie har <code>status='completed'</code>.
            </div>

            <h4 class="mt-lg mb-md">Åtgärd:</h4>
            <ol>
                <li>Gå till <a href="/admin/series">/admin/series</a></li>
                <li>Klicka på en avslutad serie för att redigera</li>
                <li>Ändra <strong>Status</strong> till <code>Avslutad</code> (completed)</li>
                <li>Spara</li>
                <li>Gå till <a href="/admin/rebuild-stats">/admin/rebuild-stats</a></li>
                <li>Kör <strong>Rebuild alla åkare</strong></li>
            </ol>
        <?php else: ?>
        <table class="table table--striped">
            <thead>
                <tr>
                    <th>Åkare</th>
                    <th>Serie</th>
                    <th>År</th>
                    <th>Registrerad</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($existingChampions as $ec): ?>
                <tr>
                    <td>
                        <a href="/admin/riders/edit/<?= $ec['rider_id'] ?>">
                            <?= h($ec['rider_name']) ?>
                        </a>
                    </td>
                    <td><?= h($ec['series_name']) ?></td>
                    <td><?= $ec['season_year'] ?></td>
                    <td><?= date('Y-m-d H:i', strtotime($ec['earned_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>📋 Snabbåtgärder</h2>
    </div>
    <div class="card-body">
        <div class="flex gap-md" style="flex-wrap: wrap;">
            <a href="/admin/series" class="btn btn--primary">
                → Hantera Serier
            </a>
            <a href="/admin/rebuild-stats" class="btn btn--secondary">
                → Rebuild Stats
            </a>
        </div>
    </div>
</div>

<style>
.bg-success-light {
    background: rgba(97, 206, 112, 0.15);
}
.text-success { color: var(--color-success); }
.text-error { color: var(--color-danger); }
.text-warning { color: var(--color-warning); }
.text-secondary { color: var(--color-text-secondary); }
code {
    background: var(--color-bg-tertiary);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.85em;
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
