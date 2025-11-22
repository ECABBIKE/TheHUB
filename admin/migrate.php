<?php
/**
 * Migration Runner
 * Reads and executes SQL migration files from database/migrations folder
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = '';

// Ensure migrations table exists
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) DEFAULT 1,
            error_message TEXT
        )
    ");
} catch (Exception $e) {
    // Table might already exist
}

// Get migrations directory
$migrationsDir = __DIR__ . '/../database/migrations';
$migrationFiles = [];

if (is_dir($migrationsDir)) {
    $files = scandir($migrationsDir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $migrationFiles[] = $file;
        }
    }
    sort($migrationFiles);
}

// Get executed migrations
$executedMigrations = [];
try {
    $rows = $db->getAll("SELECT filename, executed_at, success, error_message FROM migrations");
    foreach ($rows as $row) {
        $executedMigrations[$row['filename']] = $row;
    }
} catch (Exception $e) {
    // Table might not exist yet
}

// Handle run migration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    checkCsrf();

    $filename = basename($_POST['run_migration']); // Security: only basename
    $filepath = $migrationsDir . '/' . $filename;

    if (file_exists($filepath) && pathinfo($filename, PATHINFO_EXTENSION) === 'sql') {
        $sql = file_get_contents($filepath);

        // Split by semicolons (simple approach - doesn't handle semicolons in strings)
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $errors = [];
        $successCount = 0;

        foreach ($statements as $statement) {
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }

            try {
                $db->query($statement);
                $successCount++;
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                // Ignore "already exists" errors
                if (strpos($errorMsg, 'Duplicate column') === false &&
                    strpos($errorMsg, 'Duplicate key') === false &&
                    strpos($errorMsg, 'already exists') === false) {
                    $errors[] = $errorMsg;
                } else {
                    $successCount++;
                }
            }
        }

        // Record migration
        $success = empty($errors);
        $errorMessage = implode('; ', $errors);

        try {
            // Check if already recorded
            $existing = $db->getRow("SELECT id FROM migrations WHERE filename = ?", [$filename]);
            if ($existing) {
                $db->update('migrations', [
                    'executed_at' => date('Y-m-d H:i:s'),
                    'success' => $success ? 1 : 0,
                    'error_message' => $errorMessage ?: null
                ], 'filename = ?', [$filename]);
            } else {
                $db->insert('migrations', [
                    'filename' => $filename,
                    'success' => $success ? 1 : 0,
                    'error_message' => $errorMessage ?: null
                ]);
            }
        } catch (Exception $e) {
            // Ignore tracking errors
        }

        if ($success) {
            $message = "Migration '$filename' kördes framgångsrikt ($successCount statements)";
            $messageType = 'success';
        } else {
            $message = "Migration '$filename' kördes med fel: " . $errorMessage;
            $messageType = 'error';
        }

        // Refresh executed migrations
        try {
            $rows = $db->getAll("SELECT filename, executed_at, success, error_message FROM migrations");
            $executedMigrations = [];
            foreach ($rows as $row) {
                $executedMigrations[$row['filename']] = $row;
            }
        } catch (Exception $e) {
            // Ignore
        }
    } else {
        $message = 'Ogiltig migrationsfil';
        $messageType = 'error';
    }
}

// Handle run all pending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_all_pending'])) {
    checkCsrf();

    $ranCount = 0;
    $errorCount = 0;

    foreach ($migrationFiles as $filename) {
        if (isset($executedMigrations[$filename]) && $executedMigrations[$filename]['success']) {
            continue; // Skip already successful
        }

        $filepath = $migrationsDir . '/' . $filename;
        $sql = file_get_contents($filepath);
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $errors = [];
        $successCount = 0;

        foreach ($statements as $statement) {
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }

            try {
                $db->query($statement);
                $successCount++;
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, 'Duplicate column') === false &&
                    strpos($errorMsg, 'Duplicate key') === false &&
                    strpos($errorMsg, 'already exists') === false) {
                    $errors[] = $errorMsg;
                } else {
                    $successCount++;
                }
            }
        }

        $success = empty($errors);
        $errorMessage = implode('; ', $errors);

        try {
            $existing = $db->getRow("SELECT id FROM migrations WHERE filename = ?", [$filename]);
            if ($existing) {
                $db->update('migrations', [
                    'executed_at' => date('Y-m-d H:i:s'),
                    'success' => $success ? 1 : 0,
                    'error_message' => $errorMessage ?: null
                ], 'filename = ?', [$filename]);
            } else {
                $db->insert('migrations', [
                    'filename' => $filename,
                    'success' => $success ? 1 : 0,
                    'error_message' => $errorMessage ?: null
                ]);
            }
        } catch (Exception $e) {
            // Ignore
        }

        if ($success) {
            $ranCount++;
        } else {
            $errorCount++;
        }
    }

    if ($errorCount > 0) {
        $message = "Körde $ranCount migrationer, $errorCount misslyckades";
        $messageType = 'warning';
    } else {
        $message = "Körde $ranCount nya migrationer framgångsrikt";
        $messageType = 'success';
    }

    // Refresh
    try {
        $rows = $db->getAll("SELECT filename, executed_at, success, error_message FROM migrations");
        $executedMigrations = [];
        foreach ($rows as $row) {
            $executedMigrations[$row['filename']] = $row;
        }
    } catch (Exception $e) {
        // Ignore
    }
}

$pageTitle = 'Kör Migrationer';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <div>
                <h1 class="gs-h1">
                    <i data-lucide="database"></i>
                    Kör Migrationer
                </h1>
                <p class="gs-text-secondary">
                    Kör databasmigrationer från /database/migrations
                </p>
            </div>
            <a href="/admin/system-settings.php?tab=debug" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'alert-triangle') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <?php
        $successCount = 0;
        foreach ($executedMigrations as $m) {
            if ($m['success']) $successCount++;
        }
        $pendingCount = count($migrationFiles) - $successCount;
        ?>
        <div class="gs-grid gs-grid-cols-3 gs-gap-md gs-mb-lg">
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-text-2xl gs-text-primary"><?= count($migrationFiles) ?></div>
                    <div class="gs-text-sm gs-text-secondary">Totalt filer</div>
                </div>
            </div>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-text-2xl gs-text-success"><?= $successCount ?></div>
                    <div class="gs-text-sm gs-text-secondary">Körda</div>
                </div>
            </div>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-text-2xl gs-text-warning"><?= $pendingCount ?></div>
                    <div class="gs-text-sm gs-text-secondary">Väntande</div>
                </div>
            </div>
        </div>

        <?php if ($pendingCount > 0): ?>
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <button type="submit" name="run_all_pending" value="1" class="gs-btn gs-btn-primary"
                                onclick="return confirm('Kör alla <?= $pendingCount ?> väntande migrationer?')">
                            <i data-lucide="play"></i>
                            Kör alla väntande migrationer
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Migration List -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h3">
                    <i data-lucide="list"></i>
                    Migrationer (<?= count($migrationFiles) ?>)
                </h2>
            </div>
            <div class="gs-card-content gs-p-0">
                <?php if (empty($migrationFiles)): ?>
                    <div class="gs-p-lg gs-text-center">
                        <i data-lucide="inbox" class="gs-text-secondary" style="width: 48px; height: 48px;"></i>
                        <p class="gs-text-secondary gs-mt-md">Inga migrationsfiler hittades i /database/migrations</p>
                    </div>
                <?php else: ?>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Fil</th>
                                    <th>Status</th>
                                    <th>Körd</th>
                                    <th class="gs-text-right">Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($migrationFiles as $file):
                                    $executed = $executedMigrations[$file] ?? null;
                                    $isSuccess = $executed && $executed['success'];
                                    $isFailed = $executed && !$executed['success'];
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($file) ?></strong>
                                            <?php if ($isFailed && $executed['error_message']): ?>
                                                <br><small class="gs-text-danger"><?= h($executed['error_message']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isSuccess): ?>
                                                <span class="gs-badge gs-badge-success">Körd</span>
                                            <?php elseif ($isFailed): ?>
                                                <span class="gs-badge gs-badge-danger">Misslyckad</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-warning">Väntande</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($executed): ?>
                                                <?= date('Y-m-d H:i', strtotime($executed['executed_at'])) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-right">
                                            <form method="POST" style="display: inline;">
                                                <?= csrf_field() ?>
                                                <button type="submit" name="run_migration" value="<?= h($file) ?>"
                                                        class="gs-btn gs-btn-sm <?= $isSuccess ? 'gs-btn-outline' : 'gs-btn-primary' ?>"
                                                        onclick="return confirm('Kör migration <?= h($file) ?>?')">
                                                    <i data-lucide="play"></i>
                                                    <?= $isSuccess ? 'Kör igen' : 'Kör' ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
