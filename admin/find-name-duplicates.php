<?php
/**
 * Find Name Duplicates
 *
 * Finds riders with the same name but different license numbers.
 * These are likely the same person imported multiple times.
 *
 * Priority for merging:
 * 1. Real UCI-ID (14+ chars) > Temp SWE-ID > No ID
 * 2. Most results
 * 3. Most complete profile
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Only super_admin can run this
if (!hasRole('super_admin')) {
    die('Endast super_admin kan köra detta script');
}

$dryRun = !isset($_GET['execute']);
$mergeId = isset($_GET['merge']) ? (int)$_GET['merge'] : null;

// Helper function to determine license type priority
function getLicensePriority($license) {
    if (empty($license)) return 0; // No license
    $clean = str_replace([' ', '-'], '', $license);

    // Real UCI-ID: 14+ chars, format like SWE19900515001
    if (strlen($clean) >= 14 && preg_match('/^[A-Z]{3}\d{11}/', $clean)) {
        return 3; // Highest priority
    }

    // Temp SWE-ID: SWE25XXXXX (10 chars)
    if (preg_match('/^SWE\d{7}$/', $clean)) {
        return 1; // Low priority
    }

    // Other format (old SWE-XXXXXX with dash, etc.)
    return 2; // Medium priority
}

function getLicenseTypeName($license) {
    if (empty($license)) return 'Inget ID';
    $clean = str_replace([' ', '-'], '', $license);

    if (strlen($clean) >= 14 && preg_match('/^[A-Z]{3}\d{11}/', $clean)) {
        return 'UCI-ID';
    }
    if (preg_match('/^SWE\d{7}$/', $clean)) {
        return 'SWE-ID';
    }
    return 'Annat';
}

// Handle merge action
$mergeResult = null;
if ($mergeId !== null && !$dryRun) {
    // Get the merge group
    $keepId = isset($_GET['keep']) ? (int)$_GET['keep'] : 0;
    $removeIds = isset($_GET['remove']) ? array_map('intval', explode(',', $_GET['remove'])) : [];

    if ($keepId && !empty($removeIds)) {
        $totalMoved = 0;

        foreach ($removeIds as $removeId) {
            if ($removeId <= 0) continue;

            // Move results from remove to keep using proper update method
            $moved = $db->update('results',
                ['cyclist_id' => $keepId],
                'cyclist_id = ?',
                [$removeId]
            );
            $totalMoved += $moved;

            // Delete the duplicate rider using proper delete method
            $db->delete('riders', 'id = ?', [$removeId]);
        }

        $mergeResult = [
            'success' => true,
            'kept' => $keepId,
            'removed' => $removeIds,
            'results_moved' => $totalMoved
        ];
    }
}

// Find all name duplicates (same firstname + lastname, different IDs)
$nameDuplicates = $db->getAll("
    SELECT
        LOWER(TRIM(firstname)) as fn,
        LOWER(TRIM(lastname)) as ln,
        GROUP_CONCAT(id ORDER BY id) as rider_ids,
        COUNT(*) as count
    FROM riders
    WHERE firstname IS NOT NULL AND firstname != ''
    AND lastname IS NOT NULL AND lastname != ''
    GROUP BY fn, ln
    HAVING count > 1
    ORDER BY count DESC
    LIMIT 100
");

$duplicateGroups = [];
$totalDuplicates = 0;

foreach ($nameDuplicates as $dup) {
    $riderIds = explode(',', $dup['rider_ids']);

    // Get details for each rider
    $riders = [];
    foreach ($riderIds as $riderId) {
        $rider = $db->getRow("
            SELECT r.id, r.firstname, r.lastname, r.birth_year, r.nationality,
                   r.license_number, r.club_id,
                   (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count,
                   c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.id = ?
        ", [$riderId]);
        if ($rider) {
            $rider['license_priority'] = getLicensePriority($rider['license_number']);
            $rider['license_type'] = getLicenseTypeName($rider['license_number']);
            $riders[] = $rider;
        }
    }

    if (count($riders) < 2) continue;

    // Check birth years - only consider duplicates if birth years match or are empty
    // Different birth years = likely different people with same name
    $birthYears = [];
    foreach ($riders as $r) {
        if (!empty($r['birth_year'])) {
            $birthYears[$r['birth_year']] = true;
        }
    }
    // Skip if we have multiple DIFFERENT birth years (not the same person!)
    if (count($birthYears) > 1) continue;

    // Check if they have different license numbers (not just format differences)
    $uniqueLicenses = [];
    foreach ($riders as $r) {
        $normalized = preg_replace('/[^A-Z0-9]/i', '', $r['license_number'] ?? '');
        if (!empty($normalized)) {
            $uniqueLicenses[$normalized] = true;
        }
    }

    // Skip if all have the same license (handled by other tools)
    // But include if some have no license or different licenses
    $hasNoLicense = false;
    $hasDifferentLicenses = count($uniqueLicenses) > 1;
    foreach ($riders as $r) {
        if (empty($r['license_number'])) {
            $hasNoLicense = true;
            break;
        }
    }

    // Only show if there are different licenses OR some without license
    if (!$hasDifferentLicenses && !$hasNoLicense) continue;

    // Sort by priority: license type, then results, then completeness
    usort($riders, function($a, $b) {
        // First by license priority (higher = better)
        if ($a['license_priority'] != $b['license_priority']) {
            return $b['license_priority'] - $a['license_priority'];
        }
        // Then by result count
        if ($a['result_count'] != $b['result_count']) {
            return $b['result_count'] - $a['result_count'];
        }
        // Then by completeness
        $aComplete = (!empty($a['birth_year']) ? 1 : 0) + (!empty($a['club_id']) ? 1 : 0);
        $bComplete = (!empty($b['birth_year']) ? 1 : 0) + (!empty($b['club_id']) ? 1 : 0);
        return $bComplete - $aComplete;
    });

    $keepRider = $riders[0];
    $mergeRiders = array_slice($riders, 1);

    $duplicateGroups[] = [
        'name' => $riders[0]['firstname'] . ' ' . $riders[0]['lastname'],
        'total_riders' => count($riders),
        'total_results' => array_sum(array_column($riders, 'result_count')),
        'keep' => $keepRider,
        'merge' => $mergeRiders,
        'all_riders' => $riders
    ];

    $totalDuplicates += count($mergeRiders);
}

// Page output
$page_title = 'Hitta namndubletter';
$breadcrumbs = [
    ['label' => 'Inställningar', 'url' => '/admin/settings'],
    ['label' => 'Verktyg', 'url' => '/admin/tools'],
    ['label' => 'Hitta namndubletter']
];
include __DIR__ . '/components/unified-layout.php';
?>

<style>
.keep-badge { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.merge-badge { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.uci-badge { background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.swe-badge { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.no-badge { background: #f3f4f6; color: #6b7280; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.duplicate-card { border: 1px solid var(--color-border); border-radius: var(--radius-md); margin-bottom: var(--space-md); }
.duplicate-card-header { background: var(--color-star-fade); padding: var(--space-md); border-bottom: 1px solid var(--color-border); display: flex; justify-content: space-between; align-items: center; }
.duplicate-card-body { padding: var(--space-md); }
.rider-row { display: flex; gap: var(--space-md); align-items: center; padding: var(--space-sm) 0; border-bottom: 1px solid var(--color-border); }
.rider-row:last-child { border-bottom: none; }
.rider-info { flex: 1; }
.rider-stats { display: flex; gap: var(--space-md); font-size: 13px; color: var(--color-text); }
</style>

<?php if ($mergeResult): ?>
<div class="alert alert-success mb-lg">
    <i data-lucide="check-circle"></i>
    <strong>Sammanslagning klar!</strong>
    Behöll rider #<?= $mergeResult['kept'] ?>, tog bort <?= count($mergeResult['removed']) ?> dubbletter.
    <?= $mergeResult['results_moved'] ?> resultat flyttades.
</div>
<?php endif; ?>

<div class="alert alert-info mb-lg">
    <i data-lucide="info"></i>
    <strong>Namndubletter</strong> - Samma namn, samma födelseår, men olika licens-ID.
    <br>Endast åkare med samma (eller tomt) födelseår visas - olika födelseår = olika personer.
    <br><strong>Prioritet:</strong> UCI-ID > SWE-ID > Inget ID, sedan flest resultat.
</div>

<div class="card mb-lg">
    <div class="card-header">
        <h3>Sammanfattning</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-cols-2 gap-md">
            <div class="stat-card" style="border-left: 4px solid var(--color-warning);">
                <div class="stat-number"><?= count($duplicateGroups) ?></div>
                <div class="stat-label">Namngrupper med dubletter</div>
            </div>
            <div class="stat-card" style="border-left: 4px solid var(--color-danger);">
                <div class="stat-number"><?= $totalDuplicates ?></div>
                <div class="stat-label">Riders att slå ihop</div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($duplicateGroups)): ?>
<div class="card">
    <div class="card-header">
        <h3>Namndubletter (<?= count($duplicateGroups) ?>)</h3>
    </div>
    <div class="card-body">
        <?php foreach ($duplicateGroups as $index => $group): ?>
        <div class="duplicate-card">
            <div class="duplicate-card-header">
                <div>
                    <strong><?= h($group['name']) ?></strong>
                    <span class="text-secondary">(<?= $group['total_riders'] ?> poster, <?= $group['total_results'] ?> resultat totalt)</span>
                </div>
                <?php
                $keepId = $group['keep']['id'];
                $removeIds = implode(',', array_column($group['merge'], 'id'));
                ?>
                <a href="?execute=1&merge=<?= $index ?>&keep=<?= $keepId ?>&remove=<?= $removeIds ?>"
                   class="btn btn-sm btn-warning"
                   onclick="return confirm('Slå ihop <?= count($group['merge']) ?> dubletter till <?= h($group['keep']['firstname'] . ' ' . $group['keep']['lastname']) ?> (ID: <?= $keepId ?>)?\\n\\nResultat flyttas och dubbletter tas bort.')">
                    <i data-lucide="git-merge"></i>
                    Slå ihop
                </a>
            </div>
            <div class="duplicate-card-body">
                <!-- Keep this one -->
                <div class="rider-row" style="background: #f0fdf4;">
                    <span class="keep-badge">BEHÅLL</span>
                    <div class="rider-info">
                        <strong><?= h($group['keep']['firstname'] . ' ' . $group['keep']['lastname']) ?></strong>
                        <?php if ($group['keep']['birth_year']): ?>
                            <small>(<?= $group['keep']['birth_year'] ?>)</small>
                        <?php endif; ?>
                        <?php if ($group['keep']['club_name']): ?>
                            <small class="text-secondary">- <?= h($group['keep']['club_name']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="rider-stats">
                        <span>
                            <?php if ($group['keep']['license_priority'] == 3): ?>
                                <span class="uci-badge">UCI-ID</span>
                            <?php elseif ($group['keep']['license_priority'] == 1): ?>
                                <span class="swe-badge">SWE-ID</span>
                            <?php else: ?>
                                <span class="no-badge"><?= $group['keep']['license_type'] ?></span>
                            <?php endif; ?>
                            <code><?= h($group['keep']['license_number'] ?: '-') ?></code>
                        </span>
                        <span><strong><?= $group['keep']['result_count'] ?></strong> resultat</span>
                        <span>ID: <?= $group['keep']['id'] ?></span>
                    </div>
                </div>

                <!-- Merge these -->
                <?php foreach ($group['merge'] as $rider): ?>
                <div class="rider-row" style="background: #fefce8;">
                    <span class="merge-badge">SLÅ IHOP</span>
                    <div class="rider-info">
                        <?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>
                        <?php if ($rider['birth_year']): ?>
                            <small>(<?= $rider['birth_year'] ?>)</small>
                        <?php endif; ?>
                        <?php if ($rider['nationality'] && $rider['nationality'] != $group['keep']['nationality']): ?>
                            <small class="text-warning"><?= h($rider['nationality']) ?></small>
                        <?php endif; ?>
                        <?php if ($rider['club_name']): ?>
                            <small class="text-secondary">- <?= h($rider['club_name']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="rider-stats">
                        <span>
                            <?php if ($rider['license_priority'] == 3): ?>
                                <span class="uci-badge">UCI-ID</span>
                            <?php elseif ($rider['license_priority'] == 1): ?>
                                <span class="swe-badge">SWE-ID</span>
                            <?php else: ?>
                                <span class="no-badge"><?= $rider['license_type'] ?></span>
                            <?php endif; ?>
                            <code><?= h($rider['license_number'] ?: '-') ?></code>
                        </span>
                        <span><strong><?= $rider['result_count'] ?></strong> resultat</span>
                        <span>ID: <?= $rider['id'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="alert alert-success">
    <i data-lucide="check-circle"></i>
    Inga namndubletter hittades!
</div>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
