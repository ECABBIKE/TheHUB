<?php
/**
 * Page Performance Profiler
 * Profilerar alla publika sidor och visar exakt var tiden går.
 */
require_once __DIR__ . '/../../config.php';
require_admin();

$page_title = 'Sidprestanda-profiler';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Sidprestanda']
];
include __DIR__ . '/../components/unified-layout.php';

// Define pages to test
$testPages = [
    '/' => 'Startsida (welcome)',
    '/calendar' => 'Kalender',
    '/results' => 'Resultat',
    '/series' => 'Serier',
    '/ranking' => 'Ranking',
    '/database' => 'Databas',
    '/news' => 'Nyheter',
    '/gallery' => 'Galleri',
];

// If profiling a specific page via AJAX
if (isset($_GET['action']) && $_GET['action'] === 'profile' && isset($_GET['url'])) {
    header('Content-Type: application/json');

    $url = $_GET['url'];
    $baseUrl = env('SITE_URL', 'https://thehub.gravityseries.se');
    $fullUrl = rtrim($baseUrl, '/') . $url . '?perf=1';

    $result = ['url' => $url, 'error' => null, 'timings' => null, 'status' => null, 'size' => 0];

    // Use cURL to fetch the page
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
    ]);

    $startTime = microtime(true);
    $body = curl_exec($ch);
    $wallTime = round((microtime(true) - $startTime) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $connectTime = round(curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000);
    $dnsTime = round(curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME) * 1000);
    $tlsTime = round(curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) * 1000);
    $ttfb = round(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000);
    $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);

    if (curl_errno($ch)) {
        $result['error'] = curl_error($ch);
    }
    curl_close($ch);

    $result['status'] = $httpCode;
    $result['size'] = round($downloadSize / 1024, 1);
    $result['wall_time'] = $wallTime;

    // Parse PHP PERF comment from HTML
    if ($body && preg_match('/<!-- PERF: (.+?) -->/', $body, $m)) {
        $perfData = [];
        preg_match_all('/(\w+)=(\d+)ms/', $m[1], $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $perfData[$match[1]] = (int)$match[2];
        }
        $result['timings'] = $perfData;
    }

    // Network timings
    $result['network'] = [
        'dns' => $dnsTime,
        'connect' => $connectTime,
        'tls' => $tlsTime,
        'ttfb' => $ttfb,
        'total' => $totalTime,
    ];

    // Count external resources in HTML
    if ($body) {
        $cssCount = preg_match_all('/<link[^>]+stylesheet/', $body);
        $jsCount = preg_match_all('/<script[^>]+src=/', $body);
        $imgCount = preg_match_all('/<img[^>]+src=/', $body);
        $result['resources'] = [
            'css_files' => $cssCount,
            'js_files' => $jsCount,
            'images' => $imgCount,
        ];

        // Count inline styles
        $inlineStyleCount = preg_match_all('/<style/', $body);
        $result['resources']['inline_styles'] = $inlineStyleCount;

        // Count DB queries hint (from SQL comment if present)
        if (preg_match('/<!-- QUERIES: (\d+) -->/', $body, $qm)) {
            $result['queries'] = (int)$qm[1];
        }
    }

    echo json_encode($result);
    exit;
}
?>

<div class="admin-content">
    <div class="page-header">
        <h1><i data-lucide="gauge"></i> <?= $page_title ?></h1>
        <p style="color: var(--color-text-secondary); margin-top: var(--space-xs);">
            Profilerar alla publika sidor och visar exakt var tiden går.
        </p>
    </div>

    <div class="admin-card mb-lg">
        <div class="admin-card-header">
            <h3>Sidprofiler</h3>
            <button onclick="profileAllPages()" class="btn-admin btn-admin-primary" id="profileAllBtn">
                <i data-lucide="play"></i> Kör alla tester
            </button>
        </div>
        <div class="admin-card-body p-0">
            <div class="table-responsive">
                <table class="admin-table" id="resultsTable">
                    <thead>
                        <tr>
                            <th style="width: 180px;">Sida</th>
                            <th style="width: 80px;">Status</th>
                            <th style="width: 80px;">PHP total</th>
                            <th style="width: 80px;">Config</th>
                            <th style="width: 80px;">Head</th>
                            <th style="width: 80px;">Header</th>
                            <th style="width: 80px;">Sidebar</th>
                            <th style="width: 80px;">Page</th>
                            <th style="width: 80px;">TTFB</th>
                            <th style="width: 60px;">Storlek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($testPages as $url => $name): ?>
                        <tr id="row-<?= urlencode($url) ?>">
                            <td>
                                <strong><?= htmlspecialchars($name) ?></strong>
                                <br><small style="color: var(--color-text-muted);"><?= htmlspecialchars($url) ?></small>
                            </td>
                            <td class="status-cell">-</td>
                            <td class="total-cell">-</td>
                            <td class="config-cell">-</td>
                            <td class="head-cell">-</td>
                            <td class="header-cell">-</td>
                            <td class="sidebar-cell">-</td>
                            <td class="page-cell">-</td>
                            <td class="ttfb-cell">-</td>
                            <td class="size-cell">-</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Custom URL profiler -->
    <div class="admin-card mb-lg">
        <div class="admin-card-header">
            <h3>Testa specifik URL</h3>
        </div>
        <div class="admin-card-body">
            <div style="display: flex; gap: var(--space-sm); align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">URL (relativ, t.ex. /event/123)</label>
                    <input type="text" id="customUrl" class="form-input" placeholder="/event/123" value="">
                </div>
                <button onclick="profileCustomUrl()" class="btn-admin btn-admin-primary">
                    <i data-lucide="play"></i> Testa
                </button>
            </div>
            <div id="customResult" style="margin-top: var(--space-md); display: none;"></div>
        </div>
    </div>

    <!-- Timing Legend -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h3>Förklaring</h3>
        </div>
        <div class="admin-card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: var(--space-md);">
                <div>
                    <strong>Config</strong>
                    <p style="color: var(--color-text-secondary); font-size: 13px;">.env-parsning, DB-anslutning, session-start</p>
                </div>
                <div>
                    <strong>Head</strong>
                    <p style="color: var(--color-text-secondary); font-size: 13px;">head.php: CSS-bundle, meta-taggar, branding</p>
                </div>
                <div>
                    <strong>Header</strong>
                    <p style="color: var(--color-text-secondary); font-size: 13px;">header.php: Navigation, sponsorer, auth-check</p>
                </div>
                <div>
                    <strong>Sidebar</strong>
                    <p style="color: var(--color-text-secondary); font-size: 13px;">sidebar.php: Admin-meny, roller, claims-count</p>
                </div>
                <div>
                    <strong>Page</strong>
                    <p style="color: var(--color-text-secondary); font-size: 13px;">Sidans egen PHP-kod (queries, rendering)</p>
                </div>
                <div>
                    <strong>TTFB</strong>
                    <p style="color: var(--color-text-secondary); font-size: 13px;">Time To First Byte: DNS + TCP + TLS + PHP</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const pages = <?= json_encode(array_keys($testPages)) ?>;

function colorForMs(ms, thresholds) {
    if (ms === null || ms === undefined) return '';
    const [green, yellow] = thresholds || [100, 500];
    if (ms <= green) return 'color: var(--color-success)';
    if (ms <= yellow) return 'color: var(--color-warning)';
    return 'color: var(--color-error); font-weight: bold';
}

function formatMs(ms) {
    if (ms === null || ms === undefined) return '-';
    return ms + 'ms';
}

async function profilePage(url) {
    const rowId = 'row-' + encodeURIComponent(url);
    const row = document.getElementById(rowId);
    if (row) {
        row.querySelector('.status-cell').innerHTML = '<span style="color: var(--color-text-muted);">...</span>';
    }

    try {
        const res = await fetch('/admin/tools/page-profiler.php?action=profile&url=' + encodeURIComponent(url));
        const data = await res.json();
        updateRow(rowId, data);
        return data;
    } catch (e) {
        if (row) {
            row.querySelector('.status-cell').innerHTML = '<span style="color: var(--color-error);">Fel</span>';
        }
        return null;
    }
}

function updateRow(rowId, data) {
    const row = document.getElementById(rowId);
    if (!row) return;

    const t = data.timings || {};
    const n = data.network || {};

    row.querySelector('.status-cell').innerHTML = data.status === 200
        ? '<span style="color: var(--color-success);">200</span>'
        : '<span style="color: var(--color-error);">' + data.status + '</span>';

    row.querySelector('.total-cell').innerHTML = '<span style="' + colorForMs(t.total, [500, 1500]) + '">' + formatMs(t.total) + '</span>';
    row.querySelector('.config-cell').innerHTML = '<span style="' + colorForMs(t.config, [50, 200]) + '">' + formatMs(t.config) + '</span>';
    row.querySelector('.head-cell').innerHTML = '<span style="' + colorForMs(t.head, [50, 200]) + '">' + formatMs(t.head) + '</span>';
    row.querySelector('.header-cell').innerHTML = '<span style="' + colorForMs(t.header, [50, 200]) + '">' + formatMs(t.header) + '</span>';
    row.querySelector('.sidebar-cell').innerHTML = '<span style="' + colorForMs(t.sidebar, [50, 200]) + '">' + formatMs(t.sidebar) + '</span>';
    row.querySelector('.page-cell').innerHTML = '<span style="' + colorForMs(t.page, [200, 1000]) + '">' + formatMs(t.page) + '</span>';
    row.querySelector('.ttfb-cell').innerHTML = '<span style="' + colorForMs(n.ttfb, [500, 2000]) + '">' + formatMs(n.ttfb) + '</span>';
    row.querySelector('.size-cell').innerHTML = data.size + ' KB';
}

async function profileAllPages() {
    const btn = document.getElementById('profileAllBtn');
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="spin"></i> Testar...';

    for (const url of pages) {
        await profilePage(url);
    }

    btn.disabled = false;
    btn.innerHTML = '<i data-lucide="play"></i> Kör alla tester';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function profileCustomUrl() {
    const url = document.getElementById('customUrl').value.trim();
    if (!url || !url.startsWith('/')) {
        alert('Ange en relativ URL som börjar med /');
        return;
    }

    const resultDiv = document.getElementById('customResult');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<span style="color: var(--color-text-muted);">Testar ' + url + '...</span>';

    try {
        const res = await fetch('/admin/tools/page-profiler.php?action=profile&url=' + encodeURIComponent(url));
        const data = await res.json();
        const t = data.timings || {};
        const n = data.network || {};

        let html = '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: var(--space-sm);">';
        html += metricCard('PHP Total', t.total, [500, 1500]);
        html += metricCard('Config', t.config, [50, 200]);
        html += metricCard('Head', t.head, [50, 200]);
        html += metricCard('Header', t.header, [50, 200]);
        html += metricCard('Sidebar', t.sidebar, [50, 200]);
        html += metricCard('Page', t.page, [200, 1000]);
        html += metricCard('TTFB', n.ttfb, [500, 2000]);
        html += metricCard('Storlek', data.size + ' KB');
        html += '</div>';

        if (data.error) {
            html += '<div class="alert alert-danger" style="margin-top: var(--space-sm);">Fel: ' + data.error + '</div>';
        }

        resultDiv.innerHTML = html;
    } catch (e) {
        resultDiv.innerHTML = '<div class="alert alert-danger">Kunde inte testa: ' + e.message + '</div>';
    }
}

function metricCard(label, value, thresholds) {
    const isMs = typeof value === 'number';
    const display = isMs ? value + 'ms' : (value || '-');
    const style = isMs ? colorForMs(value, thresholds) : '';
    return '<div style="background: var(--color-bg-surface); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: var(--space-sm); text-align: center;">'
        + '<div style="font-size: 11px; color: var(--color-text-muted); text-transform: uppercase;">' + label + '</div>'
        + '<div style="font-size: 18px; font-weight: bold; ' + style + ';">' + display + '</div>'
        + '</div>';
}

</script>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
