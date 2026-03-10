<?php
/**
 * Admin — Ta bort sida (POST endpoint)
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/pages/');
    exit;
}

// CSRF check
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['gs_flash'] = ['type' => 'error', 'message' => 'Ogiltig CSRF-token.'];
    header('Location: /admin/pages/');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['gs_flash'] = ['type' => 'error', 'message' => 'Ogiltigt sid-ID.'];
    header('Location: /admin/pages/');
    exit;
}

try {
    global $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    // Get page title for message
    $stmt = $pdo->prepare("SELECT title, hero_image FROM pages WHERE id = ?");
    $stmt->execute([$id]);
    $page = $stmt->fetch();

    if (!$page) {
        $_SESSION['gs_flash'] = ['type' => 'error', 'message' => 'Sidan hittades inte.'];
        header('Location: /admin/pages/');
        exit;
    }

    // Delete hero image file if exists
    if (!empty($page['hero_image'])) {
        $filePath = __DIR__ . '/../../' . ltrim($page['hero_image'], '/');
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // Delete page
    $stmt = $pdo->prepare("DELETE FROM pages WHERE id = ?");
    $stmt->execute([$id]);

    $_SESSION['gs_flash'] = ['type' => 'success', 'message' => 'Sidan "' . $page['title'] . '" har tagits bort.'];
} catch (PDOException $e) {
    $_SESSION['gs_flash'] = ['type' => 'error', 'message' => 'Kunde inte ta bort sidan: ' . $e->getMessage()];
}

header('Location: /admin/pages/');
exit;
