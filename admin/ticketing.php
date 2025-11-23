<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_admin();

$pageTitle = 'Ticketing';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <h1>Ticketing Dashboard - v2.4.2-066</h1>
        <p>Om du ser detta fungerar sidan!</p>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
