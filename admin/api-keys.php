<?php
/**
 * Admin - API-nyckelhantering
 * Skapa, hantera och radera API-nycklar för GravityTiming och andra integrationer
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$pdo = $GLOBALS['pdo'];

// Only full admins can manage API keys
if (!hasRole('admin')) {
    header('Location: /admin/dashboard.php');
    exit;
}

$message = '';
$error = '';
$newKeyInfo = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $scope = $_POST['scope'] ?? 'timing';
        $eventIds = !empty($_POST['event_ids']) ? trim($_POST['event_ids']) : null;
        $seriesIds = !empty($_POST['series_ids']) ? trim($_POST['series_ids']) : null;
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

        if (empty($name)) {
            $error = 'Namn krävs';
        } elseif (!in_array($scope, ['timing', 'readonly', 'admin'])) {
            $error = 'Ogiltigt scope';
        } else {
            // Generate key pair
            $apiKey = 'gt_' . bin2hex(random_bytes(24)); // gt_ + 48 hex chars
            $apiSecret = bin2hex(random_bytes(32)); // 64 hex chars
            $apiSecretHash = password_hash($apiSecret, PASSWORD_BCRYPT);

            // Parse event/series IDs
            $eventIdsJson = null;
            if ($eventIds) {
                $ids = array_map('intval', array_filter(explode(',', $eventIds)));
                if (!empty($ids)) $eventIdsJson = json_encode($ids);
            }
            $seriesIdsJson = null;
            if ($seriesIds) {
                $ids = array_map('intval', array_filter(explode(',', $seriesIds)));
                if (!empty($ids)) $seriesIdsJson = json_encode($ids);
            }

            $stmt = $pdo->prepare("
                INSERT INTO api_keys (name, api_key, api_secret_hash, scope, event_ids, series_ids, created_by, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name,
                $apiKey,
                $apiSecretHash,
                $scope,
                $eventIdsJson,
                $seriesIdsJson,
                $_SESSION['admin_id'] ?? null,
                $expiresAt
            ]);

            $message = 'API-nyckel skapad! Kopiera hemligheten nedan - den visas bara en gång.';
            $newKeyInfo = [
                'api_key' => $apiKey,
                'api_secret' => $apiSecret
            ];
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['active'] ?? 0);
        $pdo->prepare("UPDATE api_keys SET active = ? WHERE id = ?")->execute([$active, $id]);
        $message = $active ? 'API-nyckel aktiverad' : 'API-nyckel inaktiverad';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$id]);
        $message = 'API-nyckel raderad';
    }
}

// Fetch all keys
$keys = [];
try {
    $stmt = $pdo->query("
        SELECT ak.*,
            (SELECT COUNT(*) FROM api_request_log arl WHERE arl.api_key_id = ak.id) AS request_count,
            (SELECT COUNT(*) FROM api_request_log arl WHERE arl.api_key_id = ak.id AND arl.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS requests_24h
        FROM api_keys ak
        ORDER BY ak.created_at DESC
    ");
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Tabellen api_keys finns inte ännu. Kör migration 053 först.';
}

// Fetch events and series for the dropdowns
$events = $pdo->query("SELECT id, name, date FROM events WHERE active = 1 ORDER BY date DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$series = $pdo->query("SELECT id, name, year FROM series ORDER BY year DESC, name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'API-nycklar';
$breadcrumbs = [
    ['label' => 'System', 'url' => '/admin/settings'],
    ['label' => 'API-nycklar']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($newKeyInfo): ?>
<div class="card" style="border: 2px solid var(--color-success); margin-bottom: var(--space-lg);">
    <div class="card-header">
        <h3>Ny API-nyckel skapad</h3>
    </div>
    <div class="card-body">
        <p style="color: var(--color-error); font-weight: 700; margin-bottom: var(--space-md);">
            <i data-lucide="alert-triangle"></i>
            Kopiera hemligheten nu! Den visas bara en gång.
        </p>
        <div class="form-group">
            <label class="form-label">API Key (X-API-Key)</label>
            <input type="text" class="form-input" value="<?= htmlspecialchars($newKeyInfo['api_key']) ?>" readonly onclick="this.select()">
        </div>
        <div class="form-group">
            <label class="form-label">API Secret (X-API-Secret)</label>
            <input type="text" class="form-input" value="<?= htmlspecialchars($newKeyInfo['api_secret']) ?>" readonly onclick="this.select()" style="font-family: monospace; font-size: 0.85rem;">
        </div>
        <p style="color: var(--color-text-muted); font-size: 0.85rem; margin-top: var(--space-sm);">
            Konfigurera GravityTiming med dessa värden under Inställningar > API.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Create new key -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-header">
        <h3>Skapa ny API-nyckel</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="form-group">
                    <label class="form-label">Namn *</label>
                    <input type="text" name="name" class="form-input" placeholder="T.ex. GravityTiming Kungsbacka" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Scope</label>
                    <select name="scope" class="form-select">
                        <option value="timing">Timing (startlistor + resultat)</option>
                        <option value="readonly">Readonly (bara läsa)</option>
                        <option value="admin">Admin (full åtkomst)</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="form-group">
                    <label class="form-label">Begränsa till event-ID (valfritt)</label>
                    <input type="text" name="event_ids" class="form-input" placeholder="T.ex. 42,43,44">
                    <small style="color: var(--color-text-muted);">Kommaseparerade ID. Tomt = alla event.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Utgår (valfritt)</label>
                    <input type="datetime-local" name="expires_at" class="form-input">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i data-lucide="key"></i> Skapa API-nyckel
            </button>
        </form>
    </div>
</div>

<!-- Existing keys -->
<div class="card">
    <div class="card-header">
        <h3>Befintliga API-nycklar (<?= count($keys) ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($keys)): ?>
        <div class="empty-state">
            <i data-lucide="key" class="empty-state-icon"></i>
            <h3>Inga API-nycklar</h3>
            <p>Skapa en ny nyckel ovan för att komma igång.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Namn</th>
                        <th>API Key</th>
                        <th>Scope</th>
                        <th>Anrop (24h / totalt)</th>
                        <th>Senast använd</th>
                        <th>Status</th>
                        <th>Åtgärder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $key): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($key['name']) ?></strong></td>
                        <td><code style="font-size: 0.8rem;"><?= htmlspecialchars(substr($key['api_key'], 0, 15)) ?>...</code></td>
                        <td>
                            <span class="badge <?= $key['scope'] === 'admin' ? 'badge-danger' : ($key['scope'] === 'timing' ? 'badge-success' : 'badge-warning') ?>">
                                <?= htmlspecialchars($key['scope']) ?>
                            </span>
                        </td>
                        <td><?= (int)$key['requests_24h'] ?> / <?= (int)$key['request_count'] ?></td>
                        <td><?= $key['last_used_at'] ? date('Y-m-d H:i', strtotime($key['last_used_at'])) : 'Aldrig' ?></td>
                        <td>
                            <?php if ($key['expires_at'] && strtotime($key['expires_at']) < time()): ?>
                                <span class="badge badge-danger">Utgången</span>
                            <?php elseif ($key['active']): ?>
                                <span class="badge badge-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $key['id'] ?>">
                                <input type="hidden" name="active" value="<?= $key['active'] ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-ghost btn-sm">
                                    <?= $key['active'] ? 'Inaktivera' : 'Aktivera' ?>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Radera denna API-nyckel?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $key['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color: var(--color-error);">
                                    <i data-lucide="trash-2"></i>
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

<!-- API Documentation -->
<div class="card" style="margin-top: var(--space-lg);">
    <div class="card-header">
        <h3>API-dokumentation</h3>
    </div>
    <div class="card-body">
        <h4 style="margin-bottom: var(--space-sm);">Autentisering</h4>
        <p style="margin-bottom: var(--space-md);">Skicka API-nyckel och hemlighet som HTTP-headers:</p>
        <pre style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-sm); overflow-x: auto; font-size: 0.85rem; margin-bottom: var(--space-lg);">X-API-Key: gt_xxxxxxxxxxxx
X-API-Secret: din_hemlighet_här</pre>

        <h4 style="margin-bottom: var(--space-sm);">Endpoints</h4>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Metod</th><th>URL</th><th>Beskrivning</th><th>Scope</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>GET</code></td><td><code>/api/v1/events</code></td><td>Lista events</td><td>readonly</td></tr>
                    <tr><td><code>GET</code></td><td><code>/api/v1/events/{id}/startlist</code></td><td>Hämta startlista</td><td>readonly</td></tr>
                    <tr><td><code>GET</code></td><td><code>/api/v1/events/{id}/classes</code></td><td>Hämta klasser</td><td>readonly</td></tr>
                    <tr><td><code>POST</code></td><td><code>/api/v1/events/{id}/results</code></td><td>Ladda upp resultat (batch)</td><td>timing</td></tr>
                    <tr><td><code>POST</code></td><td><code>/api/v1/events/{id}/results/live</code></td><td>Skicka live split time</td><td>timing</td></tr>
                    <tr><td><code>GET</code></td><td><code>/api/v1/events/{id}/results/status</code></td><td>Status/polling</td><td>readonly</td></tr>
                    <tr><td><code>PATCH</code></td><td><code>/api/v1/events/{id}/results?result_id=X</code></td><td>Uppdatera enstaka resultat</td><td>timing</td></tr>
                    <tr><td><code>DELETE</code></td><td><code>/api/v1/events/{id}/results?mode=all</code></td><td>Rensa alla resultat</td><td>timing</td></tr>
                </tbody>
            </table>
        </div>

        <h4 style="margin-top: var(--space-lg); margin-bottom: var(--space-sm);">Rate limit</h4>
        <p>60 anrop per minut per API-nyckel.</p>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
