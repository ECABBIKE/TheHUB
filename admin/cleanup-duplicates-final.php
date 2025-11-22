<?php
/**
 * Admin tool to find and merge duplicate riders
 * UPPDATERAD: Med smart matching för dubbelt-efternamn
 * 
 * Logik: Två riders matchas om:
 * 1. Samma förnamn (eller liknar mycket)
 * 2. Minst ett efternamn är gemensamt mellan dem
 * 
 * Exempel: "Milton Grundberg" och "Milton Jonsson Grundberg" matchas
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// ============================================
// SMART MATCHING FUNKTION FÖR DUBBELT-EFTERNAMN
// ============================================

/**
 * Splitta namn i förnamn och efternamn(n)
 * "Milton Grundberg" → ['Milton', ['Grundberg']]
 * "Milton Jonsson Grundberg" → ['Milton', ['Jonsson', 'Grundberg']]
 */
function splitName($fullName) {
  if (!$fullName) return ['', []];
  
  $fullName = trim($fullName);
  $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
  
  if (count($parts) < 2) {
    // Bara ett ord - behandla som förnamn
    return [$parts[0] ?? '', []];
  }
  
  // Första ordet är förnamn, resten är efternamn(n)
  $firstName = array_shift($parts);
  $lastNames = $parts;
  
  return [$firstName, $lastNames];
}

/**
 * Normalisera efternamn för jämförelse
 * "Grundberg" → "grundberg"
 */
function normalizeLastName($name) {
  return mb_strtolower(trim($name), 'UTF-8');
}

/**
 * Kontrollera om två personer är samma person
 * baserat på förnamn + minst ett gemensamt efternamn
 */
function isSamePerson($name1, $name2) {
  [$firstName1, $lastNames1] = splitName($name1);
  [$firstName2, $lastNames2] = splitName($name2);
  
  // Förnamnen måste vara mycket lika
  $firstName1Lower = mb_strtolower($firstName1, 'UTF-8');
  $firstName2Lower = mb_strtolower($firstName2, 'UTF-8');
  
  // Exakt match eller Levenshtein < 2
  if ($firstName1Lower !== $firstName2Lower) {
    $distance = levenshtein($firstName1Lower, $firstName2Lower);
    if ($distance > 1) {
      return false;
    }
  }
  
  // Normalisera alla efternamn
  $normalized1 = array_map('normalizeLastName', $lastNames1);
  $normalized2 = array_map('normalizeLastName', $lastNames2);
  
  // Kontrollera om minst ett efternamn är gemensamt
  $intersection = array_intersect($normalized1, $normalized2);
  
  return !empty($intersection);
}

/**
 * Hämta alla potentiella dubbletter baserat på förnamn + efternamn
 */
function findDuplicatesByName($db) {
  $allRiders = $db->getAll("
    SELECT id, firstname, lastname
    FROM riders
    WHERE license_number IS NULL OR license_number = ''
    ORDER BY id
  ");
  
  $duplicates = [];
  $processedPairs = [];
  
  for ($i = 0; $i < count($allRiders); $i++) {
    $rider1 = $allRiders[$i];
    $name1 = $rider1['firstname'] . ' ' . $rider1['lastname'];
    
    for ($j = $i + 1; $j < count($allRiders); $j++) {
      $rider2 = $allRiders[$j];
      $name2 = $rider2['firstname'] . ' ' . $rider2['lastname'];
      
      $pairKey = $rider1['id'] . '_' . $rider2['id'];
      if (isset($processedPairs[$pairKey])) {
        continue;
      }
      $processedPairs[$pairKey] = true;
      
      // Är de samma person?
      if (isSamePerson($name1, $name2)) {
        // Hämta antalet resultat för båda
        $results1 = $db->getRow("SELECT COUNT(*) as count FROM results WHERE cyclist_id = ?", [$rider1['id']]);
        $results2 = $db->getRow("SELECT COUNT(*) as count FROM results WHERE cyclist_id = ?", [$rider2['id']]);
        
        $duplicates[] = [
          'rider1_id' => $rider1['id'],
          'rider1_name' => $name1,
          'rider1_results' => $results1['count'] ?? 0,
          
          'rider2_id' => $rider2['id'],
          'rider2_name' => $name2,
          'rider2_results' => $results2['count'] ?? 0,
          
          'match_type' => 'FORNAMN_EFTERNAMN'
        ];
      }
    }
  }
  
  return $duplicates;
}

// Handle merge action via GET
if (isset($_GET['action']) && $_GET['action'] === 'merge') {
    $keepId = (int)($_GET['keep'] ?? 0);
    $mergeIdsRaw = $_GET['remove'] ?? '';

    $parts = explode(',', $mergeIdsRaw);
    $mergeIds = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $mergeIds[] = intval($part);
        }
    }

    $filtered = [];
    foreach ($mergeIds as $id) {
        if ($id !== $keepId && $id > 0) {
            $filtered[] = $id;
        }
    }
    $mergeIds = $filtered;

    if ($keepId && !empty($mergeIds)) {
        try {
            $db->pdo->beginTransaction();

            $keepRider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$keepId]);

            if (!$keepRider) {
                throw new Exception("Förare med ID $keepId hittades inte i databasen");
            }

            $resultsUpdated = 0;
            $resultsDeleted = 0;

            foreach ($mergeIds as $oldId) {
                $oldResults = $db->getAll(
                    "SELECT id, event_id FROM results WHERE cyclist_id = ?",
                    [$oldId]
                );

                foreach ($oldResults as $oldResult) {
                    $existing = $db->getRow(
                        "SELECT id FROM results WHERE cyclist_id = ? AND event_id = ?",
                        [$keepId, $oldResult['event_id']]
                    );

                    if ($existing) {
                        $db->delete('results', 'id = ?', [$oldResult['id']]);
                        $resultsDeleted++;
                    } else {
                        $db->update('results', ['cyclist_id' => $keepId], 'id = ?', [$oldResult['id']]);
                        $resultsUpdated++;
                    }
                }
            }

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
        $_SESSION['cleanup_message'] = "Sammanfogning kunde inte utföras.";
        $_SESSION['cleanup_message_type'] = 'error';
    }

    header('Location: /admin/cleanup-duplicates.php');
    exit;
}

// Handle normalize names action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['normalize_names'])) {
    checkCsrf();

    try {
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

            if ($rider['firstname']) {
                $normalized = mb_convert_case(mb_strtolower($rider['firstname'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
                if ($normalized !== $rider['firstname']) {
                    $newFirstname = $normalized;
                    $needsUpdate = true;
                }
            }

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

// Handle normalize UCI-IDs action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['normalize_all'])) {
    checkCsrf();

    try {
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

        $_SESSION['cleanup_message'] = "Normaliserade UCI-ID format för $updated deltagare";
        $_SESSION['cleanup_message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['cleanup_message'] = "Fel vid normalisering: " . $e->getMessage();
        $_SESSION['cleanup_message_type'] = 'error';
    }

    header('Location: /admin/cleanup-duplicates.php');
    exit;
}

// Handle assign SWE-IDs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_swe_ids'])) {
    checkCsrf();

    try {
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

// Hitta dubbletter via förnamn + efternamn
$potentialDuplicatesByName = findDuplicatesByName($db);

// Sortera efter resultat (fler = viktigare)
usort($potentialDuplicatesByName, function($a, $b) {
  $totalA = $a['rider1_results'] + $a['rider2_results'];
  $totalB = $b['rider1_results'] + $b['rider2_results'];
  return $totalB <=> $totalA;
});

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
                    <br><strong>Exempel:</strong> "JOHAN ANDERSSON" blir "Johan Andersson"
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

        <!-- Normalize UCI-IDs -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="wand-2"></i>
                    Normalisera UCI-ID
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-secondary gs-mb-md">
                    Ta bort alla mellanslag och bindestreck från UCI-ID:n.
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
                </div>
            </div>
        </div>

        <!-- POTENTIELLA DUBBLETTER - FÖRNAMN + EFTERNAMN MATCH -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-success">
                    <i data-lucide="users-check"></i>
                    Potentiella dubbletter (<?= count($potentialDuplicatesByName) ?>)
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-secondary gs-mb-md">
                    <i data-lucide="lightbulb" class="gs-icon-inline"></i>
                    Visar riders med samma förnamn och minst ett gemensamt efternamn.
                    <br><strong>Exempel:</strong> "Milton Grundberg" och "Milton Jonsson Grundberg" matchas.
                </p>
                
                <?php if (empty($potentialDuplicatesByName)): ?>
                    <div class="gs-alert gs-alert-success">
                        <i data-lucide="check"></i>
                        Inga potentiella dubbletter hittades!
                    </div>
                <?php else: ?>
                    <div class="gs-overflow-x-auto">
                        <table class="gs-table gs-table-compact">
                            <thead>
                                <tr>
                                    <th>Förare 1</th>
                                    <th>ID</th>
                                    <th>Res.</th>
                                    <th>Förare 2</th>
                                    <th>ID</th>
                                    <th>Res.</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($potentialDuplicatesByName as $dup): ?>
                                    <tr>
                                        <td>
                                            <a href="/rider.php?id=<?= $dup['rider1_id'] ?>" target="_blank">
                                                <strong><?= h($dup['rider1_name']) ?></strong>
                                            </a>
                                        </td>
                                        <td class="gs-text-xs gs-text-secondary">
                                            <?= $dup['rider1_id'] ?>
                                        </td>
                                        <td class="gs-text-xs">
                                            <?= $dup['rider1_results'] ?>
                                        </td>
                                        <td>
                                            <a href="/rider.php?id=<?= $dup['rider2_id'] ?>" target="_blank">
                                                <strong><?= h($dup['rider2_name']) ?></strong>
                                            </a>
                                        </td>
                                        <td class="gs-text-xs gs-text-secondary">
                                            <?= $dup['rider2_id'] ?>
                                        </td>
                                        <td class="gs-text-xs">
                                            <?= $dup['rider2_results'] ?>
                                        </td>
                                        <td>
                                            <?php
                                                // Behåll den med flest resultat
                                                $keepId = $dup['rider1_results'] >= $dup['rider2_results'] ? $dup['rider1_id'] : $dup['rider2_id'];
                                                $mergeId = $dup['rider1_results'] >= $dup['rider2_results'] ? $dup['rider2_id'] : $dup['rider1_id'];
                                            ?>
                                            <div class="gs-flex gs-gap-xs">
                                                <form method="POST" class="gs-inline" onsubmit="return confirm('Sammanfoga denna? Föraren med flest resultat behålls.');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="merge_riders" value="1">
                                                    <input type="hidden" name="keep_id" value="<?= $keepId ?>">
                                                    <input type="hidden" name="merge_ids" value="<?= $mergeId ?>">
                                                    <button type="submit" class="gs-btn gs-btn-xs gs-btn-success">
                                                        <i data-lucide="git-merge"></i>
                                                        Slå ihop
                                                    </button>
                                                </form>
                                                <button type="button" class="gs-btn gs-btn-xs gs-btn-outline" onclick="alert('För att skippa denna dublett, lämna den för nu. Den kommer att dyka upp igen nästa gång.')">
                                                    <i data-lucide="skip-forward"></i>
                                                    Skippa
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="gs-text-xs gs-text-secondary gs-mt-md">
                        <strong>Tips:</strong> Klicka på namnen för att öppna ridersidan i ny flik och verifiera det är samma person innan du slår ihop.
                    </p>
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