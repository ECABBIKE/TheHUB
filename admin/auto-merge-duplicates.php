<?php
/**
 * Auto-merge duplicates with same UCI-ID
 * Keeps the rider with most results, moves results from duplicate
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();

// Only super_admin can run this
if (!hasRole('super_admin')) {
    die('Endast super_admin kan köra detta script');
}

$dryRun = !isset($_GET['execute']);
$results = [];
$mergeLog = [];

// Find all UCI-ID duplicates
$duplicates = $db->getAll("
    SELECT
        REPLACE(REPLACE(license_number, ' ', ''), '-', '') as normalized_uci,
        GROUP_CONCAT(id ORDER BY id) as rider_ids,
        COUNT(*) as count
    FROM riders
    WHERE license_number IS NOT NULL
    AND license_number != ''
    AND REPLACE(REPLACE(license_number, ' ', ''), '-', '') != ''
    GROUP BY normalized_uci
    HAVING count > 1
    ORDER BY count DESC
");

$totalDuplicates = count($duplicates);
$totalMerged = 0;
$totalResultsMoved = 0;

foreach ($duplicates as $dup) {
    $riderIds = explode(',', $dup['rider_ids']);

    // Get details for each rider
    $riders = [];
    foreach ($riderIds as $riderId) {
        $rider = $db->getRow("
            SELECT r.*,
                   (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count,
                   c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.id = ?
        ", [$riderId]);
        if ($rider) {
            $riders[] = $rider;
        }
    }

    if (count($riders) < 2) continue;

    // Sort by result count (highest first), then by completeness (birth_year, club)
    usort($riders, function($a, $b) {
        // First by result count
        if ($a['result_count'] != $b['result_count']) {
            return $b['result_count'] - $a['result_count'];
        }
        // Then by having birth_year
        $aComplete = (!empty($a['birth_year']) ? 1 : 0) + (!empty($a['club_id']) ? 1 : 0);
        $bComplete = (!empty($b['birth_year']) ? 1 : 0) + (!empty($b['club_id']) ? 1 : 0);
        return $bComplete - $aComplete;
    });

    // Keep the first one (most results/most complete)
    $keepRider = $riders[0];
    $mergeRiders = array_slice($riders, 1);

    $mergeEntry = [
        'uci' => $dup['normalized_uci'],
        'keep' => [
            'id' => $keepRider['id'],
            'name' => $keepRider['firstname'] . ' ' . $keepRider['lastname'],
            'results' => $keepRider['result_count'],
            'birth_year' => $keepRider['birth_year'],
            'club' => $keepRider['club_name']
        ],
        'merge' => [],
        'results_moved' => 0
    ];

    foreach ($mergeRiders as $mergeRider) {
        $mergeEntry['merge'][] = [
            'id' => $mergeRider['id'],
            'name' => $mergeRider['firstname'] . ' ' . $mergeRider['lastname'],
            'results' => $mergeRider['result_count']
        ];

        if (!$dryRun) {
            // Move all results from duplicate to keeper
            $moved = $db->execute(
                "UPDATE results SET cyclist_id = ? WHERE cyclist_id = ?",
                [$keepRider['id'], $mergeRider['id']]
            );
            $mergeEntry['results_moved'] += $mergeRider['result_count'];
            $totalResultsMoved += $mergeRider['result_count'];

            // Update rider_club_seasons
            $db->execute(
                "UPDATE rider_club_seasons SET rider_id = ? WHERE rider_id = ? AND rider_id != ?",
                [$keepRider['id'], $mergeRider['id'], $keepRider['id']]
            );

            // Copy missing data to keeper
            if (empty($keepRider['birth_year']) && !empty($mergeRider['birth_year'])) {
                $db->update('riders', ['birth_year' => $mergeRider['birth_year']], 'id = ?', [$keepRider['id']]);
            }
            if (empty($keepRider['club_id']) && !empty($mergeRider['club_id'])) {
                $db->update('riders', ['club_id' => $mergeRider['club_id']], 'id = ?', [$keepRider['id']]);
            }
            if (empty($keepRider['nationality']) && !empty($mergeRider['nationality'])) {
                $db->update('riders', ['nationality' => $mergeRider['nationality']], 'id = ?', [$keepRider['id']]);
            }
            if (empty($keepRider['gender']) && !empty($mergeRider['gender'])) {
                $db->update('riders', ['gender' => $mergeRider['gender']], 'id = ?', [$keepRider['id']]);
            }

            // Delete the duplicate rider
            $db->execute("DELETE FROM riders WHERE id = ?", [$mergeRider['id']]);
        }

        $totalMerged++;
    }

    $mergeLog[] = $mergeEntry;
}

// Page output
$pageTitle = 'Auto-merge UCI Dubletter';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1><?= $pageTitle ?></h1>
    </div>

    <?php if ($dryRun): ?>
    <div class="alert alert-warning mb-lg">
        <i data-lucide="alert-triangle"></i>
        <strong>DRY RUN</strong> - Inga ändringar görs. Granska listan nedan och klicka "Kör merge" för att utföra.
    </div>
    <?php else: ?>
    <div class="alert alert-success mb-lg">
        <i data-lucide="check-circle"></i>
        <strong>KLAR!</strong> Mergade <?= $totalMerged ?> dubletter, flyttade <?= $totalResultsMoved ?> resultat.
    </div>
    <?php endif; ?>

    <div class="card mb-lg">
        <div class="card-header">
            <h3>Sammanfattning</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-3 gap-md">
                <div class="stat-card">
                    <div class="stat-number"><?= $totalDuplicates ?></div>
                    <div class="stat-label">UCI-ID med dubletter</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $totalMerged ?></div>
                    <div class="stat-label">Dubletter att ta bort</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $totalResultsMoved ?></div>
                    <div class="stat-label">Resultat att flytta</div>
                </div>
            </div>

            <?php if ($dryRun && $totalDuplicates > 0): ?>
            <div class="mt-lg">
                <a href="?execute=1" class="btn btn-danger" onclick="return confirm('Är du säker? Detta kommer permanent slå ihop <?= $totalMerged ?> dubletter.')">
                    <i data-lucide="merge"></i>
                    Kör merge (<?= $totalMerged ?> dubletter)
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($mergeLog)): ?>
    <div class="card">
        <div class="card-header">
            <h3>Detaljer (<?= count($mergeLog) ?> grupper)</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>UCI-ID</th>
                            <th>Behåll</th>
                            <th>Ta bort</th>
                            <th>Resultat flyttas</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mergeLog as $entry): ?>
                        <tr>
                            <td><code><?= h($entry['uci']) ?></code></td>
                            <td>
                                <strong><?= h($entry['keep']['name']) ?></strong>
                                <br><small class="text-secondary">
                                    ID: <?= $entry['keep']['id'] ?>,
                                    <?= $entry['keep']['results'] ?> resultat
                                    <?php if ($entry['keep']['birth_year']): ?>, f.<?= $entry['keep']['birth_year'] ?><?php endif; ?>
                                    <?php if ($entry['keep']['club']): ?>, <?= h($entry['keep']['club']) ?><?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <?php foreach ($entry['merge'] as $m): ?>
                                <div class="text-danger">
                                    <?= h($m['name']) ?>
                                    <small>(ID: <?= $m['id'] ?>, <?= $m['results'] ?> resultat)</small>
                                </div>
                                <?php endforeach; ?>
                            </td>
                            <td><?= $entry['results_moved'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-success">
        <i data-lucide="check-circle"></i>
        Inga UCI-ID dubletter hittades!
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
