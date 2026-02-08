<?php
require_once __DIR__ . '/../config.php';
requireAdmin();

$eventId = isset($_GET['event']) ? intval($_GET['event']) : 356;
$db = getDB();
$pdo = $db->getPDO();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Event Pricing Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1a1a1a; color: #0f0; }
        h1 { color: #0ff; }
        h2 { color: #ff0; margin-top: 30px; }
        .error { color: #f00; font-weight: bold; }
        .success { color: #0f0; font-weight: bold; }
        .info { color: #0ff; }
        pre { background: #000; padding: 10px; border: 1px solid #333; overflow-x: auto; }
        table { border-collapse: collapse; margin: 10px 0; }
        td, th { border: 1px solid #333; padding: 8px; text-align: left; }
        th { background: #333; }
    </style>
</head>
<body>
<h1>Event Pricing Debug - Event #<?= $eventId ?></h1>

<?php
// Get event data
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);

if (!$event) {
    echo '<p class="error">EVENT NOT FOUND!</p>';
    exit;
}

echo '<h2>1. Event Data</h2>';
echo '<table>';
echo '<tr><th>Field</th><th>Value</th></tr>';
echo '<tr><td>ID</td><td>' . $event['id'] . '</td></tr>';
echo '<tr><td>Name</td><td>' . htmlspecialchars($event['name']) . '</td></tr>';
echo '<tr><td>pricing_template_id</td><td class="' . (empty($event['pricing_template_id']) ? 'error' : 'success') . '">' . ($event['pricing_template_id'] ?? 'NULL') . '</td></tr>';
echo '<tr><td>series_id</td><td>' . ($event['series_id'] ?? 'NULL') . '</td></tr>';
echo '<tr><td>registration_opens</td><td>' . ($event['registration_opens'] ?? 'NULL') . '</td></tr>';
echo '</table>';

// Check pricing template
if (!empty($event['pricing_template_id'])) {
    echo '<h2>2. Pricing Template Query (Same as event.php)</h2>';

    $sql = "
        SELECT ptr.class_id, ptr.base_price, ptr.early_bird_price, ptr.late_fee,
               c.name as class_name, c.display_name, c.gender, c.min_age, c.max_age
        FROM pricing_template_rules ptr
        JOIN classes c ON c.id = ptr.class_id
        WHERE ptr.template_id = ?
        ORDER BY c.sort_order, c.name
    ";

    echo '<pre>' . htmlspecialchars($sql) . '</pre>';
    echo '<p>With template_id = <span class="info">' . $event['pricing_template_id'] . '</span></p>';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$event['pricing_template_id']]);
        $eventPricing = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<p>Result: <span class="' . (count($eventPricing) > 0 ? 'success' : 'error') . '">' . count($eventPricing) . ' rows</span></p>';

        if (count($eventPricing) > 0) {
            echo '<table>';
            echo '<tr><th>Class</th><th>Base</th><th>Early Bird</th><th>Late Fee</th></tr>';
            foreach (array_slice($eventPricing, 0, 10) as $p) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($p['class_name']) . '</td>';
                echo '<td>' . $p['base_price'] . ' kr</td>';
                echo '<td>' . $p['early_bird_price'] . ' kr</td>';
                echo '<td>' . $p['late_fee'] . ' kr</td>';
                echo '</tr>';
            }
            echo '</table>';
            if (count($eventPricing) > 10) {
                echo '<p class="info">... and ' . (count($eventPricing) - 10) . ' more</p>';
            }
        } else {
            echo '<p class="error">NO PRICING RULES FOUND!</p>';

            // Debug: Check if pricing_template_rules has ANY data for this template
            echo '<h2>3. Debug: Check pricing_template_rules table</h2>';
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM pricing_template_rules WHERE template_id = ?");
            $stmt->execute([$event['pricing_template_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo '<p>Rules in pricing_template_rules: <span class="' . ($result['cnt'] > 0 ? 'success' : 'error') . '">' . $result['cnt'] . '</span></p>';

            if ($result['cnt'] > 0) {
                // There are rules, but JOIN fails - check why
                echo '<h2>4. Debug: Check JOIN with classes</h2>';
                $stmt = $pdo->prepare("
                    SELECT ptr.id, ptr.class_id, ptr.template_id,
                           c.id as class_exists, c.name as class_name, c.active as class_active
                    FROM pricing_template_rules ptr
                    LEFT JOIN classes c ON c.id = ptr.class_id
                    WHERE ptr.template_id = ?
                    LIMIT 10
                ");
                $stmt->execute([$event['pricing_template_id']]);
                $joinTest = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo '<table>';
                echo '<tr><th>Rule ID</th><th>Class ID</th><th>Class Name</th><th>Class Active</th><th>Issue</th></tr>';
                foreach ($joinTest as $row) {
                    $issue = '';
                    if (empty($row['class_exists'])) {
                        $issue = 'Class does not exist!';
                    } elseif ($row['class_active'] != 1) {
                        $issue = 'Class is inactive!';
                    }
                    echo '<tr>';
                    echo '<td>' . $row['id'] . '</td>';
                    echo '<td>' . $row['class_id'] . '</td>';
                    echo '<td>' . ($row['class_name'] ?? '<span class="error">NULL</span>') . '</td>';
                    echo '<td>' . ($row['class_active'] ?? '<span class="error">NULL</span>') . '</td>';
                    echo '<td class="error">' . $issue . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }

            // Check template info
            echo '<h2>5. Debug: Pricing Template Info</h2>';
            $stmt = $pdo->prepare("SELECT * FROM pricing_templates WHERE id = ?");
            $stmt->execute([$event['pricing_template_id']]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($template) {
                echo '<table>';
                foreach ($template as $key => $value) {
                    echo '<tr><td>' . htmlspecialchars($key) . '</td><td>' . htmlspecialchars($value ?? 'NULL') . '</td></tr>';
                }
                echo '</table>';
            } else {
                echo '<p class="error">PRICING TEMPLATE NOT FOUND!</p>';
            }
        }

    } catch (PDOException $e) {
        echo '<p class="error">Query Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }

} else {
    echo '<p class="error">No pricing_template_id set for this event!</p>';

    // Check if series has template
    if (!empty($event['series_id'])) {
        echo '<h2>2. Check Series Pricing Template</h2>';
        $series = $db->getRow("SELECT id, name, pricing_template_id FROM series WHERE id = ?", [$event['series_id']]);
        if ($series) {
            echo '<p>Series: ' . htmlspecialchars($series['name']) . '</p>';
            echo '<p>Series pricing_template_id: <span class="' . (empty($series['pricing_template_id']) ? 'error' : 'info') . '">' . ($series['pricing_template_id'] ?? 'NULL') . '</span></p>';
        }
    }
}

// Check registration status
echo '<h2>Registration Status</h2>';
$now = time();
if (!empty($event['registration_opens'])) {
    $opensTime = strtotime($event['registration_opens']);
    echo '<p>Registration opens: ' . date('Y-m-d H:i', $opensTime) . '</p>';
    echo '<p>Current time: ' . date('Y-m-d H:i', $now) . '</p>';
    echo '<p>Status: <span class="' . ($opensTime <= $now ? 'success' : 'error') . '">' . ($opensTime <= $now ? 'OPEN' : 'NOT YET OPEN (countdown should show)') . '</span></p>';
} else {
    echo '<p class="success">No registration_opens set - should be open now</p>';
}
?>

<h2>Actions</h2>
<p><a href="?event=<?= $eventId ?>" style="color: #0ff;">Refresh</a></p>
<p><a href="/admin/event-edit.php?id=<?= $eventId ?>" style="color: #0ff;">Edit Event in Admin</a></p>
<p><a href="/event/<?= $eventId ?>" style="color: #0ff;" target="_blank">View Public Event Page</a></p>

</body>
</html>
