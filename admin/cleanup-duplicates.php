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

// Handle merge action via GET (POST doesn't work on InfinityFree)
if (isset($_GET['action']) && $_GET['action'] === 'merge') {
    $keepId = (int)($_GET['keep'] ?? 0);
    $mergeIdsRaw = $_GET['remove'] ?? '';

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

            if (!$keepRider) {
                throw new Exception("Förare med ID $keepId hittades inte i databasen");
            }

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
                        $db->delete('results', 'id = ?', [$oldResult['id']]);
                        $resultsDeleted++;
                    } else {
                        // Move the result to the kept rider
                        $db->update('results', ['cyclist_id' => $keepId], 'id = ?', [$oldResult['id']]);
                        $resultsUpdated++;
                    }
                }
            }

            // Delete the duplicate riders
            foreach ($mergeIds as $mergeId) {
                $db->delete('riders', 'id = ?', [$mergeId]);
            }

            $db->pdo->commit();

            $msg = "Sammanfogade " . count($mergeIds) . " deltagare till " . $keepRider['firstname'] . " " . $keepRider['lastname'];
            $msg .= " ($resultsUpdated resultat flyttade";
            if ($resultsDeleted > 0) {
                $msg .= ", $resultsDeleted dubbletter borttagna";
            }
            $msg .= ")";
            $_SESSION['cleanup_message'] = $msg;
            $_SESSION['cleanup_message_type'] = 'success';
        } catch (Exception $e) {
            if ($db->pdo->inTransaction()) {
                $db->pdo->rollBack();
            }
            $_SESSION['cleanup_message'] = "Fel vid sammanfogning: " . $e->getMessage();
            $_SESSION['cleanup_message_type'] = 'error';
        }
    } else {
        $_SESSION['cleanup_message'] = "Sammanfogning kunde inte utföras. keep_id=$keepId, merge_ids_raw='$mergeIdsRaw', merge_ids_filtered=" . json_encode($mergeIds);
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
        $ridersToNormalize = $db->getAll("
            SELECT id, license_number
            FROM riders
            WHERE license_number IS NOT NULL
            AND license_number != ''
            AND (license_number LIKE '% %' OR license_number LIKE '%-%')
        ");

        $updated = 0;
        foreach ($ridersToNormalize as $rider) {
            $normalized = str_replace([' ', '-'], '', $rider['license_number']);
            $db->update('riders', ['license_number' => $normalized], 'id = ?', [$rider['id']]);
            $updated++;
        }

        // Store message in session and redirect
        $_SESSION['cleanup_message'] = "Normaliserade UCI-ID format för $updated deltagare";
        $_SESSION['cleanup_message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['cleanup_message'] = "Fel vid normalisering: " . $e->getMessage();
        $_SESSION['cleanup_message_type'] = 'error';
    }

    header('Location: /admin/cleanup-duplicates.php');
    exit;
}

// Handle assign SWE-IDs to riders without license numbers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_swe_ids'])) {
    checkCsrf();

    try {
        // Find all riders without license numbers
        $ridersWithoutId = $db->getAll("
            SELECT id, firstname, lastname
            FROM riders
            WHERE license_number IS NULL OR license_number = ''
            ORDER BY id ASC
        ");

        $updated = 0;
        foreach ($ridersWithoutId as $rider) {
            $sweId = generateSweLicenseNumber($db);
            $db->update('riders', ['license_number' => $sweId], 'id = ?', [$rider['id']]);
            $updated++;
        }

        $_SESSION['cleanup_message'] = "Tilldelade SWE-ID till $updated förare som saknade licensnummer";
        $_SESSION['cleanup_message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['cleanup_message'] = "Fel vid tilldelning av SWE-ID: " . $e->getMessage();
        $_SESSION['cleanup_message_type'] = 'error';
    }

    header('Location: /admin/cleanup-duplicates.php');
    exit;
}

// Handle auto merge by last name + birth year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_merge_by_lastname_year'])) {
    checkCsrf();

    try {
        $db->pdo->beginTransaction();

        // Find riders with same lastname + birth_year but different firstnames
        $duplicates = $db->getAll("
            SELECT
                LOWER(lastname) as lastname_key,
                birth_year,
                GROUP_CONCAT(id ORDER BY
                    (SELECT COUNT(*) FROM results WHERE cyclist_id = riders.id) DESC,
                    CASE WHEN license_number IS NOT NULL AND license_number != '' THEN 0 ELSE 1 END,
                    created_at ASC
                ) as ids,
                COUNT(*) as count
            FROM riders
            WHERE birth_year IS NOT NULL
            GROUP BY lastname_key, birth_year
            HAVING count > 1
        ");

        $totalMerged = 0;
        $totalDeleted = 0;

        foreach ($duplicates as $dup) {
            $ids = array_map('intval', explode(',', $dup['ids']));
            if (count($ids) < 2) continue;

            $keepId = array_shift($ids); // First one (most results) to keep
            $keepRider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$keepId]);

            if (!$keepRider) continue;

            foreach ($ids as $oldId) {
                // Move all results
                $oldResults = $db->getAll("SELECT id, event_id FROM results WHERE cyclist_id = ?", [$oldId]);

                foreach ($oldResults as $oldResult) {
                    $existing = $db->getRow(
                        "SELECT id FROM results WHERE cyclist_id = ? AND event_id = ?",
                        [$keepId, $oldResult['event_id']]
                    );

                    if ($existing) {
                        $db->delete('results', 'id = ?', [$oldResult['id']]);
                    } else {
                        $db->update('results', ['cyclist_id' => $keepId], 'id = ?', [$oldResult['id']]);
                        $totalMerged++;
                    }
                }

                // Delete the duplicate rider
                $db->delete('riders', 'id = ?', [$oldId]);
                $totalDeleted++;
            }
        }

        $db->pdo->commit();

        $_SESSION['cleanup_message'] = "Auto-sammanslagning klar! $totalMerged resultat flyttade, $totalDeleted dubbletter borttagna.";
        $_SESSION['cleanup_message_type'] = 'success';
    } catch (Exception $e) {
        if ($db->pdo->inTransaction()) {
            $db->pdo->rollBack();
        }
        $_SESSION['cleanup_message'] = "Fel vid auto-sammanslagning: " . $e->getMessage();
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
                        $db->delete('results', 'id = ?', [$oldResult['id']]);
                        $totalResultsDeleted++;
                    } else {
                        // Move result to kept rider
                        $db->update('results', ['cyclist_id' => $keepId], 'id = ?', [$oldResult['id']]);
                        $totalResultsMoved++;
                    }
                }

                // Delete the duplicate rider
                $db->delete('riders', 'id = ?', [$oldId]);
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

// Handle normalize names action (proper case)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['normalize_names'])) {
    checkCsrf();

    try {
        // Get all riders with names that need normalization
        $riders = $db->getAll("
            SELECT id, firstname, lastname
            FROM riders
            WHERE firstname IS NOT NULL OR lastname IS NOT NULL
        ");

        $updated = 0;
        foreach ($riders as $rider) {
            $newFirstname = $rider['firstname'];
            $newLastname = $rider['lastname'];
            $needsUpdate = false;

            // Normalize firstname
            if ($rider['firstname']) {
                $normalized = mb_convert_case(mb_strtolower($rider['firstname'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
                if ($normalized !== $rider['firstname']) {
                    $newFirstname = $normalized;
                    $needsUpdate = true;
                }
            }

            // Normalize lastname
            if ($rider['lastname']) {
                $normalized = mb_convert_case(mb_strtolower($rider['lastname'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
                if ($normalized !== $rider['lastname']) {
                    $newLastname = $normalized;
                    $needsUpdate = true;
                }
            }

            if ($needsUpdate) {
                $db->update('riders', [
                    'firstname' => $newFirstname,
                    'lastname' => $newLastname
                ], 'id = ?', [$rider['id']]);
                $updated++;
            }
        }

        $_SESSION['cleanup_message'] = "Normaliserade namn för $updated deltagare till versalgemener";
        $_SESSION['cleanup_message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['cleanup_message'] = "Fel vid normalisering av namn: " . $e->getMessage();
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

// Count riders without license numbers
$ridersWithoutId = $db->getRow("
    SELECT COUNT(*) as count
    FROM riders
    WHERE license_number IS NULL OR license_number = ''
");
$ridersWithoutIdCount = $ridersWithoutId['count'] ?? 0;

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

// Find potential duplicates using fuzzy matching
// Matches names that share words regardless of which field they're in
$potentialDuplicates = $db->getAll("
    SELECT
        r1.id as id1,
        r1.firstname as firstname1,
        r1.lastname as lastname1,
        r1.license_number as license1,
        r1.birth_year as birth_year1,
        r2.id as id2,
        r2.firstname as firstname2,
        r2.lastname as lastname2,
        r2.license_number as license2,
        r2.birth_year as birth_year2,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r1.id) as results1,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r2.id) as results2
    FROM riders r1
    JOIN riders r2 ON r1.id < r2.id
    WHERE (
        -- Same lastname OR lastname appears anywhere in other's full name
        LOWER(r1.lastname) = LOWER(r2.lastname)
        OR LOWER(CONCAT(r2.firstname, ' ', r2.lastname)) LIKE CONCAT('%', LOWER(r1.lastname), '%')
        OR LOWER(CONCAT(r1.firstname, ' ', r1.lastname)) LIKE CONCAT('%', LOWER(r2.lastname), '%')
        -- Or names swapped between fields
        OR (LOWER(r1.firstname) = LOWER(r2.lastname) AND LOWER(r1.lastname) = LOWER(r2.firstname))
    )
    AND (
        -- And share firstname component
        LEFT(LOWER(r1.firstname), 3) = LEFT(LOWER(r2.firstname), 3)
        OR LOWER(r1.firstname) LIKE CONCAT('%', LOWER(r2.firstname), '%')
        OR LOWER(r2.firstname) LIKE CONCAT('%', LOWER(r1.firstname), '%')
        -- Or firstname appears in other's full name
        OR LOWER(CONCAT(r2.firstname, ' ', r2.lastname)) LIKE CONCAT('%', LOWER(r1.firstname), '%')
        OR LOWER(CONCAT(r1.firstname, ' ', r1.lastname)) LIKE CONCAT('%', LOWER(r2.firstname), '%')
    )
    AND NOT (
        -- Exclude if both have different UCI-IDs (different people)
        r1.license_number IS NOT NULL AND r1.license_number != ''
        AND r2.license_number IS NOT NULL AND r2.license_number != ''
        AND REPLACE(REPLACE(r1.license_number, ' ', ''), '-', '') != REPLACE(REPLACE(r2.license_number, ' ', ''), '-', '')
    )
    AND NOT (
        -- Exclude exact full name matches (already in duplicatesByName)
        LOWER(r1.firstname) = LOWER(r2.firstname) AND LOWER(r1.lastname) = LOWER(r2.lastname)
    )
    ORDER BY r1.lastname, r1.firstname
    LIMIT 200
");

// Handle search for riders
$searchQuery = $_GET['search'] ?? '';
$searchResults = [];
if (!empty($searchQuery)) {
    $searchTerm = '%' . $searchQuery . '%';
    $searchResults = $db->getAll("
        SELECT id, firstname, lastname, license_number, birth_year,
               (SELECT COUNT(*) FROM results WHERE cyclist_id = riders.id) as result_count
        FROM riders
        WHERE firstname LIKE ? OR lastname LIKE ?
           OR CONCAT(firstname, ' ', lastname) LIKE ?
           OR CONCAT(lastname, ' ', firstname) LIKE ?
        ORDER BY lastname, firstname
        LIMIT 50
    ", [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
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

        <!-- Manual Merge -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="git-merge"></i>
                    Manuell sammanslagning
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-secondary gs-mb-md">
                    Slå ihop två förare genom att ange deras ID-nummer. Förare 1 behålls, förare 2:s resultat flyttas dit.
                </p>
                <div class="gs-flex gs-gap-md gs-items-end">
                    <div>
                        <label class="gs-label">Behåll (ID)</label>
                        <input type="number" id="manual_keep" class="gs-input" placeholder="t.ex. 10624" style="width: 120px;">
                    </div>
                    <div>
                        <label class="gs-label">Ta bort (ID)</label>
                        <input type="number" id="manual_remove" class="gs-input" placeholder="t.ex. 9460" style="width: 120px;">
                    </div>
                    <button type="button" class="gs-btn gs-btn-warning" onclick="doManualMerge()">
                        <i data-lucide="git-merge"></i>
                        Slå ihop
                    </button>
                </div>
                <p class="gs-text-xs gs-text-secondary gs-mt-sm">
                    Tips: Hitta ID i URL:en på förarsidan, t.ex. rider.php?id=<strong>10624</strong>
                </p>
                <script>
                function doManualMerge() {
                    var keep = document.getElementById('manual_keep').value;
                    var remove = document.getElementById('manual_remove').value;
                    if (!keep || !remove) {
                        alert('Fyll i båda ID-fälten');
                        return;
                    }
                    if (confirm('Slå ihop förare ' + remove + ' till ' + keep + '? Detta kan inte ångras.')) {
                        window.location.href = '/admin/cleanup-duplicates.php?action=merge&keep=' + keep + '&remove=' + remove;
                    }
                }
                </script>
            </div>
        </div>

        <!-- Search and Merge -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="search"></i>
                    Sök och slå ihop
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="GET" class="gs-mb-md">
                    <div class="gs-flex gs-gap-md gs-items-end">
                        <div class="gs-flex-1">
                            <label class="gs-label">Sök på namn</label>
                            <input type="text" name="search" class="gs-input" placeholder="t.ex. Andersson" value="<?= h($searchQuery) ?>">
                        </div>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="search"></i>
                            Sök
                        </button>
                    </div>
                </form>

                <?php if (!empty($searchQuery)): ?>
                    <?php if (empty($searchResults)): ?>
                        <div class="gs-alert gs-alert-info">
                            Inga förare hittades för "<?= h($searchQuery) ?>"
                        </div>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirmMerge()">
                            <?= csrf_field() ?>
                            <input type="hidden" name="merge_riders" value="1">

                            <div class="gs-overflow-x-auto gs-mb-md">
                                <table class="gs-table gs-table-compact">
                                    <thead>
                                        <tr>
                                            <th style="width: 60px;">Behåll</th>
                                            <th style="width: 60px;">Ta bort</th>
                                            <th>Namn</th>
                                            <th>ID</th>
                                            <th>Licens</th>
                                            <th>År</th>
                                            <th>Res.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($searchResults as $rider): ?>
                                            <tr>
                                                <td>
                                                    <input type="radio" name="keep_id" value="<?= $rider['id'] ?>">
                                                </td>
                                                <td>
                                                    <input type="checkbox" name="merge_list[]" value="<?= $rider['id'] ?>">
                                                </td>
                                                <td>
                                                    <a href="/rider.php?id=<?= $rider['id'] ?>" target="_blank">
                                                        <?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>
                                                    </a>
                                                </td>
                                                <td class="gs-text-xs"><?= $rider['id'] ?></td>
                                                <td class="gs-text-xs"><?= h($rider['license_number'] ?: '-') ?></td>
                                                <td><?= $rider['birth_year'] ?: '-' ?></td>
                                                <td><?= $rider['result_count'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <button type="submit" class="gs-btn gs-btn-warning">
                                <i data-lucide="git-merge"></i>
                                Slå ihop valda
                            </button>
                            <p class="gs-text-xs gs-text-secondary gs-mt-sm">
                                Välj EN förare att behålla (radio) och markera de som ska tas bort (checkbox)
                            </p>
                        </form>

                        <script>
                        function confirmMerge() {
                            const keepId = document.querySelector('input[name="keep_id"]:checked');
                            const mergeList = document.querySelectorAll('input[name="merge_list[]"]:checked');

                            if (!keepId) {
                                alert('Välj en förare att behålla (radio-knapp)');
                                return false;
                            }
                            if (mergeList.length === 0) {
                                alert('Välj minst en förare att ta bort (checkbox)');
                                return false;
                            }

                            // Build merge_ids string
                            const mergeIds = Array.from(mergeList).map(cb => cb.value).join(',');

                            // Add hidden field with merge_ids
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'merge_ids';
                            input.value = mergeIds;
                            keepId.form.appendChild(input);

                            return confirm('Slå ihop ' + mergeList.length + ' förare till den valda? Detta kan inte ångras.');
                        }
                        </script>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Normalize Names -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="type"></i>
                    Normalisera namn
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-secondary gs-mb-md">
                    Konvertera alla namn till versalgemener (första bokstaven stor, resten små).
                    <br><strong>Exempel:</strong> "JOHAN ANDERSSON" eller "johan andersson" blir "Johan Andersson"
                </p>
                <form method="POST" onsubmit="return confirm('Detta kommer ändra alla deltagarnamn till versalgemener. Fortsätt?');">
                    <?= csrf_field() ?>
                    <button type="submit" name="normalize_names" class="gs-btn gs-btn-primary">
                        <i data-lucide="case-sensitive"></i>
                        Normalisera alla namn
                    </button>
                </form>
            </div>
        </div>

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
                    <form method="POST" onsubmit="return confirm('Detta tilldelar SWE-ID till <?= $ridersWithoutIdCount ?> förare som saknar licensnummer. Fortsätt?');">
                        <?= csrf_field() ?>
                        <button type="submit" name="assign_swe_ids" class="gs-btn gs-btn-success" <?= $ridersWithoutIdCount === 0 ? 'disabled' : '' ?>>
                            <i data-lucide="id-card"></i>
                            Tilldela SWE-ID (<?= $ridersWithoutIdCount ?>)
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Detta sammanfogar alla med samma EFTERNAMN + FÖDELSEÅR. Föraren med flest resultat behålls. Fortsätt?');">
                        <?= csrf_field() ?>
                        <button type="submit" name="auto_merge_by_lastname_year" class="gs-btn gs-btn-success">
                            <i data-lucide="users"></i>
                            Sammanfoga efternamn+år
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Detta kommer automatiskt sammanfoga ALLA dubbletter via UCI-ID. Åkaren med flest resultat behålls. Fortsätt?');">
                        <?= csrf_field() ?>
                        <button type="submit" name="auto_merge_all" class="gs-btn gs-btn-warning">
                            <i data-lucide="git-merge"></i>
                            Sammanfoga UCI-ID
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

        <!-- Potential Duplicates (Fuzzy Matching) -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-warning">
                    <i data-lucide="search"></i>
                    Potentiella dubbletter (<?= count($potentialDuplicates) ?>)
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-secondary gs-mb-md">
                    <i data-lucide="alert-triangle" class="gs-icon-inline"></i>
                    Dessa har liknande namn men inte exakt matchning. Granska manuellt innan sammanslagning.
                </p>
                <?php if (empty($potentialDuplicates)): ?>
                    <div class="gs-alert gs-alert-success">
                        <i data-lucide="check"></i>
                        Inga potentiella dubbletter hittades med fuzzy-matchning!
                    </div>
                <?php else: ?>
                    <div class="gs-overflow-x-auto">
                        <table class="gs-table gs-table-compact">
                            <thead>
                                <tr>
                                    <th>Förare 1</th>
                                    <th>ID/År</th>
                                    <th>Res.</th>
                                    <th>Förare 2</th>
                                    <th>ID/År</th>
                                    <th>Res.</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($potentialDuplicates as $dup): ?>
                                    <tr>
                                        <td>
                                            <a href="/rider.php?id=<?= $dup['id1'] ?>" target="_blank">
                                                <?= h($dup['firstname1'] . ' ' . $dup['lastname1']) ?>
                                            </a>
                                        </td>
                                        <td class="gs-text-xs gs-text-secondary">
                                            <?= h($dup['license1'] ?: '-') ?><br>
                                            <?= $dup['birth_year1'] ?: '-' ?>
                                        </td>
                                        <td><?= $dup['results1'] ?></td>
                                        <td>
                                            <a href="/rider.php?id=<?= $dup['id2'] ?>" target="_blank">
                                                <?= h($dup['firstname2'] . ' ' . $dup['lastname2']) ?>
                                            </a>
                                        </td>
                                        <td class="gs-text-xs gs-text-secondary">
                                            <?= h($dup['license2'] ?: '-') ?><br>
                                            <?= $dup['birth_year2'] ?: '-' ?>
                                        </td>
                                        <td><?= $dup['results2'] ?></td>
                                        <td>
                                            <?php
                                            // Determine which rider to keep (more results wins)
                                            $keepId = $dup['results1'] >= $dup['results2'] ? $dup['id1'] : $dup['id2'];
                                            $mergeId = $dup['results1'] >= $dup['results2'] ? $dup['id2'] : $dup['id1'];
                                            ?>
                                            <form method="POST" class="gs-inline" onsubmit="return confirm('Sammanfoga dessa förare? Föraren med flest resultat behålls.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="merge_riders" value="1">
                                                <input type="hidden" name="keep_id" value="<?= $keepId ?>">
                                                <input type="hidden" name="merge_ids" value="<?= $mergeId ?>">
                                                <button type="submit" class="gs-btn gs-btn-xs gs-btn-warning">
                                                    <i data-lucide="git-merge"></i>
                                                    Slå ihop
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($potentialDuplicates) >= 100): ?>
                        <p class="gs-text-sm gs-text-secondary gs-mt-md">
                            Visar max 100 potentiella dubbletter
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
