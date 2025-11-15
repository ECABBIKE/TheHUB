<?php
require_once __DIR__ . '/../config.php';
require_admin();

$message = '';
$messageType = 'info';
$executedStatements = [];

// Get all migration files
$migrationFiles = glob(__DIR__ . '/../database/migrations/*.sql');
sort($migrationFiles);

// Get list of available migrations
$migrations = [];
foreach ($migrationFiles as $file) {
    $filename = basename($file);
    $migrations[] = [
        'file' => $file,
        'filename' => $filename,
        'number' => preg_replace('/^(\d+)_.*\.sql$/', '$1', $filename),
        'name' => preg_replace('/^\d+_(.*)\.sql$/', '$1', $filename)
    ];
}

// Handle migration execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migration_file'])) {
    checkCsrf();

    $migrationFile = $_POST['migration_file'];

    if (!file_exists($migrationFile)) {
        $message = 'Migration file not found';
        $messageType = 'error';
    } else {
        $sql = file_get_contents($migrationFile);
        $filename = basename($migrationFile);

        // Split into individual statements
        $statements = array_filter(
            array_map('trim', preg_split('/;[\r\n]+/', $sql)),
            function($stmt) {
                return !empty($stmt) && !preg_match('/^--/', $stmt);
            }
        );

        $pdo = getPDO();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($statements as $i => $statement) {
            // Skip comments and empty statements
            if (empty(trim($statement)) || preg_match('/^--/', $statement)) {
                continue;
            }

            try {
                $pdo->exec($statement);
                $success++;
                $executedStatements[] = [
                    'number' => $i + 1,
                    'success' => true,
                    'statement' => substr($statement, 0, 100) . '...'
                ];
            } catch (PDOException $e) {
                $failed++;
                $errors[] = "Statement " . ($i + 1) . ": " . $e->getMessage();
                $executedStatements[] = [
                    'number' => $i + 1,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'statement' => substr($statement, 0, 100) . '...'
                ];
            }
        }

        if ($failed > 0) {
            $message = "Migration {$filename} completed with errors. Success: {$success}, Failed: {$failed}";
            $messageType = 'warning';
        } else {
            $message = "Migration {$filename} completed successfully! {$success} statements executed.";
            $messageType = 'success';
        }
    }
}

$pageTitle = 'Run Migrations';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container" style="max-width: 1000px; margin: 2rem auto;">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="database"></i>
                Database Migrations
            </h1>
            <a href="/admin/" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Back
            </a>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($executedStatements)): ?>
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h3 class="gs-h4">Execution Log</h3>
                </div>
                <div class="gs-card-content" style="padding: 0;">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th style="width: 80px;">Status</th>
                                <th>Statement Preview</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($executedStatements as $stmt): ?>
                                <tr>
                                    <td><?= $stmt['number'] ?></td>
                                    <td>
                                        <?php if ($stmt['success']): ?>
                                            <span class="gs-badge gs-badge-success gs-badge-sm">✓ OK</span>
                                        <?php else: ?>
                                            <span class="gs-badge gs-badge-danger gs-badge-sm">✗ Error</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-family: monospace; font-size: 0.85rem;">
                                        <?= h($stmt['statement']) ?>
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--gs-danger);">
                                        <?= isset($stmt['error']) ? h($stmt['error']) : '' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="gs-alert gs-alert-warning gs-mb-lg">
            <i data-lucide="alert-triangle"></i>
            <strong>Warning!</strong> Running migrations will modify your database schema. Make sure you have a backup before proceeding.
        </div>

        <!-- Migrations List -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">Available Migrations</h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($migrations)): ?>
                    <p class="gs-text-secondary gs-text-center gs-py-lg">No migrations found</p>
                <?php else: ?>
                    <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                        <?php foreach ($migrations as $migration): ?>
                            <div class="gs-card" style="background: var(--gs-background-secondary);">
                                <div class="gs-card-content">
                                    <div class="gs-flex gs-justify-between gs-items-start">
                                        <div class="gs-flex-1">
                                            <div class="gs-flex gs-items-center gs-gap-sm gs-mb-xs">
                                                <span class="gs-badge gs-badge-primary">
                                                    #<?= h($migration['number']) ?>
                                                </span>
                                                <h3 class="gs-h5 gs-text-primary">
                                                    <?= h(str_replace('_', ' ', ucfirst($migration['name']))) ?>
                                                </h3>
                                            </div>
                                            <p class="gs-text-sm gs-text-secondary">
                                                <i data-lucide="file" style="width: 14px; height: 14px;"></i>
                                                <?= h($migration['filename']) ?>
                                            </p>
                                        </div>
                                        <form method="POST" style="margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="migration_file" value="<?= h($migration['file']) ?>">
                                            <button type="submit"
                                                    class="gs-btn gs-btn-primary gs-btn-sm"
                                                    onclick="return confirm('Are you sure you want to run migration <?= h($migration['filename']) ?>?');">
                                                <i data-lucide="play" style="width: 14px; height: 14px;"></i>
                                                Run Migration
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="gs-alert gs-alert-info gs-mt-lg">
            <i data-lucide="info"></i>
            <strong>Note:</strong> Migrations are designed to be idempotent. Running them multiple times should not cause issues, but always backup your data first.
        </div>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
