<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get sample riders with license data
$riders = $db->getAll("
    SELECT
        id,
        firstname,
        lastname,
        license_number,
        license_type,
        license_valid_until,
        active
    FROM riders
    WHERE active = 1
    LIMIT 20
");

$pageTitle = 'License Debug';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <h1 class="gs-h1 gs-mb-lg">License Data Debug</h1>

        <div class="gs-card">
            <div class="gs-card-content">
                <table class="gs-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Namn</th>
                            <th>License Number</th>
                            <th>License Type</th>
                            <th>License Valid Until</th>
                            <th>Check Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riders as $rider): ?>
                            <?php $check = checkLicense($rider); ?>
                            <tr>
                                <td><?= $rider['id'] ?></td>
                                <td><?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?></td>
                                <td>
                                    <?= h($rider['license_number']) ?>
                                    <?php if (strpos($rider['license_number'], 'SWE') === 0): ?>
                                        <span class="gs-badge gs-badge-warning gs-badge-sm">SWE-ID</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?= var_export($rider['license_type'], true) ?></code>
                                </td>
                                <td>
                                    <code><?= var_export($rider['license_valid_until'], true) ?></code>
                                    <?php if ($rider['license_valid_until'] && $rider['license_valid_until'] !== '0000-00-00'): ?>
                                        <br><small>Parsed: <?= date('Y-m-d', strtotime($rider['license_valid_until'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>
                                        <strong>Valid:</strong> <?= $check['valid'] ? 'TRUE' : 'FALSE' ?><br>
                                        <strong>Message:</strong> <?= h($check['message']) ?><br>
                                        <strong>Class:</strong> <?= h($check['class']) ?>
                                    </div>
                                    <span class="<?= $check['class'] ?>">
                                        <?= $check['valid'] ? '✓' : '✗' ?> <?= h($check['message']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">checkLicense() Function Test</h2>
            </div>
            <div class="gs-card-content">
                <pre class="gs-pre-gray"><?php
                echo "Test 1 - Valid license:\n";
                $test1 = [
                    'license_type' => 'Elite',
                    'license_valid_until' => date('Y-m-d', strtotime('+60 days'))
                ];
                print_r(checkLicense($test1));

                echo "\n\nTest 2 - Expiring soon:\n";
                $test2 = [
                    'license_type' => 'Elite',
                    'license_valid_until' => date('Y-m-d', strtotime('+15 days'))
                ];
                print_r(checkLicense($test2));

                echo "\n\nTest 3 - Expired:\n";
                $test3 = [
                    'license_type' => 'Elite',
                    'license_valid_until' => date('Y-m-d', strtotime('-30 days'))
                ];
                print_r(checkLicense($test3));

                echo "\n\nTest 4 - No date:\n";
                $test4 = [
                    'license_type' => 'Elite',
                    'license_valid_until' => null
                ];
                print_r(checkLicense($test4));

                echo "\n\nTest 5 - 0000-00-00:\n";
                $test5 = [
                    'license_type' => 'Elite',
                    'license_valid_until' => '0000-00-00'
                ];
                print_r(checkLicense($test5));
                ?></pre>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
