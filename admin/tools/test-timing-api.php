<?php
/**
 * Test GravityTiming API
 * Verktyg för att testa API-endpoints direkt från admin
 */
require_once __DIR__ . '/../../config.php';
require_admin();

if (!hasRole('admin')) {
    header('Location: /admin/dashboard.php');
    exit;
}

$pdo = $GLOBALS['pdo'];

// Check if api_keys table exists
$apiKeysExist = false;
try {
    $pdo->query("SELECT 1 FROM api_keys LIMIT 1");
    $apiKeysExist = true;
} catch (Exception $e) {
    // Table doesn't exist
}

// Fetch available API keys
$keys = [];
if ($apiKeysExist) {
    $keys = $pdo->query("SELECT id, name, api_key, scope, active FROM api_keys WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Testa Timing API';
$breadcrumbs = [
    ['label' => 'System', 'url' => '/admin/tools.php'],
    ['label' => 'Testa Timing API']
];

include __DIR__ . '/../components/unified-layout.php';
?>

<?php if (!$apiKeysExist): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    Tabellen <code>api_keys</code> finns inte. <a href="/admin/migrations.php">Kör migration 053</a> först.
</div>
<?php elseif (empty($keys)): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    Inga aktiva API-nycklar. <a href="/admin/api-keys.php">Skapa en nyckel</a> först.
</div>
<?php else: ?>

<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-header">
        <h3>API-testverktyg</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md); margin-bottom: var(--space-lg);">
            <div class="form-group">
                <label class="form-label">API-nyckel</label>
                <select id="apiKeySelect" class="form-select">
                    <?php foreach ($keys as $k): ?>
                    <option value="<?= htmlspecialchars($k['api_key']) ?>"><?= htmlspecialchars($k['name']) ?> (<?= $k['scope'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">API Secret</label>
                <input type="text" id="apiSecret" class="form-input" placeholder="Klistra in hemligheten här">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: auto 1fr auto; gap: var(--space-md); margin-bottom: var(--space-md);">
            <div class="form-group">
                <label class="form-label">Metod</label>
                <select id="httpMethod" class="form-select">
                    <option>GET</option>
                    <option>POST</option>
                    <option>PATCH</option>
                    <option>DELETE</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Endpoint</label>
                <input type="text" id="endpoint" class="form-input" value="/api/v1/events" placeholder="/api/v1/events">
            </div>
            <div class="form-group" style="display: flex; align-items: flex-end;">
                <button class="btn btn-primary" onclick="sendRequest()">
                    <i data-lucide="send"></i> Skicka
                </button>
            </div>
        </div>

        <div class="form-group" id="bodyGroup" style="display: none;">
            <label class="form-label">Request Body (JSON)</label>
            <textarea id="requestBody" class="form-input" rows="6" style="font-family: monospace; font-size: 0.85rem;" placeholder='{"results": [...]}'></textarea>
        </div>

        <h4 style="margin-top: var(--space-lg); margin-bottom: var(--space-sm);">Snabblänkar</h4>
        <div style="display: flex; gap: var(--space-xs); flex-wrap: wrap;">
            <button class="btn btn-ghost btn-sm" onclick="setEndpoint('GET', '/api/v1/events')">Lista events</button>
            <button class="btn btn-ghost btn-sm" onclick="setEndpoint('GET', '/api/v1/events/1/startlist')">Startlista</button>
            <button class="btn btn-ghost btn-sm" onclick="setEndpoint('GET', '/api/v1/events/1/classes')">Klasser</button>
            <button class="btn btn-ghost btn-sm" onclick="setEndpoint('GET', '/api/v1/events/1/results/status')">Resultatstatus</button>
        </div>
    </div>
</div>

<!-- Response -->
<div class="card" id="responseCard" style="display: none;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3>Svar</h3>
        <span id="responseStatus" class="badge"></span>
    </div>
    <div class="card-body">
        <pre id="responseBody" style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-sm); overflow-x: auto; font-size: 0.85rem; max-height: 500px; overflow-y: auto;"></pre>
        <div style="margin-top: var(--space-sm); color: var(--color-text-muted); font-size: 0.8rem;">
            Svarstid: <span id="responseTime">-</span>ms
        </div>
    </div>
</div>

<script>
document.getElementById('httpMethod').addEventListener('change', function() {
    document.getElementById('bodyGroup').style.display =
        ['POST', 'PATCH'].includes(this.value) ? 'block' : 'none';
});

function setEndpoint(method, url) {
    document.getElementById('httpMethod').value = method;
    document.getElementById('endpoint').value = url;
    document.getElementById('bodyGroup').style.display =
        ['POST', 'PATCH'].includes(method) ? 'block' : 'none';
}

function sendRequest() {
    const apiKey = document.getElementById('apiKeySelect').value;
    const apiSecret = document.getElementById('apiSecret').value;
    const method = document.getElementById('httpMethod').value;
    const endpoint = document.getElementById('endpoint').value;
    const body = document.getElementById('requestBody').value;

    if (!apiSecret) {
        alert('Ange API Secret');
        return;
    }

    const responseCard = document.getElementById('responseCard');
    const statusBadge = document.getElementById('responseStatus');
    const responseBody = document.getElementById('responseBody');
    const responseTime = document.getElementById('responseTime');

    responseCard.style.display = 'block';
    statusBadge.textContent = 'Laddar...';
    statusBadge.className = 'badge badge-warning';
    responseBody.textContent = '';

    const startTime = performance.now();
    const opts = {
        method: method,
        headers: {
            'X-API-Key': apiKey,
            'X-API-Secret': apiSecret,
            'Content-Type': 'application/json'
        }
    };
    if (['POST', 'PATCH'].includes(method) && body) {
        opts.body = body;
    }

    fetch(endpoint, opts)
        .then(r => {
            const elapsed = Math.round(performance.now() - startTime);
            responseTime.textContent = elapsed;
            statusBadge.textContent = r.status + ' ' + r.statusText;
            statusBadge.className = 'badge ' + (r.ok ? 'badge-success' : 'badge-danger');
            return r.text();
        })
        .then(text => {
            try {
                const json = JSON.parse(text);
                responseBody.textContent = JSON.stringify(json, null, 2);
            } catch (e) {
                responseBody.textContent = text;
            }
        })
        .catch(err => {
            statusBadge.textContent = 'Fel';
            statusBadge.className = 'badge badge-danger';
            responseBody.textContent = err.message;
        });
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
