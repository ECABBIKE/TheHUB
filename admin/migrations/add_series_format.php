<?php
/**
 * Migration: Add format column to series table
 *
 * This adds a 'format' column to determine how qualification points are calculated
 * - Championship: Individual results
 * - Team: Team results
 */

require_once __DIR__ . '/../../config.php';
require_admin();

$db = getDB();

try {
    // Check if column already exists
    $columns = $db->getAll("SHOW COLUMNS FROM series LIKE 'format'");

    if (empty($columns)) {
        // Add the column
        $db->query("ALTER TABLE series ADD COLUMN format VARCHAR(50) DEFAULT 'Championship' AFTER type");
        echo "✓ Successfully added 'format' column to series table\n";
    } else {
        echo "ℹ Column 'format' already exists in series table\n";
    }

    echo "\nMigration completed successfully!\n";

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
