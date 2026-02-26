<?php
/**
 * Cloudflare R2 - Konfiguration & Test
 * Testa anslutning, visa status och hantera bildlagring
 */
require_once __DIR__ . '/../../config.php';
require_admin();

require_once __DIR__ . '/../../includes/r2-storage.php';

$pageTitle = 'Cloudflare R2 - Bildlagring';
$currentPage = 'tools';

$r2Configured = R2Storage::isConfigured();
$testResult = null;
$listResult = null;
$uploadResult = null;

// Handle test connection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'test_connection' && $r2Configured) {
        $r2 = R2Storage::getInstance();
        if ($r2) {
            $testResult = $r2->testConnection();
        }
    }

    if ($postAction === 'list_objects' && $r2Configured) {
        $r2 = R2Storage::getInstance();
        $prefix = trim($_POST['prefix'] ?? '');
        if ($r2) {
            $listResult = $r2->listObjects($prefix, 50);
        }
    }

    if ($postAction === 'test_upload' && $r2Configured) {
        $r2 = R2Storage::getInstance();
        if ($r2) {
            // Skapa en liten testbild (1x1 pixel PNG)
            $testKey = 'test/connection-test-' . date('Ymd-His') . '.txt';
            $uploadResult = $r2->putObject($testKey, 'TheHUB R2 connection test - ' . date('Y-m-d H:i:s'), 'text/plain');

            if ($uploadResult['success']) {
                // Rensa testfilen direkt
                $r2->deleteObject($testKey);
                $uploadResult['message'] = 'Testfil uppladdad och raderad. R2 fungerar korrekt!';
            }
        }
    }
}

// Get storage stats
$storageStats = null;
if ($r2Configured) {
    try {
        global $pdo;
        // Räkna bilder i R2 (via event_photos med external_url som matchar R2)
        $r2Url = env('R2_PUBLIC_URL', '');
        if ($r2Url) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as r2_count FROM event_photos WHERE external_url LIKE ?");
            $stmt->execute([$r2Url . '%']);
            $r2Count = (int)$stmt->fetchColumn();
        } else {
            $r2Count = 0;
        }

        $totalPhotos = (int)$pdo->query("SELECT COUNT(*) FROM event_photos")->fetchColumn();
        $totalAlbums = (int)$pdo->query("SELECT COUNT(*) FROM event_albums")->fetchColumn();

        $storageStats = [
            'total_photos' => $totalPhotos,
            'r2_photos' => $r2Count,
            'total_albums' => $totalAlbums,
        ];
    } catch (PDOException $e) {
        $storageStats = null;
    }
}

include __DIR__ . '/../components/unified-layout.php';
?>

<div class="page-header" style="margin-bottom: var(--space-lg);">
    <a href="/admin/tools.php" style="font-size: 0.8rem; color: var(--color-text-secondary); text-decoration: none;">
        <i data-lucide="arrow-left" class="icon-sm"></i> Tillbaka till Verktyg
    </a>
    <h1 style="margin: var(--space-xs) 0 0;"><?= $pageTitle ?></h1>
</div>

<!-- Status -->
<div class="admin-card" style="margin-bottom: var(--space-md);">
    <div class="admin-card-header">
        <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-sm);">
            <i data-lucide="cloud" class="icon-sm"></i> Status
        </h3>
    </div>
    <div class="admin-card-body">
        <?php if ($r2Configured): ?>
        <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
            <span class="badge badge-success">Konfigurerat</span>
            <span style="font-size: 0.85rem; color: var(--color-text-secondary);">
                Bucket: <code><?= htmlspecialchars(env('R2_BUCKET', '')) ?></code>
                <?php if (env('R2_PUBLIC_URL', '')): ?>
                &bull; URL: <code><?= htmlspecialchars(env('R2_PUBLIC_URL', '')) ?></code>
                <?php endif; ?>
            </span>
        </div>

        <?php if ($storageStats): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-md);">
            <div style="text-align: center; padding: var(--space-md); background: var(--color-bg-hover); border-radius: var(--radius-sm);">
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-text-primary);"><?= $storageStats['total_albums'] ?></div>
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">Album</div>
            </div>
            <div style="text-align: center; padding: var(--space-md); background: var(--color-bg-hover); border-radius: var(--radius-sm);">
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-text-primary);"><?= $storageStats['total_photos'] ?></div>
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">Bilder totalt</div>
            </div>
            <div style="text-align: center; padding: var(--space-md); background: var(--color-bg-hover); border-radius: var(--radius-sm);">
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent);"><?= $storageStats['r2_photos'] ?></div>
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">I R2</div>
            </div>
        </div>
        <?php endif; ?>

        <div style="display: flex; gap: var(--space-sm); flex-wrap: wrap;">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="test_connection">
                <button type="submit" class="btn btn-secondary">
                    <i data-lucide="wifi" class="icon-sm"></i> Testa anslutning
                </button>
            </form>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="test_upload">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="upload" class="icon-sm"></i> Testa uppladdning
                </button>
            </form>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="list_objects">
                <input type="hidden" name="prefix" value="events/">
                <button type="submit" class="btn btn-secondary">
                    <i data-lucide="folder-open" class="icon-sm"></i> Lista filer
                </button>
            </form>
        </div>

        <?php else: ?>
        <div class="alert alert-warning" style="margin: 0;">
            <i data-lucide="alert-triangle" class="icon-sm"></i>
            <div>
                <strong>Ej konfigurerat</strong> - Lägg till R2-inställningar i <code>.env</code>-filen.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Test Results -->
<?php if ($testResult): ?>
<div class="admin-card" style="margin-bottom: var(--space-md);">
    <div class="admin-card-header">
        <h3 style="margin: 0;">Anslutningstest</h3>
    </div>
    <div class="admin-card-body">
        <?php if ($testResult['success']): ?>
        <div class="alert alert-success" style="margin: 0;">
            <i data-lucide="check-circle" class="icon-sm"></i>
            <?= htmlspecialchars($testResult['message']) ?>
        </div>
        <?php else: ?>
        <div class="alert alert-danger" style="margin: 0;">
            <i data-lucide="x-circle" class="icon-sm"></i>
            <?= htmlspecialchars($testResult['message']) ?>
        </div>
        <?php endif; ?>
        <div style="margin-top: var(--space-sm); font-size: 0.8rem; color: var(--color-text-muted);">
            Endpoint: <?= htmlspecialchars($testResult['endpoint'] ?? '') ?>
            &bull; Bucket: <?= htmlspecialchars($testResult['bucket'] ?? '') ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($uploadResult): ?>
<div class="admin-card" style="margin-bottom: var(--space-md);">
    <div class="admin-card-header">
        <h3 style="margin: 0;">Uppladdningstest</h3>
    </div>
    <div class="admin-card-body">
        <?php if ($uploadResult['success']): ?>
        <div class="alert alert-success" style="margin: 0;">
            <i data-lucide="check-circle" class="icon-sm"></i>
            <?= htmlspecialchars($uploadResult['message'] ?? 'Uppladdning lyckades!') ?>
        </div>
        <?php else: ?>
        <div class="alert alert-danger" style="margin: 0;">
            <i data-lucide="x-circle" class="icon-sm"></i>
            <?= htmlspecialchars($uploadResult['error'] ?? 'Okänt fel') ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($listResult): ?>
<div class="admin-card" style="margin-bottom: var(--space-md);">
    <div class="admin-card-header">
        <h3 style="margin: 0;">Filer i R2 (events/)</h3>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <?php if ($listResult['success'] && !empty($listResult['objects'])): ?>
        <div class="table-responsive">
            <table class="table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Sökväg</th>
                        <th>Storlek</th>
                        <th>Senast ändrad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listResult['objects'] as $obj): ?>
                    <tr>
                        <td style="font-family: monospace; font-size: 0.8rem;"><?= htmlspecialchars($obj['key']) ?></td>
                        <td><?= number_format($obj['size'] / 1024, 1) ?> KB</td>
                        <td style="font-size: 0.8rem;"><?= htmlspecialchars($obj['last_modified']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php elseif ($listResult['success']): ?>
        <div style="text-align: center; padding: var(--space-lg); color: var(--color-text-muted);">
            Inga filer hittades
        </div>
        <?php else: ?>
        <div class="alert alert-danger" style="margin: var(--space-md);">
            <?= htmlspecialchars($listResult['error'] ?? 'Kunde inte lista filer') ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Setup Guide -->
<details class="admin-card">
    <summary class="admin-card-header" style="cursor: pointer;">
        <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-sm);">
            <i data-lucide="book-open" class="icon-sm"></i> Installationsguide
        </h3>
    </summary>
    <div class="admin-card-body">
        <div style="font-size: 0.9rem; line-height: 1.6; color: var(--color-text-secondary);">
            <h4 style="color: var(--color-text-primary); margin: 0 0 var(--space-sm);">1. Skapa Cloudflare-konto</h4>
            <p>Registrera dig gratis på <a href="https://dash.cloudflare.com" target="_blank" style="color: var(--color-accent-text);">dash.cloudflare.com</a>.</p>

            <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-sm);">2. Skapa R2-bucket</h4>
            <p>Gå till R2 Object Storage → Create Bucket. Namnge den <code>thehub-photos</code>.</p>

            <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-sm);">3. Aktivera publik åtkomst</h4>
            <p>I bucket-inställningarna, aktivera publik åtkomst via:</p>
            <ul style="margin: var(--space-xs) 0;">
                <li><strong>Custom domain</strong> (rekommenderat): Lägg till t.ex. <code>photos.gravityseries.se</code></li>
                <li><strong>R2.dev subdomain</strong> (enklare): Aktivera r2.dev-åtkomst för test</li>
            </ul>

            <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-sm);">4. Skapa API-token</h4>
            <p>Gå till R2 → Manage R2 API Tokens → Create API Token.</p>
            <ul style="margin: var(--space-xs) 0;">
                <li>Behörigheter: <strong>Object Read & Write</strong></li>
                <li>Bucket-begränsning: <strong>thehub-photos</strong></li>
                <li>Notera <strong>Access Key ID</strong> och <strong>Secret Access Key</strong></li>
            </ul>

            <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-sm);">5. Uppdatera .env</h4>
            <pre style="background: var(--color-bg-hover); padding: var(--space-md); border-radius: var(--radius-sm); font-size: 0.8rem; overflow-x: auto;">R2_ACCOUNT_ID=ditt_cloudflare_account_id
R2_ACCESS_KEY_ID=din_access_key
R2_SECRET_ACCESS_KEY=din_secret_key
R2_BUCKET=thehub-photos
R2_PUBLIC_URL=https://photos.gravityseries.se</pre>

            <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-sm);">6. Testa</h4>
            <p>Klicka "Testa anslutning" ovan för att verifiera att allt fungerar.</p>

            <div style="margin-top: var(--space-lg); padding: var(--space-md); background: var(--color-accent-light); border-radius: var(--radius-sm);">
                <strong>Kostnad:</strong> Cloudflare R2 har 10 GB gratis lagring och $0 utgående bandbredd.
                Med ~500 KB per optimerad bild räcker gratisplanen till ~20 000 bilder.
            </div>
        </div>
    </div>
</details>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
