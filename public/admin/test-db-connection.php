<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_admin();

header('Content-Type: text/html; charset=UTF-8');

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Database Connection Test</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;}";
echo ".success{color:green;font-weight:bold;}.error{color:red;font-weight:bold;}.warning{color:orange;font-weight:bold;}";
echo "pre{background:#fff;padding:15px;border:1px solid #ddd;border-radius:4px;overflow-x:auto;}";
echo "h2{border-bottom:2px solid #333;padding-bottom:10px;}</style></head><body>";

echo "<h1>🔍 Database Connection Deep Test</h1>";

// TEST 1: Check if getDB() returns something
echo "<h2>Test 1: Database Object</h2>";
try {
    $db = getDB();
    if ($db) {
        echo "<p class='success'>✅ getDB() returned an object</p>";
        echo "<pre>";
        echo "Object type: " . get_class($db) . "\n";
        echo "</pre>";
    } else {
        echo "<p class='error'>❌ getDB() returned NULL or false</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// TEST 2: Check PDO connection directly
echo "<h2>Test 2: PDO Connection</h2>";
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "<p class='success'>✅ Global \$pdo exists and is a PDO instance</p>";

        // Try a simple query
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch();
        if ($result['test'] == 1) {
            echo "<p class='success'>✅ Can execute simple SELECT query</p>";
        }

        // Show current database
        $stmt = $pdo->query("SELECT DATABASE() as current_db");
        $result = $stmt->fetch();
        echo "<p><strong>Current database:</strong> " . htmlspecialchars($result['current_db'] ?? 'NONE') . "</p>";

    } else {
        echo "<p class='error'>❌ \$pdo is not set or not a PDO instance</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ PDO Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// TEST 3: List all tables
echo "<h2>Test 3: Tables in Database</h2>";
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "<p class='error'>❌ NO TABLES FOUND IN DATABASE!</p>";
        echo "<p class='warning'>⚠️ The database exists but is completely empty. You need to run the schema migration!</p>";
    } else {
        echo "<p class='success'>✅ Found " . count($tables) . " tables:</p>";
        echo "<ul>";
        foreach ($tables as $table) {
            echo "<li>" . htmlspecialchars($table) . "</li>";
        }
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error listing tables: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// TEST 4: If riders table exists, show its structure
echo "<h2>Test 4: Riders Table Structure</h2>";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'riders'");
    $exists = $stmt->fetch();

    if ($exists) {
        echo "<p class='success'>✅ 'riders' table exists</p>";

        // Show structure
        $stmt = $pdo->query("DESCRIBE riders");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($columns)) {
            echo "<p class='error'>❌ Table exists but has NO COLUMNS! This is impossible and indicates a serious issue.</p>";
        } else {
            echo "<p class='success'>✅ Found " . count($columns) . " columns:</p>";
            echo "<table class='gs-table'>";
            echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
            foreach ($columns as $col) {
                echo "<tr>";
                echo "<td><strong>" . htmlspecialchars($col['Field']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
                echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
                echo "<td>" . htmlspecialchars($col['Key'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
                echo "<td>" . htmlspecialchars($col['Extra'] ?? '') . "</td>";
                echo "</tr>";
            }
            echo "</table>";

            // Check if 'active' column exists
            $activeExists = false;
            foreach ($columns as $col) {
                if ($col['Field'] === 'active') {
                    $activeExists = true;
                    break;
                }
            }

            if ($activeExists) {
                echo "<p class='success'>✅ 'active' column exists</p>";
            } else {
                echo "<p class='warning'>⚠️ 'active' column does NOT exist - this will cause query failures</p>";
            }
        }
    } else {
        echo "<p class='error'>❌ 'riders' table does NOT exist!</p>";
        echo "<p class='warning'>⚠️ You need to create the database schema first!</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error checking riders table: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// TEST 5: Try to insert a test record
echo "<h2>Test 5: Test INSERT</h2>";
try {
    // First check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'riders'");
    if ($stmt->fetch()) {
        echo "<p>Attempting to insert a test rider...</p>";

        $testData = [
            'firstname' => 'TEST',
            'lastname' => 'RIDER',
            'birth_year' => 1990,
            'gender' => 'M',
            'active' => 1,
            'license_number' => 'TEST-' . time()
        ];

        try {
            $newId = $db->insert('riders', $testData);
            echo "<p class='success'>✅ Test insert successful! New ID: $newId</p>";

            // Verify it's actually there
            $verify = $db->getRow("SELECT * FROM riders WHERE id = ?", [$newId]);
            if ($verify) {
                echo "<p class='success'>✅ Verified: Test rider was saved to database</p>";
                echo "<pre>" . print_r($verify, true) . "</pre>";

                // Clean up
                $pdo->exec("DELETE FROM riders WHERE id = $newId");
                echo "<p>🧹 Test rider deleted</p>";
            } else {
                echo "<p class='error'>❌ Test rider was inserted but cannot be retrieved!</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Insert failed: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p class='warning'>This is why import appears to work but saves nothing!</p>";
        }
    } else {
        echo "<p class='warning'>⚠️ Cannot test insert - riders table doesn't exist</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error in insert test: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// TEST 6: Database configuration
echo "<h2>Test 6: Database Configuration</h2>";
echo "<pre>";
echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'NOT DEFINED') . "\n";
echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'NOT DEFINED') . "\n";
echo "DB_USER: " . (defined('DB_USER') ? DB_USER : 'NOT DEFINED') . "\n";
echo "DB_PASS: " . (defined('DB_PASS') ? '***SET***' : 'NOT DEFINED') . "\n";
echo "</pre>";

echo "<hr>";
echo "<h2>📋 Diagnosis Summary</h2>";
echo "<p>Based on the tests above, the issue is:</p>";
echo "<ol>";
echo "<li>If NO TABLES exist → You need to run database migration/schema setup</li>";
echo "<li>If tables exist but have NO COLUMNS → Database corruption or migration failed</li>";
echo "<li>If INSERT test fails → Database class or permissions issue</li>";
echo "<li>If INSERT works but original import doesn't save → Check import code logic</li>";
echo "</ol>";

echo "<p><a href='/admin/debug-database.php' class='gs-btn gs-btn-primary gs-mt-md'>← Back to Debug Page</a></p>";

echo "</body></html>";
