<?php
/**
 * Admin Tool - Run Database Migrations
 * TheHUB V3
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$pdo = getPDO();
$message = '';
$error = '';
$results = [];

// Ensure migrations_log table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_file VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Exception $e) {
    // Ignore if already exists
}

// Get executed migrations
$executedMigrations = [];
try {
    $stmt = $pdo->query("SELECT migration_file FROM migrations_log");
    $executedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Table might not exist yet
}

// Get available migrations
$migrationsDir = __DIR__ . '/../database/migrations/';
$migrations = [];
$todaysMigrations = [];
$today = date('Y-m-d');

if (is_dir($migrationsDir)) {
    $files = glob($migrationsDir . '*.sql');
    foreach ($files as $file) {
        $filename = basename($file);
        $isExecuted = in_array($filename, $executedMigrations);
        $fileDate = date('Y-m-d', filemtime($file));

        $migrationData = [
            'file' => $filename,
            'path' => $file,
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'executed' => $isExecuted,
            'date' => $fileDate
        ];

        $migrations[] = $migrationData;

        // Collect today's migrations (created today)
        if ($fileDate === $today) {
            $todaysMigrations[] = $migrationData;
        }
    }
    sort($migrations);
}

// Handle migration execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $migrationFile = $_POST['migration_file'] ?? '';

    if (empty($migrationFile)) {
        $error = 'Ingen migration vald.';
    } else {
        $fullPath = $migrationsDir . basename($migrationFile);

        if (!file_exists($fullPath)) {
            $error = 'Migrationsfilen hittades inte.';
        } else {
            try {
                $sql = file_get_contents($fullPath);

                // Split into individual statements
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    fn($s) => !empty($s) && !preg_match('/^--/', $s)
                );

                $successCount = 0;
                $errorCount = 0;

                foreach ($statements as $statement) {
                    if (empty(trim($statement))) continue;

                    try {
                        $db->exec($statement);
                        $successCount++;
                        $results[] = [
                            'status' => 'success',
                            'message' => substr($statement, 0, 80) . '...'
                        ];
                    } catch (PDOException $e) {
                        // Ignore "column already exists" or "column doesn't exist" errors
                        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
                            strpos($e->getMessage(), 'check that column/key exists') !== false ||
                            strpos($e->getMessage(), 'Unknown column') !== false) {
                            $results[] = [
                                'status' => 'skipped',
                                'message' => 'Redan utförd: ' . substr($statement, 0, 60) . '...'
                            ];
                        } else {
                            $errorCount++;
                            $results[] = [
                                'status' => 'error',
                                'message' => $e->getMessage()
                            ];
                        }
                    }
                }

                if ($errorCount === 0) {
                    // Log the successful migration
                    try {
                        $logStmt = $pdo->prepare("INSERT IGNORE INTO migrations_log (migration_file) VALUES (?)");
                        $logStmt->execute([$migrationFile]);
                    } catch (Exception $e) {
                        // Ignore logging errors
                    }
                    $message = "Migration '{$migrationFile}' kördes framgångsrikt! ({$successCount} statements)";
                    // Refresh executed migrations list
                    $executedMigrations[] = $migrationFile;
                } else {
                    $error = "Migration kördes med {$errorCount} fel.";
                }

            } catch (Exception $e) {
                $error = 'Kunde inte köra migration: ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="admin-content">
    <div class="content-header">
        <h1>Kör Migrationer</h1>
        <p class="text-muted">Uppdatera databasstrukturen</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($todaysMigrations)): ?>
    <div class="card mb-lg" style="border: 2px solid var(--color-warning);">
        <div class="card-header" style="background: var(--color-warning); color: white;">
            <h3 style="margin: 0;">Nya migrationer idag (<?= date('Y-m-d') ?>)</h3>
        </div>
        <div class="card-body">
            <p class="text-muted mb-md">Dessa migrationer skapades idag och bör köras:</p>
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Migration</th>
                        <th>Åtgärd</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($todaysMigrations as $migration): ?>
                        <tr style="background: <?= $migration['executed'] ? 'rgba(97, 206, 112, 0.1)' : 'rgba(239, 68, 68, 0.1)' ?>;">
                            <td>
                                <?php if ($migration['executed']): ?>
                                    <span class="badge" style="background: var(--color-success); color: white;">Körd</span>
                                <?php else: ?>
                                    <span class="badge" style="background: var(--color-error); color: white;">Ej körd</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($migration['name']) ?></strong>
                            </td>
                            <td>
                                <?php if (!$migration['executed']): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="migration_file" value="<?= htmlspecialchars($migration['file']) ?>">
                                    <button type="submit" name="run_migration" class="btn btn-sm btn-primary" onclick="return confirm('Kör <?= htmlspecialchars($migration['file']) ?>?')">
                                        Kör nu
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted">Redan utförd</span>
                                <?php endif; ?>
                                <a href="?preview=<?= urlencode($migration['file']) ?>" class="btn btn-sm btn-outline">Visa</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <div class="card mb-lg">
            <div class="card-header">
                <h3>Resultat</h3>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 100px">Status</th>
                            <th>Detaljer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr>
                                <td>
                                    <?php if ($result['status'] === 'success'): ?>
                                        <span class="badge badge-success">✓ OK</span>
                                    <?php elseif ($result['status'] === 'skipped'): ?>
                                        <span class="badge badge-warning">→ Skipped</span>
                                    <?php else: ?>
                                        <span class="badge badge-error">✗ Fel</span>
                                    <?php endif; ?>
                                </td>
                                <td><code style="font-size: 12px;"><?= htmlspecialchars($result['message']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Tillgängliga migrationer</h3>
        </div>
        <div class="card-body">
            <?php if (empty($migrations)): ?>
                <p class="text-muted">Inga migrationer hittades.</p>
            <?php else: ?>
                <form method="POST">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 50px"></th>
                                <th style="width: 100px">Status</th>
                                <th>Migration</th>
                                <th style="width: 150px">Åtgärd</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($migrations as $migration): ?>
                                <tr style="<?= !$migration['executed'] ? 'background: rgba(239, 68, 68, 0.05);' : '' ?>">
                                    <td>
                                        <?php if (!$migration['executed']): ?>
                                        <input type="radio" name="migration_file" value="<?= htmlspecialchars($migration['file']) ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($migration['executed']): ?>
                                            <span class="badge" style="background: var(--color-success); color: white;">Körd</span>
                                        <?php else: ?>
                                            <span class="badge" style="background: var(--color-error); color: white;">Ej körd</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($migration['name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($migration['file']) ?> • <?= $migration['date'] ?></small>
                                    </td>
                                    <td>
                                        <a href="?preview=<?= urlencode($migration['file']) ?>" class="btn btn-sm btn-outline">Visa</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="form-actions mt-lg">
                        <button type="submit" name="run_migration" class="btn btn-primary" onclick="return confirm('Är du säker på att du vill köra denna migration?')">
                            Kör vald migration
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['preview']) && !empty($_GET['preview'])): ?>
        <?php
        $previewFile = $migrationsDir . basename($_GET['preview']);
        if (file_exists($previewFile)):
            $previewContent = file_get_contents($previewFile);
        ?>
        <div class="card mt-lg">
            <div class="card-header">
                <h3>Förhandsgranskning: <?= htmlspecialchars(basename($_GET['preview'])) ?></h3>
            </div>
            <div class="card-body">
                <pre style="background: var(--color-bg-sunken); padding: 1rem; border-radius: 8px; overflow-x: auto; font-size: 13px;"><?= htmlspecialchars($previewContent) ?></pre>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
