<?php
/**
 * Smart Duplicate Finder
 * Find potential duplicate riders using multiple matching strategies:
 * - Exact name match
 * - Similar names (Levenshtein distance)
 * - Same birth year + similar name
 * - Same club + similar name
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
            if (!empty($removeRider['license_number']) &&
                (empty($keepRider['license_number']) ||
                 (strpos($keepRider['license_number'], 'SWE') === 0 && strpos($removeRider['license_number'], 'SWE') !== 0))) {
                $updates['license_number'] = $removeRider['license_number'];
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

            $_SESSION['dup_message'] = "Sammanfogade {$removeRider['firstname']} {$removeRider['lastname']} → {$keepRider['firstname']} {$keepRider['lastname']} ({$moved} resultat flyttade, {$deleted} dubbletter)";
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

// Calculate similarity between two names
function nameSimilarity($name1, $name2) {
    $name1 = mb_strtolower(trim($name1), 'UTF-8');
    $name2 = mb_strtolower(trim($name2), 'UTF-8');

    if ($name1 === $name2) return 100;

    // Try Levenshtein (limited to 255 chars)
    $name1 = substr($name1, 0, 255);
    $name2 = substr($name2, 0, 255);

    $maxLen = max(strlen($name1), strlen($name2));
    if ($maxLen === 0) return 0;

    $distance = levenshtein($name1, $name2);
    $similarity = (1 - $distance / $maxLen) * 100;

    return round($similarity);
}

// Find potential duplicates
$potentialDuplicates = [];

// Strategy 1: Same lastname + birth year, different firstname variations
$sameLastnameYear = $db->getAll("
    SELECT
        r1.id as id1, r1.firstname as fn1, r1.lastname as ln1, r1.birth_year as by1,
        r1.license_number as lic1, r1.email as email1, r1.club_id as club1,
        r1.license_type as lt1, r1.gender as g1,
        r2.id as id2, r2.firstname as fn2, r2.lastname as ln2, r2.birth_year as by2,
        r2.license_number as lic2, r2.email as email2, r2.club_id as club2,
        r2.license_type as lt2, r2.gender as g2,
        c1.name as club_name1, c2.name as club_name2,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r1.id) as results1,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r2.id) as results2
    FROM riders r1
    JOIN riders r2 ON r1.id < r2.id
        AND LOWER(r1.lastname) = LOWER(r2.lastname)
        AND r1.birth_year = r2.birth_year
        AND r1.birth_year IS NOT NULL
    LEFT JOIN clubs c1 ON r1.club_id = c1.id
    LEFT JOIN clubs c2 ON r2.club_id = c2.id
    WHERE LOWER(r1.firstname) != LOWER(r2.firstname)
    ORDER BY r1.lastname, r1.birth_year
    LIMIT 100
");

foreach ($sameLastnameYear as $pair) {
    $similarity = nameSimilarity($pair['fn1'], $pair['fn2']);
    if ($similarity >= 50) { // At least 50% similar first names
        $potentialDuplicates[] = [
            'type' => 'Samma efternamn + födelseår',
            'similarity' => $similarity,
            'rider1' => [
                'id' => $pair['id1'],
                'name' => $pair['fn1'] . ' ' . $pair['ln1'],
                'birth_year' => $pair['by1'],
                'license' => $pair['lic1'],
                'email' => $pair['email1'],
                'club' => $pair['club_name1'],
                'license_type' => $pair['lt1'],
                'gender' => $pair['g1'],
                'results' => $pair['results1']
            ],
            'rider2' => [
                'id' => $pair['id2'],
                'name' => $pair['fn2'] . ' ' . $pair['ln2'],
                'birth_year' => $pair['by2'],
                'license' => $pair['lic2'],
                'email' => $pair['email2'],
                'club' => $pair['club_name2'],
                'license_type' => $pair['lt2'],
                'gender' => $pair['g2'],
                'results' => $pair['results2']
            ]
        ];
    }
}

// Strategy 2: Very similar full names (regardless of birth year)
$allRiders = $db->getAll("
    SELECT
        r.id, r.firstname, r.lastname, r.birth_year, r.license_number,
        r.email, r.club_id, r.license_type, r.gender,
        c.name as club_name,
        (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    ORDER BY r.lastname, r.firstname
");

// Compare riders with very similar names
for ($i = 0; $i < count($allRiders) - 1; $i++) {
    $r1 = $allRiders[$i];
    $fullName1 = $r1['firstname'] . ' ' . $r1['lastname'];

    for ($j = $i + 1; $j < min($i + 20, count($allRiders)); $j++) { // Check next 20 riders (sorted by name)
        $r2 = $allRiders[$j];
        $fullName2 = $r2['firstname'] . ' ' . $r2['lastname'];

        // Skip if already found by other strategy
        $alreadyFound = false;
        foreach ($potentialDuplicates as $dup) {
            if (($dup['rider1']['id'] == $r1['id'] && $dup['rider2']['id'] == $r2['id']) ||
                ($dup['rider1']['id'] == $r2['id'] && $dup['rider2']['id'] == $r1['id'])) {
                $alreadyFound = true;
                break;
            }
        }
        if ($alreadyFound) continue;

        $similarity = nameSimilarity($fullName1, $fullName2);

        if ($similarity >= 85) { // Very similar names
            $potentialDuplicates[] = [
                'type' => 'Liknande namn (' . $similarity . '%)',
                'similarity' => $similarity,
                'rider1' => [
                    'id' => $r1['id'],
                    'name' => $fullName1,
                    'birth_year' => $r1['birth_year'],
                    'license' => $r1['license_number'],
                    'email' => $r1['email'],
                    'club' => $r1['club_name'],
                    'license_type' => $r1['license_type'],
                    'gender' => $r1['gender'],
                    'results' => $r1['result_count']
                ],
                'rider2' => [
                    'id' => $r2['id'],
                    'name' => $fullName2,
                    'birth_year' => $r2['birth_year'],
                    'license' => $r2['license_number'],
                    'email' => $r2['email'],
                    'club' => $r2['club_name'],
                    'license_type' => $r2['license_type'],
                    'gender' => $r2['gender'],
                    'results' => $r2['result_count']
                ]
            ];
        }
    }

    if (count($potentialDuplicates) >= 100) break; // Limit results
}

// Sort by similarity (highest first)
usort($potentialDuplicates, function($a, $b) {
    return $b['similarity'] - $a['similarity'];
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
                Hittar potentiella dubbletter baserat på:<br>
                - Samma efternamn + födelseår med liknande förnamn<br>
                - Namn som är 85%+ lika (fuzzy matching)
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
                            <span class="gs-badge gs-badge-warning"><?= h($dup['type']) ?></span>
                            <span class="gs-text-sm gs-text-secondary">#<?= $idx + 1 ?></span>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                                <!-- Rider 1 -->
                                <div style="padding: 1rem; background: rgba(40,167,69,0.05); border-radius: 8px;">
                                    <div class="gs-flex gs-justify-between gs-items-start gs-mb-sm">
                                        <div>
                                            <strong class="gs-text-lg"><?= h($dup['rider1']['name']) ?></strong>
                                            <div class="gs-text-sm gs-text-secondary">ID: <?= $dup['rider1']['id'] ?></div>
                                        </div>
                                        <span class="gs-badge gs-badge-success"><?= $dup['rider1']['results'] ?> resultat</span>
                                    </div>
                                    <table class="gs-text-sm" style="width: 100%;">
                                        <tr><td class="gs-text-secondary" style="width: 100px;">Födelseår:</td><td><?= $dup['rider1']['birth_year'] ?: '<span class="gs-text-error">Saknas</span>' ?></td></tr>
                                        <tr><td class="gs-text-secondary">License:</td><td><code><?= h($dup['rider1']['license'] ?: '-') ?></code></td></tr>
                                        <tr><td class="gs-text-secondary">Licenstyp:</td><td><?= h($dup['rider1']['license_type'] ?: '-') ?></td></tr>
                                        <tr><td class="gs-text-secondary">Klubb:</td><td><?= h($dup['rider1']['club'] ?: '-') ?></td></tr>
                                        <tr><td class="gs-text-secondary">E-post:</td><td><?= h($dup['rider1']['email'] ?: '-') ?></td></tr>
                                        <tr><td class="gs-text-secondary">Kön:</td><td><?= h($dup['rider1']['gender'] ?: '-') ?></td></tr>
                                    </table>
                                    <div class="gs-mt-md gs-flex gs-gap-sm">
                                        <a href="/rider.php?id=<?= $dup['rider1']['id'] ?>" target="_blank" class="gs-btn gs-btn-sm gs-btn-outline">
                                            <i data-lucide="external-link"></i> Visa
                                        </a>
                                        <a href="?action=merge&keep=<?= $dup['rider1']['id'] ?>&remove=<?= $dup['rider2']['id'] ?>"
                                           class="gs-btn gs-btn-sm gs-btn-success"
                                           onclick="return confirm('Behåll denna och ta bort den andra?')">
                                            <i data-lucide="check"></i> Behåll denna
                                        </a>
                                    </div>
                                </div>

                                <!-- Rider 2 -->
                                <div style="padding: 1rem; background: rgba(220,53,69,0.05); border-radius: 8px;">
                                    <div class="gs-flex gs-justify-between gs-items-start gs-mb-sm">
                                        <div>
                                            <strong class="gs-text-lg"><?= h($dup['rider2']['name']) ?></strong>
                                            <div class="gs-text-sm gs-text-secondary">ID: <?= $dup['rider2']['id'] ?></div>
                                        </div>
                                        <span class="gs-badge gs-badge-success"><?= $dup['rider2']['results'] ?> resultat</span>
                                    </div>
                                    <table class="gs-text-sm" style="width: 100%;">
                                        <tr><td class="gs-text-secondary" style="width: 100px;">Födelseår:</td><td><?= $dup['rider2']['birth_year'] ?: '<span class="gs-text-error">Saknas</span>' ?></td></tr>
                                        <tr><td class="gs-text-secondary">License:</td><td><code><?= h($dup['rider2']['license'] ?: '-') ?></code></td></tr>
                                        <tr><td class="gs-text-secondary">Licenstyp:</td><td><?= h($dup['rider2']['license_type'] ?: '-') ?></td></tr>
                                        <tr><td class="gs-text-secondary">Klubb:</td><td><?= h($dup['rider2']['club'] ?: '-') ?></td></tr>
                                        <tr><td class="gs-text-secondary">E-post:</td><td><?= h($dup['rider2']['email'] ?: '-') ?></td></tr>
                                        <tr><td class="gs-text-secondary">Kön:</td><td><?= h($dup['rider2']['gender'] ?: '-') ?></td></tr>
                                    </table>
                                    <div class="gs-mt-md gs-flex gs-gap-sm">
                                        <a href="/rider.php?id=<?= $dup['rider2']['id'] ?>" target="_blank" class="gs-btn gs-btn-sm gs-btn-outline">
                                            <i data-lucide="external-link"></i> Visa
                                        </a>
                                        <a href="?action=merge&keep=<?= $dup['rider2']['id'] ?>&remove=<?= $dup['rider1']['id'] ?>"
                                           class="gs-btn gs-btn-sm gs-btn-success"
                                           onclick="return confirm('Behåll denna och ta bort den andra?')">
                                            <i data-lucide="check"></i> Behåll denna
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="gs-mt-lg">
            <a href="/admin/cleanup-duplicates.php" class="gs-btn gs-btn-outline">
                <i data-lucide="git-merge"></i>
                Gå till gamla dubblettverktyget
            </a>
        </div>

        <?php render_admin_footer(); ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
