<?php
require_once __DIR__ . '/../config.php';
require_admin();

$pageTitle = 'Event Fields Migration';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';

global $pdo;

$migrationFile = __DIR__ . '/../migrations/add_event_extended_fields.sql';
$sql = file_get_contents($migrationFile);

// Split by semicolons to execute multiple statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success = true;
$results = [];

try {
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }

        $pdo->exec($statement);
        $results[] = ['success' => true, 'statement' => substr($statement, 0, 100) . '...'];
    }

    $message = 'Migration completed successfully!';
    $messageType = 'success';
} catch (Exception $e) {
    $success = false;
    $message = 'Migration failed: ' . $e->getMessage();
    $messageType = 'error';
    $results[] = ['success' => false, 'error' => $e->getMessage()];
}
?>

<main class="gs-main-content">
    <div class="gs-container">
        <h1 class="gs-h2 gs-mb-lg">
            <i data-lucide="database"></i>
            Event Extended Fields Migration
        </h1>

        <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-xl">
            <p><strong><?= $messageType === 'success' ? 'Success' : 'Error' ?>:</strong> <?= h($message) ?></p>
        </div>

        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h3">Migration Details</h2>
            </div>
            <div class="gs-card-content">
                <h3 class="gs-h4 gs-mb-md">New Fields Added:</h3>
                <ul class="gs-list-spaced gs-mb-lg">
                    <li><strong>schedule</strong> - Event tidsschema (TEXT)</li>
                    <li><strong>practical_info</strong> - Praktisk information (TEXT)</li>
                    <li><strong>safety_rules</strong> - Säkerhets- och tävlingsregler (TEXT)</li>
                    <li><strong>course_description</strong> - Detaljerad banbeskrivning (TEXT)</li>
                    <li><strong>course_map_url</strong> - URL till bankarta (VARCHAR)</li>
                    <li><strong>gpx_file_url</strong> - URL till GPX-fil (VARCHAR)</li>
                    <li><strong>contact_email</strong> - Kontakt-email (VARCHAR)</li>
                    <li><strong>contact_phone</strong> - Kontakt-telefon (VARCHAR)</li>
                    <li><strong>parking_info</strong> - Parkeringsinformation (TEXT)</li>
                    <li><strong>accommodation_info</strong> - Boendeinformation (TEXT)</li>
                    <li><strong>food_info</strong> - Mat & catering (TEXT)</li>
                    <li><strong>prizes_info</strong> - Prisinformation (TEXT)</li>
                    <li><strong>sponsors</strong> - Sponsorinformation (TEXT)</li>
                </ul>

                <h3 class="gs-h4 gs-mb-md">Executed Statements:</h3>
                <?php foreach ($results as $result): ?>
                    <div class="gs-alert gs-alert-<?= $result['success'] ? 'success' : 'error' ?> gs-mb-sm">
                        <?php if ($result['success']): ?>
                            <i data-lucide="check"></i> <?= h($result['statement']) ?>
                        <?php else: ?>
                            <i data-lucide="x"></i> <?= h($result['error']) ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="gs-mt-lg">
            <a href="/admin/events.php" class="gs-btn gs-btn-primary">
                <i data-lucide="arrow-left"></i>
                Back to Events
            </a>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
