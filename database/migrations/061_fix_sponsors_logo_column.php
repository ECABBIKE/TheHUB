<?php
/**
 * Migration 061: Ensure sponsors table has logo column
 * This fixes the case where sponsors table was created without logo column
 */

// Get database connection
require_once __DIR__ . '/../../config.php';
$pdo = getDB()->getPDO();

// Check which columns exist
$existingCols = [];
$result = $pdo->query("SHOW COLUMNS FROM sponsors");
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $existingCols[] = $row['Field'];
}

// Define columns that should exist
$requiredColumns = [
    'logo' => "ALTER TABLE sponsors ADD COLUMN logo VARCHAR(255) NULL AFTER slug",
    'logo_dark' => "ALTER TABLE sponsors ADD COLUMN logo_dark VARCHAR(255) NULL AFTER logo",
    'website' => "ALTER TABLE sponsors ADD COLUMN website VARCHAR(255) NULL AFTER logo_dark",
];

// Add missing columns
foreach ($requiredColumns as $col => $sql) {
    if (!in_array($col, $existingCols)) {
        try {
            $pdo->exec($sql);
            echo "Added column: $col\n";
        } catch (PDOException $e) {
            // Column might already exist, ignore
            echo "Column $col: " . $e->getMessage() . "\n";
        }
    } else {
        echo "Column $col already exists\n";
    }
}

echo "Migration 061 complete.\n";
