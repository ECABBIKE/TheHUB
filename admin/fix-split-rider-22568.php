<?php
/**
 * One-time fix: Split rider 22568 into two separate riders
 * - Keep H35 results with rider 22568
 * - Move 2019-2020 U21 results to a new rider
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

$originalRiderId = 22568;

// Get the original rider info
$originalRider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$originalRiderId]);

if (!$originalRider) {
    die("Rider $originalRiderId not found");
}

// Get all results grouped by year and class
$results = $db->getAll("
    SELECT r.id, r.event_id, r.class_id, r.position, r.points,
           e.name as event_name, e.date, YEAR(e.date) as year,
           c.name as class_name, c.display_name
    FROM results r
    JOIN events e ON r.event_id = e.id
    LEFT JOIN classes c ON r.class_id = c.id
    WHERE r.cyclist_id = ?
    ORDER BY e.date DESC
", [$originalRiderId]);

// The two different people:
// H21/U21: UCI 10022589765, Specialized Concept Store CK
// H35:     UCI 10107308050, Hunneberg Sport & Motionsklubb

$newRiderUciId = '10022589765';
$newRiderClubName = 'Specialized Concept Store CK';

// Identify results to move (U21/H21/Junior classes, typically 2019-2020)
$resultsToMove = [];
$resultsToKeep = [];

foreach ($results as $result) {
    $year = (int)$result['year'];
    $className = strtolower($result['class_name'] ?? '');
    $displayName = strtolower($result['display_name'] ?? '');

    // Check if this is a U21/H21/Junior result
    $isU21 = strpos($className, 'u21') !== false ||
             strpos($className, 'h21') !== false ||
             strpos($className, 'under 21') !== false ||
             strpos($displayName, 'u21') !== false ||
             strpos($displayName, 'h21') !== false ||
             strpos($displayName, 'under 21') !== false ||
             strpos($className, 'junior') !== false ||
             strpos($displayName, 'junior') !== false ||
             strpos($className, 'herrar 21') !== false ||
             strpos($displayName, 'herrar 21') !== false;

    if ($isU21) {
        $resultsToMove[] = $result;
    } else {
        $resultsToKeep[] = $result;
    }
}

// Handle the split
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_split'])) {
    checkCsrf();

    try {
        $db->beginTransaction();

        // Try to find the club "Specialized Concept Store CK"
        $specializedClub = $db->getRow("SELECT id FROM clubs WHERE name LIKE '%Specialized%' OR name LIKE '%concept store%' LIMIT 1");

        // Create new rider for U21 person
        $newRiderData = [
            'firstname' => $originalRider['firstname'],
            'lastname' => $originalRider['lastname'],
            'gender' => 'male',
            'nationality' => 'SWE',
            'active' => 1,
            'uci_id' => $newRiderUciId, // 10022589765
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Add club - either from form or auto-detected
        if (!empty($_POST['new_club_id'])) {
            $newRiderData['club_id'] = (int)$_POST['new_club_id'];
        } elseif ($specializedClub) {
            $newRiderData['club_id'] = $specializedClub['id'];
        }

        // Add birth year if provided
        if (!empty($_POST['new_birth_year'])) {
            $newRiderData['birth_year'] = (int)$_POST['new_birth_year'];
        }

        $newRiderId = $db->insert('riders', $newRiderData);

        // Move the identified results to the new rider
        $movedCount = 0;
        foreach ($resultsToMove as $result) {
            $db->update('results',
                ['cyclist_id' => $newRiderId],
                'id = ?',
                [$result['id']]
            );
            $movedCount++;
        }

        $db->commit();

        $message = "Klart! Skapade ny åkare (ID: $newRiderId) och flyttade $movedCount resultat.";
        $messageType = 'success';

        // Refresh data
        $resultsToMove = [];
        $resultsToKeep = $results;

    } catch (Exception $e) {
        $db->rollBack();
        $message = "Fel: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get clubs for dropdown
$clubs = $db->getAll("SELECT id, name FROM clubs WHERE active = 1 ORDER BY name");

$page_title = 'Dela åkare #' . $originalRiderId;
$breadcrumbs = [
    ['label' => 'Åkare', 'url' => '/admin/riders.php'],
    ['label' => 'Dela åkare']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= h($message) ?>
    <?php if ($messageType === 'success'): ?>
    <br><br>
    <a href="/rider/<?= $originalRiderId ?>" class="btn btn--secondary btn--sm">Visa original</a>
    <a href="/rider/<?= $newRiderId ?? '' ?>" class="btn btn--secondary btn--sm">Visa ny åkare</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <i data-lucide="user"></i>
            <?= h($originalRider['firstname'] . ' ' . $originalRider['lastname']) ?>
            <span class="text-muted">(ID: <?= $originalRiderId ?>)</span>
        </h2>
    </div>
    <div class="card-body">
        <p><strong>Totalt:</strong> <?= count($results) ?> resultat</p>
        <p><strong>Att flytta (U21, 2019-2020):</strong> <?= count($resultsToMove) ?> resultat</p>
        <p><strong>Att behålla (H35 m.m.):</strong> <?= count($resultsToKeep) ?> resultat</p>
    </div>
</div>

<?php if (!empty($resultsToMove)): ?>
<div class="grid grid-cols-2 gap-lg">
    <!-- Results to MOVE -->
    <div class="card">
        <div class="card-header" style="background: rgba(239, 68, 68, 0.1);">
            <h3 class="text-danger">
                <i data-lucide="arrow-right"></i>
                Flyttas till NY åkare (<?= count($resultsToMove) ?>)
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table--sm">
                    <thead>
                        <tr>
                            <th>År</th>
                            <th>Event</th>
                            <th>Klass</th>
                            <th>Plac</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultsToMove as $r): ?>
                        <tr>
                            <td><?= $r['year'] ?></td>
                            <td><?= h($r['event_name']) ?></td>
                            <td><?= h($r['display_name'] ?: $r['class_name']) ?></td>
                            <td><?= $r['position'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Results to KEEP -->
    <div class="card">
        <div class="card-header" style="background: rgba(97, 206, 112, 0.1);">
            <h3 class="text-success">
                <i data-lucide="check"></i>
                Behålls på #<?= $originalRiderId ?> (<?= count($resultsToKeep) ?>)
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table--sm">
                    <thead>
                        <tr>
                            <th>År</th>
                            <th>Event</th>
                            <th>Klass</th>
                            <th>Plac</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultsToKeep as $r): ?>
                        <tr>
                            <td><?= $r['year'] ?></td>
                            <td><?= h($r['event_name']) ?></td>
                            <td><?= h($r['display_name'] ?: $r['class_name']) ?></td>
                            <td><?= $r['position'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Form -->
<div class="card mt-lg">
    <div class="card-header">
        <h3><i data-lucide="user-plus"></i> Skapa ny åkare för U21-resultaten</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>

            <div class="grid grid-cols-3 gap-md mb-lg">
                <div class="form-group">
                    <label>UCI-ID</label>
                    <input type="text" name="new_uci_id" class="input" value="<?= h($newRiderUciId) ?>" readonly style="background: #f0f0f0;">
                    <small class="text-muted">Förifyllt från importdata</small>
                </div>
                <div class="form-group">
                    <label>Klubb (valfritt)</label>
                    <select name="new_club_id" class="input">
                        <option value="">-- Välj klubb --</option>
                        <?php foreach ($clubs as $club): ?>
                        <option value="<?= $club['id'] ?>"><?= h($club['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Födelseår (valfritt)</label>
                    <input type="number" name="new_birth_year" class="input" placeholder="2001" min="1950" max="2010">
                </div>
            </div>

            <div class="alert alert--warning mb-lg">
                <i data-lucide="alert-triangle"></i>
                <strong>Detta kommer att:</strong>
                <ul class="mt-sm mb-0">
                    <li>Skapa en ny åkare med samma namn</li>
                    <li>Flytta <?= count($resultsToMove) ?> resultat till den nya åkaren</li>
                </ul>
            </div>

            <button type="submit" name="confirm_split" class="btn btn--primary btn-lg">
                <i data-lucide="scissors"></i>
                Dela åkare och flytta resultat
            </button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert--success">
    <i data-lucide="check-circle"></i>
    Inga U21-resultat från 2019-2020 hittades att flytta. Åkaren är redan korrekt eller har redan delats.
</div>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
