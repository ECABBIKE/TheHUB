<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .info { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; }
        .good { color: green; }
        .bad { color: red; }
        h2 { margin-top: 0; }
        pre { background: #eee; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>TheHUB Session Debug</h1>

    <div class="info">
        <h2>Session Status</h2>
        <p><strong>Session Status:</strong> <?= session_status() === PHP_SESSION_ACTIVE ? '<span class="good">ACTIVE</span>' : '<span class="bad">INACTIVE</span>' ?></p>
        <p><strong>Session Name:</strong> <?= session_name() ?></p>
        <p><strong>Session ID:</strong> <?= session_id() ?></p>
        <p><strong>Cookie Params:</strong></p>
        <pre><?php print_r(session_get_cookie_params()); ?></pre>
    </div>

    <div class="info">
        <h2>Login Status</h2>
        <p><strong>Is Logged In:</strong> <?= isLoggedIn() ? '<span class="good">YES</span>' : '<span class="bad">NO</span>' ?></p>
        <p><strong>Admin Logged In Flag:</strong> <?= isset($_SESSION['admin_logged_in']) ? ($_SESSION['admin_logged_in'] ? 'true' : 'false') : 'not set' ?></p>
    </div>

    <div class="info">
        <h2>Session Data</h2>
        <pre><?php print_r($_SESSION); ?></pre>
    </div>

    <div class="info">
        <h2>Cookie Data</h2>
        <pre><?php print_r($_COOKIE); ?></pre>
    </div>

    <div class="info">
        <h2>Database Connection</h2>
        <?php
        $db = getDB();
        $conn = $db->getConnection();
        ?>
        <p><strong>Database:</strong> <?= $conn === null ? '<span class="bad">NULL (Demo Mode)</span>' : '<span class="good">Connected</span>' ?></p>
    </div>

    <div class="info">
        <h2>Quick Actions</h2>
        <p><a href="/admin/login.php">→ Go to Login</a></p>
        <p><a href="/admin/dashboard.php">→ Go to Dashboard</a></p>
        <p><a href="/admin/logout.php">→ Logout</a></p>
        <form method="POST" action="/admin/login.php" class="gs-form-mt-5">
            <input type="hidden" name="username" value="admin">
            <input type="hidden" name="password" value="admin">
            <button type="submit">Quick Login (admin/admin)</button>
        </form>
    </div>
</body>
</html>
