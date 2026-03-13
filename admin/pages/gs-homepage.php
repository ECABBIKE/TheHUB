<?php
/**
 * Admin — Redigera GravitySeries startsida
 * Lagrar textblock i site_settings, styrelsemedlemmar som JSON
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireAdmin();

global $pdo;
if (!$pdo) {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = false;
$errors = [];

// Settings keys for homepage content
$settingsKeys = [
    'gs_hero_eyebrow'     => 'Svensk Gravitycykling sedan 2016',
    'gs_hero_title'        => 'Gravity<em>Series</em>',
    'gs_hero_body'         => 'Organisationen bakom svensk enduro och downhill. Vi arrangerar tävlingar, sätter regler och utvecklar sporten — från Motion till Elite.',
    'gs_hero_image'        => '',
    'gs_hero_overlay'      => '55',
    'gs_section_series_label'   => 'Tävlingsserier',
    'gs_section_series_title'   => 'Fyra serier.<br>En rörelse.',
    'gs_section_series_body'    => 'GravitySeries driver Enduro och Downhill-tävlingar från Malmö till Umeå. Hitta din serie — och ditt nästa lopp.',
    'gs_section_info_label'     => 'Praktisk info',
    'gs_section_info_title'     => 'För åkare<br>&amp; arrangörer',
    'gs_info_card_1_title'      => 'Arrangera ett event',
    'gs_info_card_1_desc'       => 'Vill du arrangera en tävling inom GravitySeries? Här hittar du allt från ansökan till banprojektering och praktisk info.',
    'gs_info_card_2_title'      => 'Licenser & SCF',
    'gs_info_card_2_desc'       => 'För att tävla i GravitySeries behöver du en giltig SCF-licens. Här förklarar vi hur du skaffar en och vad den kostar.',
    'gs_info_card_3_title'      => 'Gravity-ID',
    'gs_info_card_3_desc'       => 'Ditt Gravity-ID kopplar ihop dina tävlingsresultat, licens och profil. Allt på ett ställe — oavsett vilken serie du kör.',
    'gs_section_board_label'    => 'Organisation',
    'gs_section_board_title'    => 'Styrelsen',
    'gs_section_board_body'     => 'GravitySeries drivs ideellt av ett engagerat gäng med passion för gravitycykling.',
    'gs_board_members'          => '',  // JSON array
    'gs_hub_cta_title'          => 'Kalender, resultat<br>&amp; ranking',
    'gs_hub_cta_body'           => 'Allt samlat på TheHUB — vår tävlingsplattform.',
    'gs_series_year'            => '',
    'gs_header_logo'            => '',
];

// Load current values from DB
$currentValues = [];
try {
    $allKeys = array_keys($settingsKeys);
    $placeholders = implode(',', array_fill(0, count($allKeys), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM sponsor_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($allKeys);
    while ($row = $stmt->fetch()) {
        $currentValues[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Table might not have these keys yet
}

// Merge defaults with DB values
foreach ($settingsKeys as $key => $default) {
    if (!isset($currentValues[$key])) {
        $currentValues[$key] = $default;
    }
}

// Default board members if not set
if (empty($currentValues['gs_board_members'])) {
    $currentValues['gs_board_members'] = json_encode([
        ['role' => 'Ordförande', 'name' => 'Förnamn Efternamn', 'contact' => 'ordforde@gravityseries.se'],
        ['role' => 'Vice ordförande', 'name' => 'Förnamn Efternamn', 'contact' => 'vice@gravityseries.se'],
        ['role' => 'Kassör', 'name' => 'Förnamn Efternamn', 'contact' => 'kassor@gravityseries.se'],
        ['role' => 'Tävlingsansvarig', 'name' => 'Förnamn Efternamn', 'contact' => 'tavling@gravityseries.se'],
        ['role' => 'Teknisk ansvarig', 'name' => 'Förnamn Efternamn', 'contact' => 'teknik@gravityseries.se'],
        ['role' => 'Kontakt', 'name' => 'info@gravityseries.se', 'contact' => 'Allmänna frågor & media'],
    ], JSON_UNESCAPED_UNICODE);
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ogiltig CSRF-token.';
    }

    if (empty($errors)) {
        try {
            // Save text fields
            $textKeys = array_keys($settingsKeys);
            // Remove keys handled separately
            $textKeys = array_diff($textKeys, ['gs_board_members', 'gs_hero_image', 'gs_header_logo']);

            foreach ($textKeys as $key) {
                $value = $_POST[$key] ?? '';
                save_site_setting($key, $value, 'GS Startsida');
                $currentValues[$key] = $value;
            }

            // Handle hero image removal
            if (!empty($_POST['remove_hero_image'])) {
                // Delete old file if it exists
                $oldImg = $currentValues['gs_hero_image'] ?? '';
                if ($oldImg) {
                    $rootPath = __DIR__ . '/../..';
                    $oldPath = $rootPath . $oldImg;
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
                save_site_setting('gs_hero_image', '', 'GS Startsida - Hero-bild');
                $currentValues['gs_hero_image'] = '';
            }

            // Handle hero image upload
            if (!empty($_FILES['gs_hero_image_file']['tmp_name']) && $_FILES['gs_hero_image_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['gs_hero_image_file'];
                $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                if (in_array($mime, $allowedTypes)) {
                    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
                    $tmpPath = $file['tmp_name'];
                    // Optimize JPG
                    if ($ext === 'jpg' && function_exists('imagecreatefromjpeg')) {
                        $img = @imagecreatefromjpeg($tmpPath);
                        if ($img) {
                            $w = imagesx($img); $h = imagesy($img);
                            if ($w > 1920) {
                                $newW = 1920; $newH = (int)($h * 1920 / $w);
                                $resized = imagecreatetruecolor($newW, $newH);
                                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
                                imagedestroy($img); $img = $resized;
                            }
                            imagejpeg($img, $tmpPath, 82);
                            imagedestroy($img);
                        }
                    }
                    $rootPath = __DIR__ . '/../..';
                    $uploadDir = $rootPath . '/uploads/pages/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                    $filename = 'gs-hero-' . time() . '.' . $ext;
                    if (move_uploaded_file($tmpPath, $uploadDir . $filename)) {
                        $heroUrl = '/uploads/pages/' . $filename;
                        save_site_setting('gs_hero_image', $heroUrl, 'GS Startsida - Hero-bild');
                        $currentValues['gs_hero_image'] = $heroUrl;
                    } else {
                        $errors[] = 'Kunde inte spara hero-bilden. Kontrollera filrättigheter på uploads/pages/.';
                    }
                } else {
                    $errors[] = 'Hero-bilden måste vara JPG, PNG eller WebP.';
                }
            }

            // Handle header logo removal
            if (!empty($_POST['remove_header_logo'])) {
                $oldLogo = $currentValues['gs_header_logo'] ?? '';
                if ($oldLogo) {
                    $rootPath = __DIR__ . '/../..';
                    $oldPath = $rootPath . $oldLogo;
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
                save_site_setting('gs_header_logo', '', 'GS Startsida - Header-logga');
                $currentValues['gs_header_logo'] = '';
            }

            // Handle header logo upload
            if (!empty($_FILES['gs_header_logo_file']['tmp_name']) && $_FILES['gs_header_logo_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['gs_header_logo_file'];
                $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                if (in_array($mime, $allowedTypes)) {
                    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'][$mime];
                    $tmpPath = $file['tmp_name'];
                    $rootPath = __DIR__ . '/../..';
                    $uploadDir = $rootPath . '/uploads/pages/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                    $filename = 'gs-logo-' . time() . '.' . $ext;
                    if (move_uploaded_file($tmpPath, $uploadDir . $filename)) {
                        $logoUrl = '/uploads/pages/' . $filename;
                        // Delete old logo file
                        $oldLogo = $currentValues['gs_header_logo'] ?? '';
                        if ($oldLogo) {
                            $oldPath = $rootPath . $oldLogo;
                            if (file_exists($oldPath)) @unlink($oldPath);
                        }
                        save_site_setting('gs_header_logo', $logoUrl, 'GS Startsida - Header-logga');
                        $currentValues['gs_header_logo'] = $logoUrl;
                    } else {
                        $errors[] = 'Kunde inte spara loggan. Kontrollera filrättigheter på uploads/pages/.';
                    }
                } else {
                    $errors[] = 'Loggan måste vara JPG, PNG, WebP eller SVG.';
                }
            }

            // Save board members as JSON
            $boardMembers = [];
            $roles = $_POST['board_role'] ?? [];
            $names = $_POST['board_name'] ?? [];
            $contacts = $_POST['board_contact'] ?? [];
            for ($i = 0; $i < count($roles); $i++) {
                $role = trim($roles[$i] ?? '');
                $name = trim($names[$i] ?? '');
                $contact = trim($contacts[$i] ?? '');
                if ($role || $name) {
                    $boardMembers[] = ['role' => $role, 'name' => $name, 'contact' => $contact];
                }
            }
            $boardJson = json_encode($boardMembers, JSON_UNESCAPED_UNICODE);
            save_site_setting('gs_board_members', $boardJson, 'GS Startsida - Styrelsemedlemmar');
            $currentValues['gs_board_members'] = $boardJson;

            $success = true;
        } catch (Exception $e) {
            $errors[] = 'Kunde inte spara: ' . $e->getMessage();
        }
    }
}

$boardMembers = json_decode($currentValues['gs_board_members'], true) ?: [];

// Helper
function gs_val($key) {
    global $currentValues;
    return htmlspecialchars($currentValues[$key] ?? '', ENT_QUOTES, 'UTF-8');
}
function gs_raw($key) {
    global $currentValues;
    return $currentValues[$key] ?? '';
}

$page_title = 'GravitySeries — Redigera startsida';
include __DIR__ . '/../components/unified-layout.php';
?>

<div class="admin-content">
  <div class="page-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:var(--space-sm); margin-bottom:var(--space-lg);">
    <div>
      <h1 style="margin:0; font-size:1.5rem;">Redigera startsida</h1>
      <p style="color:var(--color-text-muted); margin:4px 0 0;">gravityseries/index.php</p>
    </div>
    <div style="display:flex; gap:var(--space-sm);">
      <a href="/admin/pages/" class="btn btn-secondary" style="font-size:13px;">Alla sidor</a>
      <a href="/gravityseries/" target="_blank" class="btn btn-secondary" style="font-size:13px;">
        <i data-lucide="external-link" style="width:14px;height:14px;"></i> Förhandsgranska
      </a>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-md);">Startsidan har sparats.</div>
  <?php endif; ?>
  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" style="margin-bottom:var(--space-md);"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <form method="POST" enctype="multipart/form-data" id="homepageForm">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <!-- HEADER-LOGGA -->
    <div class="card" style="margin-bottom:var(--space-lg);">
      <div class="card-header"><h3>Header-logga</h3></div>
      <div class="card-body" style="display:flex; flex-direction:column; gap:var(--space-md);">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Logotyp i headern</label>
          <?php $headerLogo = gs_raw('gs_header_logo'); ?>
          <?php if ($headerLogo): ?>
            <div style="display:flex; align-items:center; gap:var(--space-md); padding:var(--space-md); background:var(--color-bg-hover); border-radius:var(--radius-sm);">
              <div style="background:#1a1a2e; padding:12px 16px; border-radius:var(--radius-sm); display:flex; align-items:center;">
                <img src="<?= htmlspecialchars($headerLogo) ?>" alt="Header-logga" style="height:36px; width:auto; display:block;">
              </div>
              <div style="display:flex; gap:var(--space-xs); align-items:center;">
                <label style="background:var(--color-bg-card); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:6px 12px; cursor:pointer; font-size:13px; color:var(--color-text-secondary);">
                  <i data-lucide="replace" style="width:14px;height:14px;vertical-align:-2px;"></i> Byt
                  <input type="file" name="gs_header_logo_file" accept="image/jpeg,image/png,image/webp,image/svg+xml" style="display:none;">
                </label>
                <button type="submit" name="remove_header_logo" value="1" style="background:var(--color-error); color:#fff; border:none; border-radius:var(--radius-sm); padding:6px 12px; cursor:pointer; font-size:13px;">
                  <i data-lucide="x" style="width:14px;height:14px;vertical-align:-2px;"></i> Ta bort
                </button>
              </div>
            </div>
          <?php else: ?>
            <label style="display:flex; align-items:center; justify-content:center; border:2px dashed var(--color-border); border-radius:var(--radius-sm); padding:var(--space-lg) var(--space-md); cursor:pointer; color:var(--color-text-muted); font-size:13px; gap:var(--space-xs); transition:border-color .2s;" onmouseover="this.style.borderColor='var(--color-accent)'" onmouseout="this.style.borderColor='var(--color-border)'">
              <i data-lucide="image-plus" style="width:20px;height:20px;"></i>
              Klicka för att ladda upp en logga (PNG, SVG, JPG, WebP)
              <input type="file" name="gs_header_logo_file" accept="image/jpeg,image/png,image/webp,image/svg+xml" style="display:none;">
            </label>
          <?php endif; ?>
          <p class="form-help">Loggan visas i headern istället för texten "GravitySeries". Rekommenderad höjd: 36–40px. SVG eller transparent PNG fungerar bäst.</p>
        </div>
      </div>
    </div>

    <!-- HERO -->
    <div class="card" style="margin-bottom:var(--space-lg);">
      <div class="card-header"><h3>Hero-sektion</h3></div>
      <div class="card-body" style="display:flex; flex-direction:column; gap:var(--space-md);">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Eyebrow-text</label>
          <input type="text" name="gs_hero_eyebrow" class="form-input" value="<?= gs_val('gs_hero_eyebrow') ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Titel (HTML tillåtet, t.ex. &lt;em&gt;)</label>
          <input type="text" name="gs_hero_title" class="form-input" value="<?= gs_val('gs_hero_title') ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Beskrivning</label>
          <textarea name="gs_hero_body" class="form-textarea" rows="3"><?= gs_val('gs_hero_body') ?></textarea>
        </div>

        <!-- Hero-bild -->
        <div class="form-group" style="margin:0;">
          <label class="form-label">Bakgrundsbild</label>
          <?php $heroImg = gs_raw('gs_hero_image'); ?>
          <?php if ($heroImg): ?>
            <div style="position:relative; border-radius:var(--radius-sm); overflow:hidden; margin-bottom:var(--space-xs);">
              <img src="<?= htmlspecialchars($heroImg) ?>" alt="Hero" style="width:100%; max-height:200px; object-fit:cover; display:block;">
              <div style="position:absolute; top:8px; right:8px; display:flex; gap:6px;">
                <label style="background:var(--color-bg-card); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:6px 10px; cursor:pointer; font-size:12px; color:var(--color-text-secondary);">
                  <i data-lucide="replace" style="width:14px;height:14px;vertical-align:-2px;"></i> Byt
                  <input type="file" name="gs_hero_image_file" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="this.form.querySelector('#heroPreviewCurrent').style.display='none';">
                </label>
                <button type="submit" name="remove_hero_image" value="1" style="background:var(--color-error); color:#fff; border:none; border-radius:var(--radius-sm); padding:6px 10px; cursor:pointer; font-size:12px;">
                  <i data-lucide="x" style="width:14px;height:14px;vertical-align:-2px;"></i> Ta bort
                </button>
              </div>
            </div>
          <?php else: ?>
            <label style="display:flex; align-items:center; justify-content:center; border:2px dashed var(--color-border); border-radius:var(--radius-sm); padding:var(--space-xl) var(--space-md); cursor:pointer; color:var(--color-text-muted); font-size:13px; gap:var(--space-xs); transition:border-color .2s;" onmouseover="this.style.borderColor='var(--color-accent)'" onmouseout="this.style.borderColor='var(--color-border)'">
              <i data-lucide="image-plus" style="width:20px;height:20px;"></i>
              Klicka för att ladda upp en hero-bild (JPG, PNG, WebP)
              <input type="file" name="gs_hero_image_file" accept="image/jpeg,image/png,image/webp" style="display:none;">
            </label>
          <?php endif; ?>
          <p class="form-help">Rekommenderad storlek: minst 1920×800px. Bilden visas bakom texten med ett mörkt overlay.</p>
        </div>

        <!-- Overlay opacity -->
        <div class="form-group" style="margin:0;">
          <label class="form-label">Overlay-mörkhet (<?= (int)gs_raw('gs_hero_overlay') ?: 55 ?>%)</label>
          <input type="range" name="gs_hero_overlay" min="0" max="90" step="5" value="<?= (int)gs_raw('gs_hero_overlay') ?: 55 ?>" style="width:100%; accent-color:var(--color-accent);" oninput="this.previousElementSibling.textContent='Overlay-mörkhet ('+this.value+'%)'">
          <p class="form-help">0% = ingen mörkläggning, 90% = nästan helt mörkt. Gäller alla hero-bakgrundsbilder.</p>
        </div>
      </div>
    </div>

    <!-- SERIER -->
    <div class="card" style="margin-bottom:var(--space-lg);">
      <div class="card-header"><h3>Serier-sektion</h3></div>
      <div class="card-body" style="display:flex; flex-direction:column; gap:var(--space-md);">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Visa data för år (lämna tomt för innevarande år)</label>
          <input type="number" name="gs_series_year" class="form-input" value="<?= gs_val('gs_series_year') ?>" placeholder="<?= date('Y') ?>" min="2016" max="<?= date('Y') + 1 ?>" style="max-width:160px;">
          <p class="form-help">Styr vilket års seriedata (events, åkare, klubbar) som visas på startsidan. Tomt = <?= date('Y') ?>.</p>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Etikett</label>
          <input type="text" name="gs_section_series_label" class="form-input" value="<?= gs_val('gs_section_series_label') ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Rubrik (HTML tillåtet)</label>
          <input type="text" name="gs_section_series_title" class="form-input" value="<?= gs_val('gs_section_series_title') ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Brödtext</label>
          <textarea name="gs_section_series_body" class="form-textarea" rows="2"><?= gs_val('gs_section_series_body') ?></textarea>
        </div>
      </div>
    </div>

    <!-- INFO-KORT -->
    <div class="card" style="margin-bottom:var(--space-lg);">
      <div class="card-header"><h3>Info-kort (Praktisk info)</h3></div>
      <div class="card-body" style="display:flex; flex-direction:column; gap:var(--space-lg);">
        <div style="display:flex; gap:var(--space-md); flex-wrap:wrap;">
          <div class="form-group" style="margin:0; flex:1; min-width:200px;">
            <label class="form-label">Sektionetikett</label>
            <input type="text" name="gs_section_info_label" class="form-input" value="<?= gs_val('gs_section_info_label') ?>">
          </div>
          <div class="form-group" style="margin:0; flex:2; min-width:200px;">
            <label class="form-label">Sektionsrubrik (HTML tillåtet)</label>
            <input type="text" name="gs_section_info_title" class="form-input" value="<?= gs_val('gs_section_info_title') ?>">
          </div>
        </div>
        <?php for ($i = 1; $i <= 3; $i++): ?>
        <div style="padding:var(--space-md); background:var(--color-bg-hover); border-radius:var(--radius-sm);">
          <strong style="font-size:13px; color:var(--color-text-muted); text-transform:uppercase; letter-spacing:0.05em;">Kort <?= $i ?></strong>
          <div style="display:flex; gap:var(--space-md); flex-wrap:wrap; margin-top:var(--space-xs);">
            <div class="form-group" style="margin:0; flex:1; min-width:200px;">
              <label class="form-label">Titel</label>
              <input type="text" name="gs_info_card_<?= $i ?>_title" class="form-input" value="<?= gs_val("gs_info_card_{$i}_title") ?>">
            </div>
          </div>
          <div class="form-group" style="margin:var(--space-xs) 0 0;">
            <label class="form-label">Beskrivning</label>
            <textarea name="gs_info_card_<?= $i ?>_desc" class="form-textarea" rows="2"><?= gs_val("gs_info_card_{$i}_desc") ?></textarea>
          </div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <!-- STYRELSE -->
    <div class="card" style="margin-bottom:var(--space-lg);">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3>Styrelse</h3>
        <button type="button" class="btn btn-secondary" onclick="addBoardMember()" style="font-size:13px;">
          <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till
        </button>
      </div>
      <div class="card-body" style="display:flex; flex-direction:column; gap:var(--space-md);">
        <div style="display:flex; gap:var(--space-md); flex-wrap:wrap;">
          <div class="form-group" style="margin:0; flex:1; min-width:200px;">
            <label class="form-label">Sektionetikett</label>
            <input type="text" name="gs_section_board_label" class="form-input" value="<?= gs_val('gs_section_board_label') ?>">
          </div>
          <div class="form-group" style="margin:0; flex:1; min-width:200px;">
            <label class="form-label">Sektionsrubrik</label>
            <input type="text" name="gs_section_board_title" class="form-input" value="<?= gs_val('gs_section_board_title') ?>">
          </div>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Sektionsbeskrivning</label>
          <textarea name="gs_section_board_body" class="form-textarea" rows="2"><?= gs_val('gs_section_board_body') ?></textarea>
        </div>

        <div id="boardMembersContainer">
          <?php foreach ($boardMembers as $idx => $member): ?>
          <div class="board-member-row" style="display:flex; gap:var(--space-sm); align-items:flex-end; flex-wrap:wrap; padding:var(--space-sm); background:var(--color-bg-hover); border-radius:var(--radius-sm); margin-bottom:var(--space-xs);">
            <div class="form-group" style="margin:0; flex:1; min-width:120px;">
              <?php if ($idx === 0): ?><label class="form-label">Roll</label><?php endif; ?>
              <input type="text" name="board_role[]" class="form-input" value="<?= htmlspecialchars($member['role'] ?? '') ?>" placeholder="Roll">
            </div>
            <div class="form-group" style="margin:0; flex:1; min-width:140px;">
              <?php if ($idx === 0): ?><label class="form-label">Namn</label><?php endif; ?>
              <input type="text" name="board_name[]" class="form-input" value="<?= htmlspecialchars($member['name'] ?? '') ?>" placeholder="Namn">
            </div>
            <div class="form-group" style="margin:0; flex:1; min-width:180px;">
              <?php if ($idx === 0): ?><label class="form-label">Kontakt (e-post)</label><?php endif; ?>
              <input type="text" name="board_contact[]" class="form-input" value="<?= htmlspecialchars($member['contact'] ?? '') ?>" placeholder="E-post">
            </div>
            <button type="button" class="btn btn-danger" onclick="this.closest('.board-member-row').remove()" style="font-size:13px; padding:8px 12px; flex-shrink:0;">
              <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- HUB CTA -->
    <div class="card" style="margin-bottom:var(--space-lg);">
      <div class="card-header"><h3>TheHUB CTA-sektion</h3></div>
      <div class="card-body" style="display:flex; flex-direction:column; gap:var(--space-md);">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Rubrik (HTML tillåtet)</label>
          <input type="text" name="gs_hub_cta_title" class="form-input" value="<?= gs_val('gs_hub_cta_title') ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Undertext</label>
          <input type="text" name="gs_hub_cta_body" class="form-input" value="<?= gs_val('gs_hub_cta_body') ?>">
        </div>
      </div>
    </div>

    <!-- SAVE -->
    <div style="position:sticky; bottom:0; background:var(--color-bg-page); padding:var(--space-md) 0; border-top:1px solid var(--color-border); z-index:10;">
      <button type="submit" class="btn btn-primary" style="font-size:15px; padding:10px 32px;">
        <i data-lucide="save" style="width:16px;height:16px;"></i> Spara startsidan
      </button>
    </div>
  </form>
</div>

<script>
function addBoardMember() {
    const container = document.getElementById('boardMembersContainer');
    const row = document.createElement('div');
    row.className = 'board-member-row';
    row.style.cssText = 'display:flex; gap:var(--space-sm); align-items:flex-end; flex-wrap:wrap; padding:var(--space-sm); background:var(--color-bg-hover); border-radius:var(--radius-sm); margin-bottom:var(--space-xs);';
    row.innerHTML = `
        <div class="form-group" style="margin:0; flex:1; min-width:120px;">
            <input type="text" name="board_role[]" class="form-input" placeholder="Roll">
        </div>
        <div class="form-group" style="margin:0; flex:1; min-width:140px;">
            <input type="text" name="board_name[]" class="form-input" placeholder="Namn">
        </div>
        <div class="form-group" style="margin:0; flex:1; min-width:180px;">
            <input type="text" name="board_contact[]" class="form-input" placeholder="E-post">
        </div>
        <button type="button" class="btn btn-danger" onclick="this.closest('.board-member-row').remove()" style="font-size:13px; padding:8px 12px; flex-shrink:0;">
            <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
        </button>
    `;
    container.appendChild(row);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
</script>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
