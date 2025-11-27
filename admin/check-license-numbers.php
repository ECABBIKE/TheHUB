<?php
/**
 * Check License Numbers
 * Find riders with potentially incorrect license_number formats
 */

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();

// Find riders where license_number looks like a SWE ID but doesn't start with "SWE"
// SWE IDs are typically numeric and might be stored without the SWE prefix
// UCI IDs are 11 digits starting with country code

// Get rider 10915 first
$specificRider = $db->getRow("SELECT * FROM riders WHERE id = 10915");

// Find patterns:
// 1. License numbers that are 5-8 digits (could be SWE IDs without prefix)
// 2. License numbers that don't match UCI format (11 digits)
// 3. License numbers that might be SWE-formatted

$suspiciousRiders = $db->getAll("
    SELECT
        r.id,
        r.firstname,
        r.lastname,
        r.license_number,
        r.license_type,
        r.license_year,
        r.birth_year,
        c.name as club_name,
        LENGTH(r.license_number) as license_length,
        CASE
            WHEN r.license_number REGEXP '^[0-9]{5,8}$' THEN 'Possible SWE ID (5-8 digits)'
            WHEN r.license_number REGEXP '^[0-9]{9,10}$' THEN 'Possible short UCI (9-10 digits)'
            WHEN r.license_number REGEXP '^SWE' THEN 'SWE format (correct)'
            WHEN r.license_number REGEXP '^[0-9]{11}$' THEN 'UCI format (11 digits)'
            WHEN r.license_number REGEXP '[A-Z]{3}[0-9]+' THEN 'Country prefix format'
            ELSE 'Unknown format'
        END as format_type
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.license_number IS NOT NULL
    AND r.license_number != ''
    AND r.license_number NOT REGEXP '^SWE'
    AND r.license_number NOT REGEXP '^[0-9]{11}$'
    ORDER BY
        CASE
            WHEN r.license_number REGEXP '^[0-9]{5,8}$' THEN 1
            ELSE 2
        END,
        r.id DESC
    LIMIT 100
");

// Count by format type
$formatCounts = $db->getAll("
    SELECT
        CASE
            WHEN license_number REGEXP '^SWE' THEN 'SWE format'
            WHEN license_number REGEXP '^[0-9]{11}$' THEN 'UCI format (11 digits)'
            WHEN license_number REGEXP '^[0-9]{5,8}$' THEN 'Possible SWE ID (5-8 digits)'
            WHEN license_number REGEXP '^[0-9]{9,10}$' THEN 'Short number (9-10 digits)'
            WHEN license_number REGEXP '[A-Z]{3}[0-9]+' THEN 'Country prefix'
            ELSE 'Other'
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

        <!-- Specific Rider Check -->
        <?php if ($specificRider): ?>
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="user"></i>
                    Ryttare ID 10915
                </h2>
            </div>
            <div class="gs-card-content">
                <table class="gs-table">
                    <tr>
                        <th>Fält</th>
                        <th>Värde</th>
                    </tr>
                    <tr>
                        <td>Namn</td>
                        <td><?= h($specificRider['firstname'] . ' ' . $specificRider['lastname']) ?></td>
                    </tr>
                    <tr>
                        <td>License Number</td>
                        <td><code><?= h($specificRider['license_number'] ?? 'NULL') ?></code></td>
                    </tr>
                    <tr>
                        <td>License Type</td>
                        <td><?= h($specificRider['license_type'] ?? 'NULL') ?></td>
                    </tr>
                    <tr>
                        <td>License Year</td>
                        <td><?= h($specificRider['license_year'] ?? 'NULL') ?></td>
                    </tr>
                    <tr>
                        <td>Födelseår</td>
                        <td><?= h($specificRider['birth_year'] ?? 'NULL') ?></td>
                    </tr>
                    <tr>
                        <td>Gravity ID</td>
                        <td><?= h($specificRider['gravity_id'] ?? 'NULL') ?></td>
                    </tr>
                </table>
                <div class="gs-mt-md">
                    <a href="/rider.php?id=10915" class="gs-btn gs-btn-outline" target="_blank">
                        <i data-lucide="external-link"></i>
                        Öppna profil
                    </a>
                    <a href="/admin/edit-rider.php?id=10915" class="gs-btn gs-btn-primary">
                        <i data-lucide="edit"></i>
                        Redigera
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Format Statistics -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="bar-chart"></i>
                    License Number Format Statistik
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
                        <tr>
                            <td><?= h($format['format_type']) ?></td>
                            <td><?= number_format($format['count']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Suspicious Riders -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-warning">
                    <i data-lucide="alert-triangle"></i>
                    Ryttare med ovanligt license_number format (<?= count($suspiciousRiders) ?>)
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($suspiciousRiders)): ?>
                    <p class="gs-text-secondary">Inga ryttare med ovanligt format hittades.</p>
                <?php else: ?>
                    <div class="gs-alert gs-alert-info gs-mb-md">
                        <i data-lucide="info"></i>
                        Visar ryttare vars license_number inte matchar standard UCI (11 siffror) eller SWE-format.
                    </div>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Namn</th>
                                    <th>License Number</th>
                                    <th>Format</th>
                                    <th>Licenstyp</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suspiciousRiders as $rider): ?>
                                <tr>
                                    <td><?= $rider['id'] ?></td>
                                    <td>
                                        <a href="/rider.php?id=<?= $rider['id'] ?>" target="_blank">
                                            <?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>
                                        </a>
                                    </td>
                                    <td><code><?= h($rider['license_number']) ?></code></td>
                                    <td>
                                        <span class="gs-badge <?= strpos($rider['format_type'], 'SWE') !== false ? 'gs-badge-warning' : 'gs-badge-secondary' ?>">
                                            <?= h($rider['format_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= h($rider['license_type'] ?? '-') ?></td>
                                    <td>
                                        <a href="/admin/edit-rider.php?id=<?= $rider['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline">
                                            <i data-lucide="edit"></i>
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

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
