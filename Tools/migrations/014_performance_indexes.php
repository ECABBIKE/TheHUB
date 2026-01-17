<?php
/**
 * Migration 014: Performance indexes for Journey Analysis
 * Adds indexes required for efficient cohort calculations
 *
 * Uses PHP to properly check if indexes exist before creating
 */

// Function to check if index exists
function indexExists(PDO $pdo, string $table, string $indexName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $indexName]);
    return $stmt->fetchColumn() > 0;
}

// Function to add index if not exists
function addIndexIfNotExists(PDO $pdo, string $table, string $indexName, string $columns): void {
    if (indexExists($pdo, $table, $indexName)) {
        echo "Index '$indexName' finns redan på '$table' - hoppar över\n";
        return;
    }

    try {
        $pdo->exec("CREATE INDEX $indexName ON $table ($columns)");
        echo "Skapade index '$indexName' på '$table'\n";
    } catch (PDOException $e) {
        echo "Fel vid skapande av '$indexName': " . $e->getMessage() . "\n";
    }
}

// Get PDO connection
global $pdo;
if (!$pdo) {
    echo "ERROR: No PDO connection available\n";
    return;
}

echo "=== Migration 014: Performance Indexes ===\n\n";

// Add indexes
addIndexIfNotExists($pdo, 'results', 'idx_results_cyclist', 'cyclist_id');
addIndexIfNotExists($pdo, 'results', 'idx_results_event', 'event_id');
addIndexIfNotExists($pdo, 'events', 'idx_events_date', 'date');
addIndexIfNotExists($pdo, 'results', 'idx_results_cyclist_event', 'cyclist_id, event_id');

echo "\n=== Klar ===\n";
