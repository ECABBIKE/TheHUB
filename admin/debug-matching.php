<?php
/**
 * DEBUG: Test matching-logik för Milton Grundberg-fallet
 */
require_once __DIR__ . '/../config.php';
require_admin();

// ============================================
// MATCHING FUNKTIONER (samma som i cleanup)
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

?>
<!DOCTYPE html>
<html>
<head>
    <title>DEBUG: Matching-logik test</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .match { background-color: #90EE90; font-weight: bold; }
        .nomatch { background-color: #ffcccc; }
        h2 { margin-top: 30px; color: #333; }
        pre { background-color: #f5f5f5; padding: 10px; overflow-x: auto; }
        .debug-box { background-color: #e7f3ff; border-left: 3px solid #0066cc; padding: 10px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>DEBUG: Matching-logik test</h1>
    
    <h2>1. Test av splitName-funktion</h2>
    <table>
        <tr>
            <th>Fullname</th>
            <th>Förnamn</th>
            <th>Efternamn</th>
        </tr>
        <?php
        $testNames = [
            'Milton Grundberg',
            'Milton Jonsson Grundberg',
            'Anna Svensson Hansen',
            'Anna Svensson',
            'John Andersson',
            'John Andersson Berg',
        ];
        
        foreach ($testNames as $name) {
            [$fname, $lnames] = splitName($name);
            echo '<tr>';
            echo '<td><strong>' . h($name) . '</strong></td>';
            echo '<td>' . h($fname) . '</td>';
            echo '<td>' . implode(', ', array_map('h', $lnames)) . '</td>';
            echo '</tr>';
        }
        ?>
    </table>
    
    <h2>2. Test av isSamePerson-funktion</h2>
    <table>
        <tr>
            <th>Namn 1</th>
            <th>Namn 2</th>
            <th>Samma person?</th>
            <th>Förklaring</th>
        </tr>
        <tr class="<?= isSamePerson('Milton Grundberg', 'Milton Jonsson Grundberg') ? 'match' : 'nomatch' ?>">
            <td>Milton Grundberg</td>
            <td>Milton Jonsson Grundberg</td>
            <td><?= isSamePerson('Milton Grundberg', 'Milton Jonsson Grundberg') ? '✓ JA' : '✗ NEJ' ?></td>
            <td>
                <?php
                [$f1, $l1] = splitName('Milton Grundberg');
                [$f2, $l2] = splitName('Milton Jonsson Grundberg');
                $norm1 = array_map('normalizeLastName', $l1);
                $norm2 = array_map('normalizeLastName', $l2);
                echo 'Efternamn 1: ' . json_encode($norm1) . '<br>';
                echo 'Efternamn 2: ' . json_encode($norm2) . '<br>';
                echo 'Gemensamma: ' . json_encode(array_intersect($norm1, $norm2));
                ?>
            </td>
        </tr>
    </table>
    
    <h2>3. Databas-check: Riders utan UCI-ID</h2>
    <?php
    try {
        $db = getDB();
        
        $riders = $db->getAll("
            SELECT id, firstname, lastname
            FROM riders
            WHERE license_number IS NULL OR license_number = ''
            ORDER BY firstname, lastname
            LIMIT 100
        ");
        
        echo '<p>Hittade <strong>' . count($riders) . '</strong> riders utan UCI-ID (visar max 100)</p>';
        
        if (empty($riders)) {
            echo '<div class="debug-box" style="background-color: #ffe7e7;">';
            echo '<strong>⚠️ PROBLEM:</strong> Inga riders utan UCI-ID hittades!<br>';
            echo 'Alla riders i databasen har ett licensnummer/UCI-ID.<br>';
            echo 'Matching-funktionen letar bara bland riders <strong>utan</strong> UCI-ID.';
            echo '</div>';
        } else {
            echo '<table>';
            echo '<tr><th>ID</th><th>Förnamn</th><th>Efternamn</th><th>Fullname</th></tr>';
            
            foreach ($riders as $rider) {
                echo '<tr>';
                echo '<td>' . $rider['id'] . '</td>';
                echo '<td>' . h($rider['firstname']) . '</td>';
                echo '<td>' . h($rider['lastname']) . '</td>';
                echo '<td>' . h($rider['firstname'] . ' ' . $rider['lastname']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        
    } catch (Exception $e) {
        echo '<p style="color: red;"><strong>Databas-fel:</strong> ' . h($e->getMessage()) . '</p>';
    }
    ?>
    
    <h2>4. Manual test: Sök efter Milton</h2>
    <?php
    try {
        $db = getDB();
        
        $milton = $db->getAll("
            SELECT id, firstname, lastname, license_number
            FROM riders
            WHERE firstname LIKE '%milton%' OR lastname LIKE '%milton%'
            OR firstname LIKE '%grundberg%' OR lastname LIKE '%grundberg%'
        ", [], true);
        
        echo '<p>Hittade ' . count($milton) . ' rider(s) med "Milton" eller "Grundberg":</p>';
        
        if (empty($milton)) {
            echo '<div class="debug-box" style="background-color: #ffe7e7;">';
            echo '<strong>⚠️ PROBLEM:</strong> Milton/Grundberg hittades INTE i databasen!<br>';
            echo 'Möjliga orsaker:<br>';
            echo '1. Milton finns men med annat namn (t.ex. "Mikton" eller "Melton")<br>';
            echo '2. Milton har ett UCI-ID och sökningen exkluderar dem<br>';
            echo '3. Milton är redan slagna samman';
            echo '</div>';
        } else {
            echo '<table>';
            echo '<tr><th>ID</th><th>Förnamn</th><th>Efternamn</th><th>Fullname</th><th>UCI-ID</th></tr>';
            
            foreach ($milton as $rider) {
                echo '<tr>';
                echo '<td>' . $rider['id'] . '</td>';
                echo '<td>' . h($rider['firstname']) . '</td>';
                echo '<td>' . h($rider['lastname']) . '</td>';
                echo '<td>' . h($rider['firstname'] . ' ' . $rider['lastname']) . '</td>';
                echo '<td>' . ($rider['license_number'] ? h($rider['license_number']) : '<em>ingen</em>') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        
    } catch (Exception $e) {
        echo '<p style="color: red;"><strong>Databas-fel:</strong> ' . h($e->getMessage()) . '</p>';
    }
    ?>

    <h2>5. Info: Matching-kriteria</h2>
    <div class="debug-box">
        <strong>Funktionen letar efter riders med:</strong><br>
        1. ✅ Samma förnamn (case-insensitive)<br>
        2. ✅ MINST ett gemensamt efternamn<br>
        3. ✅ <strong>UTAN</strong> UCI-ID (license_number IS NULL)<br>
        <br>
        <strong>Om ingen dublett hittas kan det vara:</strong>
        <ul>
            <li>🔍 Milton har ett UCI-ID → sökningen exkluderar honom</li>
            <li>🔍 Förnamnet är stavat olika (Milton vs Milt vs Milten)</li>
            <li>🔍 Milton finns inte i databasen</li>
            <li>🔍 Efternamnen skiljer sig helt (t.ex. "Milton Schmidt" och "Milton Hansen" - inget gemensamt)</li>
        </ul>
    </div>

</body>
</html>
