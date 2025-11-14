<?php
/**
 * Run database migration
 * Usage: php run-migration.php 007
 */

require_once __DIR__ . '/config.php';

if ($argc < 2) {
    echo "Usage: php run-migration.php <migration_number>\n";
    echo "Example: php run-migration.php 007\n";
    exit(1);
}

$migrationNumber = str_pad($argv[1], 3, '0', STR_PAD_LEFT);
$migrationFile = __DIR__ . "/database/migrations/{$migrationNumber}_*.sql";

// Find migration file
$files = glob($migrationFile);

if (empty($files)) {
    echo "❌ Migration {$migrationNumber} not found\n";
    exit(1);
}

$file = $files[0];
$filename = basename($file);

echo "🔄 Running migration: {$filename}\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Read SQL file
$sql = file_get_contents($file);

if (!$sql) {
    echo "❌ Could not read migration file\n";
    exit(1);
}

// Split into individual statements
$statements = array_filter(
    array_map('trim', preg_split('/;[\r\n]+/', $sql)),
    function($stmt) {
        return !empty($stmt) && !preg_match('/^--/', $stmt);
    }
);

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$success = 0;
$failed = 0;

foreach ($statements as $i => $statement) {
    // Skip comments and empty statements
    if (empty(trim($statement)) || preg_match('/^--/', $statement)) {
        continue;
    }

    try {
        $pdo->exec($statement);
        $success++;
        echo "✓ Statement " . ($i + 1) . " executed\n";
    } catch (PDOException $e) {
        $failed++;
        echo "✗ Statement " . ($i + 1) . " failed: " . $e->getMessage() . "\n";

        // Continue with other statements even if one fails
        // This allows for idempotent migrations
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($failed > 0) {
    echo "⚠️  Migration completed with errors\n";
    echo "   Success: {$success}\n";
    echo "   Failed: {$failed}\n";
    exit(1);
} else {
    echo "✅ Migration {$filename} completed successfully!\n";
    echo "   {$success} statements executed\n";
    exit(0);
}
