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
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <div class="gs-admin-layout">
        <!-- Sidebar -->
        <aside class="gs-admin-sidebar">
            <div class="gs-admin-sidebar-header">
                <h1 class="gs-admin-sidebar-title">TheHUB</h1>
                <p class="gs-text-secondary gs-text-sm">Inloggad: <?= h($currentAdmin['name']) ?></p>
            </div>
            <nav>
                <ul class="gs-admin-sidebar-nav">
                    <li><a href="/admin/index.php" class="gs-admin-sidebar-link">Dashboard</a></li>
                    <li><a href="/admin/cyclists.php" class="gs-admin-sidebar-link">Cyklister</a></li>
                    <li><a href="/admin/events.php" class="gs-admin-sidebar-link">Tävlingar</a></li>
                    <li><a href="/admin/results.php" class="gs-admin-sidebar-link">Resultat</a></li>
                    <li><a href="/admin/import.php" class="gs-admin-sidebar-link active">Import</a></li>
                    <li><a href="/admin/logout.php" class="gs-admin-sidebar-link">Logga ut</a></li>
                </ul>
            </nav>
            <div style="padding: var(--gs-space-lg); margin-top: auto; border-top: 1px solid var(--gs-gray);">
                <a href="/public/index.php" target="_blank" class="gs-text-secondary gs-text-sm" style="text-decoration: none;">
                    Visa publik sida →
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="gs-admin-content">
            <h1 class="gs-h1 gs-text-primary gs-mb-lg">Importera data</h1>

            <?php $flash = getFlash(); if ($flash): ?>
                <div class="gs-alert gs-alert-<?= h($flash['type']) ?> gs-mb-lg">
                    <?= h($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Upload Form -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">Ladda upp Excel-fil</h2>
                </div>
                <div class="gs-card-content">
                    <form method="POST" enctype="multipart/form-data" style="max-width: 600px;">
                        <div class="gs-form-group">
                            <label for="import_type" class="gs-label">Importtyp</label>
                            <select id="import_type" name="import_type" class="gs-input" required>
                                <option value="">Välj typ...</option>
                                <option value="cyclists">Cyklister</option>
                                <option value="results">Resultat</option>
                            </select>
                        </div>

                        <div class="gs-form-group">
                            <label for="import_file" class="gs-label">Excel-fil (.xlsx, .xls)</label>
                            <input type="file" id="import_file" name="import_file" class="gs-input" accept=".xlsx,.xls,.csv" required>
                            <small class="gs-text-secondary gs-text-sm">Max storlek: <?= round(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB</small>
                        </div>

                        <button type="submit" class="gs-btn gs-btn-primary gs-btn-lg">Importera</button>
                    </form>

                    <!-- Import Info -->
                    <div class="gs-mt-xl" style="padding-top: var(--gs-space-xl); border-top: 1px solid var(--gs-border);">
                        <h3 class="gs-h4 gs-text-primary gs-mb-md">Filformat</h3>

                        <div class="gs-mb-lg">
                            <h4 class="gs-text-base gs-text-primary gs-mb-sm" style="font-weight: 600;">Cyklister (cyclists):</h4>
                            <ul class="gs-text-secondary gs-text-sm" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
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
                        </div>

                        <div>
                            <h4 class="gs-text-base gs-text-primary gs-mb-sm" style="font-weight: 600;">Resultat (results):</h4>
                            <ul class="gs-text-secondary gs-text-sm" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
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
                </div>
            </div>

            <!-- Import History -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">Importhistorik</h2>
                </div>
                <div class="gs-card-content">
                    <?php if (empty($recentImports)): ?>
                        <p class="gs-text-secondary gs-text-center gs-py-lg">Inga importer ännu</p>
                    <?php else: ?>
                        <div class="gs-table-responsive">
                            <table class="gs-table">
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
                                            <td class="gs-text-sm"><?= formatDate($import['created_at'], 'd M Y H:i') ?></td>
                                            <td class="gs-text-sm"><?= h($import['import_type']) ?></td>
                                            <td class="gs-text-secondary gs-text-sm"><?= h($import['filename']) ?></td>
                                            <td class="gs-text-sm"><?= number_format($import['records_total']) ?></td>
                                            <td style="color: var(--gs-success); font-weight: 600;"><?= number_format($import['records_success']) ?></td>
                                            <td style="color: #dc2626; font-weight: 600;"><?= number_format($import['records_failed']) ?></td>
                                            <td class="gs-text-secondary gs-text-sm"><?= h($import['imported_by']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
