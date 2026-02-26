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
<?php $guideOpen = !$r2Configured ? 'open' : ''; ?>
<details class="admin-card" <?= $guideOpen ?>>
    <summary class="admin-card-header" style="cursor: pointer;">
        <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-sm);">
            <i data-lucide="book-open" class="icon-sm"></i> Installationsguide - Cloudflare R2
        </h3>
    </summary>
    <div class="admin-card-body" style="font-size: 0.9rem; line-height: 1.7; color: var(--color-text-secondary);">

        <!-- Vad är R2? -->
        <div style="padding: var(--space-md); background: var(--color-accent-light); border-radius: var(--radius-sm); margin-bottom: var(--space-lg);">
            <strong style="color: var(--color-text-primary);">Vad är Cloudflare R2?</strong><br>
            R2 är Cloudflares objektlagring (som AWS S3) men med <strong>$0 utgående bandbredd</strong>.
            Det betyder att oavsett hur många besökare som tittar på bilderna kostar det inget extra.
            Gratis: 10 GB lagring (~20 000 optimerade bilder). TheHUB optimerar automatiskt alla uppladdade bilder.
        </div>

        <!-- STEG 1 -->
        <div class="setup-step" style="margin-bottom: var(--space-xl); padding-bottom: var(--space-lg); border-bottom: 1px solid var(--color-border);">
            <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--radius-full); background: var(--color-accent); color: var(--color-bg-page); font-weight: 700; font-size: 0.9rem; flex-shrink: 0;">1</span>
                <h4 style="color: var(--color-text-primary); margin: 0;">Skapa Cloudflare-konto</h4>
            </div>
            <ol style="margin: 0; padding-left: var(--space-xl);">
                <li>Gå till <a href="https://dash.cloudflare.com/sign-up" target="_blank" rel="noopener" style="color: var(--color-accent-text);">dash.cloudflare.com/sign-up</a></li>
                <li>Registrera med e-post och lösenord</li>
                <li>Verifiera din e-post</li>
                <li>Du behöver <strong>inte</strong> lägga till en domän eller betala något - gratiskontot räcker</li>
            </ol>
            <div style="margin-top: var(--space-sm); padding: var(--space-sm) var(--space-md); background: var(--color-bg-hover); border-radius: var(--radius-sm); font-size: 0.8rem;">
                <strong>Account ID:</strong> När du är inloggad, klicka på ditt konto. Account ID visas i höger sidebar (eller i URL:en).
                Ser ut som: <code>a1b2c3d4e5f6...</code> (32 tecken). Notera denna - du behöver den i steg 5.
            </div>
        </div>

        <!-- STEG 2 -->
        <div class="setup-step" style="margin-bottom: var(--space-xl); padding-bottom: var(--space-lg); border-bottom: 1px solid var(--color-border);">
            <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--radius-full); background: var(--color-accent); color: var(--color-bg-page); font-weight: 700; font-size: 0.9rem; flex-shrink: 0;">2</span>
                <h4 style="color: var(--color-text-primary); margin: 0;">Skapa R2-bucket</h4>
            </div>
            <ol style="margin: 0; padding-left: var(--space-xl);">
                <li>I vänstermenyn i Cloudflare Dashboard, klicka <strong>R2 Object Storage</strong></li>
                <li>Första gången: Cloudflare ber dig bekräfta R2-villkoren och kan fråga efter betaluppgifter (inget dras förrän du överstiger gratiskvoten)</li>
                <li>Klicka <strong>"Create bucket"</strong></li>
                <li>Bucket name: <code>thehub-photos</code></li>
                <li>Location: <strong>Automatic</strong> (Cloudflare väljer närmaste region automatiskt)</li>
                <li>Klicka <strong>"Create bucket"</strong></li>
            </ol>
            <div style="margin-top: var(--space-sm); padding: var(--space-sm) var(--space-md); background: var(--color-bg-hover); border-radius: var(--radius-sm); font-size: 0.8rem;">
                <strong>Bucket-namn måste vara globalt unikt.</strong> Om <code>thehub-photos</code> redan är taget, prova <code>thehub-photos-gs</code> eller liknande.
                Notera exakt vad du döpte bucketen till - du behöver det i steg 5.
            </div>
        </div>

        <!-- STEG 3 -->
        <div class="setup-step" style="margin-bottom: var(--space-xl); padding-bottom: var(--space-lg); border-bottom: 1px solid var(--color-border);">
            <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--radius-full); background: var(--color-accent); color: var(--color-bg-page); font-weight: 700; font-size: 0.9rem; flex-shrink: 0;">3</span>
                <h4 style="color: var(--color-text-primary); margin: 0;">Aktivera publik åtkomst (så besökare kan se bilderna)</h4>
            </div>
            <p>Bucketen är privat som standard. Bilder måste vara publikt åtkomliga för att visas på sajten.</p>

            <!-- R2.dev - primärt alternativ -->
            <div style="padding: var(--space-md); border: 2px solid var(--color-accent); border-radius: var(--radius-sm); background: var(--color-bg-card); margin: var(--space-md) 0;">
                <div style="font-weight: 600; color: var(--color-accent-text); margin-bottom: var(--space-sm); font-size: 0.9rem;">
                    <i data-lucide="globe" class="icon-sm" style="vertical-align: text-bottom;"></i> Aktivera Public Development URL (r2.dev)
                </div>
                <p style="font-size: 0.85rem; margin: 0 0 var(--space-sm);">
                    Detta ger dig en publik URL direkt från Cloudflare. Perfekt när din webbdomän ligger på en annan server (t.ex. Hostinger).
                </p>
                <ol style="margin: 0; padding-left: var(--space-lg); font-size: 0.85rem;">
                    <li>Öppna din bucket <strong>thehub-photos</strong> i Cloudflare Dashboard</li>
                    <li>Klicka fliken <strong>"Settings"</strong></li>
                    <li>Scrolla ner till sektionen <strong>"Public Development URL"</strong></li>
                    <li>Den visar texten <em>"The public development URL is disabled for this bucket."</em></li>
                    <li>Klicka <strong>"Allow Access"</strong> (eller "Enable" om det står så)</li>
                    <li>Bekräfta i dialogen som dyker upp</li>
                    <li>Nu visas en URL i formatet: <code style="background: var(--color-bg-hover); padding: 2px 6px; border-radius: 3px;">https://pub-XXXXXXXXXXXXXXXX.r2.dev</code></li>
                    <li><strong>Kopiera hela denna URL</strong> - du behöver den i steg 5</li>
                </ol>
                <div style="margin-top: var(--space-md); padding: var(--space-sm) var(--space-md); background: var(--color-accent-light); border-radius: var(--radius-sm); font-size: 0.8rem;">
                    <strong>Testa direkt:</strong> Öppna URL:en i webbläsaren. Du bör se ett XML-svar med <code>&lt;ListBucketResult&gt;</code> eller liknande.
                    Det betyder att publik åtkomst fungerar. (Bucketen är tom så inga bilder visas än.)
                </div>
            </div>

            <!-- CORS Policy -->
            <div style="padding: var(--space-md); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-card); margin: var(--space-md) 0;">
                <div style="font-weight: 600; color: var(--color-text-primary); margin-bottom: var(--space-sm); font-size: 0.85rem;">
                    <i data-lucide="shield" class="icon-sm" style="vertical-align: text-bottom;"></i> Konfigurera CORS (valfritt men rekommenderat)
                </div>
                <p style="font-size: 0.85rem; margin: 0 0 var(--space-sm);">
                    CORS tillåter att bilder från r2.dev laddas på din sajt utan att webbläsaren blockerar dem.
                    Utan CORS kan lightbox och bildladdning ibland strula.
                </p>
                <ol style="margin: 0; padding-left: var(--space-lg); font-size: 0.85rem;">
                    <li>I bucket Settings, scrolla till <strong>"CORS Policy"</strong></li>
                    <li>Klicka <strong>"Add CORS policy"</strong> (eller "Edit")</li>
                    <li>Klistra in följande JSON-policy:</li>
                </ol>
                <pre style="background: var(--color-bg-hover); padding: var(--space-sm) var(--space-md); border-radius: var(--radius-sm); font-size: 0.75rem; margin: var(--space-sm) 0; overflow-x: auto;">[
  {
    "AllowedOrigins": ["https://thehub.gravityseries.se"],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "MaxAgeSeconds": 86400
  }
]</pre>
                <ol start="4" style="margin: 0; padding-left: var(--space-lg); font-size: 0.85rem;">
                    <li>Klicka <strong>"Save"</strong></li>
                </ol>
            </div>

            <!-- Custom domain - alternativ -->
            <details style="margin-top: var(--space-md);">
                <summary style="font-size: 0.85rem; cursor: pointer; color: var(--color-text-muted);">
                    <i data-lucide="globe-2" class="icon-xs" style="vertical-align: text-bottom;"></i>
                    Alternativ: Custom domain (kräver att domänen ligger på Cloudflare)
                </summary>
                <div style="margin-top: var(--space-sm); padding: var(--space-md); border: 1px solid var(--color-border); border-radius: var(--radius-sm); font-size: 0.85rem;">
                    <p style="margin: 0 0 var(--space-sm);">
                        Om du i framtiden flyttar <code>gravityseries.se</code> till Cloudflare DNS kan du lägga till en custom domain
                        (t.ex. <code>photos.gravityseries.se</code>) istället för r2.dev-URL:en. Så här:
                    </p>
                    <ol style="margin: 0; padding-left: var(--space-lg);">
                        <li>I bucket Settings, under <strong>"Custom Domains"</strong> &rarr; <strong>"Connect Domain"</strong></li>
                        <li>Skriv in: <code>photos.gravityseries.se</code></li>
                        <li>Cloudflare skapar DNS-posten automatiskt</li>
                        <li>Uppdatera <code>R2_PUBLIC_URL</code> i <code>.env</code> till den nya domänen</li>
                    </ol>
                    <p style="margin: var(--space-sm) 0 0; color: var(--color-text-muted);">
                        <strong>Obs:</strong> Domänen måste vara tillagd i samma Cloudflare-konto som bucketen.
                        Om den hanteras av annan DNS-tjänst (Hostinger, Loopia etc.) fungerar detta <strong>inte</strong> - använd r2.dev istället.
                    </p>
                </div>
            </details>
        </div>

        <!-- STEG 4 -->
        <div class="setup-step" style="margin-bottom: var(--space-xl); padding-bottom: var(--space-lg); border-bottom: 1px solid var(--color-border);">
            <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--radius-full); background: var(--color-accent); color: var(--color-bg-page); font-weight: 700; font-size: 0.9rem; flex-shrink: 0;">4</span>
                <h4 style="color: var(--color-text-primary); margin: 0;">Skapa API-nyckel (för uppladdning från TheHUB)</h4>
            </div>
            <ol style="margin: 0; padding-left: var(--space-xl);">
                <li>Gå tillbaka till <strong>R2 Object Storage</strong> i vänstermenyn</li>
                <li>Klicka <strong>"Manage R2 API Tokens"</strong> (länken ligger till höger ovanför bucket-listan)</li>
                <li>Klicka <strong>"Create API token"</strong></li>
                <li>Fyll i:
                    <ul style="margin: var(--space-xs) 0; padding-left: var(--space-lg);">
                        <li><strong>Token name:</strong> <code>TheHUB Photo Upload</code></li>
                        <li><strong>Permissions:</strong> <code>Object Read & Write</code></li>
                        <li><strong>Specify bucket(s):</strong> Välj <strong>Apply to specific buckets only</strong> och välj <code>thehub-photos</code></li>
                        <li><strong>TTL:</strong> Lämna tomt (ingen utgångstid) eller välj 1 år</li>
                    </ul>
                </li>
                <li>Klicka <strong>"Create API Token"</strong></li>
            </ol>

            <div style="margin-top: var(--space-md); padding: var(--space-md); background: var(--color-warning); color: #000; border-radius: var(--radius-sm);">
                <strong>VIKTIGT: Spara nycklarna nu!</strong><br>
                Du får se två värden:
                <ul style="margin: var(--space-xs) 0; padding-left: var(--space-lg);">
                    <li><strong>Access Key ID</strong> — ser ut som: <code>a1b2c3d4e5f6g7h8i9j0...</code></li>
                    <li><strong>Secret Access Key</strong> — ser ut som: <code>aBcDeFgHiJkLmNoPqRsT...</code></li>
                </ul>
                Secret Access Key visas <strong>bara en gång</strong>. Kopiera och spara den direkt!
                Om du tappar den måste du skapa en ny token.
            </div>
        </div>

        <!-- STEG 5 -->
        <div class="setup-step" style="margin-bottom: var(--space-xl); padding-bottom: var(--space-lg); border-bottom: 1px solid var(--color-border);">
            <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--radius-full); background: var(--color-accent); color: var(--color-bg-page); font-weight: 700; font-size: 0.9rem; flex-shrink: 0;">5</span>
                <h4 style="color: var(--color-text-primary); margin: 0;">Uppdatera .env på servern</h4>
            </div>
            <p>Öppna filen <code>.env</code> i TheHUBs rotmapp på servern och lägg till (eller uppdatera) dessa rader:</p>

            <pre style="background: var(--color-bg-hover); padding: var(--space-md); border-radius: var(--radius-sm); font-size: 0.8rem; overflow-x: auto; line-height: 1.8;"><span style="color: var(--color-text-muted);"># Cloudflare R2 Bildlagring</span>
R2_ACCOUNT_ID=<span style="color: var(--color-accent-text);">ditt_account_id</span>             <span style="color: var(--color-text-muted);"># 32 tecken, finns i Cloudflare Dashboard URL</span>
R2_ACCESS_KEY_ID=<span style="color: var(--color-accent-text);">din_access_key_id</span>        <span style="color: var(--color-text-muted);"># Från steg 4</span>
R2_SECRET_ACCESS_KEY=<span style="color: var(--color-accent-text);">din_secret_key</span>       <span style="color: var(--color-text-muted);"># Från steg 4 (visas bara en gång!)</span>
R2_BUCKET=<span style="color: var(--color-accent-text);">thehub-photos</span>                   <span style="color: var(--color-text-muted);"># Bucket-namnet du skapade i steg 2</span>
R2_PUBLIC_URL=<span style="color: var(--color-accent-text);">https://pub-XXXXX.r2.dev</span>     <span style="color: var(--color-text-muted);"># URL:en från steg 3 (utan / på slutet)</span></pre>

            <div style="margin-top: var(--space-md); padding: var(--space-md); background: var(--color-accent-light); border-radius: var(--radius-sm);">
                <strong style="color: var(--color-text-primary);">Var hittar jag värdena?</strong>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md); margin-top: var(--space-sm); font-size: 0.85rem;">
                    <div>
                        <strong>R2_ACCOUNT_ID</strong><br>
                        Logga in på <a href="https://dash.cloudflare.com" target="_blank" style="color: var(--color-accent-text);">dash.cloudflare.com</a>.
                        Account ID syns i höger sidebar under <strong>"Account details"</strong>,
                        eller direkt i URL:en: <code>dash.cloudflare.com/<strong>a1b2c3d4...</strong></code>
                    </div>
                    <div>
                        <strong>R2_PUBLIC_URL</strong><br>
                        Gå till din bucket &rarr; <strong>Settings</strong> &rarr; <strong>Public Development URL</strong>.
                        Kopiera hela URL:en som visas, t.ex. <code>https://pub-abc123def456.r2.dev</code>.
                        <strong>Ingen</strong> avslutande <code>/</code>.
                    </div>
                </div>
            </div>
        </div>

        <!-- STEG 6 -->
        <div class="setup-step" style="margin-bottom: var(--space-xl); padding-bottom: var(--space-lg); border-bottom: 1px solid var(--color-border);">
            <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--radius-full); background: var(--color-accent); color: var(--color-bg-page); font-weight: 700; font-size: 0.9rem; flex-shrink: 0;">6</span>
                <h4 style="color: var(--color-text-primary); margin: 0;">Kör databasmigrering</h4>
            </div>
            <ol style="margin: 0; padding-left: var(--space-xl);">
                <li>Gå till <a href="/admin/migrations.php" style="color: var(--color-accent-text);">Verktyg &rarr; Databasmigrationer</a></li>
                <li>Leta efter <strong>064_event_photos_r2_key.sql</strong></li>
                <li>Klicka <strong>"Kör"</strong> om den inte redan är körd (grönmarkerad)</li>
            </ol>
            <p style="margin-top: var(--space-sm); font-size: 0.85rem;">
                Denna migration lägger till en kolumn (<code>r2_key</code>) i fotodatabasen.
                Den behövs för att TheHUB ska kunna radera bilder från R2 när du tar bort dem i admin.
            </p>
        </div>

        <!-- STEG 7 -->
        <div class="setup-step" style="margin-bottom: var(--space-lg);">
            <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--radius-full); background: var(--color-success); color: #fff; font-weight: 700; font-size: 0.9rem; flex-shrink: 0;">7</span>
                <h4 style="color: var(--color-text-primary); margin: 0;">Testa!</h4>
            </div>
            <ol style="margin: 0; padding-left: var(--space-xl);">
                <li>Ladda om denna sida - statusen ska visa <span class="badge badge-success">Konfigurerat</span></li>
                <li>Klicka <strong>"Testa anslutning"</strong> - bör visa grönt meddelande</li>
                <li>Klicka <strong>"Testa uppladdning"</strong> - laddar upp en testfil och raderar den direkt</li>
                <li>Gå till <a href="/admin/event-albums" style="color: var(--color-accent-text);">Fotoalbum</a>, skapa ett album och ladda upp en bild</li>
            </ol>
        </div>

        <!-- Kostnad -->
        <div style="padding: var(--space-md); background: var(--color-bg-hover); border-radius: var(--radius-sm); margin-bottom: var(--space-md);">
            <h4 style="color: var(--color-text-primary); margin: 0 0 var(--space-sm);">
                <i data-lucide="calculator" class="icon-sm" style="vertical-align: text-bottom;"></i> Kostnadsberäkning
            </h4>
            <table style="width: 100%; font-size: 0.8rem; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--color-border);">
                        <th style="text-align: left; padding: var(--space-xs);">År</th>
                        <th style="text-align: right; padding: var(--space-xs);">Bilder</th>
                        <th style="text-align: right; padding: var(--space-xs);">Lagring</th>
                        <th style="text-align: right; padding: var(--space-xs);">Kostnad/mån</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td style="padding: var(--space-xs);">2026</td><td style="text-align: right; padding: var(--space-xs);">~13 000</td><td style="text-align: right; padding: var(--space-xs);">6.5 GB</td><td style="text-align: right; padding: var(--space-xs); color: var(--color-success); font-weight: 600;">$0 (gratis)</td></tr>
                    <tr><td style="padding: var(--space-xs);">2027</td><td style="text-align: right; padding: var(--space-xs);">~18 000</td><td style="text-align: right; padding: var(--space-xs);">9 GB</td><td style="text-align: right; padding: var(--space-xs); color: var(--color-success); font-weight: 600;">$0 (gratis)</td></tr>
                    <tr><td style="padding: var(--space-xs);">2028</td><td style="text-align: right; padding: var(--space-xs);">~23 000</td><td style="text-align: right; padding: var(--space-xs);">11.5 GB</td><td style="text-align: right; padding: var(--space-xs);">~$0.02</td></tr>
                    <tr><td style="padding: var(--space-xs);">2030</td><td style="text-align: right; padding: var(--space-xs);">~33 000</td><td style="text-align: right; padding: var(--space-xs);">16.5 GB</td><td style="text-align: right; padding: var(--space-xs);">~$0.10</td></tr>
                </tbody>
            </table>
            <p style="font-size: 0.75rem; color: var(--color-text-muted); margin: var(--space-xs) 0 0;">
                Beräknat med ~500 KB per optimerad bild. 10 GB gratis lagring. $0 bandbredd oavsett antal besökare.
                Bilder optimeras automatiskt vid uppladdning (max 1920px, JPEG 82%).
            </p>
        </div>

        <!-- Felsökning -->
        <details style="margin-top: var(--space-md);">
            <summary style="font-size: 0.85rem; cursor: pointer; color: var(--color-text-primary); font-weight: 600;">
                <i data-lucide="wrench" class="icon-sm" style="vertical-align: text-bottom;"></i> Felsökning
            </summary>
            <div style="margin-top: var(--space-sm); font-size: 0.85rem;">
                <p><strong>"Kunde inte ansluta"</strong></p>
                <ul style="padding-left: var(--space-lg); margin: var(--space-xs) 0;">
                    <li>Kontrollera att <code>R2_ACCOUNT_ID</code> är korrekt (32 tecken, inga mellanslag)</li>
                    <li>Kontrollera att <code>R2_ACCESS_KEY_ID</code> och <code>R2_SECRET_ACCESS_KEY</code> matchar exakt vad Cloudflare visade</li>
                    <li>Kontrollera att <code>R2_BUCKET</code> är exakt samma namn som bucketen (skiftlägeskänsligt)</li>
                </ul>

                <p style="margin-top: var(--space-md);"><strong>"Uppladdning lyckades men bilderna visas inte"</strong></p>
                <ul style="padding-left: var(--space-lg); margin: var(--space-xs) 0;">
                    <li>Kontrollera att publik åtkomst är aktiverat (steg 3)</li>
                    <li>Kontrollera att <code>R2_PUBLIC_URL</code> matchar din custom domain eller r2.dev-URL</li>
                    <li>Testa att öppna <code>R2_PUBLIC_URL</code> direkt i webbläsaren (ska visa XML eller "NoSuchKey")</li>
                </ul>

                <p style="margin-top: var(--space-md);"><strong>"Access Denied" / "SignatureDoesNotMatch"</strong></p>
                <ul style="padding-left: var(--space-lg); margin: var(--space-xs) 0;">
                    <li>Secret Access Key kan ha kopierats fel (extra mellanslag?)</li>
                    <li>Token-behörigheterna kanske bara är "Read" - du behöver "Object Read & Write"</li>
                    <li>Lösning: Skapa en ny API-token med rätt behörigheter</li>
                </ul>

                <p style="margin-top: var(--space-md);"><strong>Servern har inte cURL eller GD</strong></p>
                <ul style="padding-left: var(--space-lg); margin: var(--space-xs) 0;">
                    <li>R2-klienten kräver PHP-tilläggen <code>curl</code> och <code>gd</code> (för bildoptimering)</li>
                    <li>Kontrollera med: <code>php -m | grep -i 'curl\|gd'</code></li>
                    <li>Hostinger har dessa aktiverade som standard</li>
                </ul>
            </div>
        </details>

    </div>
</details>

<!-- Så fungerar det -->
<details class="admin-card" style="margin-top: var(--space-md);">
    <summary class="admin-card-header" style="cursor: pointer;">
        <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-sm);">
            <i data-lucide="info" class="icon-sm"></i> Så fungerar bildlagringen
        </h3>
    </summary>
    <div class="admin-card-body" style="font-size: 0.9rem; line-height: 1.7; color: var(--color-text-secondary);">
        <h4 style="color: var(--color-text-primary); margin: 0 0 var(--space-sm);">Uppladdningsflöde</h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: var(--space-sm); margin-bottom: var(--space-md);">
            <div style="text-align: center; padding: var(--space-sm); background: var(--color-bg-hover); border-radius: var(--radius-sm);">
                <div style="font-size: 1.2rem; margin-bottom: var(--space-2xs);"><i data-lucide="upload" class="icon-sm"></i></div>
                <div style="font-size: 0.75rem;">Admin laddar<br>upp bild</div>
            </div>
            <div style="text-align: center; padding: var(--space-sm); display: flex; align-items: center; justify-content: center; color: var(--color-text-muted);">
                <i data-lucide="arrow-right" class="icon-sm"></i>
            </div>
            <div style="text-align: center; padding: var(--space-sm); background: var(--color-bg-hover); border-radius: var(--radius-sm);">
                <div style="font-size: 1.2rem; margin-bottom: var(--space-2xs);"><i data-lucide="image" class="icon-sm"></i></div>
                <div style="font-size: 0.75rem;">TheHUB<br>optimerar</div>
            </div>
            <div style="text-align: center; padding: var(--space-sm); display: flex; align-items: center; justify-content: center; color: var(--color-text-muted);">
                <i data-lucide="arrow-right" class="icon-sm"></i>
            </div>
            <div style="text-align: center; padding: var(--space-sm); background: var(--color-accent-light); border-radius: var(--radius-sm);">
                <div style="font-size: 1.2rem; margin-bottom: var(--space-2xs);"><i data-lucide="cloud" class="icon-sm"></i></div>
                <div style="font-size: 0.75rem;">Sparas i<br>Cloudflare R2</div>
            </div>
            <div style="text-align: center; padding: var(--space-sm); display: flex; align-items: center; justify-content: center; color: var(--color-text-muted);">
                <i data-lucide="arrow-right" class="icon-sm"></i>
            </div>
            <div style="text-align: center; padding: var(--space-sm); background: var(--color-bg-hover); border-radius: var(--radius-sm);">
                <div style="font-size: 1.2rem; margin-bottom: var(--space-2xs);"><i data-lucide="globe" class="icon-sm"></i></div>
                <div style="font-size: 0.75rem;">Visas på<br>event-sidan</div>
            </div>
        </div>

        <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-sm);">Vad händer automatiskt</h4>
        <ul style="padding-left: var(--space-lg); margin: 0;">
            <li><strong>Bildoptimering:</strong> Stora bilder skalas ner till max 1920px bredd, JPEG-kvalitet 82%</li>
            <li><strong>Thumbnails:</strong> En miniatyrbild (400px) skapas automatiskt för snabbare laddning i galleriet</li>
            <li><strong>Unik filnamn:</strong> Varje bild får ett unikt namn (<code>events/123/a1b2c3d4_foto.jpg</code>) för att undvika konflikter</li>
            <li><strong>Radering:</strong> När du tar bort en bild i admin raderas den även från R2 (inklusive thumbnail)</li>
        </ul>

        <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-sm);">Var visas bilderna?</h4>
        <ul style="padding-left: var(--space-lg); margin: 0;">
            <li><strong>Event-sidan:</strong> Galleri-flik med alla publicerade bilder, lightbox, sponsor-annonser</li>
            <li><strong>Rider-profil:</strong> "Mina bilder" visar taggade bilder (premium-medlemmar)</li>
        </ul>
    </div>
</details>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
