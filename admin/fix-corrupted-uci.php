<?php
/**
 * Fix Corrupted UCI-IDs
 *
 * Finds UCI-IDs that have been incorrectly assigned to multiple different people
 * and clears them, keeping only the most likely real owner.
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Only super_admin can run this
if (!hasRole('super_admin')) {
    die('Endast super_admin kan köra detta script');
}

$dryRun = !isset($_GET['execute']);
$fixLog = [];
$totalFixed = 0;
$totalCleared = 0;

// Find all UCI-IDs with multiple owners (potential corruption)
$corrupted = $db->getAll("
    SELECT
        license_number,
        REPLACE(REPLACE(license_number, ' ', ''), '-', '') as normalized_uci,
        GROUP_CONCAT(id ORDER BY id) as rider_ids,
        COUNT(*) as count
    FROM riders
    WHERE license_number IS NOT NULL
    AND license_number != ''
    AND LENGTH(license_number) >= 8
    GROUP BY license_number
    HAVING count > 1
    ORDER BY count DESC
");

foreach ($corrupted as $entry) {
    $riderIds = explode(',', $entry['rider_ids']);

    // Get details for each rider
    $riders = [];
    foreach ($riderIds as $riderId) {
        $rider = $db->getRow("
            SELECT r.id, r.firstname, r.lastname, r.birth_year, r.club_id, r.license_number,
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

    // Check if names are similar (real duplicate) or different (corruption)
    $firstRider = $riders[0];
    $firstName1 = mb_strtolower($firstRider['firstname'], 'UTF-8');
    $lastName1 = mb_strtolower($firstRider['lastname'], 'UTF-8');

    $hasDifferentNames = false;
    foreach (array_slice($riders, 1) as $otherRider) {
        $firstName2 = mb_strtolower($otherRider['firstname'], 'UTF-8');
        $lastName2 = mb_strtolower($otherRider['lastname'], 'UTF-8');

        // Check if names are significantly different
        $firstNameDist = levenshtein($firstName1, $firstName2);
        $lastNameDist = levenshtein($lastName1, $lastName2);

        // If both first and last name are very different, it's corruption
        if ($firstNameDist > 3 && $lastNameDist > 3) {
            $hasDifferentNames = true;
            break;
        }
    }

    // Only process if we found different names (corruption, not real duplicates)
    if (!$hasDifferentNames) continue;

    // Sort by result count (highest first), then by completeness
    usort($riders, function($a, $b) {
        if ($a['result_count'] != $b['result_count']) {
            return $b['result_count'] - $a['result_count'];
        }
        $aComplete = (!empty($a['birth_year']) ? 1 : 0) + (!empty($a['club_id']) ? 1 : 0);
        $bComplete = (!empty($b['birth_year']) ? 1 : 0) + (!empty($b['club_id']) ? 1 : 0);
        return $bComplete - $aComplete;
    });

    // Keep UCI for first rider (most results), clear for others
    $keepRider = $riders[0];
    $clearRiders = array_slice($riders, 1);

    $logEntry = [
        'uci' => $entry['license_number'],
        'total_affected' => count($riders),
        'keep' => [
            'id' => $keepRider['id'],
            'name' => $keepRider['firstname'] . ' ' . $keepRider['lastname'],
            'results' => $keepRider['result_count'],
            'birth_year' => $keepRider['birth_year'],
            'club' => $keepRider['club_name']
        ],
        'clear' => []
    ];

    foreach ($clearRiders as $rider) {
        $logEntry['clear'][] = [
            'id' => $rider['id'],
            'name' => $rider['firstname'] . ' ' . $rider['lastname'],
            'results' => $rider['result_count']
        ];

        if (!$dryRun) {
            // Clear the UCI-ID for this rider
            $db->update('riders', ['license_number' => null], 'id = ?', [$rider['id']]);
            $totalCleared++;
        }
    }

    $fixLog[] = $logEntry;
    $totalFixed++;
}

// Page output
$page_title = 'Fixa korrupt UCI-data';
$breadcrumbs = [
    ['label' => 'Inställningar', 'url' => '/admin/settings'],
    ['label' => 'Verktyg', 'url' => '/admin/tools'],
    ['label' => 'Fixa korrupt UCI-data']
];
include __DIR__ . '/components/unified-layout.php';
?>

<style>
.keep-badge { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.clear-badge { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
</style>

<?php if ($dryRun): ?>
<div class="alert alert-warning mb-lg">
    <i data-lucide="alert-triangle"></i>
    <strong>FÖRHANDSGRANSKNING</strong> - Inga ändringar görs ännu.
    <br>Detta verktyg hittar UCI-ID:n som felaktigt tilldelats flera olika personer och rensar dem.
</div>
<?php else: ?>
<div class="alert alert-success mb-lg">
    <i data-lucide="check-circle"></i>
    <strong>KLAR!</strong> Rensade UCI-ID för <?= $totalCleared ?> felaktigt tilldelade åkare.
</div>
<?php endif; ?>

<div class="card mb-lg">
    <div class="card-header">
        <h3>Sammanfattning</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-cols-2 gap-md">
            <div class="stat-card" style="border-left: 4px solid var(--color-danger);">
                <div class="stat-number text-danger"><?= $totalFixed ?></div>
                <div class="stat-label">Korrupta UCI-ID:n</div>
                <small class="text-secondary">Samma UCI tilldelat olika personer</small>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $dryRun ? array_sum(array_map(fn($e) => count($e['clear']), $fixLog)) : $totalCleared ?></div>
                <div class="stat-label">Åkare att rensa</div>
                <small class="text-secondary">UCI-ID tas bort från dessa</small>
            </div>
        </div>

        <?php if ($dryRun && $totalFixed > 0): ?>
        <div class="mt-lg">
            <a href="?execute=1" class="btn btn-danger" onclick="return confirm('Rensa UCI-ID för <?= array_sum(array_map(fn($e) => count($e['clear']), $fixLog)) ?> felaktigt tilldelade åkare?\n\nDe som behåller UCI-ID är de med flest resultat.')">
                <i data-lucide="eraser"></i>
                Rensa felaktiga UCI-ID:n
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($fixLog)): ?>
<div class="card">
    <div class="card-header">
        <h3>Detaljer (<?= count($fixLog) ?> korrupta UCI-ID:n)</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>UCI-ID</th>
                        <th>Behåller UCI <span class="keep-badge">BEHÅLL</span></th>
                        <th>Rensas <span class="clear-badge">RENSA</span></th>
                        <th>Antal</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fixLog as $entry): ?>
                    <tr>
                        <td>
                            <code><?= h($entry['uci']) ?></code>
                            <br><small class="text-danger"><?= $entry['total_affected'] ?> personer hade detta UCI</small>
                        </td>
                        <td>
                            <strong class="text-success"><?= h($entry['keep']['name']) ?></strong>
                            <br><small class="text-secondary">
                                ID: <?= $entry['keep']['id'] ?>,
                                <?= $entry['keep']['results'] ?> resultat
                                <?php if ($entry['keep']['birth_year']): ?>, f.<?= $entry['keep']['birth_year'] ?><?php endif; ?>
                                <?php if ($entry['keep']['club']): ?>, <?= h($entry['keep']['club']) ?><?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <?php
                            $showCount = min(5, count($entry['clear']));
                            $hiddenCount = count($entry['clear']) - $showCount;
                            foreach (array_slice($entry['clear'], 0, $showCount) as $c): ?>
                            <div class="mb-xs">
                                <span class="text-danger"><?= h($c['name']) ?></span>
                                <small>(ID: <?= $c['id'] ?>, <?= $c['results'] ?> res)</small>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($hiddenCount > 0): ?>
                            <small class="text-secondary">... och <?= $hiddenCount ?> till</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong class="text-danger"><?= count($entry['clear']) ?></strong>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($dryRun): ?>
<div class="alert alert-success">
    <i data-lucide="check-circle"></i>
    Inga korrupta UCI-ID:n hittades!
</div>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
