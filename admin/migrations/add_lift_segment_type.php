<?php
/**
 * Migration: Add 'lift' segment type
 *
 * Adds 'lift' to segment_type ENUM for ski lifts/gondolas.
 * Lift segments are excluded from elevation calculations.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$pageTitle = 'Migrering: Lägg till Lift-sträcktyp';
include __DIR__ . '/../../includes/admin-header.php';

$db = getDB();
?>

<div class="admin-content">
    <div class="page-header">
        <h1><?= $pageTitle ?></h1>
    </div>

    <div class="card">
        <div class="card-body">
            <?php
            try {
                // Check current ENUM values
                $result = $db->query("SHOW COLUMNS FROM event_track_segments WHERE Field = 'segment_type'");
                $column = $result->fetch(PDO::FETCH_ASSOC);

                echo "<h3>Nuvarande segment_type definition:</h3>";
                echo "<pre>" . htmlspecialchars($column['Type']) . "</pre>";

                // Check if lift already exists
                if (strpos($column['Type'], 'lift') !== false) {
                    echo "<p class='alert alert-info'>ℹ 'lift' finns redan i ENUM</p>";
                } else {
                    // Alter the ENUM to add 'lift'
                    $db->query("ALTER TABLE event_track_segments MODIFY COLUMN segment_type ENUM('stage', 'liaison', 'lift') NOT NULL DEFAULT 'stage'");
                    echo "<p class='alert alert-success'>✓ Lade till 'lift' i segment_type ENUM</p>";
                }

                // Verify the change
                $result = $db->query("SHOW COLUMNS FROM event_track_segments WHERE Field = 'segment_type'");
                $column = $result->fetch(PDO::FETCH_ASSOC);
                echo "<h3>Ny segment_type definition:</h3>";
                echo "<pre>" . htmlspecialchars($column['Type']) . "</pre>";

                echo "<div class='alert alert-success' style='margin-top: var(--space-lg);'>";
                echo "<strong>Migrering slutförd!</strong><br>";
                echo "Sträcktyper:<br>";
                echo "• <strong>stage</strong> = Tävlingssträcka (räknas i höjd)<br>";
                echo "• <strong>liaison</strong> = Transport (räknas i höjd)<br>";
                echo "• <strong>lift</strong> = Lift/gondol (exkluderas från höjdberäkning)";
                echo "</div>";

            } catch (Exception $e) {
                echo "<p class='alert alert-danger'>Fel: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            ?>

            <p style="margin-top: var(--space-lg);">
                <a href="/admin/event-map.php" class="btn btn-secondary">← Tillbaka till karthantering</a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin-footer.php'; ?>
