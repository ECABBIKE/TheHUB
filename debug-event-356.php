<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config/database.php';

$pdo = $GLOBALS['pdo'];
$eventId = 356;

// Get event data
$stmt = $pdo->prepare("SELECT id, name, pricing_template_id, series_id FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== EVENT 356 DEBUG ===\n\n";
echo "Event: " . $event['name'] . "\n";
echo "pricing_template_id: " . ($event['pricing_template_id'] ?? 'NULL') . "\n";
echo "series_id: " . ($event['series_id'] ?? 'NULL') . "\n\n";

// Check pricing template rules
if (!empty($event['pricing_template_id'])) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM pricing_template_rules
        WHERE template_id = ?
    ");
    $stmt->execute([$event['pricing_template_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Pricing template rules: " . $result['cnt'] . "\n";

    if ($result['cnt'] > 0) {
        $stmt = $pdo->prepare("
            SELECT ptr.class_id, ptr.base_price, c.name as class_name
            FROM pricing_template_rules ptr
            JOIN classes c ON c.id = ptr.class_id
            WHERE ptr.template_id = ?
            LIMIT 5
        ");
        $stmt->execute([$event['pricing_template_id']]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nFirst 5 rules:\n";
        foreach ($rules as $rule) {
            echo "  - {$rule['class_name']}: {$rule['base_price']} kr\n";
        }
    }
} else {
    echo "No pricing_template_id set!\n\n";

    // Check legacy event_pricing_rules
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM event_pricing_rules WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Legacy event_pricing_rules: " . $result['cnt'] . "\n";
}

// Check if series has pricing template
if (!empty($event['series_id'])) {
    $stmt = $pdo->prepare("SELECT id, name, pricing_template_id FROM series WHERE id = ?");
    $stmt->execute([$event['series_id']]);
    $series = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($series) {
        echo "\nSeries: " . $series['name'] . "\n";
        echo "Series pricing_template_id: " . ($series['pricing_template_id'] ?? 'NULL') . "\n";
    }
}
