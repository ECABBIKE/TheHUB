<?php
/**
 * Migration Runner: Add Extended Rider Fields
 * Date: 2025-11-15
 *
 * This migration adds fields for full rider data including:
 * - Address information (address, postal_code, country)
 * - Emergency contact
 * - District and Team
 * - Multiple disciplines (JSON)
 * - License year
 *
 * IMPORTANT: These fields contain PRIVATE data and must NOT be exposed publicly
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$errors = [];
$success = [];

// Run each migration step
$migrations = [
    // Step 1: Add personnummer field
    "ALTER TABLE riders ADD COLUMN personnummer VARCHAR(15) AFTER birth_year" => "Add personnummer field",

    // Step 2: Add address fields
    "ALTER TABLE riders ADD COLUMN address VARCHAR(255) AFTER city" => "Add address field",
    "ALTER TABLE riders ADD COLUMN postal_code VARCHAR(10) AFTER address" => "Add postal_code field",
    "ALTER TABLE riders ADD COLUMN country VARCHAR(100) DEFAULT 'Sverige' AFTER postal_code" => "Add country field",

    // Step 3: Add emergency contact
    "ALTER TABLE riders ADD COLUMN emergency_contact VARCHAR(255) AFTER phone" => "Add emergency_contact field",

    // Step 4: Add district and team
    "ALTER TABLE riders ADD COLUMN district VARCHAR(100) AFTER country" => "Add district field",
    "ALTER TABLE riders ADD COLUMN team VARCHAR(255) AFTER club_id" => "Add team field",

    // Step 5: Add disciplines JSON field
    "ALTER TABLE riders ADD COLUMN disciplines JSON AFTER discipline" => "Add disciplines JSON field",

    // Step 6: Add license year
    "ALTER TABLE riders ADD COLUMN license_year INT AFTER license_valid_until" => "Add license_year field",
];

// Run migrations
foreach ($migrations as $sql => $description) {
    try {
        $db->query($sql);
        $success[] = "✓ " . $description;
    } catch (Exception $e) {
        // Check if error is because column already exists
        if (strpos($e->getMessage(), 'Duplicate column name') !== false ||
            strpos($e->getMessage(), 'column') !== false) {
            $success[] = "↷ " . $description . " (already exists)";
        } else {
            $errors[] = "✗ " . $description . ": " . $e->getMessage();
        }
    }
}

// Add indexes
$indexes = [
    "ALTER TABLE riders ADD INDEX idx_personnummer (personnummer)" => "Add personnummer index",
    "ALTER TABLE riders ADD INDEX idx_postal_code (postal_code)" => "Add postal_code index",
    "ALTER TABLE riders ADD INDEX idx_district (district)" => "Add district index",
];

foreach ($indexes as $sql => $description) {
    try {
        $db->query($sql);
        $success[] = "✓ " . $description;
    } catch (Exception $e) {
        // Check if error is because index already exists
        if (strpos($e->getMessage(), 'Duplicate key name') !== false ||
            strpos($e->getMessage(), 'duplicate') !== false ||
            strpos($e->getMessage(), 'exists') !== false) {
            $success[] = "↷ " . $description . " (already exists)";
        } else {
            $errors[] = "✗ " . $description . ": " . $e->getMessage();
        }
    }
}

$pageTitle = 'Migration: Extended Rider Fields';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h1 gs-text-primary gs-mb-xl">
            <i data-lucide="database"></i>
            Migration: Extended Rider Fields
        </h1>

        <?php if (!empty($success)): ?>
            <div class="gs-card gs-mb-lg" style="border-left: 4px solid var(--gs-success);">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-success">
                        <i data-lucide="check-circle"></i>
                        Success (<?= count($success) ?>)
                    </h2>
                </div>
                <div class="gs-card-content">
                    <?php foreach ($success as $msg): ?>
                        <div class="gs-text-sm gs-mb-xs" style="font-family: monospace;">
                            <?= h($msg) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="gs-card gs-mb-lg" style="border-left: 4px solid var(--gs-danger);">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-danger">
                        <i data-lucide="alert-circle"></i>
                        Errors (<?= count($errors) ?>)
                    </h2>
                </div>
                <div class="gs-card-content">
                    <?php foreach ($errors as $error): ?>
                        <div class="gs-text-sm gs-mb-xs gs-text-danger" style="font-family: monospace;">
                            <?= h($error) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="info"></i>
                    Migration Information
                </h2>
            </div>
            <div class="gs-card-content">
                <h3 class="gs-h5 gs-mb-md">Added Fields:</h3>
                <ul class="gs-text-sm" style="line-height: 1.8; margin-left: var(--gs-space-lg);">
                    <li><code>personnummer</code> - Swedish personal number (YYYYMMDD-XXXX)</li>
                    <li><code>address</code> - Street address</li>
                    <li><code>postal_code</code> - Postal code</li>
                    <li><code>country</code> - Country (default: Sverige)</li>
                    <li><code>emergency_contact</code> - Emergency contact information</li>
                    <li><code>district</code> - District/Region</li>
                    <li><code>team</code> - Team name (separate from club)</li>
                    <li><code>disciplines</code> - Multiple disciplines in JSON format (Road, Track, BMX, CX, Trial, Para, MTB, E-cycling, Gravel)</li>
                    <li><code>license_year</code> - License year</li>
                </ul>

                <div class="gs-alert gs-alert-warning gs-mt-lg">
                    <i data-lucide="shield-alert"></i>
                    <strong>PRIVACY WARNING:</strong> The following fields contain PRIVATE data and must NOT be exposed publicly:
                    <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                        <li><code>personnummer</code></li>
                        <li><code>address</code></li>
                        <li><code>postal_code</code></li>
                        <li><code>phone</code></li>
                        <li><code>emergency_contact</code></li>
                    </ul>
                </div>

                <div class="gs-mt-lg">
                    <a href="/admin/import-riders-extended.php" class="gs-btn gs-btn-primary">
                        <i data-lucide="upload"></i>
                        Go to Extended Import
                    </a>
                    <a href="/admin/riders.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="users"></i>
                        View Riders
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
