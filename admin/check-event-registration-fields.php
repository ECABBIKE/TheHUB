<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<pre>';
echo "=== EVENT 356 REGISTRATION FIELDS ===\n\n";

$event = $db->getOne("SELECT * FROM events WHERE id = 356");

$registrationFields = [
    'registration_opens',
    'registration_closes',
    'early_bird_deadline',
    'late_fee_start',
    'registration_enabled',
    'registration_url'
];

echo "Event: {$event['name']}\n\n";
echo "Registration-related fields:\n";
foreach ($registrationFields as $field) {
    $value = $event[$field] ?? 'FIELD MISSING';
    echo "  $field: " . ($value === '' ? 'EMPTY' : $value) . "\n";
}

echo "\n=== EVENTS TABLE STRUCTURE ===\n\n";
$columns = $db->getAll("SHOW COLUMNS FROM events WHERE Field LIKE '%registration%' OR Field LIKE '%early%' OR Field LIKE '%late%'");
echo "Columns matching registration/early/late:\n";
foreach ($columns as $col) {
    echo "  {$col['Field']} ({$col['Type']}) - Default: " . ($col['Default'] ?? 'NULL') . "\n";
}

echo '</pre>';
?>
