<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Step-by-step Test</h1>";

echo "<h2>Step 1: Load config</h2>";
try {
    require_once '../config.php';
    echo "✅ Config loaded<br>";
} catch (Throwable $e) {
    echo "❌ Config error: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>Step 2: Check PDO</h2>";
if (isset($pdo)) {
    echo "✅ PDO exists<br>";
} else {
    echo "❌ PDO missing<br>";
    die();
}

echo "<h2>Step 3: Test query</h2>";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM riders")->fetchColumn();
    echo "✅ Query works: $count riders<br>";
} catch (Throwable $e) {
    echo "❌ Query error: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>Step 4: Call require_admin()</h2>";
try {
    require_admin();
    echo "✅ require_admin() passed (you're logged in)<br>";
} catch (Throwable $e) {
    echo "❌ require_admin() error: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>Step 5: Fetch riders</h2>";
try {
    $stmt = $pdo->query("SELECT * FROM riders LIMIT 10");
    $riders = $stmt->fetchAll();
    echo "✅ Fetched " . count($riders) . " riders<br>";
    
    if (count($riders) > 0) {
        echo "<h3>First rider:</h3>";
        echo "<pre>";
        print_r($riders[0]);
        echo "</pre>";
    }
} catch (Throwable $e) {
    echo "❌ Fetch error: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>Step 6: Include header</h2>";
try {
    $page_title = 'Test';
    $page_type = 'admin';
    include '../includes/layout-header.php';
    echo "✅ Header loaded<br>";
} catch (Throwable $e) {
    echo "❌ Header error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "<br>";
    die();
}

echo "<h2>Step 7: Include footer</h2>";
try {
    include '../includes/layout-footer.php';
    echo "✅ Footer loaded<br>";
} catch (Throwable $e) {
    echo "❌ Footer error: " . $e->getMessage() . "<br>";
    die();
}

echo "<h1>✅ ALL TESTS PASSED!</h1>";