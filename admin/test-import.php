<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>PHP TEST</h1>";
echo "<p>PHP fungerar: ✅</p>";

try {
    echo "<p>Försöker ladda config...</p>";
    require_once '../config.php';
    echo "<p>Config laddad: ✅</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Config ERROR: " . $e->getMessage() . "</p>";
}

try {
    echo "<p>Försöker ladda helpers...</p>";
    require_once '../includes/helpers.php';
    echo "<p>Helpers laddad: ✅</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Helpers ERROR: " . $e->getMessage() . "</p>";
}

try {
    echo "<p>Försöker kolla admin...</p>";
    require_admin();
    echo "<p>Admin check OK: ✅</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Admin ERROR: " . $e->getMessage() . "</p>";
}

echo "<h2>Allt funkar! 🎉</h2>";
echo "<p><a href='import.php'>Testa riktiga import.php</a></p>";
?>
