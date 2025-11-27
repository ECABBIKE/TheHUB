<?php
/**
 * Smart Duplicate Finder
 * Find potential duplicate riders - only when one profile is missing data
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

// Helper functions - defined first
function normalizeUci($uci) {
    if (empty($uci)) return null;
    return preg_replace('/\s+/', '', $uci);
}

function isRealUci($license) {
    if (empty($license)) return false;
    return strpos($license, 'SWE') !== 0;
}

function nameSimilarity($name1, $name2) {
    $name1 = mb_strtolower(trim($name1), 'UTF-8');
    $name2 = mb_strtolower(trim($name2), 'UTF-8');
    if ($name1 === $name2) return 100;
    if (strlen($name1) < 2 || strlen($name2) < 2) return 0;
    if (strpos($name1, $name2) !== false || strpos($name2, $name1) !== false) {
        return 90;
    }
    $name1 = substr($name1, 0, 255);
    $name2 = substr($name2, 0, 255);
    $maxLen = max(strlen($name1), strlen($name2));
    if ($maxLen === 0) return 0;
    $distance = levenshtein($name1, $name2);
    return round((1 - $distance / $maxLen) * 100);
}

function areNamesSimilar($fn1, $ln1, $fn2, $ln2) {
    $fn1 = trim($fn1); $ln1 = trim($ln1);
    $fn2 = trim($fn2); $ln2 = trim($ln2);

    if (empty($fn1) || empty($ln1) || empty($fn2) || empty($ln2)) {
        return ['match' => false, 'reason' => null];
    }

    if (strtolower($fn1) === strtolower($fn2) && strtolower($ln1) === strtolower($ln2)) {
        return ['match' => true, 'reason' => 'Exakt samma namn'];
    }
    if (strtolower($ln1) === strtolower($ln2)) {
        $fnSim = nameSimilarity($fn1, $fn2);
        if ($fnSim >= 80) {
            return ['match' => true, 'reason' => "Samma efternamn, liknande förnamn ({$fnSim}%)"];
        }
    }
    $lnSim = nameSimilarity($ln1, $ln2);
    if ($lnSim >= 85 && strtolower($fn1) === strtolower($fn2)) {
        return ['match' => true, 'reason' => "Samma förnamn, liknande efternamn ({$lnSim}%)"];
    }
    $ln1Lower = strtolower($ln1);
    $ln2Lower = strtolower($ln2);
    if (strlen($ln1Lower) > 3 && strlen($ln2Lower) > 3) {
        if ((strpos($ln1Lower, $ln2Lower) !== false || strpos($ln2Lower, $ln1Lower) !== false) &&
            strtolower($fn1) === strtolower($fn2)) {
            return ['match' => true, 'reason' => 'Samma förnamn, dubbelt efternamn'];
        }
    }
    return ['match' => false, 'reason' => null];
}

function checkDuplicatePair($r1, $r2, $nameReason) {
    $uci1 = normalizeUci($r1['license_number']);
    $uci2 = normalizeUci($r2['license_number']);
    $isRealUci1 = isRealUci($r1['license_number']);
    $isRealUci2 = isRealUci($r2['license_number']);

    if ($isRealUci1 && $isRealUci2 && $uci1 !== $uci2) {
        return null;
    }
    if (!empty($r1['birth_year']) && !empty($r2['birth_year']) && $r1['birth_year'] !== $r2['birth_year']) {
        return null;
    }

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

    $sameUci = $isRealUci1 && $isRealUci2 && $uci1 === $uci2;
    $hasMissingData = !empty($r1Missing) || !empty($r2Missing);

    if (!$sameUci && !$hasMissingData) {
        return null;
    }

    return [
        'reason' => $sameUci ? 'Samma UCI ID' : $nameReason,
        'rider1' => [
            'id' => $r1['id'],
            'name' => $r1['firstname'] . ' ' . $r1['lastname'],
            'birth_year' => $r1['birth_year'],
            'license' => $r1['license_number'],
            'email' => $r1['email'],
            'club' => $r1['club_name'],
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
            'results' => $r2['result_count'],
            'missing' => $r2Missing
        ]
    ];
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

// Find potential duplicates
$potentialDuplicates = [];
$processedPairs = [];

$allRiders = $db->getAll("
    SELECT
        r.id, r.firstname, r.lastname, r.birth_year, r.license_number,
        r.email, r.club_id,
        c.name as club_name,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.firstname IS NOT NULL AND r.lastname IS NOT NULL
    ORDER BY r.lastname, r.firstname
");

// Group by lastname
$byLastname = [];
foreach ($allRiders as $r) {
    $lnKey = strtolower(trim($r['lastname']));
    if (strlen($lnKey) > 0) {
        $byLastname[$lnKey][] = $r;
    }
}

// Compare within same lastname groups
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

    if (count($potentialDuplicates) >= 50) break;
}

// Sort: Same UCI first
usort($potentialDuplicates, function($a, $b) {
    if ($a['reason'] === 'Samma UCI ID' && $b['reason'] !== 'Samma UCI ID') return -1;
    if ($b['reason'] === 'Samma UCI ID' && $a['reason'] !== 'Samma UCI ID') return 1;
    return 0;
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

        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">
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
                    <div style="border: 2px solid #ffc107; border-radius: 8px; margin-bottom: 1rem; overflow: hidden;">
                        <div style="background: rgba(255,193,7,0.15); padding: 0.5rem 1rem; display: flex; justify-content: space-between; align-items: center;">
                            <span class="gs-badge <?= $dup['reason'] === 'Samma UCI ID' ? 'gs-badge-danger' : 'gs-badge-warning' ?>">
                                <?= h($dup['reason']) ?>
                            </span>
                            <span style="color: #666; font-size: 0.875rem;">#<?= $idx + 1 ?></span>
                        </div>
                        <div style="padding: 1rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                            <?php foreach (['rider1', 'rider2'] as $key):
                                $rider = $dup[$key];
                                $isComplete = empty($rider['missing']);
                                $borderColor = $isComplete ? '#28a745' : '#dc3545';
                                $bgColor = $isComplete ? 'rgba(40,167,69,0.08)' : 'rgba(220,53,69,0.08)';
                            ?>
                            <div style="flex: 1; min-width: 280px; padding: 1rem; background: <?= $bgColor ?>; border: 2px solid <?= $borderColor ?>; border-radius: 8px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <div>
                                        <strong style="font-size: 1.1rem;"><?= h($rider['name']) ?></strong>
                                        <div style="font-size: 0.75rem; color: #666;">ID: <?= $rider['id'] ?></div>
                                    </div>
                                    <span class="gs-badge gs-badge-secondary"><?= $rider['results'] ?> resultat</span>
                                </div>

                                <?php if (!empty($rider['missing'])): ?>
                                <div style="background: rgba(255,193,7,0.2); padding: 0.5rem; border-radius: 4px; margin-bottom: 0.5rem; font-size: 0.875rem;">
                                    <strong>Saknar:</strong> <?= implode(', ', $rider['missing']) ?>
                                </div>
                                <?php endif; ?>

                                <table style="width: 100%; font-size: 0.875rem;">
                                    <tr>
                                        <td style="color: #666; width: 80px;">Födelseår:</td>
                                        <td><?= $rider['birth_year'] ?: '<span style="color: #dc3545;">-</span>' ?></td>
                                    </tr>
                                    <tr>
                                        <td style="color: #666;">License:</td>
                                        <td><code><?= h($rider['license'] ?: '-') ?></code></td>
                                    </tr>
                                    <tr>
                                        <td style="color: #666;">Klubb:</td>
                                        <td><?= h($rider['club'] ?: '-') ?></td>
                                    </tr>
                                    <tr>
                                        <td style="color: #666;">E-post:</td>
                                        <td><?= h($rider['email'] ?: '-') ?></td>
                                    </tr>
                                </table>

                                <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                                    <a href="/rider.php?id=<?= $rider['id'] ?>" target="_blank" class="gs-btn gs-btn-sm gs-btn-outline">
                                        <i data-lucide="external-link"></i>
                                    </a>
                                    <?php
                                    $otherId = $key === 'rider1' ? $dup['rider2']['id'] : $dup['rider1']['id'];
                                    ?>
                                    <a href="?action=merge&keep=<?= $rider['id'] ?>&remove=<?= $otherId ?>"
                                       class="gs-btn gs-btn-sm gs-btn-success" style="flex: 1;"
                                       onclick="return confirm('Behåll denna profil och ta bort den andra?\n\nResultat flyttas och data slås ihop.')">
                                        <i data-lucide="check"></i> Behåll denna
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
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
