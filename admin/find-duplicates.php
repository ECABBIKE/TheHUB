<?php
/**
 * Smart Duplicate Finder
 * Find potential duplicate riders - only when one profile is missing data
 *
 * NOT duplicates if:
 * - Both have different UCI IDs
 * - Both have different birth years
 *
 * ARE duplicates if:
 * - Same name AND one is missing UCI/birth year that the other has
 */

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$message = '';
$messageType = 'info';

// Check for message from redirect
if (isset($_SESSION['dup_message'])) {
    $message = $_SESSION['dup_message'];
    $messageType = $_SESSION['dup_message_type'] ?? 'info';
    unset($_SESSION['dup_message'], $_SESSION['dup_message_type']);
}

// Handle merge action
if (isset($_GET['action']) && $_GET['action'] === 'merge') {
    $keepId = (int)($_GET['keep'] ?? 0);
    $removeId = (int)($_GET['remove'] ?? 0);

    if ($keepId && $removeId && $keepId !== $removeId) {
        try {
            $db->pdo->beginTransaction();

            $keepRider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$keepId]);
            $removeRider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$removeId]);

            if (!$keepRider || !$removeRider) {
                throw new Exception("En av ryttarna hittades inte");
            }

            // Move results from remove to keep
            $resultsToMove = $db->getAll("SELECT id, event_id FROM results WHERE cyclist_id = ?", [$removeId]);
            $moved = 0;
            $deleted = 0;

            foreach ($resultsToMove as $result) {
                $existing = $db->getRow(
                    "SELECT id FROM results WHERE cyclist_id = ? AND event_id = ?",
                    [$keepId, $result['event_id']]
                );

                if ($existing) {
                    $db->delete('results', 'id = ?', [$result['id']]);
                    $deleted++;
                } else {
                    $db->update('results', ['cyclist_id' => $keepId], 'id = ?', [$result['id']]);
                    $moved++;
                }
            }

            // Update keep rider with missing data from remove rider
            $updates = [];
            if (empty($keepRider['birth_year']) && !empty($removeRider['birth_year'])) {
                $updates['birth_year'] = $removeRider['birth_year'];
            }
            if (empty($keepRider['email']) && !empty($removeRider['email'])) {
                $updates['email'] = $removeRider['email'];
            }
            if (empty($keepRider['phone']) && !empty($removeRider['phone'])) {
                $updates['phone'] = $removeRider['phone'];
            }
            if (empty($keepRider['club_id']) && !empty($removeRider['club_id'])) {
                $updates['club_id'] = $removeRider['club_id'];
            }
            if (empty($keepRider['gender']) && !empty($removeRider['gender'])) {
                $updates['gender'] = $removeRider['gender'];
            }
            // Prefer UCI ID over SWE ID
            if (!empty($removeRider['license_number'])) {
                $keepIsSweid = empty($keepRider['license_number']) || strpos($keepRider['license_number'], 'SWE') === 0;
                $removeIsUci = strpos($removeRider['license_number'], 'SWE') !== 0;
                if ($keepIsSweid && $removeIsUci) {
                    $updates['license_number'] = $removeRider['license_number'];
                } elseif (empty($keepRider['license_number'])) {
                    $updates['license_number'] = $removeRider['license_number'];
                }
            }
            if (empty($keepRider['license_type']) && !empty($removeRider['license_type'])) {
                $updates['license_type'] = $removeRider['license_type'];
            }

            if (!empty($updates)) {
                $db->update('riders', $updates, 'id = ?', [$keepId]);
            }

            // Delete the duplicate rider
            $db->delete('riders', 'id = ?', [$removeId]);

            $db->pdo->commit();

            $_SESSION['dup_message'] = "Sammanfogade {$removeRider['firstname']} {$removeRider['lastname']} → {$keepRider['firstname']} {$keepRider['lastname']} ({$moved} resultat flyttade" . ($deleted > 0 ? ", {$deleted} dubbletter borttagna" : "") . ")";
            $_SESSION['dup_message_type'] = 'success';

        } catch (Exception $e) {
            if ($db->pdo->inTransaction()) {
                $db->pdo->rollBack();
            }
            $_SESSION['dup_message'] = "Fel: " . $e->getMessage();
            $_SESSION['dup_message_type'] = 'error';
        }

        header('Location: /admin/find-duplicates.php');
        exit;
    }
}

// Normalize UCI ID for comparison (remove spaces)
function normalizeUci($uci) {
    if (empty($uci)) return null;
    return preg_replace('/\s+/', '', $uci);
}

// Check if license is a real UCI (not SWE ID)
function isRealUci($license) {
    if (empty($license)) return false;
    return strpos($license, 'SWE') !== 0;
}

// Calculate name similarity (0-100)
function nameSimilarity($name1, $name2) {
    $name1 = mb_strtolower(trim($name1), 'UTF-8');
    $name2 = mb_strtolower(trim($name2), 'UTF-8');

    if ($name1 === $name2) return 100;

    // Check if one contains the other (for double surnames)
    if (strpos($name1, $name2) !== false || strpos($name2, $name1) !== false) {
        return 90;
    }

    // Try Levenshtein
    $name1 = substr($name1, 0, 255);
    $name2 = substr($name2, 0, 255);
    $maxLen = max(strlen($name1), strlen($name2));
    if ($maxLen === 0) return 0;

    $distance = levenshtein($name1, $name2);
    return round((1 - $distance / $maxLen) * 100);
}

// Check if two names are similar enough to be potential duplicates
function areNamesSimilar($fn1, $ln1, $fn2, $ln2) {
    // Exact match
    if (strtolower($fn1) === strtolower($fn2) && strtolower($ln1) === strtolower($ln2)) {
        return ['match' => true, 'reason' => 'Exakt samma namn'];
    }

    // Same lastname, similar firstname (typos)
    if (strtolower($ln1) === strtolower($ln2)) {
        $fnSim = nameSimilarity($fn1, $fn2);
        if ($fnSim >= 80) {
            return ['match' => true, 'reason' => "Samma efternamn, liknande förnamn ({$fnSim}%)"];
        }
    }

    // Similar lastname (one contains the other, or typos)
    $lnSim = nameSimilarity($ln1, $ln2);
    if ($lnSim >= 85 && strtolower($fn1) === strtolower($fn2)) {
        return ['match' => true, 'reason' => "Samma förnamn, liknande efternamn ({$lnSim}%)"];
    }

    // One lastname contains the other (double surname)
    $ln1Lower = strtolower($ln1);
    $ln2Lower = strtolower($ln2);
    if ((strpos($ln1Lower, $ln2Lower) !== false || strpos($ln2Lower, $ln1Lower) !== false) &&
        strtolower($fn1) === strtolower($fn2)) {
        return ['match' => true, 'reason' => 'Samma förnamn, dubbelt efternamn'];
    }

    // Full name similarity
    $fullSim = nameSimilarity("$fn1 $ln1", "$fn2 $ln2");
    if ($fullSim >= 90) {
        return ['match' => true, 'reason' => "Mycket lika namn ({$fullSim}%)"];
    }

    return ['match' => false, 'reason' => null];
}

// Find potential duplicates - same/similar name where one is missing data
$potentialDuplicates = [];
$processedPairs = []; // Track processed pairs to avoid duplicates

// Get all riders for comparison
$allRiders = $db->getAll("
    SELECT
        r.id, r.firstname, r.lastname, r.birth_year, r.license_number,
        r.email, r.club_id, r.license_type, r.gender,
        c.name as club_name,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.firstname IS NOT NULL AND r.lastname IS NOT NULL
    ORDER BY r.lastname, r.firstname
");

// Group by similar lastname for efficient comparison
$byLastname = [];
foreach ($allRiders as $r) {
    $lnKey = strtolower($r['lastname']);
    $byLastname[$lnKey][] = $r;
}

// Compare within same lastname groups (exact)
foreach ($byLastname as $lnKey => $riders) {
    if (count($riders) < 2) continue;

    for ($i = 0; $i < count($riders); $i++) {
        for ($j = $i + 1; $j < count($riders); $j++) {
            $r1 = $riders[$i];
            $r2 = $riders[$j];
            $pairKey = min($r1['id'], $r2['id']) . '-' . max($r1['id'], $r2['id']);
            if (isset($processedPairs[$pairKey])) continue;

            $nameCheck = areNamesSimilar($r1['firstname'], $r1['lastname'], $r2['firstname'], $r2['lastname']);
            if ($nameCheck['match']) {
                $result = checkDuplicatePair($r1, $r2, $nameCheck['reason']);
                if ($result) {
                    $potentialDuplicates[] = $result;
                    $processedPairs[$pairKey] = true;
                }
            }
        }
    }
}

// Also check for similar lastnames (typos, double surnames)
$lastnameKeys = array_keys($byLastname);
for ($i = 0; $i < count($lastnameKeys); $i++) {
    for ($j = $i + 1; $j < count($lastnameKeys); $j++) {
        $ln1 = $lastnameKeys[$i];
        $ln2 = $lastnameKeys[$j];

        // Check if lastnames are similar
        $lnSim = nameSimilarity($ln1, $ln2);
        $oneContainsOther = strpos($ln1, $ln2) !== false || strpos($ln2, $ln1) !== false;

        if ($lnSim >= 85 || $oneContainsOther) {
            foreach ($byLastname[$ln1] as $r1) {
                foreach ($byLastname[$ln2] as $r2) {
                    $pairKey = min($r1['id'], $r2['id']) . '-' . max($r1['id'], $r2['id']);
                    if (isset($processedPairs[$pairKey])) continue;

                    $nameCheck = areNamesSimilar($r1['firstname'], $r1['lastname'], $r2['firstname'], $r2['lastname']);
                    if ($nameCheck['match']) {
                        $result = checkDuplicatePair($r1, $r2, $nameCheck['reason']);
                        if ($result) {
                            $potentialDuplicates[] = $result;
                            $processedPairs[$pairKey] = true;
                        }
                    }
                }
            }
        }
    }

    if (count($potentialDuplicates) >= 100) break;
}

// Function to check if two riders are potential duplicates
function checkDuplicatePair($r1, $r2, $nameReason) {
    $uci1 = normalizeUci($r1['license_number']);
    $uci2 = normalizeUci($r2['license_number']);
    $isRealUci1 = isRealUci($r1['license_number']);
    $isRealUci2 = isRealUci($r2['license_number']);

    // NOT duplicates if both have DIFFERENT real UCI IDs
    if ($isRealUci1 && $isRealUci2 && $uci1 !== $uci2) {
        return null; // Different people
    }

    // NOT duplicates if both have DIFFERENT birth years
    if (!empty($r1['birth_year']) && !empty($r2['birth_year']) && $r1['birth_year'] !== $r2['birth_year']) {
        return null; // Different people
    }

    // Check if one is missing data that the other has
    $r1Missing = [];
    $r2Missing = [];

    if (empty($r1['birth_year']) && !empty($r2['birth_year'])) $r1Missing[] = 'födelseår';
    if (empty($r2['birth_year']) && !empty($r1['birth_year'])) $r2Missing[] = 'födelseår';

    if (!$isRealUci1 && $isRealUci2) $r1Missing[] = 'UCI ID';
    if (!$isRealUci2 && $isRealUci1) $r2Missing[] = 'UCI ID';

    if (empty($r1['email']) && !empty($r2['email'])) $r1Missing[] = 'e-post';
    if (empty($r2['email']) && !empty($r1['email'])) $r2Missing[] = 'e-post';

    if (empty($r1['club_id']) && !empty($r2['club_id'])) $r1Missing[] = 'klubb';
    if (empty($r2['club_id']) && !empty($r1['club_id'])) $r2Missing[] = 'klubb';

    if (empty($r1['license_type']) && !empty($r2['license_type'])) $r1Missing[] = 'licenstyp';
    if (empty($r2['license_type']) && !empty($r1['license_type'])) $r2Missing[] = 'licenstyp';

    // Only flag as duplicate if one has data the other is missing
    // OR if they have the same UCI ID (clear duplicate)
    $sameUci = $isRealUci1 && $isRealUci2 && $uci1 === $uci2;
    $hasMissingData = !empty($r1Missing) || !empty($r2Missing);

    if (!$sameUci && !$hasMissingData) {
        return null; // Both have same data, not a merge candidate
    }

    $reason = $sameUci ? 'Samma UCI ID' : $nameReason;

    return [
        'reason' => $reason,
        'rider1' => [
            'id' => $r1['id'],
            'name' => $r1['firstname'] . ' ' . $r1['lastname'],
            'birth_year' => $r1['birth_year'],
            'license' => $r1['license_number'],
            'email' => $r1['email'],
            'club' => $r1['club_name'],
            'license_type' => $r1['license_type'],
            'gender' => $r1['gender'],
            'results' => $r1['result_count'],
            'missing' => $r1Missing
        ],
        'rider2' => [
            'id' => $r2['id'],
            'name' => $r2['firstname'] . ' ' . $r2['lastname'],
            'birth_year' => $r2['birth_year'],
            'license' => $r2['license_number'],
            'email' => $r2['email'],
            'club' => $r2['club_name'],
            'license_type' => $r2['license_type'],
            'gender' => $r2['gender'],
            'results' => $r2['result_count'],
            'missing' => $r2Missing
        ]
    ];
}

// Sort: Same UCI first, then by number of missing fields
usort($potentialDuplicates, function($a, $b) {
    if ($a['reason'] === 'Samma UCI ID' && $b['reason'] !== 'Samma UCI ID') return -1;
    if ($b['reason'] === 'Samma UCI ID' && $a['reason'] !== 'Samma UCI ID') return 1;
    $aMissing = count($a['rider1']['missing']) + count($a['rider2']['missing']);
    $bMissing = count($b['rider1']['missing']) + count($b['rider2']['missing']);
    return $bMissing - $aMissing;
});

$pageTitle = 'Hitta Dubbletter';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <?php render_admin_header('Hitta Dubbletter', 'settings'); ?>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="gs-alert gs-alert-info gs-mb-lg">
            <i data-lucide="info"></i>
            <div>
                <strong>Smart dubblettdetektering</strong><br>
                Hittar endast dubbletter när:<br>
                - Samma namn OCH en profil saknar data (UCI, födelseår, e-post)<br>
                - Eller samma UCI ID<br><br>
                <strong>EJ dubbletter om:</strong> Olika UCI ID eller olika födelseår
            </div>
        </div>

        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-warning">
                    <i data-lucide="users"></i>
                    Potentiella dubbletter (<?= count($potentialDuplicates) ?>)
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($potentialDuplicates)): ?>
                    <div class="gs-text-center gs-py-lg">
                        <i data-lucide="check-circle" style="width: 48px; height: 48px; color: var(--gs-success);"></i>
                        <p class="gs-text-success gs-mt-md">Inga potentiella dubbletter hittades!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($potentialDuplicates as $idx => $dup): ?>
                    <div class="gs-card gs-mb-md" style="border: 2px solid var(--gs-warning); border-radius: 8px;">
                        <div class="gs-card-header gs-flex gs-justify-between gs-items-center" style="background: rgba(255,193,7,0.1);">
                            <span class="gs-badge <?= $dup['reason'] === 'Samma UCI ID' ? 'gs-badge-danger' : 'gs-badge-warning' ?>">
                                <?= h($dup['reason']) ?>
                            </span>
                            <span class="gs-text-sm gs-text-secondary">#<?= $idx + 1 ?></span>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                <?php foreach (['rider1', 'rider2'] as $key):
                                    $rider = $dup[$key];
                                    $isComplete = empty($rider['missing']);
                                    $bgColor = $isComplete ? 'rgba(40,167,69,0.05)' : 'rgba(220,53,69,0.05)';
                                ?>
                                <div style="padding: 1rem; background: <?= $bgColor ?>; border-radius: 8px;">
                                    <div class="gs-flex gs-justify-between gs-items-start gs-mb-sm">
                                        <div>
                                            <strong class="gs-text-lg"><?= h($rider['name']) ?></strong>
                                            <div class="gs-text-sm gs-text-secondary">ID: <?= $rider['id'] ?></div>
                                        </div>
                                        <span class="gs-badge gs-badge-secondary"><?= $rider['results'] ?> resultat</span>
                                    </div>

                                    <?php if (!empty($rider['missing'])): ?>
                                    <div class="gs-alert gs-alert-warning gs-mb-sm" style="padding: 0.5rem;">
                                        <small><strong>Saknar:</strong> <?= implode(', ', $rider['missing']) ?></small>
                                    </div>
                                    <?php endif; ?>

                                    <table class="gs-text-sm" style="width: 100%;">
                                        <tr>
                                            <td class="gs-text-secondary" style="width: 90px;">Födelseår:</td>
                                            <td><?= $rider['birth_year'] ?: '<span class="gs-text-error">-</span>' ?></td>
                                        </tr>
                                        <tr>
                                            <td class="gs-text-secondary">License:</td>
                                            <td><code><?= h($rider['license'] ?: '-') ?></code></td>
                                        </tr>
                                        <tr>
                                            <td class="gs-text-secondary">Licenstyp:</td>
                                            <td><?= h($rider['license_type'] ?: '-') ?></td>
                                        </tr>
                                        <tr>
                                            <td class="gs-text-secondary">Klubb:</td>
                                            <td><?= h($rider['club'] ?: '-') ?></td>
                                        </tr>
                                        <tr>
                                            <td class="gs-text-secondary">E-post:</td>
                                            <td><?= h($rider['email'] ?: '-') ?></td>
                                        </tr>
                                    </table>

                                    <div class="gs-mt-md gs-flex gs-gap-sm">
                                        <a href="/rider.php?id=<?= $rider['id'] ?>" target="_blank" class="gs-btn gs-btn-sm gs-btn-outline">
                                            <i data-lucide="external-link"></i>
                                        </a>
                                        <?php
                                        $otherId = $key === 'rider1' ? $dup['rider2']['id'] : $dup['rider1']['id'];
                                        ?>
                                        <a href="?action=merge&keep=<?= $rider['id'] ?>&remove=<?= $otherId ?>"
                                           class="gs-btn gs-btn-sm gs-btn-success gs-flex-1"
                                           onclick="return confirm('Behåll denna och slå ihop med den andra?')">
                                            <i data-lucide="check"></i> Behåll
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php render_admin_footer(); ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
