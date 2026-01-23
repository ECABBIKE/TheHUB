<?php
/**
 * Fix swapped Herrar/Damer Elit classes for event 325
 * Run once to swap class_id for affected results
 */
require_once __DIR__ . '/../config.php';

global $pdo;

$eventId = 325;

echo "Fixing swapped classes for event $eventId...\n\n";

// First, find the class IDs for Herrar Elit and Damer Elit
$stmt = $pdo->query("SELECT id, name, display_name FROM classes WHERE name LIKE '%Elit%' OR display_name LIKE '%Elit%' ORDER BY name");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Available Elit classes:\n";
foreach ($classes as $c) {
    echo "  ID: {$c['id']} - {$c['display_name']} ({$c['name']})\n";
}

// Find Herrar Elit and Damer Elit IDs
$herrarElitId = null;
$damerElitId = null;

foreach ($classes as $c) {
    $name = strtolower($c['display_name'] ?? $c['name']);
    if (strpos($name, 'herr') !== false && strpos($name, 'elit') !== false) {
        $herrarElitId = $c['id'];
    }
    if (strpos($name, 'dam') !== false && strpos($name, 'elit') !== false) {
        $damerElitId = $c['id'];
    }
}

if (!$herrarElitId || !$damerElitId) {
    die("Could not find both Herrar Elit and Damer Elit classes!\n");
}

echo "\nHerrar Elit ID: $herrarElitId\n";
echo "Damer Elit ID: $damerElitId\n";

// Check current state
$stmt = $pdo->prepare("
    SELECT r.class_id, c.display_name, COUNT(*) as cnt 
    FROM results r 
    JOIN classes c ON r.class_id = c.id 
    WHERE r.event_id = ? AND r.class_id IN (?, ?)
    GROUP BY r.class_id
");
$stmt->execute([$eventId, $herrarElitId, $damerElitId]);
$current = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nCurrent state for event $eventId:\n";
foreach ($current as $row) {
    echo "  Class {$row['class_id']} ({$row['display_name']}): {$row['cnt']} results\n";
}

// Swap the class IDs using a temporary value
echo "\nSwapping classes...\n";

$pdo->beginTransaction();
try {
    // Use a temporary class_id (negative) to avoid constraint issues
    $tempId = -999;
    
    // Step 1: Move Herrar Elit to temp
    $stmt = $pdo->prepare("UPDATE results SET class_id = ? WHERE event_id = ? AND class_id = ?");
    $stmt->execute([$tempId, $eventId, $herrarElitId]);
    $herrarCount = $stmt->rowCount();
    echo "  Moved $herrarCount Herrar Elit results to temp\n";
    
    // Step 2: Move Damer Elit to Herrar Elit
    $stmt->execute([$herrarElitId, $eventId, $damerElitId]);
    $damerCount = $stmt->rowCount();
    echo "  Moved $damerCount Damer Elit results to Herrar Elit\n";
    
    // Step 3: Move temp to Damer Elit
    $stmt->execute([$damerElitId, $eventId, $tempId]);
    echo "  Moved temp results to Damer Elit\n";
    
    $pdo->commit();
    echo "\nDone! Swapped $herrarCount Herrar <-> $damerCount Damer results.\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}

// Verify
$stmt = $pdo->prepare("
    SELECT r.class_id, c.display_name, COUNT(*) as cnt 
    FROM results r 
    JOIN classes c ON r.class_id = c.id 
    WHERE r.event_id = ? AND r.class_id IN (?, ?)
    GROUP BY r.class_id
");
$stmt->execute([$eventId, $herrarElitId, $damerElitId]);
$after = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nAfter swap:\n";
foreach ($after as $row) {
    echo "  Class {$row['class_id']} ({$row['display_name']}): {$row['cnt']} results\n";
}
