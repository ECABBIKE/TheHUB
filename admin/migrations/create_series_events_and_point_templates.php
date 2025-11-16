<?php
/**
 * Migration: Create series_events and qualification_point_templates tables
 *
 * This allows:
 * - Many-to-many relationship between series and events
 * - Each series can have its own list of events
 * - Each event in a series can have a specific point template
 */

require_once __DIR__ . '/../../config.php';
require_admin();

$db = getDB();

try {
    // Create qualification_point_templates table
    $db->query("
        CREATE TABLE IF NOT EXISTS qualification_point_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            points JSON NOT NULL COMMENT 'Array of points by position: {\"1\": 100, \"2\": 80, ...}',
            active BOOLEAN DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ Created qualification_point_templates table\n";

    // Create series_events junction table
    $db->query("
        CREATE TABLE IF NOT EXISTS series_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            series_id INT NOT NULL,
            event_id INT NOT NULL,
            template_id INT NULL COMMENT 'Qualification point template for this event in this series',
            sort_order INT DEFAULT 0 COMMENT 'Order of events within the series',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (template_id) REFERENCES qualification_point_templates(id) ON DELETE SET NULL,
            UNIQUE KEY unique_series_event (series_id, event_id),
            INDEX idx_series (series_id),
            INDEX idx_event (event_id),
            INDEX idx_template (template_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ Created series_events table\n";

    // Insert some default point templates
    $defaultTemplates = [
        [
            'name' => 'SweCup Standard',
            'description' => 'Standard SweCup poängfördelning',
            'points' => json_encode([
                '1' => 100, '2' => 80, '3' => 60, '4' => 50, '5' => 45,
                '6' => 40, '7' => 36, '8' => 32, '9' => 29, '10' => 26,
                '11' => 24, '12' => 22, '13' => 20, '14' => 18, '15' => 16,
                '16' => 15, '17' => 14, '18' => 13, '19' => 12, '20' => 11,
                '21' => 10, '22' => 9, '23' => 8, '24' => 7, '25' => 6,
                '26' => 5, '27' => 4, '28' => 3, '29' => 2, '30' => 1
            ])
        ],
        [
            'name' => 'UCI Standard',
            'description' => 'UCI standardpoäng',
            'points' => json_encode([
                '1' => 250, '2' => 200, '3' => 150, '4' => 120, '5' => 100,
                '6' => 90, '7' => 80, '8' => 70, '9' => 60, '10' => 50,
                '11' => 45, '12' => 40, '13' => 35, '14' => 30, '15' => 25,
                '16' => 20, '17' => 15, '18' => 10, '19' => 5, '20' => 1
            ])
        ],
        [
            'name' => 'Top 10',
            'description' => 'Endast topp 10 får poäng',
            'points' => json_encode([
                '1' => 50, '2' => 40, '3' => 30, '4' => 25, '5' => 20,
                '6' => 15, '7' => 10, '8' => 7, '9' => 5, '10' => 3
            ])
        ]
    ];

    foreach ($defaultTemplates as $template) {
        // Check if template already exists
        $existing = $db->getRow(
            "SELECT id FROM qualification_point_templates WHERE name = ?",
            [$template['name']]
        );

        if (!$existing) {
            $db->insert('qualification_point_templates', $template);
            echo "  ✓ Created template: {$template['name']}\n";
        } else {
            echo "  ℹ Template already exists: {$template['name']}\n";
        }
    }

    // Migrate existing event->series relationships if series_id column exists in events
    $eventsColumns = $db->getAll("SHOW COLUMNS FROM events LIKE 'series_id'");
    if (!empty($eventsColumns)) {
        echo "\nMigrating existing event->series relationships...\n";

        $events = $db->getAll("SELECT id, series_id FROM events WHERE series_id IS NOT NULL");
        foreach ($events as $event) {
            // Check if relationship already exists
            $existing = $db->getRow(
                "SELECT id FROM series_events WHERE series_id = ? AND event_id = ?",
                [$event['series_id'], $event['id']]
            );

            if (!$existing) {
                $db->insert('series_events', [
                    'series_id' => $event['series_id'],
                    'event_id' => $event['id'],
                    'template_id' => null,
                    'sort_order' => 0
                ]);
                echo "  ✓ Migrated event {$event['id']} to series {$event['series_id']}\n";
            }
        }

        echo "\n⚠ Note: The old 'series_id' column in 'events' table is still present.\n";
        echo "   You can remove it manually later with: ALTER TABLE events DROP COLUMN series_id;\n";
    }

    echo "\n✅ Migration completed successfully!\n";

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
