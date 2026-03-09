<?php
/**
 * Festival Diagnostik
 * Testar att alla delar fungerar: JS, GlobalCart, API, databas
 */
require_once __DIR__ . '/../../config.php';
require_admin();

$pageTitle = 'Festival - Diagnostik';
$currentPage = 'tools';
include __DIR__ . '/../components/unified-layout.php';

global $pdo;

// Kolla tabeller
$tables = ['festivals', 'festival_activities', 'festival_events', 'festival_activity_registrations', 'festival_passes', 'festival_activity_slots', 'festival_activity_groups'];
$tableStatus = [];
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        $tableStatus[$t] = ['ok' => true, 'count' => $count];
    } catch (PDOException $e) {
        $tableStatus[$t] = ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Kolla kolumner
$columns = [
    'festival_activities.pass_included_count' => "SELECT pass_included_count FROM festival_activities LIMIT 0",
    'festival_activities.instructor_rider_id' => "SELECT instructor_rider_id FROM festival_activities LIMIT 0",
    'festival_activities.group_id' => "SELECT group_id FROM festival_activities LIMIT 0",
    'festival_activity_registrations.pass_discount' => "SELECT pass_discount FROM festival_activity_registrations LIMIT 0",
    'festival_activity_registrations.slot_id' => "SELECT slot_id FROM festival_activity_registrations LIMIT 0",
    'festival_events.included_in_pass' => "SELECT included_in_pass FROM festival_events LIMIT 0",
];
$colStatus = [];
foreach ($columns as $col => $sql) {
    try {
        $pdo->query($sql);
        $colStatus[$col] = true;
    } catch (PDOException $e) {
        $colStatus[$col] = false;
    }
}

// Hämta festivaler
$festivals = [];
try {
    $festivals = $pdo->query("SELECT id, name, status, pass_enabled, pass_price FROM festivals WHERE active = 1 ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Kolla site_setting
$festivalPublic = site_setting('festival_public_enabled', '0');

// Kolla om includes/header.php och includes/footer.php finns (borde INTE finnas)
$badFiles = [
    'includes/header.php' => file_exists(HUB_ROOT . '/includes/header.php'),
    'includes/footer.php' => file_exists(HUB_ROOT . '/includes/footer.php'),
];

// Kolla att festival-sidorna INTE inkluderar felaktiga filer
$festivalPages = [
    'pages/festival/show.php',
    'pages/festival/activity.php',
    'pages/festival/single-activity.php',
    'pages/festival/index.php',
];
$pageIncludes = [];
foreach ($festivalPages as $fp) {
    $content = file_get_contents(HUB_ROOT . '/' . $fp);
    $hasBadHeader = preg_match('/include.*includes\/header\.php/', $content);
    $hasBadFooter = preg_match('/include.*includes\/footer\.php/', $content);
    $pageIncludes[$fp] = [
        'bad_header' => (bool)$hasBadHeader,
        'bad_footer' => (bool)$hasBadFooter,
    ];
}

// Kolla global-cart.js
$globalCartExists = file_exists(HUB_ROOT . '/assets/js/global-cart.js');
$globalCartSize = $globalCartExists ? filesize(HUB_ROOT . '/assets/js/global-cart.js') : 0;

// Kolla components/footer.php laddar global-cart.js
$footerContent = file_get_contents(HUB_ROOT . '/components/footer.php');
$footerLoadsCart = strpos($footerContent, 'global-cart.js') !== false;
?>

<div class="admin-content">
    <div class="page-header">
        <h1><i data-lucide="bug"></i> Festival - Diagnostik</h1>
    </div>

    <!-- ============ JS + GLOBALCART TEST ============ -->
    <div class="admin-card">
        <div class="admin-card-header"><h3>1. JavaScript & GlobalCart</h3></div>
        <div class="admin-card-body">
            <div id="js-test-results" style="font-family: monospace; font-size: 0.85rem;">
                <div id="js-basic">JS: Laddar...</div>
                <div id="js-globalcart">GlobalCart: Laddar...</div>
                <div id="js-globalcart-methods">GlobalCart metoder: Laddar...</div>
                <div id="js-fetch">Fetch API: Laddar...</div>
                <div id="js-search-api">Search API: Laddar...</div>
            </div>

            <div style="margin-top: var(--space-md);">
                <strong>Testknapp:</strong><br>
                <button class="btn btn-primary" id="testCartBtn" onclick="testAddToCart()" style="margin-top: var(--space-xs);">
                    <i data-lucide="shopping-cart"></i> Testa GlobalCart.addItem()
                </button>
                <div id="cart-test-result" style="margin-top: var(--space-xs); font-family: monospace; font-size: 0.85rem;"></div>
            </div>
        </div>
    </div>

    <!-- ============ FILER ============ -->
    <div class="admin-card">
        <div class="admin-card-header"><h3>2. Filer & Includes</h3></div>
        <div class="admin-card-body" style="padding: 0;">
            <table class="table">
                <thead><tr><th>Kontroll</th><th>Status</th></tr></thead>
                <tbody>
                    <tr>
                        <td>global-cart.js finns</td>
                        <td><?= $globalCartExists ? '<span class="badge badge-success">OK</span> (' . number_format($globalCartSize) . ' bytes)' : '<span class="badge badge-danger">SAKNAS</span>' ?></td>
                    </tr>
                    <tr>
                        <td>components/footer.php laddar global-cart.js</td>
                        <td><?= $footerLoadsCart ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">SAKNAS</span>' ?></td>
                    </tr>
                    <?php foreach ($badFiles as $f => $exists): ?>
                    <tr>
                        <td><?= $f ?> (borde EJ finnas)</td>
                        <td><?= $exists ? '<span class="badge badge-danger">FINNS - kan orsaka problem</span>' : '<span class="badge badge-success">Finns inte (korrekt)</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============ FESTIVAL-SIDOR INCLUDES ============ -->
    <div class="admin-card">
        <div class="admin-card-header"><h3>3. Festivalsidor - Felaktiga includes</h3></div>
        <div class="admin-card-body" style="padding: 0;">
            <table class="table">
                <thead><tr><th>Fil</th><th>includes/header.php</th><th>includes/footer.php</th></tr></thead>
                <tbody>
                    <?php foreach ($pageIncludes as $fp => $checks): ?>
                    <tr>
                        <td style="font-size: 0.85rem;"><?= basename($fp) ?></td>
                        <td><?= $checks['bad_header'] ? '<span class="badge badge-danger">HITTAT - borde tas bort</span>' : '<span class="badge badge-success">Ren</span>' ?></td>
                        <td><?= $checks['bad_footer'] ? '<span class="badge badge-danger">HITTAT - borde tas bort</span>' : '<span class="badge badge-success">Ren</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============ DATABAS ============ -->
    <div class="admin-card">
        <div class="admin-card-header"><h3>4. Databas - Tabeller</h3></div>
        <div class="admin-card-body" style="padding: 0;">
            <table class="table">
                <thead><tr><th>Tabell</th><th>Status</th><th>Rader</th></tr></thead>
                <tbody>
                    <?php foreach ($tableStatus as $t => $s): ?>
                    <tr>
                        <td style="font-family: monospace; font-size: 0.85rem;"><?= $t ?></td>
                        <td><?= $s['ok'] ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">FEL</span>' ?></td>
                        <td><?= $s['ok'] ? $s['count'] : '<span style="color:var(--color-error);font-size:0.8rem;">' . htmlspecialchars($s['error']) . '</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============ KOLUMNER ============ -->
    <div class="admin-card">
        <div class="admin-card-header"><h3>5. Databas - Kolumner (migrationer)</h3></div>
        <div class="admin-card-body" style="padding: 0;">
            <table class="table">
                <thead><tr><th>Kolumn</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($colStatus as $col => $ok): ?>
                    <tr>
                        <td style="font-family: monospace; font-size: 0.85rem;"><?= $col ?></td>
                        <td><?= $ok ? '<span class="badge badge-success">Finns</span>' : '<span class="badge badge-danger">SAKNAS - kör migration</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============ INSTÄLLNINGAR ============ -->
    <div class="admin-card">
        <div class="admin-card-header"><h3>6. Inställningar</h3></div>
        <div class="admin-card-body">
            <div style="font-family: monospace; font-size: 0.85rem;">
                <div>festival_public_enabled: <strong><?= htmlspecialchars($festivalPublic) ?></strong> <?= $festivalPublic === '1' ? '<span class="badge badge-success">Publikt</span>' : '<span class="badge badge-warning">Bara admin</span>' ?></div>
                <div style="margin-top: var(--space-xs);">display_errors: <strong><?= ini_get('display_errors') ?></strong></div>
                <div>APP_ENV: <strong><?= defined('APP_ENV') ? APP_ENV : 'undefined' ?></strong></div>
            </div>
        </div>
    </div>

    <!-- ============ FESTIVALER ============ -->
    <?php if ($festivals): ?>
    <div class="admin-card">
        <div class="admin-card-header"><h3>7. Festivaler i databasen</h3></div>
        <div class="admin-card-body" style="padding: 0;">
            <table class="table">
                <thead><tr><th>ID</th><th>Namn</th><th>Status</th><th>Pass</th><th>Pris</th><th>Test</th></tr></thead>
                <tbody>
                    <?php foreach ($festivals as $f): ?>
                    <tr>
                        <td><?= $f['id'] ?></td>
                        <td><?= htmlspecialchars($f['name']) ?></td>
                        <td><span class="badge badge-<?= $f['status'] === 'published' ? 'success' : 'warning' ?>"><?= $f['status'] ?></span></td>
                        <td><?= $f['pass_enabled'] ? 'Ja' : 'Nej' ?></td>
                        <td><?= $f['pass_price'] ? $f['pass_price'] . ' kr' : '-' ?></td>
                        <td><a href="/festival/<?= $f['id'] ?>" target="_blank" style="color: var(--color-accent);">Visa</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============ PHP ERROR LOG ============ -->
    <div class="admin-card">
        <div class="admin-card-header"><h3>8. Senaste PHP-fel (festival-relaterade)</h3></div>
        <div class="admin-card-body">
            <?php
            $logFile = HUB_ROOT . '/logs/error.log';
            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $festivalErrors = [];
                foreach (array_reverse($lines) as $line) {
                    if (stripos($line, 'festival') !== false || stripos($line, 'global-cart') !== false || stripos($line, 'GlobalCart') !== false) {
                        $festivalErrors[] = $line;
                        if (count($festivalErrors) >= 20) break;
                    }
                }
                if ($festivalErrors): ?>
                <pre style="font-size: 0.75rem; max-height: 300px; overflow: auto; background: var(--color-bg-page); padding: var(--space-sm); border-radius: var(--radius-sm); white-space: pre-wrap; word-break: break-all;"><?php
                    foreach ($festivalErrors as $err) {
                        echo htmlspecialchars($err) . "\n";
                    }
                ?></pre>
                <?php else: ?>
                <p style="color: var(--color-text-muted);">Inga festival-relaterade fel i loggen.</p>
                <?php endif;
            } else { ?>
                <p style="color: var(--color-text-muted);">Loggfil saknas (<?= htmlspecialchars($logFile) ?>)</p>
            <?php } ?>
        </div>
    </div>

    <!-- ============ BROWSER CONSOLE LOG ============ -->
    <div class="admin-card">
        <div class="admin-card-header"><h3>9. Webbläsarens JS-konsol</h3></div>
        <div class="admin-card-body">
            <div id="console-log" style="font-family: monospace; font-size: 0.75rem; max-height: 200px; overflow: auto; background: var(--color-bg-page); padding: var(--space-sm); border-radius: var(--radius-sm);">
                <div style="color: var(--color-text-muted);">Fångar JS-fel...</div>
            </div>
        </div>
    </div>
</div>

<script>
// Fånga JS-fel
const consoleDiv = document.getElementById('console-log');
const origError = console.error;
const origWarn = console.warn;
window.addEventListener('error', function(e) {
    consoleDiv.innerHTML += '<div style="color:var(--color-error);">[ERROR] ' + e.message + ' (' + e.filename + ':' + e.lineno + ')</div>';
});
console.error = function() {
    origError.apply(console, arguments);
    consoleDiv.innerHTML += '<div style="color:var(--color-error);">[console.error] ' + Array.from(arguments).join(' ') + '</div>';
};
console.warn = function() {
    origWarn.apply(console, arguments);
    consoleDiv.innerHTML += '<div style="color:var(--color-warning);">[console.warn] ' + Array.from(arguments).join(' ') + '</div>';
};

// Test 1: JS basics
document.getElementById('js-basic').innerHTML = 'JS: <span class="badge badge-success">OK</span>';

// Test 2: GlobalCart
setTimeout(function() {
    const gcEl = document.getElementById('js-globalcart');
    const gmEl = document.getElementById('js-globalcart-methods');

    if (typeof GlobalCart !== 'undefined') {
        gcEl.innerHTML = 'GlobalCart: <span class="badge badge-success">Definierad</span>';

        const methods = ['addItem', 'getCart', 'removeItem', 'removeFestivalItem', 'getItemsByEvent', 'clearCart'];
        let missing = [];
        methods.forEach(m => {
            if (typeof GlobalCart[m] !== 'function') missing.push(m);
        });
        if (missing.length === 0) {
            gmEl.innerHTML = 'GlobalCart metoder: <span class="badge badge-success">Alla ' + methods.length + ' finns</span>';
        } else {
            gmEl.innerHTML = 'GlobalCart metoder: <span class="badge badge-danger">Saknas: ' + missing.join(', ') + '</span>';
        }
    } else {
        gcEl.innerHTML = 'GlobalCart: <span class="badge badge-danger">UNDEFINED - global-cart.js laddas inte!</span>';
        gmEl.innerHTML = 'GlobalCart metoder: <span class="badge badge-danger">Kan inte testa</span>';
    }

    // Test 3: Fetch
    document.getElementById('js-fetch').innerHTML = 'Fetch API: ' +
        (typeof fetch === 'function' ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">SAKNAS</span>');

    // Test 4: Search API
    fetch('/api/search.php?type=riders&filter=all&q=test&limit=1')
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            const count = (data.riders || data.results || []).length;
            document.getElementById('js-search-api').innerHTML = 'Search API: <span class="badge badge-success">OK</span> (returnerade ' + count + ' resultat för "test")';
        })
        .catch(err => {
            document.getElementById('js-search-api').innerHTML = 'Search API: <span class="badge badge-danger">FEL: ' + err.message + '</span>';
        });
}, 1500); // Vänta på att footer/scripts laddats

function testAddToCart() {
    const resultDiv = document.getElementById('cart-test-result');
    try {
        if (typeof GlobalCart === 'undefined') {
            resultDiv.innerHTML = '<span style="color:var(--color-error);">GlobalCart är UNDEFINED. global-cart.js har inte laddats.</span>';
            return;
        }
        const testItem = {
            type: 'festival_activity',
            activity_id: 99999,
            festival_id: 99999,
            rider_id: 1,
            rider_name: 'Test Testsson',
            activity_name: 'Diagnostik-test',
            festival_name: 'Test Festival',
            festival_date: '2026-01-01',
            price: 0,
            included_in_pass: false
        };
        GlobalCart.addItem(testItem);
        const cart = GlobalCart.getCart();
        const found = cart.find(i => i.activity_id === 99999 && i.type === 'festival_activity');
        if (found) {
            resultDiv.innerHTML = '<span style="color:var(--color-success);">GlobalCart.addItem() fungerar! Item tillagt. Rensar...</span>';
            // Ta bort testitem
            GlobalCart.removeFestivalItem(99999, 1);
            const cartAfter = GlobalCart.getCart();
            const stillThere = cartAfter.find(i => i.activity_id === 99999);
            if (!stillThere) {
                resultDiv.innerHTML += '<br><span style="color:var(--color-success);">GlobalCart.removeFestivalItem() fungerar! Item borttaget.</span>';
            } else {
                resultDiv.innerHTML += '<br><span style="color:var(--color-warning);">removeFestivalItem tog inte bort item (kolla metoden)</span>';
            }
        } else {
            resultDiv.innerHTML = '<span style="color:var(--color-error);">addItem() kördes utan fel men item hittades inte i cart!</span>';
        }
    } catch (e) {
        resultDiv.innerHTML = '<span style="color:var(--color-error);">FEL: ' + e.message + '</span>';
    }
}
</script>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
