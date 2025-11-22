php<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
require_once '../includes/helpers.php';
require_admin();

$page_title = 'Import';
$page_type = 'admin';
include '../includes/layout-header.php';
?>

<div class="gs-container">
    <h1 class="gs-h2 gs-mb-lg">Import Data</h1>
    
    <div class="gs-card">
        <div class="gs-card-content">
            <h3>Import-alternativ:</h3>
            <ul class="gs-list-reset">
                <li class="gs-my-4">
                    <a href="import-uci.php" class="gs-btn gs-btn-primary gs-btn-lg">
                        📥 UCI Licensregister Import
                    </a>
                </li>
                <li class="gs-my-4">
                    <button class="gs-btn gs-btn-outline" disabled>
                        📊 Resultat Import (Kommer snart)
                    </button>
                </li>
                <li class="gs-my-4">
                    <button class="gs-btn gs-btn-outline" disabled>
                        📅 Event Import (Kommer snart)
                    </button>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php include '../includes/layout-footer.php'; ?>
