<?php
/**
 * Admin — Skapa/redigera sida (Pages CMS)
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

// DB connection
try {
    global $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
} catch (PDOException $e) {
    die('DB-fel: ' . $e->getMessage());
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$page = null;
$errors = [];
$success = false;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE id = ?");
    $stmt->execute([$id]);
    $page = $stmt->fetch();
    if (!$page) {
        $_SESSION['gs_flash'] = ['type' => 'error', 'message' => 'Sidan hittades inte.'];
        header('Location: /admin/pages/');
        exit;
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ogiltig CSRF-token. Försök igen.';
    }

    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $metaDesc = trim($_POST['meta_description'] ?? '');
    $content = $_POST['content'] ?? '';
    $template = $_POST['template'] ?? 'default';
    $status = $_POST['status'] ?? 'draft';
    $showInNav = !empty($_POST['show_in_nav']) ? 1 : 0;
    $navOrder = (int)($_POST['nav_order'] ?? 99);
    $navLabel = trim($_POST['nav_label'] ?? '');
    $heroImagePosition = $_POST['hero_image_position'] ?? 'center';
    $heroOverlayOpacity = max(0, min(80, (int)($_POST['hero_overlay_opacity'] ?? 50)));
    $seriesBrandId = !empty($_POST['series_brand_id']) ? (int)$_POST['series_brand_id'] : null;

    // Validate
    if (empty($title)) $errors[] = 'Titel krävs.';
    if (empty($slug)) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $slug = trim($slug, '-');
    }
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        $errors[] = 'Slug får bara innehålla gemener, siffror och bindestreck.';
    }
    if (strlen($slug) > 100) $errors[] = 'Slug får vara max 100 tecken.';
    if (!in_array($template, ['default', 'full-width', 'landing'])) $template = 'default';
    if (!in_array($status, ['published', 'draft'])) $status = 'draft';
    if (!in_array($heroImagePosition, ['center', 'top', 'bottom'])) $heroImagePosition = 'center';

    // Check unique slug
    if (empty($errors)) {
        $checkStmt = $pdo->prepare("SELECT id FROM pages WHERE slug = ? AND id != ?");
        $checkStmt->execute([$slug, $isEdit ? $id : 0]);
        if ($checkStmt->fetch()) {
            $errors[] = 'Sluggen "' . $slug . '" används redan av en annan sida.';
        }
    }

    // Handle serie logo upload (stored on series_brands.logo)
    if ($seriesBrandId && !empty($_FILES['serie_logo_file']['tmp_name'])) {
        $logoFile = $_FILES['serie_logo_file'];
        $logoAllowed = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        $logoFinfo = new finfo(FILEINFO_MIME_TYPE);
        $logoMime = $logoFinfo->file($logoFile['tmp_name']);
        if (!in_array($logoMime, $logoAllowed)) {
            $errors[] = 'Serie-logotypen måste vara JPG, PNG, WebP eller SVG.';
        } else {
            $logoTmp = $logoFile['tmp_name'];
            // Auto-resize non-SVG (max 600px wide)
            if ($logoMime !== 'image/svg+xml') {
                $logoInfo = getimagesize($logoTmp);
                if ($logoInfo && $logoInfo[0] > 600) {
                    $src = null;
                    if ($logoMime === 'image/jpeg') $src = imagecreatefromjpeg($logoTmp);
                    elseif ($logoMime === 'image/png') $src = imagecreatefrompng($logoTmp);
                    elseif ($logoMime === 'image/webp') $src = imagecreatefromwebp($logoTmp);
                    if ($src) {
                        $newH = (int)round($logoInfo[1] * (600 / $logoInfo[0]));
                        $dst = imagecreatetruecolor(600, $newH);
                        // Preserve alpha for PNG
                        if ($logoMime === 'image/png') {
                            imagealphablending($dst, false);
                            imagesavealpha($dst, true);
                        }
                        imagecopyresampled($dst, $src, 0, 0, 0, 0, 600, $newH, $logoInfo[0], $logoInfo[1]);
                        if ($logoMime === 'image/png') {
                            imagepng($dst, $logoTmp, 8);
                        } else {
                            imagejpeg($dst, $logoTmp, 85);
                            $logoMime = 'image/jpeg';
                        }
                        imagedestroy($src);
                        imagedestroy($dst);
                    }
                }
            }
            $logoExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'][$logoMime];
            $logoUploadDir = __DIR__ . '/../../uploads/pages/';
            if (!is_dir($logoUploadDir)) mkdir($logoUploadDir, 0755, true);
            $logoFilename = 'serie-logo-' . ($slug ?: 'new') . '-' . time() . '.' . $logoExt;
            if (move_uploaded_file($logoTmp, $logoUploadDir . $logoFilename)) {
                $serieLogo = '/uploads/pages/' . $logoFilename;
                // Save directly to series_brands.logo
                try {
                    $pdo->prepare("UPDATE series_brands SET logo = ? WHERE id = ?")->execute([$serieLogo, $seriesBrandId]);
                } catch (PDOException $e) {}
            }
        }
    }
    // Remove serie logo
    if ($seriesBrandId && !empty($_POST['remove_serie_logo'])) {
        try {
            $pdo->prepare("UPDATE series_brands SET logo = NULL WHERE id = ?")->execute([$seriesBrandId]);
        } catch (PDOException $e) {}
    }

    // Handle hero image upload
    $heroImage = $isEdit ? ($page['hero_image'] ?? null) : null;
    if (!empty($_POST['remove_hero_image'])) {
        $heroImage = null;
    }
    if (!empty($_FILES['hero_image_file']['tmp_name'])) {
        $file = $_FILES['hero_image_file'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedTypes)) {
            $errors[] = 'Hero-bilden måste vara JPG, PNG eller WebP.';
        } else {
            // Auto-resize large images (max 1920px wide, JPEG 85% quality)
            $maxWidth = 1920;
            $tmpPath = $file['tmp_name'];
            $info = getimagesize($tmpPath);
            if ($info && $info[0] > $maxWidth) {
                $src = null;
                if ($mime === 'image/jpeg') $src = imagecreatefromjpeg($tmpPath);
                elseif ($mime === 'image/png') $src = imagecreatefrompng($tmpPath);
                elseif ($mime === 'image/webp') $src = imagecreatefromwebp($tmpPath);
                if ($src) {
                    $newH = (int)round($info[1] * ($maxWidth / $info[0]));
                    $dst = imagecreatetruecolor($maxWidth, $newH);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newH, $info[0], $info[1]);
                    imagejpeg($dst, $tmpPath, 85);
                    imagedestroy($src);
                    imagedestroy($dst);
                    $mime = 'image/jpeg'; // Output is always JPEG after resize
                }
            }
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $uploadDir = __DIR__ . '/../../uploads/pages/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = 'hero-' . $slug . '-' . time() . '.' . $ext;
            if (move_uploaded_file($tmpPath, $uploadDir . $filename)) {
                $heroImage = '/uploads/pages/' . $filename;
            } else {
                $errors[] = 'Kunde inte spara hero-bilden.';
            }
        }
    }

    // Save
    if (empty($errors)) {
        if ($isEdit) {
            $sql = "UPDATE pages SET
                    title = ?, slug = ?, meta_description = ?, content = ?,
                    template = ?, status = ?, show_in_nav = ?, nav_order = ?,
                    nav_label = ?, hero_image = ?, hero_image_position = ?,
                    hero_overlay_opacity = ?
                WHERE id = ?";
            $params = [
                $title, $slug, $metaDesc ?: null, $content,
                $template, $status, $showInNav, $navOrder,
                $navLabel ?: null, $heroImage, $heroImagePosition,
                $heroOverlayOpacity, $id
            ];
            $pdo->prepare($sql)->execute($params);
            // Save series_brand_id if column exists
            try {
                $pdo->prepare("UPDATE pages SET series_brand_id = ? WHERE id = ?")->execute([$seriesBrandId, $id]);
            } catch (PDOException $e) {}
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pages (title, slug, meta_description, content, template, status, show_in_nav, nav_order, nav_label, hero_image, hero_image_position, hero_overlay_opacity, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $title, $slug, $metaDesc ?: null, $content,
                $template, $status, $showInNav, $navOrder,
                $navLabel ?: null, $heroImage, $heroImagePosition,
                $heroOverlayOpacity, $_SESSION['admin_id'] ?? null
            ]);
            $id = $pdo->lastInsertId();
            // Save series_brand_id if column exists
            try {
                $pdo->prepare("UPDATE pages SET series_brand_id = ? WHERE id = ?")->execute([$seriesBrandId, $id]);
            } catch (PDOException $e) {}
        }

        // Preview redirect
        if (!empty($_POST['save_preview'])) {
            $_SESSION['gs_flash'] = ['type' => 'success', 'message' => 'Sidan sparad.'];
            // If linked to a series brand, preview at /gravityseries/serie/{brand-slug}
            if ($seriesBrandId) {
                try {
                    $brandSlug = $pdo->query("SELECT slug FROM series_brands WHERE id = " . (int)$seriesBrandId)->fetchColumn();
                    if ($brandSlug) {
                        header('Location: /gravityseries/serie/' . urlencode($brandSlug));
                        exit;
                    }
                } catch (PDOException $e) {}
            }
            header('Location: /gravityseries/' . urlencode($slug));
            exit;
        }

        $_SESSION['gs_flash'] = ['type' => 'success', 'message' => 'Sidan sparad.'];
        header('Location: /admin/pages/edit.php?id=' . $id);
        exit;
    }

    // On error, rebuild page from POST data
    $page = [
        'id' => $id,
        'title' => $title,
        'slug' => $slug,
        'meta_description' => $metaDesc,
        'content' => $content,
        'template' => $template,
        'status' => $status,
        'show_in_nav' => $showInNav,
        'nav_order' => $navOrder,
        'nav_label' => $navLabel,
        'hero_image' => $heroImage,
        'hero_image_position' => $heroImagePosition,
        'hero_overlay_opacity' => $heroOverlayOpacity,
        'series_brand_id' => $seriesBrandId,
    ];
}

// Defaults for new page
if (!$page) {
    $page = [
        'id' => 0, 'title' => '', 'slug' => '', 'meta_description' => '',
        'content' => '', 'template' => 'default', 'status' => 'draft',
        'show_in_nav' => 0, 'nav_order' => 99, 'nav_label' => '',
        'hero_image' => null, 'hero_image_position' => 'center',
        'hero_overlay_opacity' => 50, 'series_brand_id' => null,
    ];
}

// Load series brands for dropdown
$seriesBrands = [];
try {
    $seriesBrands = $pdo->query("SELECT id, name, slug, accent_color FROM series_brands WHERE active = 1 ORDER BY display_order, name")->fetchAll();
} catch (PDOException $e) {}

$flash = $_SESSION['gs_flash'] ?? null;
unset($_SESSION['gs_flash']);

$page_title = $isEdit ? 'Redigera: ' . $page['title'] : 'Skapa ny sida';
$current_admin_page = 'pages';
include __DIR__ . '/../components/unified-layout.php';
?>

<style>
/* Mobile fixes for page editor */
@media (max-width: 767px) {
  .gs-edit-grid-3 { grid-template-columns: 1fr !important; }
  .gs-edit-grid-2 { grid-template-columns: 1fr !important; }
  .gs-edit-content { padding: 16px !important; }
  .gs-edit-content input[type="text"],
  .gs-edit-content input[type="number"],
  .gs-edit-content select,
  .gs-edit-content textarea {
    font-size: 16px !important; /* Prevents iOS zoom */
    min-height: 44px;
    background: var(--color-bg-card, #fff) !important;
    color: var(--color-text-primary, #333) !important;
  }
  .gs-edit-content select {
    -webkit-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 12px center !important;
    padding-right: 32px !important;
  }
  .gs-edit-card {
    margin-left: -16px;
    margin-right: -16px;
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
  }
  .gs-edit-actions { flex-direction: column; }
  .gs-edit-actions button { width: 100%; justify-content: center; }
}
</style>

<div class="admin-content gs-edit-content" style="padding: 24px; max-width: 960px;">

  <!-- Header -->
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
    <div style="display:flex; align-items:center; gap:12px;">
      <a href="/admin/pages/" style="color:var(--color-text-muted,#888); text-decoration:none;">&larr; Tillbaka</a>
      <h1 style="font-size:1.5rem; font-weight:700; margin:0;">
        <?= $isEdit ? 'Redigera sida' : 'Skapa ny sida' ?>
      </h1>
    </div>
    <?php if ($isEdit): ?>
      <a href="/gravityseries/<?= htmlspecialchars($page['slug']) ?>" target="_blank" style="font-size:13px; color:var(--color-text-muted,#888); text-decoration:none; display:flex; align-items:center; gap:4px;">
        <i data-lucide="external-link" style="width:14px;height:14px;"></i> Förhandsgranska
      </a>
    <?php endif; ?>
  </div>

  <?php if ($flash): ?>
    <div style="background:<?= $flash['type'] === 'success' ? '#d1fae5' : '#fee2e2' ?>; border:1px solid <?= $flash['type'] === 'success' ? '#10b981' : '#ef4444' ?>; border-radius:var(--radius-sm,6px); padding:12px 16px; margin-bottom:16px; font-size:14px;">
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div style="background:#fee2e2; border:1px solid #ef4444; border-radius:var(--radius-sm,6px); padding:12px 16px; margin-bottom:16px; font-size:14px;">
      <ul style="margin:0; padding-left:18px;">
        <?php foreach ($errors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <!-- Title & Slug -->
    <div style="background:var(--color-bg-card,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-md,10px); padding:20px; margin-bottom:16px;" class="gs-edit-card">
      <div style="margin-bottom:16px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary,#555);">Titel *</label>
        <input type="text" name="title" value="<?= htmlspecialchars($page['title']) ?>" required
          style="width:100%; padding:10px 14px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:16px;"
          oninput="autoSlug(this.value)">
      </div>
      <div style="margin-bottom:16px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary,#555);">Slug</label>
        <div style="display:flex; align-items:center; gap:4px;">
          <span style="color:var(--color-text-muted,#888); font-family:monospace; font-size:14px;">/gravityseries/</span>
          <input type="text" name="slug" id="slugInput" value="<?= htmlspecialchars($page['slug']) ?>"
            style="flex:1; padding:10px 14px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:14px; font-family:monospace;"
            pattern="^[a-z0-9-]+$" maxlength="100">
        </div>
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary,#555);">Meta-beskrivning</label>
        <input type="text" name="meta_description" value="<?= htmlspecialchars($page['meta_description'] ?? '') ?>" maxlength="300"
          style="width:100%; padding:10px 14px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:14px;"
          placeholder="Kort beskrivning för sökmotorer (max 300 tecken)">
      </div>
    </div>

    <!-- Content (TinyMCE) -->
    <div style="background:var(--color-bg-card,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-md,10px); padding:20px; margin-bottom:16px;" class="gs-edit-card">
      <label style="display:block; font-size:13px; font-weight:600; margin-bottom:8px; color:var(--color-text-secondary,#555);">Innehåll</label>
      <textarea name="content" id="tinymce-editor" rows="20" style="width:100%; min-height:400px;"><?= htmlspecialchars($page['content']) ?></textarea>
    </div>

    <!-- Hero Image -->
    <div style="background:var(--color-bg-card,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-md,10px); padding:20px; margin-bottom:16px;" class="gs-edit-card">
      <label style="display:block; font-size:13px; font-weight:600; margin-bottom:8px; color:var(--color-text-secondary,#555);">Hero-bild (valfritt)</label>
      <?php if (!empty($page['hero_image'])): ?>
        <div style="margin-bottom:12px; position:relative; max-width:400px;">
          <img src="<?= htmlspecialchars($page['hero_image']) ?>" style="width:100%; border-radius:6px; aspect-ratio:16/6; object-fit:cover;">
          <label style="display:flex; align-items:center; gap:6px; margin-top:8px; font-size:13px; color:var(--color-text-muted,#888);">
            <input type="checkbox" name="remove_hero_image" value="1"> Ta bort bild
          </label>
        </div>
      <?php endif; ?>
      <input type="file" name="hero_image_file" accept="image/jpeg,image/png,image/webp"
        style="font-size:14px; margin-bottom:12px;">
      <div class="gs-edit-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:8px;">
        <div>
          <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:var(--color-text-muted,#888);">Bildposition</label>
          <select name="hero_image_position" style="width:100%; padding:8px 12px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:14px;">
            <option value="center" <?= ($page['hero_image_position'] ?? 'center') === 'center' ? 'selected' : '' ?>>Mitten</option>
            <option value="top" <?= ($page['hero_image_position'] ?? '') === 'top' ? 'selected' : '' ?>>Topp</option>
            <option value="bottom" <?= ($page['hero_image_position'] ?? '') === 'bottom' ? 'selected' : '' ?>>Botten</option>
          </select>
        </div>
        <div>
          <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:var(--color-text-muted,#888);">Overlay-mörkhet (<?= $page['hero_overlay_opacity'] ?? 50 ?>%)</label>
          <input type="range" name="hero_overlay_opacity" min="0" max="80" value="<?= $page['hero_overlay_opacity'] ?? 50 ?>"
            style="width:100%;" oninput="this.previousElementSibling.textContent='Overlay-mörkhet (' + this.value + '%)'">
        </div>
      </div>
    </div>

    <!-- Settings -->
    <div style="background:var(--color-bg-card,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-md,10px); padding:20px; margin-bottom:16px;" class="gs-edit-card">
      <div class="gs-edit-grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px;">
        <div>
          <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary,#555);">Mall</label>
          <select name="template" style="width:100%; padding:10px 14px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:14px;">
            <option value="default" <?= $page['template'] === 'default' ? 'selected' : '' ?>>Standard</option>
            <option value="full-width" <?= $page['template'] === 'full-width' ? 'selected' : '' ?>>Helbredd</option>
            <option value="landing" <?= $page['template'] === 'landing' ? 'selected' : '' ?>>Landningssida</option>
          </select>
        </div>
        <div>
          <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary,#555);">Status</label>
          <select name="status" style="width:100%; padding:10px 14px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:14px;">
            <option value="draft" <?= $page['status'] === 'draft' ? 'selected' : '' ?>>Utkast</option>
            <option value="published" <?= $page['status'] === 'published' ? 'selected' : '' ?>>Publicerad</option>
          </select>
        </div>
        <div>
          <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary,#555);">Navigationsordning</label>
          <input type="number" name="nav_order" value="<?= (int)$page['nav_order'] ?>" min="0" max="999"
            style="width:100%; padding:10px 14px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:14px;">
        </div>
      </div>
      <div class="gs-edit-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:16px;">
        <div>
          <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary,#555);">Navigationslabel <span style="color:var(--color-text-muted,#aaa); font-weight:400;">(om tom: titel)</span></label>
          <input type="text" name="nav_label" value="<?= htmlspecialchars($page['nav_label'] ?? '') ?>" maxlength="60"
            style="width:100%; padding:10px 14px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:14px;"
            placeholder="T.ex. 'Om oss'">
        </div>
        <div style="display:flex; align-items:flex-end; padding-bottom:4px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px;">
            <input type="checkbox" name="show_in_nav" value="1" <?= $page['show_in_nav'] ? 'checked' : '' ?>
              style="width:18px; height:18px;">
            Visa i navigationen
          </label>
        </div>
      </div>
      <?php if (!empty($seriesBrands)): ?>
      <div style="margin-top:16px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary,#555);">
          Kopplad tävlingsserie <span style="color:var(--color-text-muted,#aaa); font-weight:400;">(valfritt)</span>
        </label>
        <select name="series_brand_id" id="seriesBrandSelect" style="width:100%; padding:10px 14px; border:1px solid var(--color-border,#ddd); border-radius:6px; font-size:14px; background:var(--color-bg-card,#fff); color:var(--color-text-primary,#333);"
          onchange="toggleSerieLogoSection()">
          <option value="">— Ingen serie —</option>
          <?php foreach ($seriesBrands as $sb): ?>
            <option value="<?= (int)$sb['id'] ?>" <?= ($page['series_brand_id'] ?? null) == $sb['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($sb['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>

    <!-- Serie Logo (only visible when series brand is selected) -->
    <?php
    // Load current serie logo from brand
    $currentSerieLogo = '';
    if (!empty($page['series_brand_id'])) {
        try {
            $logoStmt = $pdo->prepare("SELECT logo FROM series_brands WHERE id = ?");
            $logoStmt->execute([$page['series_brand_id']]);
            $currentSerieLogo = $logoStmt->fetchColumn() ?: '';
        } catch (PDOException $e) {}
    }
    ?>
    <div id="serieLogoSection" class="gs-edit-card" style="background:var(--color-bg-card,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-md,10px); padding:20px; margin-bottom:16px; <?= empty($page['series_brand_id']) ? 'display:none;' : '' ?>">
      <label style="display:block; font-size:13px; font-weight:600; margin-bottom:8px; color:var(--color-text-secondary,#555);">
        Serie-logotyp <span style="color:var(--color-text-muted,#aaa); font-weight:400;">(visas ovanför titeln på seriesidan)</span>
      </label>
      <?php if ($currentSerieLogo): ?>
        <div style="margin-bottom:12px; padding:16px; background:var(--color-bg-page,#f5f5f5); border-radius:6px; display:inline-block;">
          <img src="<?= htmlspecialchars($currentSerieLogo) ?>" alt="Serie-logotyp"
            style="max-height:80px; max-width:300px; width:auto; display:block;">
        </div>
        <div style="margin-bottom:12px;">
          <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--color-text-muted,#888); cursor:pointer;">
            <input type="checkbox" name="remove_serie_logo" value="1" style="width:16px; height:16px;"> Ta bort logotyp
          </label>
        </div>
      <?php endif; ?>
      <input type="file" name="serie_logo_file" accept="image/jpeg,image/png,image/webp,image/svg+xml"
        style="font-size:14px; color:var(--color-text-primary,#333);">
      <p style="margin:8px 0 0; font-size:12px; color:var(--color-text-muted,#888);">
        PNG med transparens rekommenderas. Max 600px bred (auto-skalas).
      </p>
    </div>

    <!-- Actions -->
    <div class="gs-edit-actions" style="display:flex; gap:12px; flex-wrap:wrap;">
      <button type="submit" style="background:var(--color-accent,#37d4d6); color:#000 !important; padding:12px 24px; border:none; border-radius:6px; font-size:15px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
        <i data-lucide="save" style="width:16px;height:16px;"></i> Spara
      </button>
      <button type="submit" name="save_preview" value="1" style="background:var(--color-bg-card,#fff); border:1px solid var(--color-border,#ddd); padding:12px 24px; border-radius:6px; font-size:15px; font-weight:600; cursor:pointer; color:var(--color-text-secondary,#555); display:flex; align-items:center; gap:6px;">
        <i data-lucide="external-link" style="width:16px;height:16px;"></i> Spara &amp; förhandsgranska
      </button>
    </div>
  </form>
</div>

<!-- TinyMCE -->
<script src="https://cdn.tiny.cloud/1/c596deswjoxxx0j9h1jw03pny7idcbcwtgowqh6jjgtn6xqk/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
  selector: '#tinymce-editor',
  height: 500,
  menubar: false,
  plugins: 'lists link image table code blockquote',
  toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link image blockquote | table | code',
  block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3',
  content_style: `
    @import url('https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;500;600&family=Barlow+Condensed:wght@400;600;700&family=Bebas+Neue&display=swap');
    body { font-family: 'Barlow', sans-serif; font-size: 18px; line-height: 1.7; color: #1e2420; max-width: 760px; margin: 0 auto; padding: 16px; }
    h2 { font-family: 'Barlow Condensed', sans-serif; font-size: 28px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; line-height: 1.15; margin-top: 32px; }
    h3 { font-family: 'Barlow Condensed', sans-serif; font-size: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-top: 24px; }
    a { color: #3fa84d; }
    blockquote { border-left: 3px solid #61CE70; padding: 12px 20px; background: rgba(97,206,112,.06); font-style: italic; }
  `,
  branding: false,
  promotion: false,
  license_key: 'gpl'
});

// Auto-generate slug from title
var slugEdited = <?= $isEdit ? 'true' : 'false' ?>;
function autoSlug(title) {
  if (slugEdited) return;
  var slug = title.toLowerCase()
    .replace(/[åä]/g, 'a').replace(/ö/g, 'o')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
  document.getElementById('slugInput').value = slug;
}
document.getElementById('slugInput').addEventListener('input', function() {
  slugEdited = true;
});

// Show/hide serie logo section based on brand dropdown
function toggleSerieLogoSection() {
  var sel = document.getElementById('seriesBrandSelect');
  var sec = document.getElementById('serieLogoSection');
  if (sel && sec) {
    sec.style.display = sel.value ? '' : 'none';
  }
}
</script>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
