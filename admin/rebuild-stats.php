<?php
/**
 * TheHUB Admin - Rebuild Rider Statistics
 *
 * Placera i: /admin/rebuild-stats.php
 */

require_once __DIR__ . '/../config.php';
require_admin();

require_once __DIR__ . '/../includes/rebuild-rider-stats.php';

$db = getDB();
$pdo = $db->getPdo();

$message = null;
$messageType = null;
$results = null;

// Hantera form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'migrate':
            // Kör databasmigration
            $results = runStatsMigration($pdo);
            $message = 'Migration slutförd!';
            $messageType = 'success';
            break;

        case 'rebuild_single':
            // Bygg om en enskild åkare
            $riderId = (int)$_POST['rider_id'];
            if ($riderId > 0) {
                $results = rebuildRiderStats($pdo, $riderId);
                if (isset($results['success']) && $results['success']) {
                    $message = "Statistik uppdaterad för åkare #$riderId ({$results['achievements_added']} achievements)";
                    $messageType = 'success';
                } else {
                    $message = "Fel: " . implode(', ', $results['errors'] ?? ['Okänt fel']);
                    $messageType = 'error';
                }
            }
            break;

        case 'rebuild_all':
            // Bygg om ALLA åkare (kan ta tid!)
            set_time_limit(300); // 5 minuter
            $results = rebuildAllRiderStats($pdo);
            $message = sprintf(
                'Rebuild klar! %d åkare processade på %.1f sekunder. %d misslyckades.',
                $results['processed'],
                $results['duration_seconds'],
                $results['failed']
            );
            $messageType = $results['failed'] > 0 ? 'warning' : 'success';
            break;

        case 'update_leaders':
            // Uppdatera endast serieledare
            updateCurrentSeriesLeaders($pdo);
            $message = 'Serieledare uppdaterade!';
            $messageType = 'success';
            break;
    }
}

// Hämta statistik för dashboard
$stats = [
    'total_riders' => 0,
    'riders_with_results' => 0,
    'total_achievements' => 0,
    'total_results' => 0,
    'last_rebuild' => null
];

try {
    $stats['total_riders'] = $db->getValue("SELECT COUNT(*) FROM riders") ?: 0;
    $stats['riders_with_results'] = $db->getValue("SELECT COUNT(DISTINCT cyclist_id) FROM results") ?: 0;

    // Check if rider_achievements table exists
    try {
        $stats['total_achievements'] = $db->getValue("SELECT COUNT(*) FROM rider_achievements") ?: 0;
    } catch (Exception $e) {
        $stats['total_achievements'] = 0;
    }

    $stats['total_results'] = $db->getValue("SELECT COUNT(*) FROM results") ?: 0;

    // Check if stats_updated_at column exists
    try {
        $stats['last_rebuild'] = $db->getValue("SELECT MAX(stats_updated_at) FROM riders");
    } catch (Exception $e) {
        $stats['last_rebuild'] = null;
    }
} catch (Exception $e) {
    // Ignore errors
}

/**
 * Kör databasmigration direkt
 */
function runStatsMigration($pdo) {
    $migrations = [
        // Achievements tabell
        "CREATE TABLE IF NOT EXISTS rider_achievements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rider_id INT NOT NULL,
            achievement_type VARCHAR(50) NOT NULL,
            achievement_value VARCHAR(100) DEFAULT NULL,
            series_id INT DEFAULT NULL,
            season_year INT DEFAULT NULL,
            earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rider (rider_id),
            INDEX idx_type (achievement_type),
            INDEX idx_season (season_year)
        )",

        // Sociala profiler
        "ALTER TABLE riders ADD COLUMN social_instagram VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE riders ADD COLUMN social_facebook VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE riders ADD COLUMN social_strava VARCHAR(50) DEFAULT NULL",
        "ALTER TABLE riders ADD COLUMN social_youtube VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE riders ADD COLUMN social_tiktok VARCHAR(100) DEFAULT NULL",

        // Cached stats
        "ALTER TABLE riders ADD COLUMN stats_total_starts INT DEFAULT 0",
        "ALTER TABLE riders ADD COLUMN stats_total_finished INT DEFAULT 0",
        "ALTER TABLE riders ADD COLUMN stats_total_wins INT DEFAULT 0",
        "ALTER TABLE riders ADD COLUMN stats_total_podiums INT DEFAULT 0",
        "ALTER TABLE riders ADD COLUMN stats_total_points INT DEFAULT 0",
        "ALTER TABLE riders ADD COLUMN stats_updated_at DATETIME DEFAULT NULL",

        // Experience
        "ALTER TABLE riders ADD COLUMN first_season INT DEFAULT NULL",
        "ALTER TABLE riders ADD COLUMN experience_level INT DEFAULT 1"
    ];

    $results = [];
    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
            $results[] = ['sql' => substr($sql, 0, 60) . '...', 'status' => 'OK'];
        } catch (PDOException $e) {
            // Ignorera "column already exists" fel
            if (strpos($e->getMessage(), 'Duplicate column') === false &&
                strpos($e->getMessage(), 'already exists') === false) {
                $results[] = ['sql' => substr($sql, 0, 60) . '...', 'status' => 'ERROR: ' . $e->getMessage()];
            } else {
                $results[] = ['sql' => substr($sql, 0, 60) . '...', 'status' => 'SKIPPED (exists)'];
            }
        }
    }

    return $results;
}

$pageTitle = 'Rebuild Rider Stats';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
    <div class="container">
        <!-- Header -->
        <div class="flex items-center justify-between mb-lg">
            <div>
                <h1>
                    <i data-lucide="refresh-cw"></i>
                    Rebuild Rider Statistics
                </h1>
                <p class="text-secondary">
                    Räkna om statistik och achievements efter resultatimport
                </p>
            </div>
            <a href="/admin" class="btn btn--secondary">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= h($messageType) ?> mb-lg">
            <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'alert-triangle') ?>"></i>
            <?= h($message) ?>
        </div>
        <?php endif; ?>

        <!-- Current Stats -->
        <div class="card mb-lg">
            <div class="card-header">
                <h2><i data-lucide="bar-chart-2"></i> Nuvarande status</h2>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-4 gap-md">
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($stats['total_riders']) ?></div>
                        <div class="stat-label">Åkare totalt</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($stats['riders_with_results']) ?></div>
                        <div class="stat-label">Med resultat</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($stats['total_results']) ?></div>
                        <div class="stat-label">Resultat</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($stats['total_achievements']) ?></div>
                        <div class="stat-label">Achievements</div>
                    </div>
                </div>
                <p class="text-secondary mt-md" style="font-size: 0.85rem;">
                    Senaste rebuild: <?= $stats['last_rebuild'] ? date('Y-m-d H:i', strtotime($stats['last_rebuild'])) : 'Aldrig' ?>
                </p>
            </div>
        </div>

        <!-- Migration -->
        <div class="card mb-lg">
            <div class="card-header">
                <h2><i data-lucide="database"></i> Steg 1: Databasmigration</h2>
            </div>
            <div class="card-body">
                <p class="text-secondary mb-md">
                    Kör detta FÖRST för att skapa nya tabeller och kolumner. Säkert att köra flera gånger.
                </p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="migrate">
                    <button type="submit" class="btn btn--secondary">
                        <i data-lucide="play"></i>
                        Kör Migration
                    </button>
                </form>

                <?php if ($results && isset($results[0]['sql'])): ?>
                <div class="mt-md">
                    <table class="table table--striped">
                        <thead>
                            <tr>
                                <th>Query</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $r): ?>
                            <tr>
                                <td><code style="font-size: 0.75rem;"><?= h($r['sql']) ?></code></td>
                                <td class="<?= strpos($r['status'], 'OK') !== false ? 'text-success' : (strpos($r['status'], 'ERROR') !== false ? 'text-error' : 'text-secondary') ?>">
                                    <?= h($r['status']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Single Rider Rebuild -->
        <div class="card mb-lg">
            <div class="card-header">
                <h2><i data-lucide="user"></i> Steg 2a: Rebuild enskild åkare</h2>
            </div>
            <div class="card-body">
                <p class="text-secondary mb-md">
                    Testa på en åkare först för att verifiera att allt fungerar.
                </p>
                <form method="POST" class="flex gap-sm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rebuild_single">
                    <input type="number" name="rider_id" placeholder="Rider ID (t.ex. 7701)" required min="1" class="form-control" style="max-width: 200px;">
                    <button type="submit" class="btn btn--primary">
                        <i data-lucide="refresh-cw"></i>
                        Rebuild åkare
                    </button>
                </form>
            </div>
        </div>

        <!-- Full Rebuild -->
        <div class="card mb-lg">
            <div class="card-header">
                <h2><i data-lucide="users"></i> Steg 2b: Rebuild ALLA åkare</h2>
            </div>
            <div class="card-body">
                <div class="alert alert-warning mb-md">
                    <i data-lucide="alert-triangle"></i>
                    <strong>Varning:</strong> Detta kan ta flera minuter beroende på antal åkare och resultat.
                    Stäng inte webbläsaren medan processen körs.
                </div>
                <form method="POST" onsubmit="return confirm('Är du säker? Detta kommer räkna om statistik för ALLA åkare.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rebuild_all">
                    <button type="submit" class="btn btn--danger">
                        <i data-lucide="zap"></i>
                        Rebuild alla åkare
                    </button>
                </form>
            </div>
        </div>

        <!-- Update Leaders Only -->
        <div class="card mb-lg">
            <div class="card-header">
                <h2><i data-lucide="trophy"></i> Uppdatera serieledare</h2>
            </div>
            <div class="card-body">
                <p class="text-secondary mb-md">
                    Snabb uppdatering av endast "Serieledare"-achievements för pågående säsong.
                </p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_leaders">
                    <button type="submit" class="btn btn--primary">
                        <i data-lucide="crown"></i>
                        Uppdatera ledare
                    </button>
                </form>
            </div>
        </div>

        <!-- Usage Info -->
        <div class="card">
            <div class="card-header">
                <h2><i data-lucide="book-open"></i> Användning</h2>
            </div>
            <div class="card-body">
                <ol class="usage-list">
                    <li>Kör <strong>Migration</strong> första gången (skapar nya tabeller)</li>
                    <li>Importera resultat som vanligt (CSV-import etc.)</li>
                    <li>Kör <strong>Rebuild enskild åkare</strong> för att testa</li>
                    <li>Kör <strong>Rebuild alla åkare</strong> efter stora importer</li>
                    <li>Kör <strong>Uppdatera ledare</strong> efter varje nytt event-resultat</li>
                </ol>
            </div>
        </div>
    </div>
</main>

<style>
/* Mobile first - base styles */
.stat-box {
    text-align: center;
    padding: var(--space-md);
    background: var(--color-star-fade);
    border-radius: var(--radius-md);
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-primary);
    line-height: 1;
}

.stat-label {
    font-size: 0.7rem;
    color: var(--color-text);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: var(--space-xs);
}

.usage-list {
    margin-left: var(--space-lg);
    color: var(--color-text);
}

.usage-list li {
    margin-bottom: var(--space-sm);
}

.usage-list strong {
    color: var(--color-primary);
}

.form-control {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-star);
    color: var(--color-primary);
    width: 100%;
}

.form-control:focus {
    outline: none;
    border-color: var(--color-accent);
}

/* Grid - mobile first (2 columns) */
.grid {
    display: grid;
}

.grid-cols-4 {
    grid-template-columns: repeat(2, 1fr);
}

.gap-md {
    gap: var(--space-md);
}

/* Page header - mobile: stack vertically */
.main-content .container > .flex:first-child {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-md);
}

/* Form with input + button - mobile: stack */
form.flex {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

form.flex .form-control {
    width: 100%;
}

/* Cards full width on mobile */
.card {
    overflow-x: auto;
}

/* Tablet (600px+) */
@media (min-width: 600px) {
    .stat-value {
        font-size: 1.5rem;
    }

    .stat-label {
        font-size: 0.75rem;
    }

    form.flex {
        flex-direction: row;
        align-items: center;
    }

    form.flex .form-control {
        width: auto;
        max-width: 200px;
    }
}

/* Desktop (900px+) */
@media (min-width: 900px) {
    .grid-cols-4 {
        grid-template-columns: repeat(4, 1fr);
    }

    .stat-value {
        font-size: 1.75rem;
    }

    .main-content .container > .flex:first-child {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }
}
</style>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
