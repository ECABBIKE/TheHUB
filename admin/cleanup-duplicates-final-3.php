<?php
/**
 * Admin tool to find and merge duplicate riders
 * UPPDATERAD: Med event-visning + fixed merge-logik
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// ============================================
// SMART MATCHING FUNKTION FÖR DUBBELT-EFTERNAMN
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

function normalizeLastName($name) {
  return mb_strtolower(trim($name), 'UTF-8');
}

function isSamePerson($name1, $name2) {
  [$firstName1, $lastNames1] = splitName($name1);
  [$firstName2, $lastNames2] = splitName($name2);
  $firstName1Lower = mb_strtolower($firstName1, 'UTF-8');
  $firstName2Lower = mb_strtolower($firstName2, 'UTF-8');
  if ($firstName1Lower !== $firstName2Lower) {
    $distance = levenshtein($firstName1Lower, $firstName2Lower);
    if ($distance > 1) {
      return false;
    }
  }
  $normalized1 = array_map('normalizeLastName', $lastNames1);
  $normalized2 = array_map('normalizeLastName', $lastNames2);
  $intersection = array_intersect($normalized1, $normalized2);
  return !empty($intersection);
}

function findDuplicatesByName($db) {
  $allRiders = $db->getAll("
    SELECT id, firstname, lastname
    FROM riders
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
      
      if (isSamePerson($name1, $name2)) {
        $results1 = $db->getRow("SELECT COUNT(*) as count FROM results WHERE cyclist_id = ?", [$rider1['id']]);
        $results2 = $db->getRow("SELECT COUNT(*) as count FROM results WHERE cyclist_id = ?", [$rider2['id']]);
        
        $duplicates[] = [
          'rider1_id' => $rider1['id'],
          'rider1_name' => $name1,
          'rider1_results' => $results1['count'] ?? 0,
          'rider2_id' => $rider2['id'],
          'rider2_name' => $name2,
          'rider2_results' => $results2['count'] ?? 0,
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

// Check for message from redirect
if (isset($_SESSION['cleanup_message'])) {
    $message = $_SESSION['cleanup_message'];
    $messageType = $_SESSION['cleanup_message_type'] ?? 'info';
    unset($_SESSION['cleanup_message'], $_SESSION['cleanup_message_type']);
}

// Hitta dubbletter
$potentialDuplicatesByName = findDuplicatesByName($db);

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
                    Visar riders med samma förnamn och minst ett gemensamt efternamn.
                    <br><strong>Tips:</strong> Verifiera event-listan - om samma event dyker upp två gånger är det SÄKERT samma person.
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
                                    <th>UCI</th>
                                    <th style="width: 180px;">Events</th>
                                    <th>Förare 2</th>
                                    <th>UCI</th>
                                    <th style="width: 180px;">Events</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($potentialDuplicatesByName as $dup): ?>
                                    <?php
                                        $rider1 = $db->getRow("SELECT license_number FROM riders WHERE id = ?", [$dup['rider1_id']]);
                                        $rider2 = $db->getRow("SELECT license_number FROM riders WHERE id = ?", [$dup['rider2_id']]);
                                        
                                        $rider1HasUCI = !empty($rider1['license_number']);
                                        $rider2HasUCI = !empty($rider2['license_number']);
                                        
                                        if ($rider1HasUCI && !$rider2HasUCI) {
                                          $masterRider = 1;
                                          $keepId = $dup['rider1_id'];
                                          $mergeId = $dup['rider2_id'];
                                        } elseif ($rider2HasUCI && !$rider1HasUCI) {
                                          $masterRider = 2;
                                          $keepId = $dup['rider2_id'];
                                          $mergeId = $dup['rider1_id'];
                                        } else {
                                          $masterRider = $dup['rider1_results'] >= $dup['rider2_results'] ? 1 : 2;
                                          $keepId = $masterRider === 1 ? $dup['rider1_id'] : $dup['rider2_id'];
                                          $mergeId = $masterRider === 1 ? $dup['rider2_id'] : $dup['rider1_id'];
                                        }
                                        
                                        $events1 = $db->getAll("
                                            SELECT DISTINCT e.name, e.date, c.name as class_name
                                            FROM results r
                                            JOIN events e ON r.event_id = e.id
                                            LEFT JOIN classes c ON r.class_id = c.id
                                            WHERE r.cyclist_id = ?
                                            ORDER BY e.date DESC
                                            LIMIT 8
                                        ", [$dup['rider1_id']]);
                                        
                                        $events2 = $db->getAll("
                                            SELECT DISTINCT e.name, e.date, c.name as class_name
                                            FROM results r
                                            JOIN events e ON r.event_id = e.id
                                            LEFT JOIN classes c ON r.class_id = c.id
                                            WHERE r.cyclist_id = ?
                                            ORDER BY e.date DESC
                                            LIMIT 8
                                        ", [$dup['rider2_id']]);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="/rider.php?id=<?= $dup['rider1_id'] ?>" target="_blank">
                                                <strong><?= h($dup['rider1_name']) ?></strong>
                                            </a>
                                            <?php if ($masterRider === 1): ?>
                                                <br><span class="gs-badge gs-badge-success" style="font-size: 10px;">✓ BEHÅLLS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 11px;">
                                            <?= h($rider1['license_number'] ?? '-') ?>
                                            <?php if ($rider1HasUCI): ?><br><span class="gs-badge gs-badge-xs gs-badge-info">UCI</span><?php endif; ?>
                                        </td>
                                        <td style="font-size: 10px; max-height: 100px; overflow-y: auto;">
                                            <?php foreach ($events1 as $e): ?>
                                                <div style="margin-bottom: 4px; padding-bottom: 4px; border-bottom: 1px solid #eee;">
                                                    <strong><?= h($e['name']) ?></strong><br>
                                                    <span style="color: #666;"><?= h($e['class_name'] ?? '-') ?></span><br>
                                                    <span style="color: #999; font-size: 9px;"><?= h($e['date']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <a href="/rider.php?id=<?= $dup['rider2_id'] ?>" target="_blank">
                                                <strong><?= h($dup['rider2_name']) ?></strong>
                                            </a>
                                            <?php if ($masterRider === 2): ?>
                                                <br><span class="gs-badge gs-badge-success" style="font-size: 10px;">✓ BEHÅLLS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 11px;">
                                            <?= h($rider2['license_number'] ?? '-') ?>
                                            <?php if ($rider2HasUCI): ?><br><span class="gs-badge gs-badge-xs gs-badge-info">UCI</span><?php endif; ?>
                                        </td>
                                        <td style="font-size: 10px; max-height: 100px; overflow-y: auto;">
                                            <?php foreach ($events2 as $e): ?>
                                                <div style="margin-bottom: 4px; padding-bottom: 4px; border-bottom: 1px solid #eee;">
                                                    <strong><?= h($e['name']) ?></strong><br>
                                                    <span style="color: #666;"><?= h($e['class_name'] ?? '-') ?></span><br>
                                                    <span style="color: #999; font-size: 9px;"><?= h($e['date']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="gs-inline" onsubmit="return confirm('Slå ihop dessa?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="merge_riders" value="1">
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
