# TheHUB V3.0 – KOMPLETT PROMPT
## Del 1: Översikt, Struktur & Konfiguration

---

# ÖVERSIKT

Du bygger en komplett PWA för TheHUB – Sveriges gravity cycling platform.

**URL:** https://thehub.gravityseries.se/v3/

## Navigation (5 ikoner)

```
┌──────────────────────────────────────────────┐
│              [TheHUB Logo] → Hem             │
├──────────────────────────────────────────────┤
│                                              │
│              Sidinnehåll                     │
│                                              │
├──────────────────────────────────────────────┤
│   📅      🏁       🗂️       📊       👤     │
│ Kalender Resultat Databas Ranking   Mitt    │
└──────────────────────────────────────────────┘
```

## Sidorna

### 📅 Kalender
- Kommande event med filter (månad, serie, format)
- Event-sidor med info, anmälda, anmälan
- Anmäl dig själv, barn, eller vem som helst
- Multi-person checkout via WooCommerce popup

### 🏁 Resultat  
- Avklarade tävlingar
- Event-resultat (heat, totalt)
- Serietabeller per serie/säsong

### 🗂️ Databas
- Separata flikar: Åkare | Klubbar
- Live-sök (resultat medan du skriver, ingen sök-knapp)
- Åkarprofiler och klubbsidor

### 📊 Ranking
- 24 månaders rullande ranking
- Poängförklaring med alla kriterier
- Individual-, team-, event-ranking
- Grafer och statistik

### 👤 Mitt (Min Sida)
- Din profil (editera)
- Kopplade barn (under 18)
- Klubb-admin (om tilldelad)
- Dina anmälningar
- Dina resultat  
- Dina kvitton

---

# FILSTRUKTUR

```
/v3/
├── index.php
├── router.php
├── config.php
├── .htaccess
├── manifest.json
├── sw.js
├── offline.html
│
├── includes/
│   ├── db.php                   # Länk till V2 databas
│   ├── auth.php                 # Authentication
│   ├── functions.php            # Hjälpfunktioner
│   └── validation.php           # V2 validering (länk)
│
├── assets/
│   ├── css/
│   │   ├── reset.css
│   │   ├── tokens.css
│   │   ├── theme.css
│   │   ├── layout.css
│   │   ├── components.css
│   │   ├── navigation.css
│   │   ├── calendar.css
│   │   ├── results.css
│   │   ├── database.css
│   │   ├── ranking.css
│   │   ├── profile.css
│   │   ├── registration.css
│   │   ├── pwa.css
│   │   └── utilities.css
│   │
│   ├── js/
│   │   ├── app.js
│   │   ├── router.js
│   │   ├── theme.js
│   │   ├── search.js
│   │   ├── calendar.js
│   │   ├── registration.js
│   │   ├── ranking.js
│   │   ├── woocommerce.js
│   │   └── pwa.js
│   │
│   └── icons/
│
├── components/
│   ├── head.php
│   ├── header.php
│   ├── nav-bottom.php
│   ├── footer.php
│   ├── search-live.php
│   ├── event-card.php
│   ├── rider-card.php
│   ├── club-card.php
│   ├── result-table.php
│   ├── ranking-table.php
│   ├── points-breakdown.php
│   ├── participant-picker.php
│   └── woocommerce-modal.php
│
├── pages/
│   ├── dashboard.php
│   │
│   ├── calendar/
│   │   ├── index.php
│   │   └── event.php
│   │
│   ├── results/
│   │   ├── index.php
│   │   ├── event.php
│   │   └── series.php
│   │
│   ├── database/
│   │   ├── index.php
│   │   ├── rider.php
│   │   └── club.php
│   │
│   ├── ranking/
│   │   ├── index.php
│   │   ├── riders.php
│   │   ├── clubs.php
│   │   └── events.php
│   │
│   ├── profile/
│   │   ├── index.php
│   │   ├── edit.php
│   │   ├── children.php
│   │   ├── club-admin.php
│   │   ├── registrations.php
│   │   ├── results.php
│   │   ├── receipts.php
│   │   └── login.php
│   │
│   └── 404.php
│
└── api/
    ├── search.php
    ├── calendar.php
    ├── registration.php
    ├── profile.php
    └── ranking.php
```

---

# KONFIGURATION

## /v3/config.php

```php
<?php
/**
 * TheHUB V3 Configuration
 */

define('HUB_VERSION', '3.0.0');
define('CSS_VERSION', '3.0.0');
define('JS_VERSION', '3.0.0');

define('HUB_V3_ROOT', __DIR__);
define('HUB_V3_URL', '/v3');
define('HUB_V2_ROOT', dirname(__DIR__));

// WooCommerce
define('WC_CHECKOUT_URL', '/checkout');

// Navigation
define('HUB_NAV', [
    ['id' => 'calendar', 'label' => 'Kalender', 'icon' => 'calendar', 'url' => '/v3/calendar'],
    ['id' => 'results', 'label' => 'Resultat', 'icon' => 'flag', 'url' => '/v3/results'],
    ['id' => 'database', 'label' => 'Databas', 'icon' => 'search', 'url' => '/v3/database'],
    ['id' => 'ranking', 'label' => 'Ranking', 'icon' => 'trending', 'url' => '/v3/ranking'],
    ['id' => 'profile', 'label' => 'Mitt', 'icon' => 'user', 'url' => '/v3/profile']
]);

function hub_get_theme(): string {
    $theme = $_COOKIE['hub_theme'] ?? 'auto';
    return in_array($theme, ['light', 'dark', 'auto']) ? $theme : 'auto';
}

function hub_asset(string $path): string {
    $version = strpos($path, '.css') !== false ? CSS_VERSION : JS_VERSION;
    return HUB_V3_URL . '/assets/' . $path . '?v=' . $version;
}

// Database - koppla till V2
function hub_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        require_once HUB_V2_ROOT . '/includes/db.php';
        $pdo = get_db_connection();
    }
    return $pdo;
}

// Auth - koppla till WooCommerce/WordPress
function hub_is_logged_in(): bool {
    if (function_exists('is_user_logged_in')) {
        return is_user_logged_in();
    }
    return isset($_SESSION['hub_user_id']);
}

function hub_current_user(): ?array {
    if (!hub_is_logged_in()) return null;
    
    if (function_exists('wp_get_current_user')) {
        $wp_user = wp_get_current_user();
        return hub_get_rider_by_email($wp_user->user_email);
    }
    
    return isset($_SESSION['hub_user_id']) 
        ? hub_get_rider_by_id($_SESSION['hub_user_id']) 
        : null;
}

function hub_get_rider_by_id(int $id): ?array {
    $stmt = hub_db()->prepare("SELECT * FROM riders WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hub_get_rider_by_email(string $email): ?array {
    $stmt = hub_db()->prepare("SELECT * FROM riders WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Parent/Child relationship
function hub_is_parent_of(int $parentId, int $childId): bool {
    $stmt = hub_db()->prepare("SELECT 1 FROM rider_parents WHERE parent_rider_id = ? AND child_rider_id = ?");
    $stmt->execute([$parentId, $childId]);
    return (bool) $stmt->fetch();
}

function hub_get_linked_children(int $parentId): array {
    $stmt = hub_db()->prepare("
        SELECT r.* FROM riders r
        JOIN rider_parents rp ON r.id = rp.child_rider_id
        WHERE rp.parent_rider_id = ?
    ");
    $stmt->execute([$parentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hub_can_edit_profile(int $profileId): bool {
    $user = hub_current_user();
    if (!$user) return false;
    if ($user['id'] === $profileId) return true;
    if (hub_is_parent_of($user['id'], $profileId)) return true;
    return false;
}

// Club admin
function hub_can_edit_club(int $clubId): bool {
    $user = hub_current_user();
    if (!$user) return false;
    
    $stmt = hub_db()->prepare("SELECT 1 FROM club_admins WHERE rider_id = ? AND club_id = ?");
    $stmt->execute([$user['id'], $clubId]);
    return (bool) $stmt->fetch();
}

function hub_get_admin_clubs(int $riderId): array {
    $stmt = hub_db()->prepare("
        SELECT c.* FROM clubs c
        JOIN club_admins ca ON c.id = ca.club_id
        WHERE ca.rider_id = ?
    ");
    $stmt->execute([$riderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

---

## /v3/router.php

```php
<?php
require_once __DIR__ . '/config.php';

function hub_parse_route(): array {
    $path = trim($_GET['route'] ?? '', '/');
    
    if ($path === '' || $path === 'index.php') {
        return [
            'section' => 'dashboard',
            'page' => 'index',
            'id' => null,
            'file' => HUB_V3_ROOT . '/pages/dashboard.php'
        ];
    }
    
    $segments = explode('/', $path);
    $section = $segments[0] ?? 'dashboard';
    $page = $segments[1] ?? 'index';
    $id = $segments[2] ?? null;
    
    // Om andra segmentet är numeriskt, är det ett ID
    if (isset($segments[1]) && is_numeric($segments[1])) {
        $id = $segments[1];
        $detailPages = [
            'calendar' => 'event',
            'results' => 'event',
            'database' => 'rider',
            'ranking' => 'riders'
        ];
        $page = $detailPages[$section] ?? 'index';
    }
    
    $routes = [
        'calendar' => ['index' => '/pages/calendar/index.php', 'event' => '/pages/calendar/event.php'],
        'results' => ['index' => '/pages/results/index.php', 'event' => '/pages/results/event.php', 'series' => '/pages/results/series.php'],
        'database' => ['index' => '/pages/database/index.php', 'rider' => '/pages/database/rider.php', 'club' => '/pages/database/club.php'],
        'ranking' => ['index' => '/pages/ranking/index.php', 'riders' => '/pages/ranking/riders.php', 'clubs' => '/pages/ranking/clubs.php', 'events' => '/pages/ranking/events.php'],
        'profile' => ['index' => '/pages/profile/index.php', 'edit' => '/pages/profile/edit.php', 'children' => '/pages/profile/children.php', 'club-admin' => '/pages/profile/club-admin.php', 'registrations' => '/pages/profile/registrations.php', 'results' => '/pages/profile/results.php', 'receipts' => '/pages/profile/receipts.php', 'login' => '/pages/profile/login.php']
    ];
    
    $file = HUB_V3_ROOT . '/pages/404.php';
    if (isset($routes[$section][$page])) {
        $file = HUB_V3_ROOT . $routes[$section][$page];
    } elseif (isset($routes[$section]['index'])) {
        $file = HUB_V3_ROOT . $routes[$section]['index'];
    }
    
    return compact('section', 'page', 'id', 'file');
}

function hub_is_ajax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function hub_is_section_active(string $sectionId): bool {
    global $route;
    return ($route['section'] ?? '') === $sectionId;
}
```

---

## /v3/.htaccess

```apache
RewriteEngine On
RewriteBase /v3/

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]

<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/svg+xml "access plus 1 month"
</IfModule>

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json
</IfModule>
```

---

## /v3/index.php

```php
<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/router.php';

$route = hub_parse_route();
$theme = hub_get_theme();
$isLoggedIn = hub_is_logged_in();
$currentUser = hub_current_user();

if (hub_is_ajax()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Page-Title: TheHUB – ' . ucfirst($route['section']));
    
    if (file_exists($route['file'])) {
        include $route['file'];
    } else {
        include HUB_V3_ROOT . '/pages/404.php';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="sv" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <?php include __DIR__ . '/components/head.php'; ?>
</head>
<body class="<?= $isLoggedIn ? 'logged-in' : 'logged-out' ?>">
    
    <a href="#main-content" class="skip-link">Hoppa till huvudinnehåll</a>
    
    <?php include __DIR__ . '/components/header.php'; ?>
    
    <main id="main-content" class="main-content" role="main" tabindex="-1">
        <div id="page-content" class="page-content">
            <?php
            if (file_exists($route['file'])) {
                include $route['file'];
            } else {
                include HUB_V3_ROOT . '/pages/404.php';
            }
            ?>
        </div>
    </main>
    
    <?php include __DIR__ . '/components/nav-bottom.php'; ?>
    <?php include __DIR__ . '/components/footer.php'; ?>
    <?php include __DIR__ . '/components/woocommerce-modal.php'; ?>
    
    <script src="<?= hub_asset('js/app.js') ?>"></script>
    <script src="<?= hub_asset('js/router.js') ?>"></script>
    <script src="<?= hub_asset('js/theme.js') ?>"></script>
    <script src="<?= hub_asset('js/search.js') ?>"></script>
    <script src="<?= hub_asset('js/registration.js') ?>"></script>
    <script src="<?= hub_asset('js/ranking.js') ?>"></script>
    <script src="<?= hub_asset('js/woocommerce.js') ?>"></script>
    <script src="<?= hub_asset('js/pwa.js') ?>"></script>
</body>
</html>
```
