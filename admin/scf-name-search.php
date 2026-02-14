<?php
/**
 * SCF Name Search Tool
 *
 * AJAX-based tool for searching riders without UCI ID in SCF.
 * Processes batches asynchronously to keep the page responsive.
 *
 * Performance optimizations (2026-02-14):
 * - All batch processing via AJAX JSON API (no full page reloads)
 * - Tracks "not_found" riders to avoid re-searching
 * - UNIQUE KEY on rider_id prevents duplicate match candidates
 * - Confirm/reject via AJAX (inline, no reload)
 * - Real-time progress with ETA
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$apiKey = env('SCF_API_KEY', '');
$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));

// Initialize SCF service
require_once __DIR__ . '/../includes/SCFLicenseService.php';
$scfService = null;
if ($apiKey) {
    $scfService = new SCFLicenseService($apiKey, $db);
}

// ========================================
// AJAX API Handler - returns JSON
// ========================================
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        echo json_encode(['error' => 'CSRF-validering misslyckades']);
        exit;
    }

    if (!$apiKey || !$scfService) {
        echo json_encode(['error' => 'SCF API-nyckel saknas']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'search_batch':
            set_time_limit(120);
            $batchSize = min(25, max(1, (int)($_POST['batch_size'] ?? 10)));

            // Get riders to search - exclude already searched (pending, confirmed, not_found)
            $riders = $db->getAll("
                SELECT r.id, r.firstname, r.lastname, r.birth_year, r.gender,
                       r.license_number, c.name as club_name
                FROM riders r
                LEFT JOIN clubs c ON r.club_id = c.id
                WHERE (r.license_number IS NULL OR r.license_number = '' OR r.license_number LIKE 'SWE%')
                AND r.firstname IS NOT NULL AND r.firstname != ''
                AND r.lastname IS NOT NULL AND r.lastname != ''
                AND NOT EXISTS (
                    SELECT 1 FROM scf_match_candidates mc
                    WHERE mc.rider_id = r.id
                    AND mc.status IN ('pending', 'confirmed', 'not_found', 'auto_confirmed')
                )
                ORDER BY r.id
                LIMIT ?
            ", [$batchSize]);

            $found = 0;
            $notFound = 0;
            $results = [];

            foreach ($riders as $rider) {
                $gender = $rider['gender'] ?: 'M';
                $birthdate = $rider['birth_year'] ? $rider['birth_year'] . '-01-01' : null;

                $scfService->rateLimit();

                $scfData = $scfService->lookupByName(
                    $rider['firstname'],
                    $rider['lastname'],
                    $gender,
                    $birthdate,
                    $year
                );

                if (!empty($scfData)) {
                    $found++;
                    $matchScore = calculateMatchScore($rider, $scfData);

                    // Insert or update match candidate (UNIQUE KEY on rider_id)
                    $db->query("
                        INSERT INTO scf_match_candidates
                        (rider_id, hub_firstname, hub_lastname, hub_gender, hub_birth_year,
                         scf_uci_id, scf_firstname, scf_lastname, scf_club, scf_nationality,
                         match_score, match_reason, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                        ON DUPLICATE KEY UPDATE
                            scf_uci_id = VALUES(scf_uci_id),
                            scf_firstname = VALUES(scf_firstname),
                            scf_lastname = VALUES(scf_lastname),
                            scf_club = VALUES(scf_club),
                            scf_nationality = VALUES(scf_nationality),
                            match_score = VALUES(match_score),
                            match_reason = VALUES(match_reason),
                            status = 'pending',
                            created_at = NOW()
                    ", [
                        $rider['id'],
                        $rider['firstname'],
                        $rider['lastname'],
                        $rider['gender'],
                        $rider['birth_year'],
                        $scfData['uci_id'] ?? null,
                        $scfData['firstname'] ?? null,
                        $scfData['lastname'] ?? null,
                        $scfData['club_name'] ?? null,
                        $scfData['nationality'] ?? null,
                        $matchScore['score'],
                        $matchScore['reason']
                    ]);

                    $results[] = [
                        'rider' => $rider['firstname'] . ' ' . $rider['lastname'],
                        'match' => ($scfData['firstname'] ?? '') . ' ' . ($scfData['lastname'] ?? ''),
                        'score' => $matchScore['score'],
                        'uci_id' => $scfData['uci_id'] ?? null
                    ];
                } else {
                    $notFound++;
                    // Track "not found" to avoid re-searching this rider
                    $db->query("
                        INSERT INTO scf_match_candidates
                        (rider_id, hub_firstname, hub_lastname, hub_gender, hub_birth_year,
                         match_score, match_reason, status, created_at)
                        VALUES (?, ?, ?, ?, ?, 0, 'Ingen matchning i SCF', 'not_found', NOW())
                        ON DUPLICATE KEY UPDATE
                            status = 'not_found',
                            match_reason = 'Ingen matchning i SCF',
                            created_at = NOW()
                    ", [
                        $rider['id'],
                        $rider['firstname'],
                        $rider['lastname'],
                        $rider['gender'],
                        $rider['birth_year']
                    ]);
                }
            }

            // Get remaining count (unsearched riders)
            $remaining = (int)$db->getValue("
                SELECT COUNT(*) FROM riders r
                WHERE (r.license_number IS NULL OR r.license_number = '' OR r.license_number LIKE 'SWE%')
                AND r.firstname IS NOT NULL AND r.firstname != ''
                AND r.lastname IS NOT NULL AND r.lastname != ''
                AND NOT EXISTS (
                    SELECT 1 FROM scf_match_candidates mc
                    WHERE mc.rider_id = r.id
                    AND mc.status IN ('pending', 'confirmed', 'not_found', 'auto_confirmed')
                )
            ");

            // Get updated pending count
            $pendingCount = (int)$db->getValue("SELECT COUNT(*) FROM scf_match_candidates WHERE status = 'pending'");

            echo json_encode([
                'success' => true,
                'processed' => count($riders),
                'found' => $found,
                'not_found' => $notFound,
                'remaining' => $remaining,
                'pending_matches' => $pendingCount,
                'has_more' => $remaining > 0,
                'results' => $results
            ]);
            exit;

        case 'confirm_match':
            $matchId = (int)($_POST['match_id'] ?? 0);
            $match = $db->getRow("SELECT * FROM scf_match_candidates WHERE id = ?", [$matchId]);

            if (!$match || empty($match['scf_uci_id'])) {
                echo json_encode(['error' => 'Matchning hittades inte']);
                exit;
            }

            // Update rider with UCI ID
            $db->query("
                UPDATE riders SET license_number = ?, updated_at = NOW() WHERE id = ?
            ", [$match['scf_uci_id'], $match['rider_id']]);

            // Mark match as confirmed
            $db->query("
                UPDATE scf_match_candidates
                SET status = 'confirmed', reviewed_by = ?, reviewed_at = NOW()
                WHERE id = ?
            ", [$_SESSION['admin_id'] ?? null, $matchId]);

            // Verify the rider's license via UCI ID lookup
            $uciIds = [$match['scf_uci_id']];
            $scfResults = $scfService->lookupByUciIds($uciIds, $year);
            $licenseData = reset($scfResults);

            if ($licenseData) {
                $scfService->updateRiderLicense($match['rider_id'], $licenseData, $year);
                $scfService->cacheLicense($licenseData, $year);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Matchning bekraftad! UCI ID ' . $match['scf_uci_id'] . ' tilldelat.'
            ]);
            exit;

        case 'reject_match':
            $matchId = (int)($_POST['match_id'] ?? 0);
            $db->query("
                UPDATE scf_match_candidates
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW()
                WHERE id = ?
            ", [$_SESSION['admin_id'] ?? null, $matchId]);

            echo json_encode(['success' => true, 'message' => 'Matchning avvisad.']);
            exit;

        case 'get_stats':
            $stats = getStats($db);
            echo json_encode(['success' => true, 'stats' => $stats]);
            exit;

        case 'get_pending':
            $pendingMatches = getPendingMatches($db);
            $html = renderPendingMatches($pendingMatches);
            echo json_encode(['success' => true, 'html' => $html, 'count' => count($pendingMatches)]);
            exit;

        case 'reset_not_found':
            // Allow re-searching riders that were previously not found
            $deleted = (int)$db->getValue("SELECT COUNT(*) FROM scf_match_candidates WHERE status = 'not_found'");
            $db->query("DELETE FROM scf_match_candidates WHERE status = 'not_found'");
            echo json_encode(['success' => true, 'message' => $deleted . ' riders aterstallda for ny sokning.']);
            exit;

        default:
            echo json_encode(['error' => 'Okand atgard: ' . $action]);
            exit;
    }
}

// ========================================
// Helper functions
// ========================================
function getStats($db) {
    return [
        'without_uci' => (int)$db->getValue("SELECT COUNT(*) FROM riders WHERE license_number IS NULL OR license_number = '' OR license_number LIKE 'SWE%'"),
        'with_swe_id' => (int)$db->getValue("SELECT COUNT(*) FROM riders WHERE license_number LIKE 'SWE%'"),
        'pending_matches' => (int)$db->getValue("SELECT COUNT(*) FROM scf_match_candidates WHERE status = 'pending'"),
        'confirmed_matches' => (int)$db->getValue("SELECT COUNT(*) FROM scf_match_candidates WHERE status = 'confirmed'"),
        'not_found' => (int)$db->getValue("SELECT COUNT(*) FROM scf_match_candidates WHERE status = 'not_found'"),
        'remaining' => (int)$db->getValue("
            SELECT COUNT(*) FROM riders r
            WHERE (r.license_number IS NULL OR r.license_number = '' OR r.license_number LIKE 'SWE%')
            AND r.firstname IS NOT NULL AND r.firstname != ''
            AND r.lastname IS NOT NULL AND r.lastname != ''
            AND NOT EXISTS (
                SELECT 1 FROM scf_match_candidates mc
                WHERE mc.rider_id = r.id
                AND mc.status IN ('pending', 'confirmed', 'not_found', 'auto_confirmed')
            )
        ")
    ];
}

function getPendingMatches($db) {
    return $db->getAll("
        SELECT mc.*, r.firstname as rider_firstname, r.lastname as rider_lastname,
               r.birth_year as rider_birth_year, r.gender as rider_gender,
               c.name as rider_club
        FROM scf_match_candidates mc
        JOIN riders r ON mc.rider_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE mc.status = 'pending'
        ORDER BY mc.match_score DESC, mc.created_at DESC
        LIMIT 50
    ");
}

function renderPendingMatches($matches) {
    if (empty($matches)) return '<p class="text-secondary">Inga vantande matchningar.</p>';

    $html = '';
    foreach ($matches as $match) {
        $scoreClass = $match['match_score'] >= 70 ? 'high' : ($match['match_score'] >= 40 ? 'medium' : 'low');
        $genderText = ($match['rider_gender'] ?? '') === 'M' ? 'Man' : (($match['rider_gender'] ?? '') === 'F' ? 'Kvinna' : '');

        $html .= '<div class="match-card" id="match-' . $match['id'] . '">';
        $html .= '<div class="match-header">';
        $html .= '<div><strong>Match #' . $match['id'] . '</strong> ';
        $html .= '<span class="text-secondary text-sm">Skapad ' . date('Y-m-d H:i', strtotime($match['created_at'])) . '</span></div>';
        $html .= '<div class="match-score ' . $scoreClass . '">' . $match['match_score'] . '%</div>';
        $html .= '</div>';

        $html .= '<div class="match-comparison">';

        // HUB side
        $html .= '<div class="match-side hub">';
        $html .= '<div class="match-name">' . htmlspecialchars(($match['hub_firstname'] ?? '') . ' ' . ($match['hub_lastname'] ?? '')) . '</div>';
        $html .= '<div class="match-details">';
        if (!empty($match['rider_birth_year'])) $html .= 'Fodd ' . $match['rider_birth_year'];
        if (!empty($genderText)) $html .= ' &middot; ' . $genderText;
        if (!empty($match['rider_club'])) $html .= '<br>' . htmlspecialchars($match['rider_club']);
        $html .= '</div></div>';

        // Arrow
        $html .= '<div class="match-arrow"><i data-lucide="arrow-right"></i></div>';

        // SCF side
        $html .= '<div class="match-side scf">';
        $html .= '<div class="match-name">' . htmlspecialchars(($match['scf_firstname'] ?? '') . ' ' . ($match['scf_lastname'] ?? '')) . '</div>';
        $html .= '<div class="match-details">';
        $html .= 'UCI: <code>' . htmlspecialchars($match['scf_uci_id'] ?? '') . '</code>';
        if (!empty($match['scf_nationality'])) $html .= ' &middot; ' . htmlspecialchars($match['scf_nationality']);
        if (!empty($match['scf_club'])) $html .= '<br>' . htmlspecialchars($match['scf_club']);
        $html .= '</div></div>';

        $html .= '</div>'; // match-comparison

        // Match reason
        $html .= '<div class="text-secondary text-sm" style="margin-top: var(--space-sm);">';
        $html .= htmlspecialchars($match['match_reason'] ?? '');
        $html .= '</div>';

        // Actions
        $html .= '<div class="match-actions">';
        $html .= '<button type="button" class="btn btn-primary btn-sm" onclick="confirmMatch(' . $match['id'] . ', this)">';
        $html .= '<i data-lucide="check"></i> Bekrafta</button>';
        $html .= '<button type="button" class="btn btn-danger btn-sm" onclick="rejectMatch(' . $match['id'] . ', this)">';
        $html .= '<i data-lucide="x"></i> Avvisa</button>';
        $html .= '<a href="/rider/' . $match['rider_id'] . '" class="btn btn-secondary btn-sm" target="_blank">';
        $html .= '<i data-lucide="external-link"></i> Visa profil</a>';
        $html .= '</div>';

        $html .= '</div>'; // match-card
    }
    return $html;
}

// Match score calculator
function calculateMatchScore($rider, $scfData) {
    $score = 0;
    $reasons = [];

    $hubFirstname = mb_strtolower(trim($rider['firstname'] ?? ''));
    $hubLastname = mb_strtolower(trim($rider['lastname'] ?? ''));
    $scfFirstname = mb_strtolower(trim($scfData['firstname'] ?? ''));
    $scfLastname = mb_strtolower(trim($scfData['lastname'] ?? ''));

    if ($hubFirstname === $scfFirstname) {
        $score += 30;
        $reasons[] = "Exakt fornamn (+30)";
    } elseif (strlen($hubFirstname) > 0 && strlen($scfFirstname) > 0 &&
              similar_text($hubFirstname, $scfFirstname) / max(strlen($hubFirstname), strlen($scfFirstname)) > 0.8) {
        $score += 20;
        $reasons[] = "Liknande fornamn (+20)";
    }

    if ($hubLastname === $scfLastname) {
        $score += 30;
        $reasons[] = "Exakt efternamn (+30)";
    } elseif (strlen($hubLastname) > 0 && strlen($scfLastname) > 0 &&
              similar_text($hubLastname, $scfLastname) / max(strlen($hubLastname), strlen($scfLastname)) > 0.8) {
        $score += 20;
        $reasons[] = "Liknande efternamn (+20)";
    }

    if (!empty($rider['birth_year']) && !empty($scfData['birth_year'])) {
        if ($rider['birth_year'] == $scfData['birth_year']) {
            $score += 25;
            $reasons[] = "Samma fodelsear (+25)";
        } elseif (abs($rider['birth_year'] - $scfData['birth_year']) <= 1) {
            $score += 10;
            $reasons[] = "Fodelsear +/-1 ar (+10)";
        }
    }

    if (!empty($rider['gender']) && !empty($scfData['gender'])) {
        if (strtoupper($rider['gender']) === strtoupper($scfData['gender'])) {
            $score += 10;
            $reasons[] = "Samma kon (+10)";
        }
    }

    if (!empty($scfData['license_type'])) {
        $score += 5;
        $reasons[] = "Har licens (+5)";
    }

    return [
        'score' => min(100, $score),
        'reason' => implode(', ', $reasons)
    ];
}

// ========================================
// Page rendering (initial load only)
// ========================================
$stats = getStats($db);
$pendingMatches = getPendingMatches($db);

$page_title = 'SCF Namnsok';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'SCF Namnsok']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}

.stat-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    text-align: center;
}

.stat-value {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--color-accent);
}

.stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.stat-card.warning .stat-value { color: var(--color-warning); }
.stat-card.success .stat-value { color: var(--color-success); }
.stat-card.muted .stat-value { color: var(--color-text-muted); }

.match-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-md);
    transition: opacity 0.3s ease;
}

.match-card.removing {
    opacity: 0;
    transform: translateX(20px);
    transition: all 0.3s ease;
}

.match-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-sm);
}

.match-score {
    font-size: var(--text-lg);
    font-weight: 700;
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
}

.match-score.high { background: var(--color-success); color: white; }
.match-score.medium { background: var(--color-warning); color: white; }
.match-score.low { background: var(--color-error); color: white; }

.match-comparison {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: var(--space-md);
    align-items: center;
}

.match-side { padding: var(--space-sm); }
.match-side.hub { background: var(--color-bg-hover); border-radius: var(--radius-sm); }
.match-side.scf { background: rgba(16, 185, 129, 0.1); border-radius: var(--radius-sm); }

.match-arrow {
    color: var(--color-text-muted);
    font-size: var(--text-xl);
}

.match-name { font-weight: 600; font-size: var(--text-base); }
.match-details { font-size: var(--text-sm); color: var(--color-text-secondary); }

.match-actions {
    display: flex;
    gap: var(--space-sm);
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
    flex-wrap: wrap;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.progress-bar {
    background: var(--color-bg-hover);
    border-radius: var(--radius-full);
    height: 8px;
    overflow: hidden;
}

.progress-fill {
    background: var(--color-accent);
    height: 100%;
    transition: width 0.3s ease;
}

.search-log {
    max-height: 200px;
    overflow-y: auto;
    font-size: var(--text-sm);
    font-family: monospace;
    background: var(--color-bg-page);
    border-radius: var(--radius-sm);
    padding: var(--space-sm);
    margin-top: var(--space-md);
}

.search-log-entry {
    padding: 2px 0;
    color: var(--color-text-secondary);
}

.search-log-entry.found {
    color: var(--color-success);
}

@media (max-width: 767px) {
    .match-comparison {
        grid-template-columns: 1fr;
        gap: var(--space-sm);
    }
    .match-arrow {
        display: none;
    }
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
</style>

<?php if (!$apiKey): ?>
<div class="alert alert-danger">
    API-nyckel saknas. Lagg till <code>SCF_API_KEY=din_nyckel</code> i <code>.env</code>-filen.
</div>
<?php else: ?>

<!-- Statistics -->
<div class="stats-grid" id="statsGrid">
    <div class="stat-card warning">
        <div class="stat-value" id="statRemaining"><?= number_format($stats['remaining']) ?></div>
        <div class="stat-label">Att soka</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" id="statWithoutUci"><?= number_format($stats['without_uci']) ?></div>
        <div class="stat-label">Utan UCI ID</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-value" id="statPending"><?= number_format($stats['pending_matches']) ?></div>
        <div class="stat-label">Vantande</div>
    </div>
    <div class="stat-card success">
        <div class="stat-value" id="statConfirmed"><?= number_format($stats['confirmed_matches']) ?></div>
        <div class="stat-label">Bekraftade</div>
    </div>
    <div class="stat-card muted">
        <div class="stat-value" id="statNotFound"><?= number_format($stats['not_found']) ?></div>
        <div class="stat-label">Ej i SCF</div>
    </div>
</div>

<!-- Batch Search -->
<div class="card">
    <div class="card-header">
        <h3>Sok i SCF</h3>
    </div>
    <div class="card-body">
        <p class="text-secondary" style="margin-bottom: var(--space-md);">
            Soker efter cyklister utan UCI ID i SCF baserat pa namn och fodelsear.
            Varje sokning tar ca 0.8 sek (API-begransning). Sidan forblir interaktiv under sokning.
        </p>

        <div style="display: flex; gap: var(--space-md); flex-wrap: wrap; align-items: end;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Antal per batch</label>
                <select id="batchSize" class="form-select" style="width: 100px;">
                    <option value="5">5</option>
                    <option value="10" selected>10</option>
                    <option value="15">15</option>
                    <option value="20">20</option>
                    <option value="25">25</option>
                </select>
            </div>

            <button type="button" id="searchBatchBtn" class="btn btn-secondary" onclick="runSingleBatch()">
                <i data-lucide="search"></i> Sok en batch
            </button>

            <button type="button" id="searchAllBtn" class="btn btn-primary" onclick="startAutoSearch()">
                <i data-lucide="zap"></i> Sok alla
            </button>

            <button type="button" id="stopBtn" class="btn btn-danger" style="display: none;" onclick="stopAutoSearch()">
                <i data-lucide="square"></i> Stoppa
            </button>

            <?php if ($stats['not_found'] > 0): ?>
            <button type="button" class="btn btn-ghost" onclick="resetNotFound()" title="Tillat omsokning av riders som tidigare inte hittades">
                <i data-lucide="refresh-cw"></i> Aterstall ej hittade (<?= $stats['not_found'] ?>)
            </button>
            <?php endif; ?>
        </div>

        <?php if ($stats['remaining'] > 0): ?>
        <div class="progress-bar" style="margin-top: var(--space-md);">
            <div class="progress-fill" id="progressFill" style="width: 0%;"></div>
        </div>
        <p class="text-secondary text-sm" style="margin-top: var(--space-xs);" id="progressText">
            <?= number_format($stats['remaining']) ?> cyklister kvar att soka
        </p>
        <?php endif; ?>

        <div id="autoStatus" style="display: none; margin-top: var(--space-md); padding: var(--space-md); background: var(--color-bg-hover); border-radius: var(--radius-md);">
            <div style="display: flex; align-items: center; gap: var(--space-sm);">
                <div class="spinner" style="width: 20px; height: 20px; border: 2px solid var(--color-border); border-top-color: var(--color-accent); border-radius: 50%; animation: spin 1s linear infinite;"></div>
                <span id="autoStatusText">Forbereder...</span>
            </div>
        </div>

        <div id="searchLog" class="search-log" style="display: none;"></div>
    </div>
</div>

<!-- Pending Matches -->
<div class="card" id="pendingCard">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3>Vantande matchningar (<span id="pendingCount"><?= count($pendingMatches) ?></span>)</h3>
        <button type="button" class="btn btn-ghost btn-sm" onclick="refreshPending()" title="Uppdatera listan">
            <i data-lucide="refresh-cw"></i>
        </button>
    </div>
    <div class="card-body" id="pendingList">
        <?= renderPendingMatches($pendingMatches) ?>
    </div>
</div>

<?php endif; ?>

<script>
const CSRF_TOKEN = '<?= get_csrf_token() ?>';
const YEAR = <?= $year ?>;
let isRunning = false;
let totalProcessed = 0;
let totalFound = 0;
let startTime = 0;
let initialRemaining = <?= $stats['remaining'] ?>;

// ========================================
// AJAX helpers
// ========================================
async function ajaxPost(action, extraData = {}) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('action', action);
    formData.append('year', YEAR);
    for (const [key, val] of Object.entries(extraData)) {
        formData.append(key, val);
    }

    const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData
    });

    if (!response.ok) throw new Error('Server error: ' + response.status);
    return response.json();
}

// ========================================
// Stats & UI updates
// ========================================
function updateStatsFromData(data) {
    if (data.remaining !== undefined) {
        document.getElementById('statRemaining').textContent = data.remaining.toLocaleString();
    }
    if (data.pending_matches !== undefined) {
        document.getElementById('statPending').textContent = data.pending_matches.toLocaleString();
        document.getElementById('pendingCount').textContent = data.pending_matches;
    }
}

function updateProgress(remaining) {
    const searched = initialRemaining - remaining;
    const pct = initialRemaining > 0 ? Math.round(searched / initialRemaining * 100) : 100;
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');

    if (progressFill) progressFill.style.width = pct + '%';
    if (progressText) {
        let text = remaining.toLocaleString() + ' kvar att soka (' + pct + '%)';
        if (isRunning && totalProcessed > 0) {
            const elapsed = (Date.now() - startTime) / 1000;
            const perRider = elapsed / totalProcessed;
            const eta = Math.round(remaining * perRider);
            if (eta > 60) {
                text += ' - ca ' + Math.round(eta / 60) + ' min kvar';
            } else if (eta > 0) {
                text += ' - ca ' + eta + ' sek kvar';
            }
        }
        progressText.textContent = text;
    }
}

function addLogEntry(text, isFound) {
    const log = document.getElementById('searchLog');
    if (!log) return;
    log.style.display = 'block';
    const entry = document.createElement('div');
    entry.className = 'search-log-entry' + (isFound ? ' found' : '');
    entry.textContent = text;
    log.appendChild(entry);
    log.scrollTop = log.scrollHeight;
}

function setSearchUI(running) {
    isRunning = running;
    document.getElementById('searchAllBtn').style.display = running ? 'none' : 'inline-flex';
    document.getElementById('searchBatchBtn').style.display = running ? 'none' : 'inline-flex';
    document.getElementById('stopBtn').style.display = running ? 'inline-flex' : 'none';
    document.getElementById('autoStatus').style.display = running ? 'block' : 'none';
}

// ========================================
// Batch search
// ========================================
async function runSingleBatch() {
    const batchSize = document.getElementById('batchSize').value;
    const btn = document.getElementById('searchBatchBtn');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border:2px solid var(--color-border);border-top-color:var(--color-accent);border-radius:50%;animation:spin 1s linear infinite;display:inline-block;"></div> Soker...';

    try {
        const data = await ajaxPost('search_batch', { batch_size: batchSize });
        if (data.error) {
            alert(data.error);
            return;
        }

        updateStatsFromData(data);
        updateProgress(data.remaining);

        // Log results
        data.results.forEach(r => {
            addLogEntry('Hittade: ' + r.rider + ' -> ' + r.match + ' (' + r.score + '%)', true);
        });
        if (data.not_found > 0) {
            addLogEntry(data.not_found + ' riders utan matchning i SCF', false);
        }

        // Refresh pending matches
        refreshPending();

        alert('Sokte ' + data.processed + ' cyklister. Hittade ' + data.found + ' matchningar, ' + data.not_found + ' utan matchning.');
    } catch (e) {
        alert('Fel: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="search"></i> Sok en batch';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

async function startAutoSearch() {
    setSearchUI(true);
    totalProcessed = 0;
    totalFound = 0;
    startTime = Date.now();
    initialRemaining = parseInt(document.getElementById('statRemaining').textContent.replace(/\D/g, '')) || 0;
    document.getElementById('searchLog').innerHTML = '';
    document.getElementById('searchLog').style.display = 'block';

    await runNextBatch();
}

function stopAutoSearch() {
    setSearchUI(false);
    document.getElementById('autoStatusText').textContent = 'Stoppad. ' + totalProcessed + ' sokta, ' + totalFound + ' hittade.';
    refreshPending();
}

async function runNextBatch() {
    if (!isRunning) return;

    const batchSize = document.getElementById('batchSize').value;
    document.getElementById('autoStatusText').textContent =
        'Soker... (' + totalProcessed + ' behandlade, ' + totalFound + ' hittade)';

    try {
        const data = await ajaxPost('search_batch', { batch_size: batchSize });

        if (data.error) {
            addLogEntry('FEL: ' + data.error, false);
            stopAutoSearch();
            return;
        }

        totalProcessed += data.processed;
        totalFound += data.found;

        updateStatsFromData(data);
        updateProgress(data.remaining);

        // Log results
        data.results.forEach(r => {
            addLogEntry('Hittade: ' + r.rider + ' -> ' + r.match + ' (' + r.score + '%)', true);
        });
        if (data.not_found > 0) {
            addLogEntry('Batch klar: ' + data.not_found + '/' + data.processed + ' utan matchning', false);
        }

        if (data.has_more && isRunning && data.processed > 0) {
            document.getElementById('autoStatusText').textContent =
                'Soker... (' + totalProcessed + ' behandlade, ' + totalFound + ' hittade)';
            // Small delay between batches to not overload
            setTimeout(runNextBatch, 500);
        } else {
            setSearchUI(false);
            document.getElementById('autoStatus').style.display = 'block';
            document.getElementById('autoStatusText').textContent =
                'Klart! ' + totalProcessed + ' cyklister sokta, ' + totalFound + ' matchningar hittade.';
            refreshPending();
            refreshStats();
        }
    } catch (e) {
        addLogEntry('FEL: ' + e.message, false);
        // Retry once after a short delay
        if (isRunning) {
            addLogEntry('Forsoker igen om 3 sek...', false);
            setTimeout(runNextBatch, 3000);
        }
    }
}

// ========================================
// Match management (AJAX, no reload)
// ========================================
async function confirmMatch(matchId, btn) {
    if (!confirm('Bekrafta matchning och uppdatera cyklisten med UCI ID?')) return;
    btn.disabled = true;
    btn.textContent = '...';

    try {
        const data = await ajaxPost('confirm_match', { match_id: matchId });
        if (data.error) {
            alert(data.error);
            btn.disabled = false;
            btn.textContent = 'Bekrafta';
            return;
        }

        // Remove card with animation
        const card = document.getElementById('match-' + matchId);
        if (card) {
            card.classList.add('removing');
            setTimeout(() => card.remove(), 300);
        }

        // Update counts
        const countEl = document.getElementById('pendingCount');
        countEl.textContent = Math.max(0, parseInt(countEl.textContent) - 1);
        const confirmedEl = document.getElementById('statConfirmed');
        confirmedEl.textContent = (parseInt(confirmedEl.textContent.replace(/\D/g, '')) + 1).toLocaleString();
        const pendingStatEl = document.getElementById('statPending');
        pendingStatEl.textContent = Math.max(0, parseInt(pendingStatEl.textContent.replace(/\D/g, '')) - 1).toLocaleString();
    } catch (e) {
        alert('Fel: ' + e.message);
        btn.disabled = false;
        btn.textContent = 'Bekrafta';
    }
}

async function rejectMatch(matchId, btn) {
    btn.disabled = true;
    btn.textContent = '...';

    try {
        const data = await ajaxPost('reject_match', { match_id: matchId });
        if (data.error) {
            alert(data.error);
            btn.disabled = false;
            btn.textContent = 'Avvisa';
            return;
        }

        const card = document.getElementById('match-' + matchId);
        if (card) {
            card.classList.add('removing');
            setTimeout(() => card.remove(), 300);
        }

        const countEl = document.getElementById('pendingCount');
        countEl.textContent = Math.max(0, parseInt(countEl.textContent) - 1);
        const pendingStatEl = document.getElementById('statPending');
        pendingStatEl.textContent = Math.max(0, parseInt(pendingStatEl.textContent.replace(/\D/g, '')) - 1).toLocaleString();
    } catch (e) {
        alert('Fel: ' + e.message);
        btn.disabled = false;
        btn.textContent = 'Avvisa';
    }
}

async function refreshPending() {
    try {
        const data = await ajaxPost('get_pending');
        if (data.html) {
            document.getElementById('pendingList').innerHTML = data.html;
            document.getElementById('pendingCount').textContent = data.count;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (e) {
        console.error('Failed to refresh pending:', e);
    }
}

async function refreshStats() {
    try {
        const data = await ajaxPost('get_stats');
        if (data.stats) {
            document.getElementById('statRemaining').textContent = data.stats.remaining.toLocaleString();
            document.getElementById('statWithoutUci').textContent = data.stats.without_uci.toLocaleString();
            document.getElementById('statPending').textContent = data.stats.pending_matches.toLocaleString();
            document.getElementById('statConfirmed').textContent = data.stats.confirmed_matches.toLocaleString();
            document.getElementById('statNotFound').textContent = data.stats.not_found.toLocaleString();
            document.getElementById('pendingCount').textContent = data.stats.pending_matches;
        }
    } catch (e) {
        console.error('Failed to refresh stats:', e);
    }
}

async function resetNotFound() {
    if (!confirm('Aterstall alla "ej hittade" riders sa de kan sokas igen?')) return;
    try {
        const data = await ajaxPost('reset_not_found');
        if (data.error) {
            alert(data.error);
            return;
        }
        alert(data.message);
        location.reload();
    } catch (e) {
        alert('Fel: ' + e.message);
    }
}

// Initialize icons
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
