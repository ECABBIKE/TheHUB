<?php
/**
 * Database Connection Test Page
 * Use this to verify your database is configured correctly
 */
require_once __DIR__ . '/../config.php';

$pageTitle = 'Database Connection Test';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
    <style>
        .test-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
        }
        .test-result {
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .test-success {
            background: #d1fae5;
            border-color: #10b981;
            color: #065f46;
        }
        .test-error {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
        }
        .test-info {
            background: #dbeafe;
            border-color: #3b82f6;
            color: #1e40af;
        }
        .code-block {
            background: #1f2937;
            color: #f9fafb;
            padding: 1rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            overflow-x: auto;
            margin: 0.5rem 0;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <h1>🔍 Database Connection Test</h1>
        <p>This page verifies your TheHUB database configuration.</p>

        <?php
        $tests = [];
        $db = getDB();

        // TEST 1: Config files exist
        $tests[] = [
            'name' => 'Configuration Files',
            'description' => 'Check if config files exist',
            'result' => file_exists(__DIR__ . '/../config/database.php'),
            'success_msg' => 'config/database.php exists',
            'error_msg' => 'config/database.php is missing',
            'info' => file_exists(__DIR__ . '/../.env') ? '.env file also exists' : '.env file is missing (optional)'
        ];

        // TEST 2: Database constants
        $tests[] = [
            'name' => 'Database Constants',
            'description' => 'Check if DB constants are defined',
            'result' => defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER'),
            'success_msg' => 'All constants defined',
            'error_msg' => 'Missing DB constants',
            'info' => 'Host: ' . (defined('DB_HOST') ? DB_HOST : 'undefined') .
                     ' | DB: ' . (defined('DB_NAME') ? DB_NAME : 'undefined') .
                     ' | User: ' . (defined('DB_USER') ? DB_USER : 'undefined')
        ];

        // TEST 3: Not in demo mode
        $tests[] = [
            'name' => 'Demo Mode Check',
            'description' => 'Verify not running in demo mode',
            'result' => DB_NAME !== 'thehub_demo',
            'success_msg' => 'Production/Development mode (not demo)',
            'error_msg' => 'DEMO MODE ACTIVE - no data will be saved!',
            'info' => 'Database name: ' . DB_NAME
        ];

        // TEST 4: Database connection
        $conn = $db->getConnection();
        $tests[] = [
            'name' => 'Database Connection',
            'description' => 'Attempt to connect to database',
            'result' => $conn !== null,
            'success_msg' => 'Connected successfully',
            'error_msg' => 'Connection failed - check credentials and that MySQL is running',
            'info' => $conn ? 'PDO connection object created' : 'Connection is null'
        ];

        // TEST 5: Query test (if connected)
        if ($conn) {
            try {
                $stmt = $conn->query("SELECT VERSION() as version");
                $version = $stmt->fetch();
                $tests[] = [
                    'name' => 'Query Test',
                    'description' => 'Execute simple query',
                    'result' => true,
                    'success_msg' => 'Queries work!',
                    'error_msg' => '',
                    'info' => 'MySQL Version: ' . ($version['version'] ?? 'unknown')
                ];
            } catch (Exception $e) {
                $tests[] = [
                    'name' => 'Query Test',
                    'description' => 'Execute simple query',
                    'result' => false,
                    'success_msg' => '',
                    'error_msg' => 'Query failed: ' . $e->getMessage(),
                    'info' => ''
                ];
            }

            // TEST 6: Check if tables exist
            try {
                $stmt = $conn->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $expected_tables = ['riders', 'clubs', 'events', 'series', 'results', 'categories', 'venues', 'admin_users', 'import_history', 'import_records'];
                $existing_tables = array_intersect($expected_tables, $tables);
                $missing_tables = array_diff($expected_tables, $tables);

                $tests[] = [
                    'name' => 'Database Schema',
                    'description' => 'Check if required tables exist',
                    'result' => count($missing_tables) === 0,
                    'success_msg' => 'All ' . count($expected_tables) . ' tables exist',
                    'error_msg' => count($missing_tables) . ' tables missing: ' . implode(', ', $missing_tables),
                    'info' => 'Existing: ' . implode(', ', $existing_tables)
                ];

                // TEST 7: Count records
                if (in_array('riders', $tables)) {
                    $stmt = $conn->query("SELECT COUNT(*) as count FROM riders");
                    $count = $stmt->fetch()['count'];
                    $tests[] = [
                        'name' => 'Data Test',
                        'description' => 'Count riders in database',
                        'result' => true,
                        'success_msg' => "Found $count riders in database",
                        'error_msg' => '',
                        'info' => $count == 0 ? 'Database is empty - import some riders!' : ''
                    ];
                }

            } catch (Exception $e) {
                $tests[] = [
                    'name' => 'Database Schema',
                    'description' => 'Check tables',
                    'result' => false,
                    'success_msg' => '',
                    'error_msg' => 'Cannot check tables: ' . $e->getMessage(),
                    'info' => 'You may need to run database/schema.sql'
                ];
            }
        }

        // Display results
        foreach ($tests as $test) {
            $class = $test['result'] ? 'test-success' : 'test-error';
            $icon = $test['result'] ? '✅' : '❌';
            $msg = $test['result'] ? $test['success_msg'] : $test['error_msg'];
            ?>
            <div class="test-result <?= $class ?>">
                <h3><?= $icon ?> <?= h($test['name']) ?></h3>
                <p><?= h($test['description']) ?></p>
                <strong><?= h($msg) ?></strong>
                <?php if ($test['info']): ?>
                    <p class="gs-mt-2 gs-text-sm">
                        ℹ️ <?= h($test['info']) ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php
        }

        // Overall status
        $all_passed = array_reduce($tests, function($carry, $test) {
            return $carry && $test['result'];
        }, true);
        ?>

        <div class="test-result <?= $all_passed ? 'test-success' : 'test-info' ?>" class="gs-mt-8 gs-border-w-3">
            <h2><?= $all_passed ? '🎉 All Tests Passed!' : '⚠️ Some Tests Failed' ?></h2>
            <?php if ($all_passed): ?>
                <p>Your database is configured correctly and ready to use!</p>
                <p>
                    <a href="/admin/riders.php" class="gs-btn gs-btn-primary">Go to Admin Panel →</a>
                </p>
            <?php else: ?>
                <p><strong>Next Steps:</strong></p>
                <ol>
                    <li>If config/database.php is missing:
                        <div class="code-block">cp config/database.example.php config/database.php
nano config/database.php  # Edit with your credentials</div>
                    </li>
                    <li>If tables are missing:
                        <div class="code-block">mysql -u root -p thehub < database/schema.sql</div>
                    </li>
                    <li>If connection fails, check:
                        <ul>
                            <li>MySQL is running</li>
                            <li>Database name is correct</li>
                            <li>Username/password are correct</li>
                            <li>User has permissions on the database</li>
                        </ul>
                    </li>
                </ol>
            <?php endif; ?>
        </div>

        <div class="gs-mt-8 gs-bg-info-box">
            <h3>📚 Documentation</h3>
            <ul>
                <li><a href="../docs/DEPLOYMENT.md">Deployment Guide</a></li>
                <li><a href="../docs/BUG-REPORT.md">Bug Report</a></li>
                <li><a href="../docs/ROADMAP-2025.md">Development Roadmap</a></li>
            </ul>
        </div>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
