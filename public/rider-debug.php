<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "Starting rider debug...<br>";

try {
    require_once __DIR__ . '/config.php';
    echo "✓ Config loaded<br>";

    $db = getDB();
    echo "✓ Database connected<br>";

    $riderId = isset($_GET['id']) ? (int)$_GET['id'] : 7761;
    echo "✓ Testing Rider ID: {$riderId}<br>";

    // Fetch rider
    $rider = $db->getRow("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.birth_year,
            r.gender,
            r.club_id,
            r.license_number,
            r.license_type,
            r.license_category,
            r.license_year,
            r.discipline,
            r.license_valid_until,
            r.city,
            r.active,
            c.name as club_name,
            c.city as club_city
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.id = ?
    ", [$riderId]);

    if (!$rider) {
        die("❌ Rider not found");
    }
    echo "✓ Rider found: {$rider['firstname']} {$rider['lastname']}<br>";

    // Try to load class-calculations.php
    echo "Loading class-calculations.php...<br>";
    require_once __DIR__ . '/includes/class-calculations.php';
    echo "✓ class-calculations.php loaded<br>";

    // Fetch results
    echo "Fetching results...<br>";
    $results = $db->getAll("
        SELECT
            res.*,
            e.name as event_name,
            e.date as event_date,
            e.location as event_location,
            e.series_id,
            s.name as series_name,
            v.name as venue_name,
            v.city as venue_city
        FROM results res
        INNER JOIN events e ON res.event_id = e.id
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN venues v ON e.venue_id = v.id
        WHERE res.cyclist_id = ?
        ORDER BY e.date DESC
    ", [$riderId]);
    echo "✓ Results fetched: " . count($results) . " results<br>";

    // Try to determine class
    echo "Determining class...<br>";
    if ($rider['birth_year'] && $rider['gender']) {
        $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'));
        echo "✓ Class determined: " . ($classId ?: 'none') . "<br>";
    }

    // Try checkLicense
    echo "Checking license...<br>";
    $licenseCheck = checkLicense($rider);
    echo "✓ License check: <pre>";
    print_r($licenseCheck);
    echo "</pre>";

    echo "<hr>";
    echo "<h2>All seems OK! The issue might be in the HTML rendering.</h2>";
    echo "<p>Rider data:</p>";
    echo "<pre>";
    print_r($rider);
    echo "</pre>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ ERROR CAUGHT:</h2>";
    echo "<pre>";
    echo $e->getMessage();
    echo "\n\n";
    echo $e->getTraceAsString();
    echo "</pre>";
}
?>
