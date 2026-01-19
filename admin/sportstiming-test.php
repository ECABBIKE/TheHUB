<?php
/**
 * Sportstiming API Test Tool
 *
 * Test fetching results from Sportstiming.se
 */
require_once __DIR__ . '/../config.php';
require_admin();

$page_title = 'Sportstiming Test';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Sportstiming Test']
];

$testResult = null;
$testError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        $testError = 'CSRF-validering misslyckades.';
    } else {
        $eventId = trim($_POST['event_id'] ?? '');

        if (empty($eventId) || !is_numeric($eventId)) {
            $testError = 'Ange ett giltigt event-ID (endast siffror).';
        } else {
            // Try different URL patterns
            $urls = [
                'results_page' => "https://www.sportstiming.se/event/{$eventId}/results",
                'api_results' => "https://www.sportstiming.se/api/event/{$eventId}/results",
                'api_v1' => "https://api.sportstiming.se/v1/event/{$eventId}",
                'json_results' => "https://www.sportstiming.se/event/{$eventId}/results.json",
            ];

            $results = [];

            foreach ($urls as $name => $url) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json, text/html, */*',
                        'Accept-Language: sv-SE,sv;q=0.9,en;q=0.8',
                    ],
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                curl_close($ch);

                $results[$name] = [
                    'url' => $url,
                    'effective_url' => $effectiveUrl,
                    'http_code' => $httpCode,
                    'error' => $curlError,
                    'content_type' => $contentType,
                    'response_size' => strlen($response),
                    'response_preview' => substr($response, 0, 1000),
                    'is_json' => json_decode($response) !== null,
                    'is_html' => strpos($response, '<html') !== false || strpos($response, '<!DOCTYPE') !== false,
                ];
            }

            $testResult = $results;
        }
    }
}

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.test-result {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}
.test-result.success { border-color: var(--color-success); }
.test-result.error { border-color: var(--color-error); }
.response-preview {
    background: var(--color-bg-hover);
    padding: var(--space-md);
    border-radius: var(--radius-sm);
    font-family: monospace;
    font-size: var(--text-sm);
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 300px;
    overflow-y: auto;
}
</style>

<div class="card">
    <div class="card-header">
        <h3>Testa Sportstiming Event</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label">Event ID</label>
                <input type="text" name="event_id" class="form-input" style="max-width: 200px;"
                       placeholder="t.ex. 14420" value="<?= htmlspecialchars($_POST['event_id'] ?? '14420') ?>">
                <small class="text-secondary">
                    Hitta event-ID i URL:en, t.ex. sportstiming.se/event/<strong>14420</strong>/results
                </small>
            </div>
            <button type="submit" class="btn btn-primary">Testa API</button>
        </form>
    </div>
</div>

<?php if ($testError): ?>
<div class="alert alert-danger"><?= htmlspecialchars($testError) ?></div>
<?php endif; ?>

<?php if ($testResult): ?>
<h3 style="margin-top: var(--space-xl);">Testresultat</h3>

<?php foreach ($testResult as $name => $result): ?>
<div class="test-result <?= $result['http_code'] == 200 ? 'success' : 'error' ?>">
    <h4><?= htmlspecialchars($name) ?></h4>
    <table class="table" style="margin-bottom: var(--space-md);">
        <tr><td style="width: 150px;"><strong>URL</strong></td><td><code style="word-break: break-all;"><?= htmlspecialchars($result['url']) ?></code></td></tr>
        <tr><td><strong>HTTP Status</strong></td><td>
            <span class="badge <?= $result['http_code'] == 200 ? 'badge-success' : 'badge-danger' ?>">
                <?= $result['http_code'] ?>
            </span>
        </td></tr>
        <tr><td><strong>Content-Type</strong></td><td><?= htmlspecialchars($result['content_type'] ?? 'N/A') ?></td></tr>
        <tr><td><strong>Storlek</strong></td><td><?= number_format($result['response_size']) ?> bytes</td></tr>
        <tr><td><strong>Är JSON?</strong></td><td><?= $result['is_json'] ? '<span class="badge badge-success">Ja</span>' : '<span class="badge badge-secondary">Nej</span>' ?></td></tr>
        <tr><td><strong>Är HTML?</strong></td><td><?= $result['is_html'] ? '<span class="badge badge-info">Ja</span>' : '<span class="badge badge-secondary">Nej</span>' ?></td></tr>
        <?php if ($result['error']): ?>
        <tr><td><strong>Fel</strong></td><td class="text-danger"><?= htmlspecialchars($result['error']) ?></td></tr>
        <?php endif; ?>
    </table>

    <h5>Response Preview</h5>
    <div class="response-preview"><?= htmlspecialchars($result['response_preview']) ?><?= $result['response_size'] > 1000 ? '...' : '' ?></div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<script>
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
