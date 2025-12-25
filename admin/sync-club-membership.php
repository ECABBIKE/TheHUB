<?php
/**
 * Sync Club Membership 2025
 *
 * Finds riders who have a club in rider_club_seasons for 2025
 * but their riders.club_id is NULL or different.
 * Syncs the riders.club_id to match their 2025 membership.
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Only super_admin can run this
if (!hasRole('super_admin')) {
    die('Endast super_admin kan köra detta script');
}

$currentYear = date('Y');
$targetYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)$currentYear;
$dryRun = !isset($_GET['execute']);
$direction = $_GET['direction'] ?? 'season_to_rider'; // or 'rider_to_season'

// Find mismatches: riders with 2025 season club but different/null rider.club_id
$mismatches = $db->getAll("
    SELECT
        r.id as rider_id,
        r.firstname,
        r.lastname,
        r.club_id as current_club_id,
        c_current.name as current_club_name,
        rcs.club_id as season_club_id,
        c_season.name as season_club_name,
        rcs.locked,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as total_results,
        (SELECT COUNT(*) FROM results res
         JOIN events e ON res.event_id = e.id
         WHERE res.cyclist_id = r.id AND YEAR(e.date) = ?) as year_results
    FROM rider_club_seasons rcs
    JOIN riders r ON rcs.rider_id = r.id
    JOIN clubs c_season ON rcs.club_id = c_season.id
    LEFT JOIN clubs c_current ON r.club_id = c_current.id
    WHERE rcs.season_year = ?
    AND (r.club_id IS NULL OR r.club_id != rcs.club_id)
    ORDER BY r.lastname, r.firstname
", [$targetYear, $targetYear]);

// Also find: riders with club_id but NO season entry for target year
$noSeasonEntry = $db->getAll("
    SELECT
        r.id as rider_id,
        r.firstname,
        r.lastname,
        r.club_id as current_club_id,
        c.name as current_club_name,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as total_results,
        (SELECT COUNT(*) FROM results res
         JOIN events e ON res.event_id = e.id
         WHERE res.cyclist_id = r.id AND YEAR(e.date) = ?) as year_results
    FROM riders r
    JOIN clubs c ON r.club_id = c.id
    LEFT JOIN rider_club_seasons rcs ON r.id = rcs.rider_id AND rcs.season_year = ?
    WHERE r.club_id IS NOT NULL
    AND rcs.id IS NULL
    ORDER BY r.lastname, r.firstname
    LIMIT 500
", [$targetYear, $targetYear]);

$syncResult = null;
if (!$dryRun) {
    $synced = 0;

    if ($direction === 'season_to_rider') {
        // Update riders.club_id to match their season club
        foreach ($mismatches as $m) {
            $db->update('riders',
                ['club_id' => $m['season_club_id']],
                'id = ?',
                [$m['rider_id']]
            );
            $synced++;
        }
    } elseif ($direction === 'rider_to_season') {
        // Create season entries from riders.club_id
        foreach ($noSeasonEntry as $r) {
            $db->query(
                "INSERT IGNORE INTO rider_club_seasons (rider_id, club_id, season_year, locked) VALUES (?, ?, ?, 0)",
                [$r['rider_id'], $r['current_club_id'], $targetYear]
            );
            $synced++;
        }
    }

    $syncResult = ['synced' => $synced, 'direction' => $direction];

    // Reload data
    header("Location: ?year={$targetYear}");
    exit;
}

// Page output
$page_title = 'Synka klubbmedlemskap ' . $targetYear;
$breadcrumbs = [
    ['label' => 'Inställningar', 'url' => '/admin/settings'],
    ['label' => 'Verktyg', 'url' => '/admin/tools'],
    ['label' => 'Synka klubbmedlemskap']
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($syncResult): ?>
<div class="alert alert-success mb-lg">
    <i data-lucide="check-circle"></i>
    <strong>Klart!</strong> <?= $syncResult['synced'] ?> poster synkade
    (<?= $syncResult['direction'] === 'season_to_rider' ? 'säsong → profil' : 'profil → säsong' ?>).
</div>
<?php endif; ?>

<div class="alert alert-info mb-lg">
    <i data-lucide="info"></i>
    <strong>Klubbmedlemskap för <?= $targetYear ?></strong><br>
    Hittar åkare där <code>riders.club_id</code> inte matchar <code>rider_club_seasons</code> för <?= $targetYear ?>.
    <br>Detta är kritiskt för klubbmästerskap!
</div>

<!-- Year selector -->
<div class="card mb-lg">
    <div class="card-body">
        <form method="get" style="display: flex; gap: var(--space-md); align-items: center;">
            <label class="form-label" style="margin: 0;">Välj år:</label>
            <select name="year" class="form-select" style="width: auto;" onchange="this.form.submit()">
                <?php for ($y = (int)$currentYear; $y >= 2020; $y--): ?>
                <option value="<?= $y ?>" <?= $y == $targetYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>
</div>

<!-- Problem 1: Season club differs from rider.club_id -->
<div class="card mb-lg">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3>Säsongsmedlemskap skiljer sig från profil (<?= count($mismatches) ?>)</h3>
            <p class="text-secondary" style="margin: 0; font-size: 13px;">
                Dessa har <?= $targetYear ?>-medlemskap i historiken men annan/tom klubb i profilen
            </p>
        </div>
        <?php if (!empty($mismatches)): ?>
        <a href="?year=<?= $targetYear ?>&execute=1&direction=season_to_rider"
           class="btn btn-primary"
           onclick="return confirm('Uppdatera <?= count($mismatches) ?> åkares profil-klubb till deras <?= $targetYear ?>-medlemskap?')">
            <i data-lucide="refresh-cw"></i>
            Synka alla till profil (<?= count($mismatches) ?>)
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($mismatches)): ?>
        <div class="alert alert-success">
            <i data-lucide="check-circle"></i>
            Alla med <?= $targetYear ?>-medlemskap har korrekt klubb i profilen!
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Åkare</th>
                        <th>Nuvarande (profil)</th>
                        <th></th>
                        <th><?= $targetYear ?> (säsong)</th>
                        <th>Resultat <?= $targetYear ?></th>
                        <th>Låst</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($mismatches, 0, 100) as $m): ?>
                    <tr>
                        <td>
                            <a href="/admin/rider-edit/<?= $m['rider_id'] ?>">
                                <?= h($m['firstname'] . ' ' . $m['lastname']) ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($m['current_club_name']): ?>
                                <span class="text-danger"><?= h($m['current_club_name']) ?></span>
                            <?php else: ?>
                                <span class="text-secondary">Ingen klubb</span>
                            <?php endif; ?>
                        </td>
                        <td><i data-lucide="arrow-right" style="width: 16px; color: var(--color-accent);"></i></td>
                        <td><span class="text-success"><strong><?= h($m['season_club_name']) ?></strong></span></td>
                        <td><?= $m['year_results'] ?></td>
                        <td>
                            <?php if ($m['locked']): ?>
                                <span class="badge badge-warning">Låst</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Ej låst</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($mismatches) > 100): ?>
        <p class="text-secondary">Visar 100 av <?= count($mismatches) ?></p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Problem 2: Has club in profile but no season entry -->
<div class="card mb-lg">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3>Har klubb i profil men saknar <?= $targetYear ?>-medlemskap (<?= count($noSeasonEntry) ?>)</h3>
            <p class="text-secondary" style="margin: 0; font-size: 13px;">
                Dessa har klubb i profilen men ingen post i rider_club_seasons för <?= $targetYear ?>
            </p>
        </div>
        <?php if (!empty($noSeasonEntry)): ?>
        <a href="?year=<?= $targetYear ?>&execute=1&direction=rider_to_season"
           class="btn btn-secondary"
           onclick="return confirm('Skapa <?= $targetYear ?>-medlemskap för <?= count($noSeasonEntry) ?> åkare baserat på deras profilklubb?')">
            <i data-lucide="plus"></i>
            Skapa säsongsposter (<?= count($noSeasonEntry) ?>)
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($noSeasonEntry)): ?>
        <div class="alert alert-success">
            <i data-lucide="check-circle"></i>
            Alla med klubb i profilen har <?= $targetYear ?>-medlemskap!
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Åkare</th>
                        <th>Klubb (profil)</th>
                        <th>Resultat <?= $targetYear ?></th>
                        <th>Totalt resultat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($noSeasonEntry, 0, 100) as $r): ?>
                    <tr>
                        <td>
                            <a href="/admin/rider-edit/<?= $r['rider_id'] ?>">
                                <?= h($r['firstname'] . ' ' . $r['lastname']) ?>
                            </a>
                        </td>
                        <td><?= h($r['current_club_name']) ?></td>
                        <td><?= $r['year_results'] ?></td>
                        <td><?= $r['total_results'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($noSeasonEntry) > 100): ?>
        <p class="text-secondary">Visar 100 av <?= count($noSeasonEntry) ?></p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
