<?php
/**
 * Bulk Merge Duplicates - Hitta och slå ihop ALLA dubletter
 * Kombinerar UCI-matchning och namnmatchning
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$pdo = $db->getPdo();

$message = '';
$messageType = 'info';

// Handle bulk merge
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_all'])) {
    checkCsrf();

    $mergeCount = 0;
    $resultsMoved = 0;
    $errors = 0;

    // Step 1: Merge by UCI-ID (most reliable)
    $uciDuplicates = $pdo->query("
        SELECT REPLACE(REPLACE(license_number, ' ', ''), '-', '') as uci_normalized,
               GROUP_CONCAT(id ORDER BY id) as ids,
               COUNT(*) as cnt
        FROM riders
        WHERE license_number IS NOT NULL
          AND license_number != ''
          AND LENGTH(REPLACE(REPLACE(license_number, ' ', ''), '-', '')) >= 8
        GROUP BY uci_normalized
        HAVING cnt > 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($uciDuplicates as $dup) {
        $ids = explode(',', $dup['ids']);
        if (count($ids) < 2) continue;

        // Get rider details to pick the best one
        $riders = [];
        foreach ($ids as $id) {
            $r = $db->getRow("
                SELECT r.*, (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count
                FROM riders r WHERE r.id = ?
            ", [(int)$id]);
            if ($r) {
                // Score: results + completeness
                $r['score'] = $r['result_count'] * 10;
                if (!empty($r['birth_year'])) $r['score'] += 5;
                if (!empty($r['email'])) $r['score'] += 5;
                if (!empty($r['club_id'])) $r['score'] += 5;
                $riders[] = $r;
            }
        }

        if (count($riders) < 2) continue;

        // Sort by score desc
        usort($riders, fn($a, $b) => $b['score'] - $a['score']);

        $keep = array_shift($riders);
        $keepId = $keep['id'];

        foreach ($riders as $remove) {
            try {
                $pdo->beginTransaction();

                // Move results
                $stmt = $pdo->prepare("UPDATE results SET cyclist_id = ? WHERE cyclist_id = ?");
                $stmt->execute([$keepId, $remove['id']]);
                $resultsMoved += $stmt->rowCount();

                // Move series_results
                $pdo->prepare("UPDATE series_results SET cyclist_id = ? WHERE cyclist_id = ?")->execute([$keepId, $remove['id']]);

                // Copy missing data
                $updates = [];
                if (empty($keep['birth_year']) && !empty($remove['birth_year'])) $updates['birth_year'] = $remove['birth_year'];
                if (empty($keep['email']) && !empty($remove['email'])) $updates['email'] = $remove['email'];
                if (empty($keep['club_id']) && !empty($remove['club_id'])) $updates['club_id'] = $remove['club_id'];
                if (!empty($updates)) {
                    $db->update('riders', $updates, 'id = ?', [$keepId]);
                }

                // Delete duplicate
                $pdo->prepare("DELETE FROM riders WHERE id = ?")->execute([$remove['id']]);

                $pdo->commit();
                $mergeCount++;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors++;
            }
        }
    }

    // Step 2: Merge by exact name (case-insensitive using UPPER)
    $nameDuplicates = $pdo->query("
        SELECT UPPER(firstname) as fn, UPPER(lastname) as ln,
               GROUP_CONCAT(id ORDER BY id) as ids,
               COUNT(*) as cnt
        FROM riders
        WHERE firstname IS NOT NULL AND firstname != ''
          AND lastname IS NOT NULL AND lastname != ''
        GROUP BY UPPER(firstname), UPPER(lastname)
        HAVING cnt > 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($nameDuplicates as $dup) {
        $ids = explode(',', $dup['ids']);
        if (count($ids) < 2) continue;

        // Get rider details
        $riders = [];
        foreach ($ids as $id) {
            $r = $db->getRow("
                SELECT r.*, (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count
                FROM riders r WHERE r.id = ?
            ", [(int)$id]);
            if ($r) {
                $r['score'] = $r['result_count'] * 10;
                $r['uci_digits'] = preg_replace('/[^0-9]/', '', $r['license_number'] ?? '');
                if (!empty($r['uci_digits']) && strlen($r['uci_digits']) >= 8) $r['score'] += 100;
                if (!empty($r['birth_year'])) $r['score'] += 5;
                if (!empty($r['email'])) $r['score'] += 5;
                if (!empty($r['club_id'])) $r['score'] += 5;
                $riders[] = $r;
            }
        }

        if (count($riders) < 2) continue;

        // Check for UCI conflicts - if different real UCIs, skip (different people)
        $ucis = array_filter(array_column($riders, 'uci_digits'), fn($u) => strlen($u) >= 8);
        $uniqueUcis = array_unique($ucis);
        if (count($uniqueUcis) > 1) {
            // Different UCI-IDs = different people with same name, skip
            continue;
        }

        // Sort by score desc
        usort($riders, fn($a, $b) => $b['score'] - $a['score']);

        $keep = array_shift($riders);
        $keepId = $keep['id'];

        foreach ($riders as $remove) {
            try {
                $pdo->beginTransaction();

                // Move results
                $stmt = $pdo->prepare("UPDATE results SET cyclist_id = ? WHERE cyclist_id = ?");
                $stmt->execute([$keepId, $remove['id']]);
                $resultsMoved += $stmt->rowCount();

                // Move series_results
                $pdo->prepare("UPDATE series_results SET cyclist_id = ? WHERE cyclist_id = ?")->execute([$keepId, $remove['id']]);

                // Copy missing data (including UCI if keep doesn't have one)
                $updates = [];
                if (empty($keep['birth_year']) && !empty($remove['birth_year'])) {
                    $updates['birth_year'] = $remove['birth_year'];
                    $keep['birth_year'] = $remove['birth_year'];
                }
                if (empty($keep['email']) && !empty($remove['email'])) {
                    $updates['email'] = $remove['email'];
                    $keep['email'] = $remove['email'];
                }
                if (empty($keep['club_id']) && !empty($remove['club_id'])) {
                    $updates['club_id'] = $remove['club_id'];
                    $keep['club_id'] = $remove['club_id'];
                }
                if (empty($keep['uci_digits']) && !empty($remove['uci_digits'])) {
                    $updates['license_number'] = $remove['license_number'];
                    $keep['license_number'] = $remove['license_number'];
                }
                if (!empty($updates)) {
                    $db->update('riders', $updates, 'id = ?', [$keepId]);
                }

                // Delete duplicate
                $pdo->prepare("DELETE FROM riders WHERE id = ?")->execute([$remove['id']]);

                $pdo->commit();
                $mergeCount++;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors++;
            }
        }
    }

    $message = "Klart! Slog ihop {$mergeCount} dubletter, flyttade {$resultsMoved} resultat.";
    if ($errors > 0) {
        $message .= " ({$errors} fel)";
        $messageType = 'warning';
    } else {
        $messageType = 'success';
    }
}

// Count current duplicates
$uciDupCount = $pdo->query("
    SELECT COUNT(*) as cnt FROM (
        SELECT REPLACE(REPLACE(license_number, ' ', ''), '-', '') as uci
        FROM riders
        WHERE license_number IS NOT NULL AND license_number != ''
          AND LENGTH(REPLACE(REPLACE(license_number, ' ', ''), '-', '')) >= 8
        GROUP BY uci
        HAVING COUNT(*) > 1
    ) t
")->fetch()['cnt'] ?? 0;

$nameDupCount = $pdo->query("
    SELECT COUNT(*) as cnt FROM (
        SELECT UPPER(firstname), UPPER(lastname)
        FROM riders
        WHERE firstname IS NOT NULL AND firstname != ''
          AND lastname IS NOT NULL AND lastname != ''
        GROUP BY UPPER(firstname), UPPER(lastname)
        HAVING COUNT(*) > 1
    ) t
")->fetch()['cnt'] ?? 0;

// Get total extra riders (duplicates that would be removed)
$totalUciExtra = $pdo->query("
    SELECT SUM(cnt - 1) as total FROM (
        SELECT COUNT(*) as cnt
        FROM riders
        WHERE license_number IS NOT NULL AND license_number != ''
          AND LENGTH(REPLACE(REPLACE(license_number, ' ', ''), '-', '')) >= 8
        GROUP BY REPLACE(REPLACE(license_number, ' ', ''), '-', '')
        HAVING cnt > 1
    ) t
")->fetch()['total'] ?? 0;

$totalNameExtra = $pdo->query("
    SELECT SUM(cnt - 1) as total FROM (
        SELECT COUNT(*) as cnt
        FROM riders
        WHERE firstname IS NOT NULL AND firstname != ''
          AND lastname IS NOT NULL AND lastname != ''
        GROUP BY UPPER(firstname), UPPER(lastname)
        HAVING cnt > 1
    ) t
")->fetch()['total'] ?? 0;

// Sample duplicates for preview
$sampleUci = $pdo->query("
    SELECT REPLACE(REPLACE(license_number, ' ', ''), '-', '') as uci,
           GROUP_CONCAT(CONCAT(firstname, ' ', lastname) ORDER BY id SEPARATOR ' | ') as names,
           GROUP_CONCAT(id ORDER BY id) as ids,
           COUNT(*) as cnt
    FROM riders
    WHERE license_number IS NOT NULL AND license_number != ''
      AND LENGTH(REPLACE(REPLACE(license_number, ' ', ''), '-', '')) >= 8
    GROUP BY uci
    HAVING cnt > 1
    ORDER BY cnt DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$sampleName = $pdo->query("
    SELECT UPPER(firstname) as fn, UPPER(lastname) as ln,
           GROUP_CONCAT(CONCAT(firstname, ' ', lastname) ORDER BY id SEPARATOR ' | ') as names,
           GROUP_CONCAT(id ORDER BY id) as ids,
           GROUP_CONCAT(COALESCE(license_number, '-') ORDER BY id SEPARATOR ' | ') as ucis,
           COUNT(*) as cnt
    FROM riders
    WHERE firstname IS NOT NULL AND firstname != ''
      AND lastname IS NOT NULL AND lastname != ''
    GROUP BY UPPER(firstname), UPPER(lastname)
    HAVING cnt > 1
    ORDER BY cnt DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Bulk-sammanslagning av dubletter';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools'],
    ['label' => 'Bulk-sammanslagning']
];
include __DIR__ . '/components/unified-layout.php';
?>

<h1 class="text-primary mb-lg">
    <i data-lucide="git-merge"></i> Bulk-sammanslagning av dubletter
</h1>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-lg">
    <?= h($message) ?>
</div>
<?php endif; ?>

<div class="alert alert-warning mb-lg">
    <i data-lucide="alert-triangle"></i>
    <strong>OBS!</strong> Detta verktyg slår ihop ALLA hittade dubletter automatiskt.
    Den bästa profilen (flest resultat + mest data) behålls.
    Par med olika UCI-ID hoppas över (antas vara olika personer med samma namn).
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md-grid-cols-4 gap-md mb-lg">
    <div class="stat-card">
        <div class="stat-number text-danger"><?= $uciDupCount ?></div>
        <div class="stat-label">UCI-dubbletter</div>
        <div class="text-xs text-muted"><?= $totalUciExtra ?> extra poster</div>
    </div>
    <div class="stat-card">
        <div class="stat-number text-warning"><?= $nameDupCount ?></div>
        <div class="stat-label">Namn-dubbletter</div>
        <div class="text-xs text-muted"><?= $totalNameExtra ?> extra poster</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $totalUciExtra + $totalNameExtra ?></div>
        <div class="stat-label">Totalt att ta bort</div>
    </div>
    <div class="stat-card">
        <form method="POST">
            <?= csrf_field() ?>
            <button type="submit" name="merge_all" class="btn btn-danger btn-lg w-full"
                    onclick="return confirm('Slå ihop ALLA dubletter?\n\n<?= $totalUciExtra + $totalNameExtra ?> poster kommer att tas bort.\nResultat flyttas till den bästa profilen.')">
                <i data-lucide="git-merge"></i>
                SLÅ IHOP ALLA
            </button>
        </form>
    </div>
</div>

<!-- UCI Duplicates -->
<div class="card mb-lg">
    <div class="card-header">
        <h3><i data-lucide="fingerprint"></i> UCI-dubletter (<?= $uciDupCount ?> grupper)</h3>
    </div>
    <div class="card-body gs-padding-0">
        <?php if (empty($sampleUci)): ?>
        <div class="alert alert-success m-md">Inga UCI-dubletter!</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>UCI-ID</th>
                        <th>Namn</th>
                        <th>IDs</th>
                        <th>Antal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sampleUci as $dup): ?>
                    <tr>
                        <td><code><?= h($dup['uci']) ?></code></td>
                        <td><?= h($dup['names']) ?></td>
                        <td><code><?= h($dup['ids']) ?></code></td>
                        <td><span class="badge badge-danger"><?= $dup['cnt'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Name Duplicates -->
<div class="card mb-lg">
    <div class="card-header">
        <h3><i data-lucide="users"></i> Namn-dubletter (<?= $nameDupCount ?> grupper)</h3>
    </div>
    <div class="card-body gs-padding-0">
        <?php if (empty($sampleName)): ?>
        <div class="alert alert-success m-md">Inga namn-dubletter!</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Namn</th>
                        <th>UCI-IDs</th>
                        <th>IDs</th>
                        <th>Antal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sampleName as $dup): ?>
                    <tr>
                        <td><strong><?= h($dup['fn'] . ' ' . $dup['ln']) ?></strong></td>
                        <td><code class="text-xs"><?= h($dup['ucis']) ?></code></td>
                        <td><code><?= h($dup['ids']) ?></code></td>
                        <td><span class="badge badge-warning"><?= $dup['cnt'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>
<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
