<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get event ID from URL
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if (!$eventId) {
    header('Location: /admin/results.php');
    exit;
}

// Fetch event details
$event = $db->getRow("SELECT e.*, s.name as series_name FROM events e LEFT JOIN series s ON e.series_id = s.id WHERE e.id = ?", [$eventId]);

if (!$event) {
    header('Location: /admin/results.php');
    exit;
}

$message = '';
$messageType = 'info';

// Handle form submission for updating results
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $resultId = isset($_POST['result_id']) ? (int)$_POST['result_id'] : 0;
    $action = $_POST['action'] ?? '';

    if ($action === 'update' && $resultId) {
        // Update result
        $updateData = [
            'position' => !empty($_POST['position']) ? (int)$_POST['position'] : null,
            'bib_number' => trim($_POST['bib_number'] ?? ''),
            'finish_time' => !empty($_POST['finish_time']) ? trim($_POST['finish_time']) : null,
            'points' => !empty($_POST['points']) ? (float)$_POST['points'] : 0,
            'status' => trim($_POST['status'] ?? 'finished'),
        ];

        try {
            $db->update('results', $updateData, 'id = ?', [$resultId]);
            $message = 'Resultat uppdaterat!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Fel vid uppdatering: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'delete' && $resultId) {
        // Delete result
        try {
            $db->delete('results', 'id = ?', [$resultId]);
            $message = 'Resultat borttaget!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Fel vid borttagning: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Fetch all results for this event
$results = $db->getAll("
    SELECT
        res.*,
        r.firstname,
        r.lastname,
        r.gender,
        r.birth_year,
        c.name as club_name,
        cat.name as category_name,
        cat.short_name as category_short
    FROM results res
    INNER JOIN riders r ON res.cyclist_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    LEFT JOIN categories cat ON res.category_id = cat.id
    WHERE res.event_id = ?
    ORDER BY
        COALESCE(cat.name, 'Okategoriserad'),
        CASE WHEN res.status = 'finished' THEN res.position ELSE 999 END,
        res.finish_time
", [$eventId]);

// Group results by category
$resultsByCategory = [];
foreach ($results as $result) {
    $categoryName = $result['category_name'] ?? 'Okategoriserad';
    if (!isset($resultsByCategory[$categoryName])) {
        $resultsByCategory[$categoryName] = [];
    }
    $resultsByCategory[$categoryName][] = $result;
}

// Get all categories for dropdown
$categories = $db->getAll("SELECT id, name FROM categories ORDER BY name");

$pageTitle = 'Editera Resultat - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <div>
                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="edit"></i>
                    Editera Resultat
                </h1>
                <h2 class="gs-h4 gs-text-secondary">
                    <?= h($event['name']) ?> - <?= date('Y-m-d', strtotime($event['date'])) ?>
                </h2>
            </div>
            <a href="/admin/results.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <!-- Message -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($results)): ?>
            <div class="gs-card">
                <div class="gs-card-content">
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga resultat hittades för detta event.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Results by Category -->
            <?php foreach ($resultsByCategory as $categoryName => $categoryResults): ?>
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h3 class="gs-h4 gs-text-primary">
                            <i data-lucide="users"></i>
                            <?= h($categoryName) ?>
                            <span class="gs-badge gs-badge-secondary gs-ml-sm">
                                <?= count($categoryResults) ?> deltagare
                            </span>
                        </h3>
                    </div>
                    <div class="gs-card-content" style="padding: 0; overflow-x: auto;">
                        <table class="gs-table" style="font-size: 0.9rem;">
                            <thead>
                                <tr>
                                    <th style="width: 60px; text-align: center;">Plac.</th>
                                    <th>Namn</th>
                                    <th style="width: 150px;">Klubb</th>
                                    <th style="width: 100px; text-align: center;">Startnr</th>
                                    <th style="width: 120px; text-align: center;">Tid</th>
                                    <th style="width: 80px; text-align: center;">Poäng</th>
                                    <th style="width: 120px; text-align: center;">Status</th>
                                    <th style="width: 120px; text-align: center;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categoryResults as $result): ?>
                                    <tr id="result-row-<?= $result['id'] ?>">
                                        <form method="POST" style="display: contents;" class="result-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="result_id" value="<?= $result['id'] ?>">
                                            <input type="hidden" name="action" value="update">

                                            <!-- Position -->
                                            <td style="text-align: center;">
                                                <input type="number"
                                                       name="position"
                                                       value="<?= h($result['position']) ?>"
                                                       class="gs-input"
                                                       style="width: 60px; text-align: center; padding: 0.25rem;"
                                                       min="1">
                                            </td>

                                            <!-- Name (read-only) -->
                                            <td>
                                                <strong><?= h($result['firstname']) ?> <?= h($result['lastname']) ?></strong>
                                                <div style="font-size: 0.75rem; color: var(--gs-text-secondary);">
                                                    <?php if ($result['birth_year']): ?>
                                                        <?= calculateAge($result['birth_year']) ?> år
                                                    <?php endif; ?>
                                                    <?php if ($result['gender']): ?>
                                                        • <?= $result['gender'] == 'M' ? 'Herr' : ($result['gender'] == 'F' ? 'Dam' : '') ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Club (read-only) -->
                                            <td>
                                                <?php if ($result['club_name']): ?>
                                                    <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                        <?= h($result['club_name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Bib Number -->
                                            <td style="text-align: center;">
                                                <input type="text"
                                                       name="bib_number"
                                                       value="<?= h($result['bib_number']) ?>"
                                                       class="gs-input"
                                                       style="width: 80px; text-align: center; padding: 0.25rem;">
                                            </td>

                                            <!-- Finish Time -->
                                            <td style="text-align: center;">
                                                <input type="text"
                                                       name="finish_time"
                                                       value="<?= h($result['finish_time']) ?>"
                                                       class="gs-input"
                                                       style="width: 100px; text-align: center; padding: 0.25rem; font-family: monospace;"
                                                       placeholder="HH:MM:SS">
                                            </td>

                                            <!-- Points -->
                                            <td style="text-align: center;">
                                                <input type="number"
                                                       name="points"
                                                       value="<?= h($result['points']) ?>"
                                                       class="gs-input"
                                                       style="width: 70px; text-align: center; padding: 0.25rem;"
                                                       step="1"
                                                       min="0">
                                            </td>

                                            <!-- Status -->
                                            <td style="text-align: center;">
                                                <select name="status" class="gs-input" style="padding: 0.25rem;">
                                                    <option value="finished" <?= $result['status'] === 'finished' ? 'selected' : '' ?>>Slutförd</option>
                                                    <option value="dnf" <?= $result['status'] === 'dnf' ? 'selected' : '' ?>>DNF</option>
                                                    <option value="dns" <?= $result['status'] === 'dns' ? 'selected' : '' ?>>DNS</option>
                                                    <option value="dq" <?= $result['status'] === 'dq' ? 'selected' : '' ?>>DQ</option>
                                                </select>
                                            </td>

                                            <!-- Actions -->
                                            <td style="text-align: center;">
                                                <div class="gs-flex gs-gap-xs gs-justify-center">
                                                    <button type="submit"
                                                            class="gs-btn gs-btn-primary gs-btn-sm"
                                                            title="Spara">
                                                        <i data-lucide="save" style="width: 14px; height: 14px;"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="gs-btn gs-btn-danger gs-btn-sm delete-result"
                                                            data-result-id="<?= $result['id'] ?>"
                                                            data-rider-name="<?= h($result['firstname'] . ' ' . $result['lastname']) ?>"
                                                            title="Ta bort">
                                                        <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();

    // Delete result confirmation
    document.querySelectorAll('.delete-result').forEach(btn => {
        btn.addEventListener('click', function() {
            const resultId = this.dataset.resultId;
            const riderName = this.dataset.riderName;

            if (confirm('Är du säker på att du vill ta bort resultatet för ' + riderName + '?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="result_id" value="${resultId}">
                    <input type="hidden" name="action" value="delete">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
