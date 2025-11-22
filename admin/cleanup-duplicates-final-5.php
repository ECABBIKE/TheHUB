<?php
/**
 * Admin tool to find and merge duplicate riders
 * FINAL VERSION: Med UCI-ID säkerhet + tävlingsklass-matching
 * 
 * KRITISKA REGLER:
 * - ALDRIG slå samman om de har OLIKA UCI-ID (=olika personer garanterat)
 * - KRÄVS: Förnamn + efternamn + tävlingsklass matchar
 * - Samma UCI-ID = redan samma person, ingen merge behövs
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// ============================================
// MATCHING FUNKTIONER
// ============================================

function splitName($fullName) {
  if (!$fullName) return ['', []];
  $fullName = trim($fullName);
  $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
  if (count($parts) < 2) {
    return [$parts[0] ?? '', []];
  }
  $firstName = array_shift($parts);
  $lastNames = $parts;
  return [$firstName, $lastNames];
}

function normalizeString($str) {
  return mb_strtolower(trim($str), 'UTF-8');
}

function stringsSimilar($str1, $str2, $maxDistance = 1) {
  $norm1 = normalizeString($str1);
  $norm2 = normalizeString($str2);
  if ($norm1 === $norm2) return true;
  $distance = levenshtein($norm1, $norm2);
  return $distance <= $maxDistance;
}

function getRiderClasses($db, $riderId) {
  $classes = $db->getAll("
    SELECT DISTINCT c.name as class_name
    FROM results r
    LEFT JOIN classes c ON r.class_id = c.id
    WHERE r.cyclist_id = ?
    AND c.name IS NOT NULL
  ", [$riderId]);
  
  return array_map(fn($c) => normalizeString($c['class_name']), $classes);
}

/**
 * KRITISK MATCHING-LOGIK
 * 
 * Två riders är samma person om:
 * 1. INTE båda har OLIKA UCI-ID (det är garanterat olika personer!)
 * 2. Förnamn matchar (Levenshtein ≤ 1)
 * 3. Minst ett efternamn matchar (Levenshtein ≤ 1)
 * 4. (OPTIONAL) Minst en tävlingsklass är gemensam (om klassdata finns)
 * 
 * Logik: Namn är de viktigaste kriterierna. Klassdata är ofta tomt vid import,
 * så vi matchar på namn även om klassdata saknas.
 */
function isSamePerson($db, $rider1Id, $rider2Id) {
  $r1 = $db->getRow("SELECT firstname, lastname, license_number FROM riders WHERE id = ?", [$rider1Id]);
  $r2 = $db->getRow("SELECT firstname, lastname, license_number FROM riders WHERE id = ?", [$rider2Id]);
  
  if (!$r1 || !$r2) return false;
  
  // *** KRITISK REGEL 1 ***
  // Om BÅDA har UCI-ID och de är OLIKA = garanterat olika personer!
  $r1HasUCI = !empty($r1['license_number']);
  $r2HasUCI = !empty($r2['license_number']);
  
  if ($r1HasUCI && $r2HasUCI) {
    // Båda har UCI-ID
    if ($r1['license_number'] !== $r2['license_number']) {
      // OLIKA UCI-ID = ALDRIG samma person
      return false;
    }
    // Samma UCI-ID = redan samma person i officiell DB, ingen merge behövs
    return false;
  }
  
  // Endast en eller ingen har UCI-ID - då kan vi matcha på andra kriterier
  
  [$fname1, $lnames1] = splitName($r1['firstname'] . ' ' . $r1['lastname']);
  [$fname2, $lnames2] = splitName($r2['firstname'] . ' ' . $r2['lastname']);
  
  // REGEL 2: Förnamn måste matcha
  $firstnameMatch = stringsSimilar($fname1, $fname2, 1);
  if (!$firstnameMatch) return false;
  
  // REGEL 3: Minst ett efternamn måste matcha
  $lastnameMatch = false;
  foreach ($lnames1 as $ln1) {
    foreach ($lnames2 as $ln2) {
      if (stringsSimilar($ln1, $ln2, 1)) {
        $lastnameMatch = true;
        break 2;
      }
    }
  }
  if (!$lastnameMatch) return false;
  
  // REGEL 4 (OPTIONAL): Minst en tävlingsklass måste vara gemensam
  // OBS: Klassdata är ofta tomt, så vi matchar även utan detta
  $classes1 = getRiderClasses($db, $rider1Id);
  $classes2 = getRiderClasses($db, $rider2Id);
  
  // Om båda har klassdata, måste den matcha
  if (!empty($classes1) && !empty($classes2)) {
    $commonClasses = array_intersect($classes1, $classes2);
    if (empty($commonClasses)) {
      // Både har klassdata men ingen gemensam klass = olika personer
      return false;
    }
  }
  // Om endast en eller ingen har klassdata = OK, vi matchar ändå på namn
  
  // ✓ ALLA KRITISKA KRITERIER UPPFYLLDA
  return true;
}

function findDuplicatesByClassMatch($db) {
  $allRiders = $db->getAll("
    SELECT DISTINCT r.id, r.firstname, r.lastname, r.license_number
    FROM riders r
    WHERE EXISTS (SELECT 1 FROM results WHERE cyclist_id = r.id)
    ORDER BY r.id
  ");
  
  $duplicates = [];
  $processedPairs = [];
  
  for ($i = 0; $i < count($allRiders); $i++) {
    $rider1 = $allRiders[$i];
    
    for ($j = $i + 1; $j < count($allRiders); $j++) {
      $rider2 = $allRiders[$j];
      
      $pairKey = $rider1['id'] . '_' . $rider2['id'];
      if (isset($processedPairs[$pairKey])) {
        continue;
      }
      $processedPairs[$pairKey] = true;
      
      if (isSamePerson($db, $rider1['id'], $rider2['id'])) {
        $results1 = $db->getRow("SELECT COUNT(*) as count FROM results WHERE cyclist_id = ?", [$rider1['id']]);
        $results2 = $db->getRow("SELECT COUNT(*) as count FROM results WHERE cyclist_id = ?", [$rider2['id']]);
        
        $classes1 = getRiderClasses($db, $rider1['id']);
        $classes2 = getRiderClasses($db, $rider2['id']);
        $commonClasses = array_intersect($classes1, $classes2);
        
        $duplicates[] = [
          'rider1_id' => $rider1['id'],
          'rider1_name' => $rider1['firstname'] . ' ' . $rider1['lastname'],
          'rider1_uci' => $rider1['license_number'],
          'rider1_results' => $results1['count'] ?? 0,
          
          'rider2_id' => $rider2['id'],
          'rider2_name' => $rider2['firstname'] . ' ' . $rider2['lastname'],
          'rider2_uci' => $rider2['license_number'],
          'rider2_results' => $results2['count'] ?? 0,
          
          'common_classes' => implode(', ', $commonClasses),
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
            // Verifiera att vi aldrig slår samman OLIKA UCI-ID
            $keepRider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$keepId]);
            if (!$keepRider) {
                throw new Exception("Förare med ID $keepId hittades inte");
            }

            foreach ($mergeIds as $mergeId) {
                $mergeRider = $db->getRow("SELECT license_number FROM riders WHERE id = ?", [$mergeId]);
                if (!$mergeRider) continue;
                
                $keepHasUCI = !empty($keepRider['license_number']);
                $mergeHasUCI = !empty($mergeRider['license_number']);
                
                if ($keepHasUCI && $mergeHasUCI && $keepRider['license_number'] !== $mergeRider['license_number']) {
                    throw new Exception("SÄKERHET: Kan INTE slå samman - de har OLIKA UCI-ID! ($keepRider[license_number] vs $mergeRider[license_number])");
                }
            }

            $db->pdo->beginTransaction();

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

            $msg = "✓ Sammanfogade " . count($mergeIds) . " deltagare till " . $keepRider['firstname'] . " " . $keepRider['lastname'];
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
            $_SESSION['cleanup_message'] = "Fel: " . $e->getMessage();
            $_SESSION['cleanup_message_type'] = 'error';
        }
    } else {
        $_SESSION['cleanup_message'] = "Sammanfogning kunde inte utföras.";
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

// Hitta dubbletter
$potentialDuplicatesByName = findDuplicatesByClassMatch($db);

// Sortera efter resultat
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

        <!-- Info Box -->
        <div class="gs-card gs-mb-lg" style="background-color: #f0f7ff; border-left: 4px solid #0066cc;">
            <div class="gs-card-content">
                <h3 class="gs-h5 gs-text-primary gs-mb-sm">
                    <i data-lucide="shield-alert"></i>
                    Matchnings-kriterier
                </h3>
                <ul style="margin: 0; padding-left: 20px; font-size: 13px; line-height: 1.6;">
                    <li><strong>✗ ALDRIG sammanfoga</strong> om de har OLIKA UCI-ID</li>
                    <li><strong>✓ KRÄVS:</strong> Förnamn matchar (Levenshtein ≤ 1)</li>
                    <li><strong>✓ KRÄVS:</strong> Minst ett efternamn matchar</li>
                    <li><strong>✓ OPTIONAL:</strong> Tävlingsklass (matchar bara om klassdata finns för båda)</li>
                </ul>
            </div>
        </div>

        <!-- POTENTIELLA DUBBLETTER -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-success">
                    <i data-lucide="users-check"></i>
                    Potentiella dubbletter (<?= count($potentialDuplicatesByName) ?>)
                </h2>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-secondary gs-mb-md">
                    Dessa har matchat alla kriterier: samma förnamn + efternamn + tävlingsklass.
                </p>
                
                <?php if (empty($potentialDuplicatesByName)): ?>
                    <div class="gs-alert gs-alert-success">
                        <i data-lucide="check"></i>
                        Inga potentiella dubbletter hittades!
                    </div>
                <?php else: ?>
                    <div class="gs-overflow-x-auto">
                        <table class="gs-table gs-table-compact" style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th>Förare 1</th>
                                    <th>UCI-ID</th>
                                    <th>Res.</th>
                                    <th>Förare 2</th>
                                    <th>UCI-ID</th>
                                    <th>Res.</th>
                                    <th>Gemensam klass</th>
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
                                        <td style="font-size: 11px;">
                                            <?= $dup['rider1_uci'] ? h($dup['rider1_uci']) : '<em>ingen</em>' ?>
                                        </td>
                                        <td><?= $dup['rider1_results'] ?></td>
                                        <td>
                                            <a href="/rider.php?id=<?= $dup['rider2_id'] ?>" target="_blank">
                                                <strong><?= h($dup['rider2_name']) ?></strong>
                                            </a>
                                        </td>
                                        <td style="font-size: 11px;">
                                            <?= $dup['rider2_uci'] ? h($dup['rider2_uci']) : '<em>ingen</em>' ?>
                                        </td>
                                        <td><?= $dup['rider2_results'] ?></td>
                                        <td style="font-size: 11px;">
                                            <?= h($dup['common_classes']) ?>
                                        </td>
                                        <td>
                                            <?php
                                                $keepId = $dup['rider1_results'] >= $dup['rider2_results'] ? $dup['rider1_id'] : $dup['rider2_id'];
                                                $mergeId = $dup['rider1_results'] >= $dup['rider2_results'] ? $dup['rider2_id'] : $dup['rider1_id'];
                                            ?>
                                            <form method="POST" class="gs-inline" onsubmit="return confirm('Slå ihop dessa?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="keep_id" value="<?= $keepId ?>">
                                                <input type="hidden" name="merge_ids" value="<?= $mergeId ?>">
                                                <button type="submit" class="gs-btn gs-btn-xs gs-btn-success">
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
                <?php endif; ?>
            </div>
        </div>

        <div class="gs-mt-lg">
            <a href="/admin/import.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
