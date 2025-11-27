<?php
/**
 * Check License Numbers
 * Find riders with potentially incorrect license_number formats
 *
 * Valid formats:
 * - UCI ID: 10-11 digits starting with 100 or 101 (e.g., 10048820303)
 * - SWE ID: Starts with "SWE" followed by digits
 *
 * Invalid (should be cleared):
 * - Other numeric values (incorrectly created from results import)
 */

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$message = '';
$messageType = 'info';

// Handle clear action
if (isset($_GET['action']) && $_GET['action'] === 'clear' && isset($_GET['id'])) {
    $riderId = (int)$_GET['id'];
    $db->update('riders', ['license_number' => null], 'id = ?', [$riderId]);
    $message = "License number rensat för ryttare ID {$riderId}";
    $messageType = 'success';
}

// Handle bulk clear action
if (isset($_GET['action']) && $_GET['action'] === 'clear_all_invalid') {
    $cleared = $db->query("
        UPDATE riders
        SET license_number = NULL
        WHERE license_number IS NOT NULL
        AND license_number != ''
        AND license_number NOT REGEXP '^SWE'
        AND license_number NOT REGEXP '^10[01][0-9]{7,8}$'
    ");
    $affectedRows = $cleared->rowCount();
    $message = "Rensade {$affectedRows} ogiltiga license_number";
    $messageType = 'success';
}

// Valid UCI: 10-11 digits starting with 100 or 101
// Valid SWE: Starts with SWE
$invalidRiders = $db->getAll("
    SELECT
        r.id,
        r.firstname,
        r.lastname,
        r.license_number,
        r.license_type,
        r.license_year,
        r.birth_year,
        c.name as club_name,
        LENGTH(r.license_number) as license_length
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.license_number IS NOT NULL
    AND r.license_number != ''
    AND r.license_number NOT REGEXP '^SWE'
    AND r.license_number NOT REGEXP '^10[01][0-9]{7,8}$'
    ORDER BY r.id DESC
    LIMIT 200
");

// Count total invalid
$invalidCount = $db->getRow("
    SELECT COUNT(*) as count
    FROM riders
    WHERE license_number IS NOT NULL
    AND license_number != ''
    AND license_number NOT REGEXP '^SWE'
    AND license_number NOT REGEXP '^10[01][0-9]{7,8}$'
")['count'];

// Count by format type
$formatCounts = $db->getAll("
    SELECT
        CASE
            WHEN license_number REGEXP '^SWE' THEN 'SWE ID (korrekt)'
            WHEN license_number REGEXP '^10[01][0-9]{7,8}$' THEN 'UCI ID (korrekt)'
            ELSE 'Ogiltigt format (bör rensas)'
        END as format_type,
        COUNT(*) as count
    FROM riders
    WHERE license_number IS NOT NULL AND license_number != ''
    GROUP BY format_type
    ORDER BY count DESC
");

$pageTitle = 'Kontrollera License Numbers';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <?php render_admin_header('Kontrollera License Numbers', 'settings'); ?>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'info' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Format explanation -->
        <div class="gs-alert gs-alert-info gs-mb-lg">
            <i data-lucide="info"></i>
            <div>
                <strong>Giltiga format:</strong><br>
                - <strong>UCI ID:</strong> 10-11 siffror som börjar med 100 eller 101 (t.ex. 10048820303)<br>
                - <strong>SWE ID:</strong> Börjar med "SWE" följt av siffror<br><br>
                <strong>Ogiltiga:</strong> Allt annat (skapade felaktigt från resultatimport)
            </div>
        </div>

        <!-- Format Statistics -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="bar-chart"></i>
                    License Number Statistik
                </h2>
            </div>
            <div class="gs-card-content">
                <table class="gs-table">
                    <thead>
                        <tr>
                            <th>Format</th>
                            <th>Antal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($formatCounts as $format): ?>
                        <tr class="<?= strpos($format['format_type'], 'Ogiltigt') !== false ? 'gs-bg-warning-light' : '' ?>">
                            <td>
                                <?php if (strpos($format['format_type'], 'Ogiltigt') !== false): ?>
                                    <i data-lucide="alert-triangle" class="gs-text-warning"></i>
                                <?php else: ?>
                                    <i data-lucide="check-circle" class="gs-text-success"></i>
                                <?php endif; ?>
                                <?= h($format['format_type']) ?>
                            </td>
                            <td><?= number_format($format['count']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Invalid Riders -->
        <div class="gs-card">
            <div class="gs-card-header gs-flex gs-justify-between gs-items-center">
                <h2 class="gs-h4 gs-text-warning">
                    <i data-lucide="alert-triangle"></i>
                    Ryttare med ogiltigt license_number (<?= number_format($invalidCount) ?> totalt)
                </h2>
                <?php if ($invalidCount > 0): ?>
                <a href="?action=clear_all_invalid"
                   class="gs-btn gs-btn-danger"
                   onclick="return confirm('Är du säker? Detta rensar license_number för alla <?= $invalidCount ?> ryttare med ogiltigt format.')">
                    <i data-lucide="trash-2"></i>
                    Rensa alla ogiltiga (<?= $invalidCount ?>)
                </a>
                <?php endif; ?>
            </div>
            <div class="gs-card-content">
                <?php if (empty($invalidRiders)): ?>
                    <div class="gs-text-center gs-py-lg">
                        <i data-lucide="check-circle" style="width: 48px; height: 48px; color: var(--gs-success);"></i>
                        <p class="gs-text-success gs-mt-md">Inga ryttare med ogiltigt format!</p>
                    </div>
                <?php else: ?>
                    <p class="gs-text-secondary gs-mb-md">
                        Visar <?= count($invalidRiders) ?> av <?= number_format($invalidCount) ?> ryttare.
                        Dessa license_number är varken giltiga UCI ID eller SWE ID och bör rensas.
                    </p>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Namn</th>
                                    <th>Ogiltigt License Number</th>
                                    <th>Längd</th>
                                    <th>Licenstyp</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invalidRiders as $rider): ?>
                                <tr>
                                    <td><?= $rider['id'] ?></td>
                                    <td>
                                        <a href="/rider.php?id=<?= $rider['id'] ?>" target="_blank">
                                            <?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>
                                        </a>
                                    </td>
                                    <td><code class="gs-text-warning"><?= h($rider['license_number']) ?></code></td>
                                    <td><?= $rider['license_length'] ?> tecken</td>
                                    <td><?= h($rider['license_type'] ?? '-') ?></td>
                                    <td>
                                        <a href="?action=clear&id=<?= $rider['id'] ?>"
                                           class="gs-btn gs-btn-sm gs-btn-danger"
                                           onclick="return confirm('Rensa license_number för denna ryttare?')">
                                            <i data-lucide="x"></i>
                                            Rensa
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php render_admin_footer(); ?>
    </div>
</main>

<style>
.gs-bg-warning-light {
    background-color: rgba(255, 193, 7, 0.1);
}
</style>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
