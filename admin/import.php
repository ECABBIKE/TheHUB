<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$db = getDB();
$message = '';
$messageType = 'success';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $importType = $_POST['import_type'] ?? '';
    $file = $_FILES['import_file'];

    $validation = validateUpload($file);

    if (!$validation['valid']) {
        $message = $validation['error'];
        $messageType = 'error';
    } else {
        // Move uploaded file
        $uploadDir = UPLOAD_PATH;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = time() . '_' . basename($file['name']);
        $filepath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Import file based on type
            if ($importType === 'cyclists') {
                require_once __DIR__ . '/../imports/import_cyclists.php';
                $importer = new CyclistImporter();

                ob_start();
                $success = $importer->import($filepath);
                $output = ob_get_clean();

                $stats = $importer->getStats();

                if ($success) {
                    $message = "Import klar! {$stats['success']} av {$stats['total']} rader importerade.";
                    $messageType = 'success';
                } else {
                    $message = "Import misslyckades. Kontrollera filformatet.";
                    $messageType = 'error';
                }
            } elseif ($importType === 'results') {
                require_once __DIR__ . '/../imports/import_results.php';
                $importer = new ResultImporter();

                ob_start();
                $success = $importer->import($filepath);
                $output = ob_get_clean();

                $stats = $importer->getStats();

                if ($success) {
                    $message = "Import klar! {$stats['success']} resultat importerade.";
                    $messageType = 'success';
                } else {
                    $message = "Import misslyckades. Kontrollera filformatet.";
                    $messageType = 'error';
                }
            }

            // Clean up uploaded file
            @unlink($filepath);
        } else {
            $message = "Kunde inte ladda upp filen.";
            $messageType = 'error';
        }
    }

    if ($message) {
        setFlash($message, $messageType);
        redirect('/admin/import.php');
    }
}

// Get recent imports
$recentImports = $db->getAll(
    "SELECT * FROM import_logs ORDER BY created_at DESC LIMIT 20"
);

$pageTitle = 'Import Data';
$currentAdmin = getCurrentAdmin();
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB Admin</title>
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body>
    <div class="admin-container">
        <nav class="admin-nav">
            <div class="nav-header">
                <h1>TheHUB</h1>
                <p class="nav-user">Inloggad: <?= h($currentAdmin['name']) ?></p>
            </div>
            <ul>
                <li><a href="/admin/index.php">Dashboard</a></li>
                <li><a href="/admin/cyclists.php">Cyklister</a></li>
                <li><a href="/admin/events.php">Tävlingar</a></li>
                <li><a href="/admin/results.php">Resultat</a></li>
                <li><a href="/admin/import.php" class="active">Import</a></li>
                <li><a href="/admin/logout.php">Logga ut</a></li>
            </ul>
        </nav>

        <main class="admin-content">
            <h1>Importera data</h1>

            <?php $flash = getFlash(); if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?>">
                    <?= h($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="import-section">
                <h2>Ladda upp Excel-fil</h2>

                <form method="POST" enctype="multipart/form-data" class="import-form">
                    <div class="form-group">
                        <label for="import_type">Importtyp:</label>
                        <select id="import_type" name="import_type" required>
                            <option value="">Välj typ...</option>
                            <option value="cyclists">Cyklister</option>
                            <option value="results">Resultat</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="import_file">Excel-fil (.xlsx, .xls):</label>
                        <input type="file" id="import_file" name="import_file" accept=".xlsx,.xls,.csv" required>
                        <small>Max storlek: <?= round(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB</small>
                    </div>

                    <button type="submit" class="btn btn-primary">Importera</button>
                </form>

                <div class="import-info">
                    <h3>Filformat</h3>

                    <h4>Cyklister (cyclists):</h4>
                    <ul>
                        <li>Kolumn A: Förnamn</li>
                        <li>Kolumn B: Efternamn</li>
                        <li>Kolumn C: Födelseår</li>
                        <li>Kolumn D: Kön (M/F)</li>
                        <li>Kolumn E: Klubb</li>
                        <li>Kolumn F: Licensnummer</li>
                        <li>Kolumn G: E-post</li>
                        <li>Kolumn H: Telefon</li>
                        <li>Kolumn I: Ort</li>
                    </ul>

                    <h4>Resultat (results):</h4>
                    <ul>
                        <li>Kolumn A: Tävlingsnamn</li>
                        <li>Kolumn B: Datum (YYYY-MM-DD)</li>
                        <li>Kolumn C: Plats</li>
                        <li>Kolumn D: Placering</li>
                        <li>Kolumn E: Startnummer</li>
                        <li>Kolumn F: Förnamn</li>
                        <li>Kolumn G: Efternamn</li>
                        <li>Kolumn H: Födelseår</li>
                        <li>Kolumn I: Klubb</li>
                        <li>Kolumn J: Tid (HH:MM:SS)</li>
                        <li>Kolumn K: Kategori</li>
                    </ul>
                </div>
            </div>

            <div class="import-history">
                <h2>Importhistorik</h2>

                <?php if (empty($recentImports)): ?>
                    <p class="no-data">Inga importer ännu</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Typ</th>
                                <th>Fil</th>
                                <th>Totalt</th>
                                <th>Lyckade</th>
                                <th>Misslyckade</th>
                                <th>Importerad av</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentImports as $import): ?>
                                <tr>
                                    <td><?= formatDate($import['created_at'], 'd M Y H:i') ?></td>
                                    <td><?= h($import['import_type']) ?></td>
                                    <td><?= h($import['filename']) ?></td>
                                    <td><?= number_format($import['records_total']) ?></td>
                                    <td class="text-success"><?= number_format($import['records_success']) ?></td>
                                    <td class="text-danger"><?= number_format($import['records_failed']) ?></td>
                                    <td><?= h($import['imported_by']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
