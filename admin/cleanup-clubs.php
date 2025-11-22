<?php
/**
 * Club Duplicate Cleanup Tool
 * Find and merge duplicate clubs created during import
 * Version: v1.0.2 [2025-11-22-003]
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = '';

// Handle merge action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge'])) {
    checkCsrf();

    $keepId = (int)$_POST['keep_club'];
    $mergeIds = array_map('intval', $_POST['merge_clubs'] ?? []);

    if ($keepId && !empty($mergeIds)) {
        try {
            $movedCount = 0;
            $deletedCount = 0;

            // Move all riders from each merge club to keep club
            foreach ($mergeIds as $mergeId) {
                // Count riders to move
                $count = $db->getRow("SELECT COUNT(*) as cnt FROM riders WHERE club_id = ?", [$mergeId])['cnt'] ?? 0;
                $movedCount += $count;

                // Update riders
                if ($count > 0) {
                    $db->update('riders', ['club_id' => $keepId], 'club_id = ?', [$mergeId]);
                }

                // Delete the empty club
                $db->delete('clubs', 'id = ?', [$mergeId]);
                $deletedCount++;
            }

            $message = "Flyttade $movedCount deltagare och tog bort $deletedCount dubblettklubbar";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Fel vid sammanslagning: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Handle single delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_club'])) {
    checkCsrf();

    $deleteId = (int)$_POST['delete_club'];

    // Check if club has riders
    $riderCount = $db->getRow("SELECT COUNT(*) as cnt FROM riders WHERE club_id = ?", [$deleteId])['cnt'] ?? 0;

    if ($riderCount == 0) {
        try {
            $db->delete('clubs', 'id = ?', [$deleteId]);
            $message = 'Klubb borttagen';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Fel vid borttagning: ' . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Kan inte ta bort klubb med $riderCount deltagare";
        $messageType = 'error';
    }
}

// Handle delete all empty clubs action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_empty'])) {
    checkCsrf();

    $clubIds = array_map('intval', $_POST['merge_clubs'] ?? []);
    $deletedCount = 0;
    $skippedCount = 0;

    if (!empty($clubIds)) {
        try {
            foreach ($clubIds as $clubId) {
                // Verify club is actually empty
                $riderCount = $db->getRow("SELECT COUNT(*) as cnt FROM riders WHERE club_id = ?", [$clubId])['cnt'] ?? 0;

                if ($riderCount == 0) {
                    $db->delete('clubs', 'id = ?', [$clubId]);
                    $deletedCount++;
                } else {
                    $skippedCount++;
                }
            }

            if ($skippedCount > 0) {
                $message = "Tog bort $deletedCount tomma klubbar, hoppade över $skippedCount klubbar med deltagare";
                $messageType = 'warning';
            } else {
                $message = "Tog bort $deletedCount tomma klubbar";
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Fel vid borttagning: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get all clubs with rider counts
$clubs = $db->getAll("
    SELECT c.id, c.name, c.short_name, c.city, c.region, c.country,
           COUNT(r.id) as rider_count
    FROM clubs c
    LEFT JOIN riders r ON c.id = r.club_id
    GROUP BY c.id
    ORDER BY c.name
");

// Group clubs by normalized name to find potential duplicates
$clubGroups = [];
foreach ($clubs as $club) {
    $normalized = normalizeClubName($club['name']);

    if (!isset($clubGroups[$normalized])) {
        $clubGroups[$normalized] = [];
    }
    $clubGroups[$normalized][] = $club;
}

// Filter to only show groups with potential duplicates (2+ clubs)
$duplicateGroups = array_filter($clubGroups, function($group) {
    return count($group) >= 2;
});

// Sort groups by total rider count (most important first)
uasort($duplicateGroups, function($a, $b) {
    $totalA = array_sum(array_column($a, 'rider_count'));
    $totalB = array_sum(array_column($b, 'rider_count'));
    return $totalB - $totalA;
});

// Also find empty clubs
$emptyClubs = array_filter($clubs, function($club) {
    return $club['rider_count'] == 0;
});

/**
 * Normalize club name for comparison
 */
function normalizeClubName($name) {
    $name = mb_strtolower(trim($name), 'UTF-8');

    // Remove common suffixes/variations
    $name = preg_replace('/\s*(ck|cykelklubb|cykel klubb|cykel|if|ik|sk|fk|bk|idrottssällskap|idrottsällskap|idrotts|enduro|mtb)\s*/u', '', $name);

    // Replace Swedish chars
    $name = preg_replace('/[åä]/u', 'a', $name);
    $name = preg_replace('/[ö]/u', 'o', $name);
    $name = preg_replace('/[é]/u', 'e', $name);

    // Remove non-alphanumeric
    $name = preg_replace('/[^a-z0-9]/u', '', $name);

    return $name;
}

$pageTitle = 'Rensa Klubbdubbletter';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">

        <!-- Header -->
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <div>
                <h1 class="gs-h1">
                    <i data-lucide="building-2"></i>
                    Rensa Klubbdubbletter
                </h1>
                <p class="gs-text-secondary">
                    Hitta och slå samman dubblettklubbar skapade vid import
                </p>
            </div>
            <a href="/admin/clubs.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-md gs-mb-lg">
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-text-2xl gs-text-primary"><?= count($clubs) ?></div>
                    <div class="gs-text-sm gs-text-secondary">Totalt klubbar</div>
                </div>
            </div>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-text-2xl gs-text-warning"><?= count($duplicateGroups) ?></div>
                    <div class="gs-text-sm gs-text-secondary">Dubblettgrupper</div>
                </div>
            </div>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-text-2xl gs-text-danger"><?= count($emptyClubs) ?></div>
                    <div class="gs-text-sm gs-text-secondary">Tomma klubbar</div>
                </div>
            </div>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-text-2xl gs-text-success"><?= count($clubs) - count($emptyClubs) ?></div>
                    <div class="gs-text-sm gs-text-secondary">Med deltagare</div>
                </div>
            </div>
        </div>

        <?php if (!empty($duplicateGroups)): ?>
            <!-- Duplicate Groups -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h3">
                        <i data-lucide="copy"></i>
                        Potentiella dubbletter (<?= count($duplicateGroups) ?> grupper)
                    </h2>
                </div>
                <div class="gs-card-content">
                    <?php foreach ($duplicateGroups as $normalized => $group): ?>
                        <form method="POST" class="gs-mb-lg gs-pb-lg gs-border-b">
                            <?= csrf_field() ?>

                            <h4 class="gs-h4 gs-mb-md">
                                <?= h($group[0]['name']) ?>
                                <span class="gs-badge gs-badge-secondary"><?= count($group) ?> varianter</span>
                            </h4>

                            <div class="gs-table-responsive gs-mb-md">
                                <table class="gs-table">
                                    <thead>
                                        <tr>
                                            <th>Behåll</th>
                                            <th>Slå samman</th>
                                            <th>Klubbnamn</th>
                                            <th>Kort</th>
                                            <th>Stad</th>
                                            <th>Deltagare</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Sort by rider count desc
                                        usort($group, function($a, $b) {
                                            return $b['rider_count'] - $a['rider_count'];
                                        });
                                        $first = true;
                                        foreach ($group as $club):
                                        ?>
                                            <tr class="<?= $club['rider_count'] == 0 ? 'gs-bg-danger-light' : '' ?>">
                                                <td>
                                                    <input type="radio" name="keep_club" value="<?= $club['id'] ?>"
                                                           <?= $first ? 'checked' : '' ?>>
                                                </td>
                                                <td>
                                                    <?php if (!$first): ?>
                                                        <input type="checkbox" name="merge_clubs[]" value="<?= $club['id'] ?>"
                                                               <?= $club['rider_count'] == 0 ? 'checked' : '' ?>>
                                                    <?php else: ?>
                                                        <span class="gs-text-secondary">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= h($club['name']) ?></strong>
                                                    <?php if ($club['rider_count'] > 0): ?>
                                                        <span class="gs-badge gs-badge-success gs-badge-sm">Huvudklubb</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h($club['short_name']) ?: '-' ?></td>
                                                <td><?= h($club['city']) ?: '-' ?></td>
                                                <td>
                                                    <?php if ($club['rider_count'] > 0): ?>
                                                        <strong class="gs-text-success"><?= $club['rider_count'] ?></strong>
                                                    <?php else: ?>
                                                        <span class="gs-text-danger">0</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php
                                        $first = false;
                                        endforeach;
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                            <button type="submit" name="merge" value="1" class="gs-btn gs-btn-warning">
                                <i data-lucide="merge"></i>
                                Slå samman markerade till vald klubb
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content gs-text-center gs-py-xl">
                    <i data-lucide="check-circle" class="gs-text-success" style="width: 48px; height: 48px;"></i>
                    <p class="gs-text-lg gs-mt-md">Inga dubblettgrupper hittades!</p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($emptyClubs)): ?>
            <!-- Empty Clubs -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h3">
                        <i data-lucide="trash-2"></i>
                        Tomma klubbar (<?= count($emptyClubs) ?>)
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Klubbnamn</th>
                                    <th>Kort</th>
                                    <th>Stad</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($emptyClubs as $club): ?>
                                    <tr>
                                        <td><?= h($club['name']) ?></td>
                                        <td><?= h($club['short_name']) ?: '-' ?></td>
                                        <td><?= h($club['city']) ?: '-' ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <?= csrf_field() ?>
                                                <button type="submit" name="delete_club" value="<?= $club['id'] ?>"
                                                        class="gs-btn gs-btn-sm gs-btn-danger"
                                                        onclick="return confirm('Ta bort <?= h($club['name']) ?>?')">
                                                    <i data-lucide="trash-2"></i>
                                                    Ta bort
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (count($emptyClubs) > 5): ?>
                        <form method="POST" class="gs-mt-lg">
                            <?= csrf_field() ?>
                            <?php foreach ($emptyClubs as $club): ?>
                                <input type="hidden" name="merge_clubs[]" value="<?= $club['id'] ?>">
                            <?php endforeach; ?>
                            <input type="hidden" name="keep_club" value="0">
                            <button type="submit" name="delete_all_empty" value="1"
                                    class="gs-btn gs-btn-danger"
                                    onclick="return confirm('Ta bort ALLA <?= count($emptyClubs) ?> tomma klubbar?')">
                                <i data-lucide="trash-2"></i>
                                Ta bort alla tomma klubbar
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</main>

<div class="gs-container gs-py-sm">
    <small class="gs-text-secondary">Cleanup Clubs v1.0.2 [2025-11-22-003]</small>
</div>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
