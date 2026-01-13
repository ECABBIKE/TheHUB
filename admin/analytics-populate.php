<?php
/**
 * Populate Historical - Interactive Version
 *
 * Kor populate ar for ar med AJAX for att undvika timeout.
 * Visar progress i realtid.
 *
 * @package TheHUB Analytics
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

// Hantera AJAX-request for ett specifikt ar
if (isset($_GET['run_year'])) {
    header('Content-Type: application/json');

    $year = (int)$_GET['run_year'];
    $force = isset($_GET['force']);

    if ($year < 2000 || $year > 2050) {
        echo json_encode(['error' => 'Ogiltigt ar']);
        exit;
    }

    try {
        require_once __DIR__ . '/../analytics/includes/AnalyticsEngine.php';

        global $pdo;
        $engine = new AnalyticsEngine($pdo);
        $engine->enableNonBlockingMode();
        $engine->setForceRerun($force);

        // Anvand den snabba bulk-versionen
        $results = $engine->refreshAllStatsFast($year);
        $results['year'] = $year;

        $results['success'] = true;
        $results['total'] = array_sum(array_filter($results, 'is_numeric'));

        echo json_encode($results);

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Hamta tillgangliga ar
global $pdo;
$years = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT YEAR(date) as year
        FROM events
        WHERE date IS NOT NULL
        ORDER BY year ASC
    ");
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $error = $e->getMessage();
}

$page_title = 'Populate Historical Data';
$breadcrumbs = [
    ['label' => 'Tools', 'url' => '/admin/tools.php'],
    ['label' => 'Populate Historical']
];
include __DIR__ . '/components/unified-layout.php';
?>

<style>
.populate-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.year-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: var(--space-sm);
    margin: var(--space-lg) 0;
}
.year-item {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: var(--space-sm) var(--space-md);
    text-align: center;
    transition: all 0.2s;
}
.year-item.pending { opacity: 0.5; }
.year-item.running {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}
.year-item.done {
    border-color: var(--color-success);
    background: rgba(16, 185, 129, 0.1);
}
.year-item.error {
    border-color: var(--color-error);
    background: rgba(239, 68, 68, 0.1);
}
.year-item .year-label {
    font-weight: 600;
    font-size: var(--text-lg);
}
.year-item .year-status {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    margin-top: var(--space-2xs);
}
.year-item.done .year-status { color: var(--color-success); }
.year-item.error .year-status { color: var(--color-error); }

.progress-bar {
    width: 100%;
    height: 8px;
    background: var(--color-bg-surface);
    border-radius: var(--radius-full);
    overflow: hidden;
    margin: var(--space-md) 0;
}
.progress-bar-fill {
    height: 100%;
    background: var(--color-accent);
    transition: width 0.3s;
    width: 0%;
}
.progress-text {
    text-align: center;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.log-output {
    background: var(--color-bg-page);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: var(--space-md);
    font-family: monospace;
    font-size: var(--text-sm);
    max-height: 300px;
    overflow-y: auto;
    white-space: pre-wrap;
}
.controls {
    display: flex;
    gap: var(--space-md);
    align-items: center;
    flex-wrap: wrap;
}
</style>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i data-lucide="alert-circle"></i>
    <div><?= htmlspecialchars($error) ?></div>
</div>
<?php endif; ?>

<div class="populate-card">
    <h3>Generera Analytics-data</h3>
    <p style="color: var(--color-text-secondary);">
        Beraknar statistik for varje ar. Klicka "Starta" for att borja.
    </p>

    <div class="controls">
        <button id="startBtn" class="btn-admin btn-admin-primary" onclick="startPopulate(false)">
            <i data-lucide="play"></i> Starta
        </button>
        <button id="forceBtn" class="btn-admin btn-admin-warning" onclick="startPopulate(true)">
            <i data-lucide="refresh-cw"></i> Starta (Force)
        </button>
        <button id="stopBtn" class="btn-admin btn-admin-secondary" onclick="stopPopulate()" disabled>
            <i data-lucide="square"></i> Stoppa
        </button>
        <span id="statusText" style="color: var(--color-text-muted);"></span>
    </div>

    <div class="progress-bar">
        <div class="progress-bar-fill" id="progressFill"></div>
    </div>
    <div class="progress-text" id="progressText">0 / <?= count($years) ?> ar</div>

    <div class="year-list" id="yearList">
        <?php foreach ($years as $year): ?>
        <div class="year-item pending" id="year-<?= $year ?>" data-year="<?= $year ?>">
            <div class="year-label"><?= $year ?></div>
            <div class="year-status">Vantar...</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="populate-card">
    <h3>Logg</h3>
    <div class="log-output" id="logOutput">Klicka "Starta" for att borja...</div>
</div>

<div class="populate-card">
    <h3>Nasta steg</h3>
    <div class="controls">
        <a href="/admin/analytics-diagnose.php" class="btn-admin btn-admin-secondary">
            <i data-lucide="stethoscope"></i> Diagnostisera
        </a>
        <a href="/admin/analytics-trends.php" class="btn-admin btn-admin-primary">
            <i data-lucide="trending-up"></i> Visa Trender
        </a>
    </div>
</div>

<script>
const years = <?= json_encode($years) ?>;
let isRunning = false;
let currentIndex = 0;
let forceMode = false;
let totalProcessed = 0;

function log(msg) {
    const el = document.getElementById('logOutput');
    const time = new Date().toLocaleTimeString();
    el.textContent += `[${time}] ${msg}\n`;
    el.scrollTop = el.scrollHeight;
}

function updateProgress() {
    const pct = years.length > 0 ? (currentIndex / years.length * 100) : 0;
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressText').textContent = `${currentIndex} / ${years.length} ar (${totalProcessed} rader totalt)`;
}

function setYearStatus(year, status, text) {
    const el = document.getElementById('year-' + year);
    if (el) {
        el.className = 'year-item ' + status;
        el.querySelector('.year-status').textContent = text;
    }
}

async function processYear(year) {
    setYearStatus(year, 'running', 'Bearbetar...');

    try {
        const url = `/admin/analytics-populate.php?run_year=${year}${forceMode ? '&force=1' : ''}`;
        const response = await fetch(url);
        const data = await response.json();

        if (data.error) {
            setYearStatus(year, 'error', data.error);
            log(`${year}: FEL - ${data.error}`);
            return false;
        }

        const total = data.total || 0;
        totalProcessed += total;

        if (total > 0) {
            setYearStatus(year, 'done', `${total} rader`);
            log(`${year}: ${data.yearly_stats} riders, ${data.series_participation} participations, ${data.club_stats} klubbar`);
        } else {
            setYearStatus(year, 'done', 'Redan klar');
            log(`${year}: Redan beraknat (hoppades over)`);
        }
        return true;

    } catch (e) {
        setYearStatus(year, 'error', 'Natverksfel');
        log(`${year}: FEL - ${e.message}`);
        return false;
    }
}

async function runLoop() {
    while (isRunning && currentIndex < years.length) {
        const year = years[currentIndex];
        await processYear(year);
        currentIndex++;
        updateProgress();

        // Liten paus mellan ar for att inte overbelasta servern
        await new Promise(r => setTimeout(r, 100));
    }

    if (currentIndex >= years.length) {
        log('=== KLAR! ===');
        document.getElementById('statusText').textContent = 'Klar!';
    }

    isRunning = false;
    document.getElementById('startBtn').disabled = false;
    document.getElementById('forceBtn').disabled = false;
    document.getElementById('stopBtn').disabled = true;
}

function startPopulate(force) {
    if (isRunning) return;

    forceMode = force;
    isRunning = true;
    currentIndex = 0;
    totalProcessed = 0;

    // Reset UI
    document.getElementById('logOutput').textContent = '';
    years.forEach(y => setYearStatus(y, 'pending', 'Vantar...'));

    document.getElementById('startBtn').disabled = true;
    document.getElementById('forceBtn').disabled = true;
    document.getElementById('stopBtn').disabled = false;
    document.getElementById('statusText').textContent = force ? 'Kor med Force...' : 'Kor...';

    log(force ? '=== STARTAR (FORCE MODE) ===' : '=== STARTAR ===');
    log(`Bearbetar ${years.length} ar...`);
    log('');

    updateProgress();
    runLoop();
}

function stopPopulate() {
    isRunning = false;
    document.getElementById('statusText').textContent = 'Stoppad';
    log('--- Stoppad av anvandaren ---');
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
