<?php
/**
 * Admin Tool - Run Database Migrations
 * TheHUB V3
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$pdo = $GLOBALS['pdo'];
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

                // Remove comment lines (lines starting with --)
                $sqlLines = explode("\n", $sql);
                $sqlLines = array_filter($sqlLines, fn($line) => !preg_match('/^\s*--/', $line));
                $sql = implode("\n", $sqlLines);

                // Split into individual statements
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    fn($s) => !empty($s)
                );

                $successCount = 0;
                $errorCount = 0;

                foreach ($statements as $statement) {
                    if (empty(trim($statement))) continue;

                    try {
                        $pdo->exec($statement);
                        $successCount++;
                        $results[] = [
                            'status' => 'success',
                            'message' => substr($statement, 0, 80) . '...'
                        ];
                    } catch (Exception $e) {
                        $errMsg = $e->getMessage();
                        // Ignore common "already exists" errors
                        $ignorableErrors = [
                            'Duplicate column',      // Column already exists
                            'Duplicate key name',    // Index already exists
                            'Duplicate entry',       // Row already exists
                            'already exists',        // Generic already exists
                            'check that column/key exists', // Column doesn't exist for DROP
                            'Unknown column',        // Column doesn't exist
                            'Can\'t DROP',           // Can't drop non-existent
                            'BLOB/TEXT column',      // Index on text column
                        ];

                        $isIgnorable = false;
                        foreach ($ignorableErrors as $pattern) {
                            if (stripos($errMsg, $pattern) !== false) {
                                $isIgnorable = true;
                                break;
                            }
                        }

                        if ($isIgnorable) {
                            $results[] = [
                                'status' => 'skipped',
                                'message' => 'Redan utförd: ' . substr($statement, 0, 60) . '...'
                            ];
                        } else {
                            $errorCount++;
                            $results[] = [
                                'status' => 'error',
                                'message' => $errMsg
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

$current_admin_page = 'tools';
$page_title = 'Kör Migrationer';
include __DIR__ . '/components/unified-layout.php';
?>

<style>
/* Mobile-friendly migrations page */
.migration-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}
.migration-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}
.migration-item.not-executed {
    background: rgba(239, 68, 68, 0.05);
    border-color: rgba(239, 68, 68, 0.2);
}
.migration-item.today {
    border: 2px solid var(--color-warning);
}
.migration-radio {
    flex-shrink: 0;
}
.migration-radio input[type="radio"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}
.migration-info {
    flex: 1;
    min-width: 0;
}
.migration-name {
    font-weight: 600;
    word-break: break-word;
    margin-bottom: 4px;
}
.migration-meta {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}
.migration-actions {
    display: flex;
    gap: var(--space-xs);
    flex-shrink: 0;
}
.migration-status {
    flex-shrink: 0;
}
@media (max-width: 600px) {
    .migration-item {
        flex-wrap: wrap;
    }
    .migration-info {
        order: 1;
        width: calc(100% - 80px);
    }
    .migration-status {
        order: 2;
    }
    .migration-radio {
        order: 3;
    }
    .migration-actions {
        order: 4;
        width: 100%;
        margin-top: var(--space-sm);
    }
    .migration-actions .btn {
        flex: 1;
    }
    .result-table {
        font-size: var(--text-xs);
    }
    .result-table code {
        word-break: break-all;
    }
}
</style>

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
            <h3 class="m-0">Nya migrationer idag (<?= date('Y-m-d') ?>)</h3>
        </div>
        <div class="card-body">
            <p class="text-muted mb-md">Dessa migrationer skapades idag och bör köras:</p>
            <div class="migration-list">
                <?php foreach ($todaysMigrations as $migration): ?>
                    <div class="migration-item today <?= !$migration['executed'] ? 'not-executed' : '' ?>">
                        <div class="migration-status">
                            <?php if ($migration['executed']): ?>
                                <span class="badge" style="background: var(--color-success); color: white;">Körd</span>
                            <?php else: ?>
                                <span class="badge" style="background: var(--color-error); color: white;">Ej körd</span>
                            <?php endif; ?>
                        </div>
                        <div class="migration-info">
                            <div class="migration-name"><?= htmlspecialchars($migration['name']) ?></div>
                        </div>
                        <div class="migration-actions">
                            <?php if (!$migration['executed']): ?>
                            <form method="POST" style="display: contents;">
                                <input type="hidden" name="migration_file" value="<?= htmlspecialchars($migration['file']) ?>">
                                <button type="submit" name="run_migration" class="btn btn-sm btn-primary" onclick="return confirm('Kör <?= htmlspecialchars($migration['file']) ?>?')">
                                    Kör nu
                                </button>
                            </form>
                            <?php endif; ?>
                            <a href="?preview=<?= urlencode($migration['file']) ?>" class="btn btn-sm btn-outline">Visa</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <div class="card mb-lg">
            <div class="card-header">
                <h3>Resultat</h3>
            </div>
            <div class="card-body">
                <div class="migration-list">
                    <?php foreach ($results as $result): ?>
                        <div class="migration-item" style="padding: var(--space-sm);">
                            <div class="migration-status">
                                <?php if ($result['status'] === 'success'): ?>
                                    <span class="badge badge-success">OK</span>
                                <?php elseif ($result['status'] === 'skipped'): ?>
                                    <span class="badge badge-warning">Skip</span>
                                <?php else: ?>
                                    <span class="badge badge-error">Fel</span>
                                <?php endif; ?>
                            </div>
                            <div class="migration-info" style="flex: 1;">
                                <code style="font-size: 11px; word-break: break-all;"><?= htmlspecialchars($result['message']) ?></code>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
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
                    <div class="migration-list">
                        <?php foreach ($migrations as $migration): ?>
                            <div class="migration-item <?= !$migration['executed'] ? 'not-executed' : '' ?>">
                                <?php if (!$migration['executed']): ?>
                                <div class="migration-radio">
                                    <input type="radio" name="migration_file" value="<?= htmlspecialchars($migration['file']) ?>" id="mig_<?= md5($migration['name']) ?>">
                                </div>
                                <?php endif; ?>
                                <div class="migration-status">
                                    <?php if ($migration['executed']): ?>
                                        <span class="badge" style="background: var(--color-success); color: white;">Körd</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: var(--color-error); color: white;">Ej körd</span>
                                    <?php endif; ?>
                                </div>
                                <div class="migration-info">
                                    <label class="migration-name" for="mig_<?= md5($migration['name']) ?>"><?= htmlspecialchars($migration['name']) ?></label>
                                    <div class="migration-meta"><?= $migration['date'] ?></div>
                                </div>
                                <div class="migration-actions">
                                    <a href="?preview=<?= urlencode($migration['file']) ?>" class="btn btn-sm btn-outline">Visa</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

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

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
