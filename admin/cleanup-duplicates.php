<?php
/**
 * Admin tool to find and merge duplicate riders
 * Duplicates often occur due to UCI-ID format differences (spaces vs no spaces)
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Handle merge action FIRST (before querying duplicates)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_riders'])) {
    checkCsrf();

    $keepId = (int)($_POST['keep_id'] ?? 0);
    $mergeIdsRaw = $_POST['merge_ids'] ?? '';

    // Debug: Log incoming data
    error_log("Merge attempt: keep_id=$keepId, merge_ids_raw='$mergeIdsRaw'");

    // Split and convert to integers
    $parts = explode(',', $mergeIdsRaw);
    $mergeIds = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $mergeIds[] = intval($part);
        }
    }

    // Remove the keep_id from merge list
    $filtered = [];
    foreach ($mergeIds as $id) {
        if ($id !== $keepId && $id > 0) {
            $filtered[] = $id;
        }
    }
    $mergeIds = $filtered;

    error_log("After filtering: mergeIds=" . json_encode($mergeIds));

    if ($keepId && !empty($mergeIds)) {
        try {
            $db->pdo->beginTransaction();

            // Get the rider to keep
            $keepRider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$keepId]);

            error_log("Keep rider: " . ($keepRider ? $keepRider['firstname'] . ' ' . $keepRider['lastname'] : 'NOT FOUND'));

            if ($keepRider) {
                // Update all results to point to the kept rider
                $resultsUpdated = 0;
                $resultsDeleted = 0;

                foreach ($mergeIds as $oldId) {
                    // Get results for this duplicate rider
                    $oldResults = $db->getAll(
                        "SELECT id, event_id FROM results WHERE cyclist_id = ?",
                        [$oldId]
                    );

                    foreach ($oldResults as $oldResult) {
                        // Check if kept rider already has result for this event
                        $existing = $db->getRow(
                            "SELECT id FROM results WHERE cyclist_id = ? AND event_id = ?",
                            [$keepId, $oldResult['event_id']]
                        );

                        if ($existing) {
                            // Delete the duplicate result (keep the one from the primary rider)
                            $db->query("DELETE FROM results WHERE id = ?", [$oldResult['id']]);
                            $resultsDeleted++;
                        } else {
                            // Move the result to the kept rider
                            $db->query(
                                "UPDATE results SET cyclist_id = ? WHERE id = ?",
                                [$keepId, $oldResult['id']]
                            );
                            $resultsUpdated++;
                        }
                    }
                }

                // Delete the duplicate riders
                $placeholders = implode(',', array_fill(0, count($mergeIds), '?'));
                $db->query("DELETE FROM riders WHERE id IN ($placeholders)", $mergeIds);

                $db->pdo->commit();

                $msg = "Sammanfogade " . count($mergeIds) . " deltagare till " . $keepRider['firstname'] . " " . $keepRider['lastname'];
                $msg .= " ($resultsUpdated resultat flyttade";
                if ($resultsDeleted > 0) {
                    $msg .= ", $resultsDeleted dubbletter borttagna";
                }
                $msg .= ")";
                $_SESSION['cleanup_message'] = $msg;
                $_SESSION['cleanup_message_type'] = 'success';
            } else {
                $db->pdo->rollBack();
                $_SESSION['cleanup_message'] = "Kunde inte hitta deltagare med ID: " . $keepId;
                $_SESSION['cleanup_message_type'] = 'error';
            }
        } catch (Exception $e) {
            if ($db->pdo->inTransaction()) {
                $db->pdo->rollBack();
            }
            $_SESSION['cleanup_message'] = "Fel vid sammanfogning: " . $e->getMessage();
            $_SESSION['cleanup_message_type'] = 'error';
        }
    } else {
        $debugInfo = "keep_id: $keepId, merge_ids_raw: '$mergeIdsRaw', merge_ids_filtered: [" . implode(',', $mergeIds) . "]";
        $_SESSION['cleanup_message'] = "Ogiltiga parametrar för sammanfogning. $debugInfo";
        $_SESSION['cleanup_message_type'] = 'error';
    }

    // Refresh duplicate lists
    header('Location: /admin/cleanup-duplicates.php');
    exit;
}

// Handle normalize UCI-IDs action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['normalize_all'])) {
    checkCsrf();

    try {
        // Normalize all UCI-IDs by removing spaces and dashes
        $updated = $db->query("
            UPDATE riders
            SET license_number = REPLACE(REPLACE(license_number, ' ', ''), '-', '')
            WHERE license_number IS NOT NULL
            AND license_number != ''
            AND (license_number LIKE '% %' OR license_number LIKE '%-%')
        ");

        // Store message in session and redirect
        $_SESSION['cleanup_message'] = "Normaliserade UCI-ID format för alla deltagare";
        $_SESSION['cleanup_message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['cleanup_message'] = "Fel vid normalisering: " . $e->getMessage();
        $_SESSION['cleanup_message_type'] = 'error';
    }

    header('Location: /admin/cleanup-duplicates.php');
    exit;
}

// Handle auto merge ALL duplicates action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_merge_all'])) {
    checkCsrf();

    try {
        $db->pdo->beginTransaction();

        // Find all duplicates by normalized UCI-ID
        $duplicates = $db->getAll("
            SELECT
                REPLACE(REPLACE(license_number, ' ', ''), '-', '') as normalized_uci,
                GROUP_CONCAT(id ORDER BY
                    (SELECT COUNT(*) FROM results WHERE cyclist_id = riders.id) DESC,
                    CASE WHEN club_id IS NOT NULL THEN 0 ELSE 1 END,
                    CASE WHEN birth_year IS NOT NULL THEN 0 ELSE 1 END,
                    created_at ASC
                ) as ids
            FROM riders
            WHERE license_number IS NOT NULL
            AND license_number != ''
            GROUP BY normalized_uci
            HAVING COUNT(*) > 1
        ");

        $totalMerged = 0;
        $totalResultsMoved = 0;
        $totalResultsDeleted = 0;
        $ridersDeleted = 0;

        foreach ($duplicates as $dup) {
            $ids = array_map('intval', explode(',', $dup['ids']));
            if (count($ids) < 2) continue;

            $keepId = $ids[0]; // First ID has most results (ordered by COUNT DESC)
            array_shift($ids); // Remove keep ID from list

            foreach ($ids as $oldId) {
                // Get results for duplicate rider
                $oldResults = $db->getAll(
                    "SELECT id, event_id FROM results WHERE cyclist_id = ?",
                    [$oldId]
                );

                foreach ($oldResults as $oldResult) {
                    // Check if kept rider already has result for this event
                    $existing = $db->getRow(
                        "SELECT id FROM results WHERE cyclist_id = ? AND event_id = ?",
                        [$keepId, $oldResult['event_id']]
                    );

                    if ($existing) {
                        // Delete duplicate result
                        $db->query("DELETE FROM results WHERE id = ?", [$oldResult['id']]);
                        $totalResultsDeleted++;
                    } else {
                        // Move result to kept rider
                        $db->query(
                            "UPDATE results SET cyclist_id = ? WHERE id = ?",
                            [$keepId, $oldResult['id']]
                        );
                        $totalResultsMoved++;
                    }
                }

                // Delete the duplicate rider
                $db->query("DELETE FROM riders WHERE id = ?", [$oldId]);
                $ridersDeleted++;
            }

            $totalMerged++;
        }

        $db->pdo->commit();

        $msg = "Automatisk sammanslagning klar: ";
        $msg .= "$totalMerged dubblettgrupper, ";
        $msg .= "$ridersDeleted åkare borttagna, ";
        $msg .= "$totalResultsMoved resultat flyttade";
        if ($totalResultsDeleted > 0) {
            $msg .= ", $totalResultsDeleted dubbletter borttagna";
        }

        $_SESSION['cleanup_message'] = $msg;
        $_SESSION['cleanup_message_type'] = 'success';

    } catch (Exception $e) {
        if ($db->pdo->inTransaction()) {
            $db->pdo->rollBack();
        }
        $_SESSION['cleanup_message'] = "Fel vid automatisk sammanslagning: " . $e->getMessage();
        $_SESSION['cleanup_message_type'] = 'error';
    }

    header('Location: /admin/cleanup-duplicates.php');
    exit;
}

// Check for message from redirect
if (isset($_SESSION['cleanup_message'])) {
    $message = $_SESSION['cleanup_message'];
    $messageType = $_SESSION['cleanup_message_type'] ?? 'info';
    unset($_SESSION['cleanup_message'], $_SESSION['cleanup_message_type']);
}

// Find duplicate riders by normalized UCI-ID
$duplicatesByUci = $db->getAll("
    SELECT
        REPLACE(REPLACE(license_number, ' ', ''), '-', '') as normalized_uci,
        GROUP_CONCAT(id ORDER BY
            CASE WHEN club_id IS NOT NULL THEN 0 ELSE 1 END,
            CASE WHEN birth_year IS NOT NULL THEN 0 ELSE 1 END,
            created_at ASC
        ) as ids,
        GROUP_CONCAT(CONCAT(firstname, ' ', lastname) SEPARATOR ' | ') as names,
        COUNT(*) as count
    FROM riders
    WHERE license_number IS NOT NULL AND license_number != ''
    GROUP BY normalized_uci
    HAVING count > 1
    ORDER BY count DESC
");

// Find duplicate riders by name (exact match)
// Only show duplicates where ALL have same normalized UCI-ID or ALL have no UCI-ID
// Never show same name with different UCI-IDs (those are different people)
$duplicatesByNameRaw = $db->getAll("
    SELECT
        CONCAT(LOWER(firstname), '|', LOWER(lastname)) as name_key,
        GROUP_CONCAT(id ORDER BY
            CASE WHEN license_number IS NOT NULL AND license_number != '' THEN 0 ELSE 1 END,
            CASE WHEN club_id IS NOT NULL THEN 0 ELSE 1 END,
            created_at ASC
        ) as ids,
        GROUP_CONCAT(COALESCE(REPLACE(REPLACE(license_number, ' ', ''), '-', ''), '') SEPARATOR '|') as normalized_licenses,
        MIN(firstname) as firstname,
        MIN(lastname) as lastname,
        COUNT(*) as count
    FROM riders
    GROUP BY name_key
    HAVING count > 1
    ORDER BY count DESC
");

// Filter out entries where people have different UCI-IDs (those are different people)
$duplicatesByName = [];
foreach ($duplicatesByNameRaw as $dup) {
    $licenses = array_filter(explode('|', $dup['normalized_licenses']), fn($l) => $l !== '');

    // If there are different UCI-IDs, these are different people - skip
    if (count($licenses) > 1) {
        $uniqueLicenses = array_unique($licenses);
        if (count($uniqueLicenses) > 1) {
            // Different UCI-IDs = different people, not duplicates
            continue;
        }
    }

    $duplicatesByName[] = $dup;
}

$pageTitle = 'Rensa dubbletter';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h1 gs-text-primary gs-mb-lg">
            <i data-lucide="copy-x"></i>
            Rensa dubbletter
        </h1>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Normalize All UCI-IDs -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="wand-2"></i>
                    Normalisera UCI-ID format
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-secondary gs-mb-md">
                    Ta bort alla mellanslag och bindestreck från UCI-ID:n för att förhindra framtida dubbletter.
                    <br><strong>Exempel:</strong> "101 089 432 09" blir "10108943209"
                </p>
                <div class="gs-flex gs-gap-md">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <button type="submit" name="normalize_all" class="gs-btn gs-btn-primary">
                            <i data-lucide="zap"></i>
                            Normalisera alla UCI-ID
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Detta kommer automatiskt sammanfoga ALLA dubbletter. Åkaren med flest resultat behålls. Fortsätt?');">
                        <?= csrf_field() ?>
                        <button type="submit" name="auto_merge_all" class="gs-btn gs-btn-warning">
                            <i data-lucide="git-merge"></i>
                            Sammanfoga alla automatiskt
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Duplicates by UCI-ID -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="fingerprint"></i>
                    Dubbletter via UCI-ID (<?= count($duplicatesByUci) ?>)
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($duplicatesByUci)): ?>
                    <div class="gs-alert gs-alert-success">
                        <i data-lucide="check"></i>
                        Inga dubbletter hittades baserat på UCI-ID
                    </div>
                <?php else: ?>
                    <div class="gs-table-responsive" style="max-height: 400px; overflow: auto;">
                        <table class="gs-table gs-table-sm">
                            <thead>
                                <tr>
                                    <th>UCI-ID</th>
                                    <th>Namn</th>
                                    <th>Antal</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($duplicatesByUci as $dup): ?>
                                    <?php
                                    $ids = explode(',', $dup['ids']);
                                    $riders = $db->getAll(
                                        "SELECT id, firstname, lastname, license_number, birth_year, club_id,
                                         (SELECT name FROM clubs WHERE id = riders.club_id) as club_name,
                                         (SELECT COUNT(*) FROM results WHERE cyclist_id = riders.id) as results_count
                                         FROM riders WHERE id IN (" . implode(',', $ids) . ")
                                         ORDER BY FIELD(id, " . implode(',', $ids) . ")"
                                    );
                                    ?>
                                    <tr>
                                        <td><code><?= h($dup['normalized_uci']) ?></code></td>
                                        <td>
                                            <?php foreach ($riders as $i => $rider): ?>
                                                <div class="gs-mb-sm <?= $i === 0 ? 'gs-text-success' : 'gs-text-secondary' ?>">
                                                    <strong><?= h($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                                                    <?php if ($rider['club_name']): ?>
                                                        <span class="gs-text-xs">(<?= h($rider['club_name']) ?>)</span>
                                                    <?php endif; ?>
                                                    <span class="gs-badge gs-badge-sm <?= $i === 0 ? 'gs-badge-success' : 'gs-badge-secondary' ?>">
                                                        <?= $rider['results_count'] ?> resultat
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td><?= $dup['count'] ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Sammanfoga dessa deltagare? Alla resultat flyttas till den första.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="keep_id" value="<?= $ids[0] ?>">
                                                <input type="hidden" name="merge_ids" value="<?= $dup['ids'] ?>">
                                                <button type="submit" name="merge_riders" class="gs-btn gs-btn-sm gs-btn-warning">
                                                    <i data-lucide="merge"></i>
                                                    Sammanfoga
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Duplicates by Name -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="users"></i>
                    Dubbletter via namn (<?= count($duplicatesByName) ?>)
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($duplicatesByName)): ?>
                    <div class="gs-alert gs-alert-success">
                        <i data-lucide="check"></i>
                        Inga dubbletter hittades baserat på namn
                    </div>
                <?php else: ?>
                    <p class="gs-text-sm gs-text-secondary gs-mb-md">
                        <strong>Varning:</strong> Dubbletter via namn kan vara olika personer med samma namn.
                        Kontrollera UCI-ID och klubb innan sammanfogning.
                    </p>
                    <div class="gs-table-responsive" style="max-height: 400px; overflow: auto;">
                        <table class="gs-table gs-table-sm">
                            <thead>
                                <tr>
                                    <th>Namn</th>
                                    <th>UCI-ID</th>
                                    <th>Antal</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($duplicatesByName, 0, 50) as $dup): ?>
                                    <?php
                                    $ids = explode(',', $dup['ids']);
                                    $riders = $db->getAll(
                                        "SELECT id, firstname, lastname, license_number, birth_year, club_id,
                                         (SELECT name FROM clubs WHERE id = riders.club_id) as club_name,
                                         (SELECT COUNT(*) FROM results WHERE cyclist_id = riders.id) as results_count
                                         FROM riders WHERE id IN (" . implode(',', $ids) . ")
                                         ORDER BY FIELD(id, " . implode(',', $ids) . ")"
                                    );
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($dup['firstname'] . ' ' . $dup['lastname']) ?></strong>
                                        </td>
                                        <td>
                                            <?php foreach ($riders as $i => $rider): ?>
                                                <div class="gs-text-xs <?= $i === 0 ? 'gs-text-success' : 'gs-text-secondary' ?>">
                                                    <?= $rider['license_number'] ?: '<em>ingen</em>' ?>
                                                    <?php if ($rider['club_name']): ?>
                                                        (<?= h($rider['club_name']) ?>)
                                                    <?php endif; ?>
                                                    <span class="gs-badge gs-badge-xs"><?= $rider['results_count'] ?> res</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td><?= $dup['count'] ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Sammanfoga dessa deltagare? Kontrollera att det verkligen är samma person!');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="keep_id" value="<?= $ids[0] ?>">
                                                <input type="hidden" name="merge_ids" value="<?= $dup['ids'] ?>">
                                                <button type="submit" name="merge_riders" class="gs-btn gs-btn-sm gs-btn-outline">
                                                    <i data-lucide="merge"></i>
                                                    Sammanfoga
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($duplicatesByName) > 50): ?>
                        <p class="gs-text-sm gs-text-secondary gs-mt-md">
                            Visar 50 av <?= count($duplicatesByName) ?> dubbletter
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="gs-mt-lg">
            <a href="/admin/import.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka till import
            </a>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
