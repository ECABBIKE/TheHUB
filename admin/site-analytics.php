<?php
/**
 * Site Analytics Dashboard
 *
 * Displays Umami analytics data within TheHUB admin.
 * Supports two modes:
 * 1. API mode (UMAMI_API_TOKEN) - Fetches data via Umami API, custom dashboard
 * 2. Embed mode (UMAMI_SHARE_URL) - Embeds Umami share dashboard in iframe
 */
require_once __DIR__ . '/../config.php';
require_admin();

$shareUrl = env('UMAMI_SHARE_URL', '');
$apiToken = env('UMAMI_API_TOKEN', '');
$websiteId = env('UMAMI_WEBSITE_ID', 'd48052b4-61f9-4f41-ae2b-8215cdd3a82e');
$apiBase = 'https://api.umami.is';

// ========================================
// API Handler for AJAX requests
// ========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $apiToken) {
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();

    $period = $_GET['period'] ?? '24h';
    $now = time();

    // Calculate time range
    switch ($period) {
        case '24h':
            $startAt = ($now - 86400) * 1000;
            $unit = 'hour';
            break;
        case '7d':
            $startAt = ($now - 7 * 86400) * 1000;
            $unit = 'day';
            break;
        case '30d':
            $startAt = ($now - 30 * 86400) * 1000;
            $unit = 'day';
            break;
        case '90d':
            $startAt = ($now - 90 * 86400) * 1000;
            $unit = 'day';
            break;
        default:
            $startAt = ($now - 86400) * 1000;
            $unit = 'hour';
    }
    $endAt = $now * 1000;

    $type = $_GET['type'] ?? 'stats';

    switch ($type) {
        case 'stats':
            $data = umamiGet("/websites/{$websiteId}/stats", [
                'startAt' => $startAt,
                'endAt' => $endAt
            ], $apiToken, $apiBase);
            break;

        case 'pageviews':
            $data = umamiGet("/websites/{$websiteId}/pageviews", [
                'startAt' => $startAt,
                'endAt' => $endAt,
                'unit' => $unit
            ], $apiToken, $apiBase);
            break;

        case 'metrics':
            $metricType = $_GET['metric'] ?? 'url';
            $data = umamiGet("/websites/{$websiteId}/metrics", [
                'startAt' => $startAt,
                'endAt' => $endAt,
                'type' => $metricType,
                'limit' => 10
            ], $apiToken, $apiBase);
            break;

        case 'active':
            $data = umamiGet("/websites/{$websiteId}/active", [], $apiToken, $apiBase);
            break;

        default:
            $data = ['error' => 'Unknown type'];
    }

    echo json_encode($data);
    exit;
}

function umamiGet($endpoint, $params, $token, $base) {
    $url = $base . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'x-umami-api-key: ' . $token,
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'API error: HTTP ' . $httpCode, 'raw' => substr($response, 0, 200)];
    }

    return json_decode($response, true) ?? ['error' => 'Invalid JSON'];
}

// ========================================
// Page rendering
// ========================================
$mode = $apiToken ? 'api' : ($shareUrl ? 'embed' : 'setup');

$page_title = 'Besoksstatistik';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Besoksstatistik']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($mode === 'setup'): ?>
<!-- ========== SETUP INSTRUCTIONS ========== -->
<div class="card">
    <div class="card-header"><h3>Konfigurera Umami Analytics</h3></div>
    <div class="card-body">
        <p>Umami-tracking ar aktivt pa sajten. For att se statistik har behover du konfigurera en av foljande:</p>

        <div style="margin-top: var(--space-lg);">
            <h4 style="margin-bottom: var(--space-sm);">Alternativ 1: Share URL (enklast)</h4>
            <ol style="padding-left: var(--space-lg); color: var(--color-text-secondary);">
                <li>Logga in pa <a href="https://cloud.umami.is" target="_blank">cloud.umami.is</a></li>
                <li>Ga till Settings > Websites > din sajt > Share URL</li>
                <li>Aktivera "Enable share URL"</li>
                <li>Kopiera URL:en och lagg till i <code>.env</code>:</li>
            </ol>
            <pre style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-sm); margin-top: var(--space-sm); overflow-x: auto;"><code>UMAMI_SHARE_URL=https://cloud.umami.is/share/XXXXX/thehub</code></pre>
        </div>

        <div style="margin-top: var(--space-xl);">
            <h4 style="margin-bottom: var(--space-sm);">Alternativ 2: API Token (rikare data)</h4>
            <ol style="padding-left: var(--space-lg); color: var(--color-text-secondary);">
                <li>Logga in pa <a href="https://cloud.umami.is" target="_blank">cloud.umami.is</a></li>
                <li>Ga till Settings > API Keys</li>
                <li>Skapa en ny API-nyckel</li>
                <li>Lagg till i <code>.env</code>:</li>
            </ol>
            <pre style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-sm); margin-top: var(--space-sm); overflow-x: auto;"><code>UMAMI_API_TOKEN=din_api_nyckel_har</code></pre>
        </div>
    </div>
</div>

<?php elseif ($mode === 'embed'): ?>
<!-- ========== IFRAME EMBED ========== -->
<div class="card" style="padding: 0; overflow: hidden;">
    <iframe
        src="<?= htmlspecialchars($shareUrl) ?>"
        style="width: 100%; height: calc(100vh - 120px); border: none; display: block;"
        loading="lazy"
        title="Umami Analytics"
    ></iframe>
</div>

<?php else: ?>
<!-- ========== API DASHBOARD ========== -->

<style>
.analytics-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}

.analytics-stat {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    text-align: center;
}

.analytics-stat .value {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--color-accent);
}

.analytics-stat .value.live {
    color: var(--color-success);
}

.analytics-stat .label {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    margin-top: var(--space-2xs);
}

.period-selector {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
}

.period-btn {
    padding: var(--space-xs) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-full);
    background: var(--color-bg-card);
    color: var(--color-text-secondary);
    cursor: pointer;
    font-size: var(--text-sm);
    transition: all 0.2s;
}

.period-btn.active,
.period-btn:hover {
    background: var(--color-accent);
    color: white;
    border-color: var(--color-accent);
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--space-lg);
}

.metric-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.metric-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-sm) 0;
    border-bottom: 1px solid var(--color-border);
}

.metric-item:last-child {
    border-bottom: none;
}

.metric-name {
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    max-width: 70%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.metric-count {
    font-weight: 600;
    color: var(--color-accent);
    font-size: var(--text-sm);
}

.chart-bar-container {
    display: flex;
    align-items: end;
    gap: 2px;
    height: 120px;
    padding: var(--space-sm) 0;
}

.chart-bar {
    flex: 1;
    background: var(--color-accent);
    border-radius: 2px 2px 0 0;
    min-height: 2px;
    opacity: 0.8;
    transition: opacity 0.2s;
}

.chart-bar:hover {
    opacity: 1;
}

.loading-placeholder {
    color: var(--color-text-muted);
    text-align: center;
    padding: var(--space-lg);
}

@media (max-width: 767px) {
    .analytics-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .metrics-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Period selector -->
<div class="period-selector">
    <button class="period-btn active" data-period="24h" onclick="setPeriod('24h', this)">Senaste 24h</button>
    <button class="period-btn" data-period="7d" onclick="setPeriod('7d', this)">7 dagar</button>
    <button class="period-btn" data-period="30d" onclick="setPeriod('30d', this)">30 dagar</button>
    <button class="period-btn" data-period="90d" onclick="setPeriod('90d', this)">90 dagar</button>
</div>

<!-- Live + Stats -->
<div class="analytics-stats" id="statsGrid">
    <div class="analytics-stat">
        <div class="value live" id="statActive">-</div>
        <div class="label">Just nu</div>
    </div>
    <div class="analytics-stat">
        <div class="value" id="statPageviews">-</div>
        <div class="label">Sidvisningar</div>
    </div>
    <div class="analytics-stat">
        <div class="value" id="statVisitors">-</div>
        <div class="label">Besokare</div>
    </div>
    <div class="analytics-stat">
        <div class="value" id="statVisits">-</div>
        <div class="label">Besok</div>
    </div>
    <div class="analytics-stat">
        <div class="value" id="statBounce">-</div>
        <div class="label">Avvisningsfrekvens</div>
    </div>
    <div class="analytics-stat">
        <div class="value" id="statAvgTime">-</div>
        <div class="label">Snittid</div>
    </div>
</div>

<!-- Pageviews chart -->
<div class="card">
    <div class="card-header"><h3>Sidvisningar</h3></div>
    <div class="card-body">
        <div class="chart-bar-container" id="pageviewsChart">
            <div class="loading-placeholder">Laddar...</div>
        </div>
    </div>
</div>

<!-- Metrics -->
<div class="metrics-grid">
    <div class="card">
        <div class="card-header"><h3>Populara sidor</h3></div>
        <div class="card-body">
            <ul class="metric-list" id="metricPages"><li class="loading-placeholder">Laddar...</li></ul>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Referrers</h3></div>
        <div class="card-body">
            <ul class="metric-list" id="metricReferrers"><li class="loading-placeholder">Laddar...</li></ul>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Enheter</h3></div>
        <div class="card-body">
            <ul class="metric-list" id="metricDevices"><li class="loading-placeholder">Laddar...</li></ul>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Lander</h3></div>
        <div class="card-body">
            <ul class="metric-list" id="metricCountries"><li class="loading-placeholder">Laddar...</li></ul>
        </div>
    </div>
</div>

<script>
let currentPeriod = '24h';

function setPeriod(period, btn) {
    currentPeriod = period;
    document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    loadAll();
}

async function fetchApi(type, extra = '') {
    const url = window.location.pathname + '?ajax=1&period=' + currentPeriod + '&type=' + type + extra;
    const res = await fetch(url);
    return res.json();
}

function formatNum(n) {
    if (n === undefined || n === null) return '-';
    return Number(n).toLocaleString('sv-SE');
}

function formatTime(seconds) {
    if (!seconds) return '0s';
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    return m > 0 ? m + 'm ' + s + 's' : s + 's';
}

async function loadStats() {
    const data = await fetchApi('stats');
    if (data.error) return;

    document.getElementById('statPageviews').textContent = formatNum(data.pageviews?.value);
    document.getElementById('statVisitors').textContent = formatNum(data.visitors?.value);
    document.getElementById('statVisits').textContent = formatNum(data.visits?.value);

    const bounceRate = data.bounces?.value && data.visits?.value
        ? Math.round((data.bounces.value / data.visits.value) * 100) + '%'
        : '-';
    document.getElementById('statBounce').textContent = bounceRate;

    const avgTime = data.totaltime?.value && data.visits?.value
        ? formatTime(data.totaltime.value / data.visits.value)
        : '-';
    document.getElementById('statAvgTime').textContent = avgTime;
}

async function loadActive() {
    const data = await fetchApi('active');
    document.getElementById('statActive').textContent = formatNum(data?.[0]?.x ?? data?.x ?? 0);
}

async function loadPageviews() {
    const data = await fetchApi('pageviews');
    const container = document.getElementById('pageviewsChart');

    if (data.error || !data.pageviews) {
        container.innerHTML = '<div class="loading-placeholder">Kunde inte ladda data</div>';
        return;
    }

    const values = data.pageviews.map(d => d.y);
    const max = Math.max(...values, 1);

    container.innerHTML = values.map((v, i) => {
        const pct = Math.max(2, (v / max) * 100);
        const label = data.pageviews[i].x || '';
        return '<div class="chart-bar" style="height:' + pct + '%" title="' + label + ': ' + v + ' visningar"></div>';
    }).join('');
}

async function loadMetric(type, elementId, labelKey) {
    const data = await fetchApi('metrics', '&metric=' + type);
    const list = document.getElementById(elementId);

    if (data.error || !Array.isArray(data) || data.length === 0) {
        list.innerHTML = '<li class="loading-placeholder">Ingen data</li>';
        return;
    }

    list.innerHTML = data.slice(0, 10).map(item => {
        const name = item[labelKey || 'x'] || '(direkt)';
        return '<li class="metric-item"><span class="metric-name" title="' + name.replace(/"/g, '&quot;') + '">' +
            name + '</span><span class="metric-count">' + formatNum(item.y) + '</span></li>';
    }).join('');
}

async function loadAll() {
    loadActive();
    loadStats();
    loadPageviews();
    loadMetric('url', 'metricPages', 'x');
    loadMetric('referrer', 'metricReferrers', 'x');
    loadMetric('device', 'metricDevices', 'x');
    loadMetric('country', 'metricCountries', 'x');
}

// Initial load
loadAll();

// Refresh active visitors every 30 seconds
setInterval(loadActive, 30000);
</script>

<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
