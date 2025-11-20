<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

$pageTitle = 'Database Debug';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h2 gs-mb-lg">
            <i data-lucide="database"></i>
            🔍 Database Debug
        </h1>

        <?php
        // CHECK RIDERS TABLE
        echo "<div class='gs-card gs-mb-lg'>";
        echo "<div class='gs-card-header'><h3 class='gs-h3'>Riders Table</h3></div>";
        echo "<div class='gs-card-content'>";

        try {
            // Count total riders
            $total = $db->getRow("SELECT COUNT(*) as count FROM riders");
            $total_count = $total['count'] ?? 0;
            echo "<p class='gs-stat-text'><strong>Total riders:</strong> <span class='gs-stat-value-primary'>$total_count</span></p>";

            // Count active riders
            $active = $db->getRow("SELECT COUNT(*) as count FROM riders WHERE active = 1");
            $active_count = $active['count'] ?? 0;
            echo "<p class='gs-stat-text-md'><strong>Active riders:</strong> <span class='gs-stat-value-success'>$active_count</span></p>";

            if ($total_count > 0) {
                // Show first 10 riders
                $riders = $db->getAll("SELECT * FROM riders ORDER BY id DESC LIMIT 10");

                echo "<h4 class='gs-h4 gs-mt-lg gs-mb-md'>Latest 10 riders:</h4>";
                echo "<div class='gs-table-scrollable'>";
                echo "<table class='gs-table'>";
                echo "<thead><tr>";
                if (!empty($riders)) {
                    foreach (array_keys($riders[0]) as $col) {
                        echo "<th>" . h($col) . "</th>";
                    }
                }
                echo "</tr></thead>";
                echo "<tbody>";
                foreach ($riders as $rider) {
                    echo "<tr>";
                    foreach ($rider as $key => $value) {
                        if ($key === 'active') {
                            echo "<td>" . ($value ? '✅ Yes' : '❌ No') . "</td>";
                        } else {
                            echo "<td>" . h($value ?? 'NULL') . "</td>";
                        }
                    }
                    echo "</tr>";
                }
                echo "</tbody></table>";
                echo "</div>";
            } else {
                echo "<div class='gs-alert gs-alert-danger gs-mt-md'>";
                echo "<p class='gs-alert-text-bold'>⚠️ NO RIDERS IN DATABASE!</p>";
                echo "<p class='gs-mt-2'>Import has not saved any data yet.</p>";
                echo "</div>";
            }

        } catch (Exception $e) {
            echo "<div class='gs-alert gs-alert-danger'>";
            echo "<p class='gs-m-0'><strong>ERROR:</strong> " . h($e->getMessage()) . "</p>";
            echo "</div>";
        }

        echo "</div></div>";

        // CHECK CLUBS TABLE
        echo "<div class='gs-card gs-mb-lg'>";
        echo "<div class='gs-card-header'><h3 class='gs-h3'>Clubs Table</h3></div>";
        echo "<div class='gs-card-content'>";

        try {
            $total = $db->getRow("SELECT COUNT(*) as count FROM clubs");
            $count = $total['count'] ?? 0;
            echo "<p class='gs-stat-text'><strong>Total clubs:</strong> <span class='gs-stat-value-primary'>$count</span></p>";

            if ($count > 0) {
                $clubs = $db->getAll("SELECT * FROM clubs ORDER BY id DESC LIMIT 20");

                echo "<h4 class='gs-h4 gs-mb-md'>Latest 20 clubs:</h4>";
                echo "<ul class='gs-list gs-cols-2'>";
                foreach ($clubs as $club) {
                    $active_badge = $club['active'] ? "<span class='gs-stat-value-success'>✅</span>" : "<span class='gs-stat-value-secondary'>❌</span>";
                    echo "<li>" . $active_badge . " <strong>" . h($club['name']) . "</strong> (ID: " . $club['id'] . ")</li>";
                }
                echo "</ul>";
            } else {
                echo "<p class='gs-stat-value-secondary'>No clubs in database</p>";
            }

        } catch (Exception $e) {
            echo "<div class='gs-alert gs-alert-danger'>";
            echo "<p class='gs-m-0'><strong>ERROR:</strong> " . h($e->getMessage()) . "</p>";
            echo "</div>";
        }

        echo "</div></div>";

        // CHECK EVENTS TABLE
        echo "<div class='gs-card gs-mb-lg'>";
        echo "<div class='gs-card-header'><h3 class='gs-h3'>Events Table</h3></div>";
        echo "<div class='gs-card-content'>";

        try {
            $total = $db->getRow("SELECT COUNT(*) as count FROM events");
            $count = $total['count'] ?? 0;
            echo "<p class='gs-stat-text'><strong>Total events:</strong> <span class='gs-stat-value-primary'>$count</span></p>";

            if ($count > 0) {
                $events = $db->getAll("SELECT * FROM events ORDER BY date DESC LIMIT 10");

                echo "<h4 class='gs-h4 gs-mb-md'>Latest 10 events:</h4>";
                echo "<div class='gs-table-scrollable'>";
                echo "<table class='gs-table'>";
                echo "<thead><tr><th>ID</th><th>Name</th><th>Date</th><th>Location</th><th>Series</th><th>Status</th></tr></thead>";
                echo "<tbody>";
                foreach ($events as $event) {
                    echo "<tr>";
                    echo "<td>" . h($event['id']) . "</td>";
                    echo "<td><strong>" . h($event['name']) . "</strong></td>";
                    echo "<td>" . h($event['date'] ?? 'N/A') . "</td>";
                    echo "<td>" . h($event['location'] ?? 'N/A') . "</td>";
                    echo "<td>" . h($event['series_id'] ?? 'N/A') . "</td>";
                    echo "<td>" . h($event['status'] ?? 'N/A') . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
                echo "</div>";
            } else {
                echo "<p class='gs-stat-value-secondary'>⚠️ No events in database</p>";
            }

        } catch (Exception $e) {
            echo "<div class='gs-alert gs-alert-danger'>";
            echo "<p class='gs-m-0'><strong>ERROR:</strong> " . h($e->getMessage()) . "</p>";
            echo "</div>";
        }

        echo "</div></div>";

        // CHECK SERIES TABLE
        echo "<div class='gs-card gs-mb-lg'>";
        echo "<div class='gs-card-header'><h3 class='gs-h3'>Series Table</h3></div>";
        echo "<div class='gs-card-content'>";

        try {
            $total = $db->getRow("SELECT COUNT(*) as count FROM series");
            $count = $total['count'] ?? 0;
            echo "<p class='gs-stat-text'><strong>Total series:</strong> <span class='gs-stat-value-primary'>$count</span></p>";

            if ($count > 0) {
                $series_list = $db->getAll("SELECT * FROM series ORDER BY id");

                echo "<h4 class='gs-h4 gs-mb-md'>All series:</h4>";
                echo "<table class='gs-table'>";
                echo "<thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Discipline</th><th>Active</th></tr></thead>";
                echo "<tbody>";
                foreach ($series_list as $series) {
                    echo "<tr>";
                    echo "<td>" . h($series['id']) . "</td>";
                    echo "<td><strong>" . h($series['name']) . "</strong></td>";
                    echo "<td>" . h($series['type'] ?? 'N/A') . "</td>";
                    echo "<td>" . h($series['discipline'] ?? 'N/A') . "</td>";
                    echo "<td>" . ($series['active'] ? '✅' : '❌') . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            } else {
                echo "<p class='gs-stat-value-secondary'>⚠️ No series in database</p>";
            }

        } catch (Exception $e) {
            echo "<div class='gs-alert gs-alert-danger'>";
            echo "<p class='gs-m-0'><strong>ERROR:</strong> " . h($e->getMessage()) . "</p>";
            echo "</div>";
        }

        echo "</div></div>";

        // CHECK RESULTS TABLE
        echo "<div class='gs-card gs-mb-lg'>";
        echo "<div class='gs-card-header'><h3 class='gs-h3'>Results Table</h3></div>";
        echo "<div class='gs-card-content'>";

        try {
            $total = $db->getRow("SELECT COUNT(*) as count FROM results");
            $count = $total['count'] ?? 0;
            echo "<p class='gs-stat-text'><strong>Total results:</strong> <span class='gs-stat-value-primary'>$count</span></p>";

            if ($count > 0) {
                echo "<p class='gs-stat-value-success'>✅ Results exist in database</p>";
            } else {
                echo "<p class='gs-stat-value-secondary'>⚠️ No results in database yet</p>";
            }

        } catch (Exception $e) {
            echo "<div class='gs-alert gs-alert-danger'>";
            echo "<p class='gs-m-0'><strong>ERROR:</strong> " . h($e->getMessage()) . "</p>";
            echo "</div>";
        }

        echo "</div></div>";

        // SHOW TABLE STRUCTURES
        echo "<div class='gs-card gs-mb-xl'>";
        echo "<div class='gs-card-header'><h3 class='gs-h3'>Table Structures</h3></div>";
        echo "<div class='gs-card-content'>";

        $tables = ['riders', 'clubs', 'events', 'series', 'results'];

        foreach ($tables as $table) {
            try {
                echo "<h4 class='gs-h4 gs-mt-lg gs-mb-sm gs-font-mono'>$table</h4>";
                $columns = $db->getAll("DESCRIBE $table");

                echo "<table class='gs-table gs-font-mono-sm'>";
                echo "<thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>";
                echo "<tbody>";
                foreach ($columns as $col) {
                    echo "<tr>";
                    echo "<td><strong>" . h($col['Field']) . "</strong></td>";
                    echo "<td>" . h($col['Type']) . "</td>";
                    echo "<td>" . h($col['Null']) . "</td>";
                    echo "<td>" . h($col['Key'] ?? '') . "</td>";
                    echo "<td>" . h($col['Default'] ?? 'NULL') . "</td>";
                    echo "<td>" . h($col['Extra'] ?? '') . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";

            } catch (Exception $e) {
                echo "<div class='gs-alert gs-alert-danger gs-mt-sm'>";
                echo "<p class='gs-m-0'><strong>Table '$table':</strong> " . h($e->getMessage()) . "</p>";
                echo "</div>";
            }
        }

        echo "</div></div>";

        // QUERY TEST SECTION
        echo "<div class='gs-card gs-mb-xl'>";
        echo "<div class='gs-card-header'><h3 class='gs-h3'>Query Test: riders.php Logic</h3></div>";
        echo "<div class='gs-card-content'>";

        try {
            echo "<h4 class='gs-h4 gs-mb-md'>Testing exact query from riders.php:</h4>";

            $query = "
                SELECT
                    c.id,
                    c.firstname,
                    c.lastname,
                    c.birth_year,
                    c.gender,
                    c.license_number,
                    cl.name as club_name,
                    COUNT(DISTINCT r.id) as total_races,
                    COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
                    MIN(r.position) as best_position
                FROM riders c
                LEFT JOIN clubs cl ON c.club_id = cl.id
                LEFT JOIN results r ON c.id = r.cyclist_id
                WHERE c.active = 1
                GROUP BY c.id
                ORDER BY c.lastname, c.firstname
                LIMIT 10
            ";

            echo "<pre class='gs-code-preview'>";
            echo h($query);
            echo "</pre>";

            $test_riders = $db->getAll($query);

            echo "<p class='gs-mt-4'><strong>Result:</strong> <span class='gs-stat-value-primary'>" . count($test_riders) . " riders returned</span></p>";

            if (!empty($test_riders)) {
                echo "<table class='gs-table gs-mt-md'>";
                echo "<thead><tr><th>ID</th><th>Name</th><th>Age</th><th>Club</th><th>Races</th><th>Podiums</th></tr></thead>";
                echo "<tbody>";
                foreach ($test_riders as $rider) {
                    echo "<tr>";
                    echo "<td>" . h($rider['id']) . "</td>";
                    echo "<td><strong>" . h($rider['firstname']) . " " . h($rider['lastname']) . "</strong></td>";
                    $age = !empty($rider['birth_year']) ? calculateAge($rider['birth_year']) . ' år' : 'N/A';
                    echo "<td>" . h($age) . "</td>";
                    echo "<td>" . h($rider['club_name'] ?? 'No club') . "</td>";
                    echo "<td>" . h($rider['total_races']) . "</td>";
                    echo "<td>" . h($rider['podiums']) . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            } else {
                echo "<div class='gs-alert gs-alert-warning gs-mt-md'>";
                echo "<p class='gs-m-0'>⚠️ Query returned 0 riders even though riders exist in database.</p>";
                echo "<p class='gs-mt-2'>This means: All riders have <code>active = 0</code> OR there's a column name mismatch.</p>";
                echo "</div>";
            }

        } catch (Exception $e) {
            echo "<div class='gs-alert gs-alert-danger'>";
            echo "<p class='gs-m-0'><strong>QUERY ERROR:</strong> " . h($e->getMessage()) . "</p>";
            echo "</div>";
        }

        echo "</div></div>";
        ?>

    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
