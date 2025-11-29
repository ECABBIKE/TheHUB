<?php
/**
 * Debug session state
 * Access via: /debug-session.php
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Debug - TheHUB</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        h1 { color: #004a98; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        th { background: #f5f5f5; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Session Debug</h1>

    <h2>Session Status</h2>
    <table>
        <tr>
            <th>Property</th>
            <th>Value</th>
        </tr>
        <tr>
            <td>Session Status</td>
            <td><?= session_status() === PHP_SESSION_ACTIVE ? '<span class="success">ACTIVE</span>' : '<span class="error">NOT ACTIVE</span>' ?></td>
        </tr>
        <tr>
            <td>Session ID</td>
            <td><?= session_id() ?: '<span class="error">None</span>' ?></td>
        </tr>
        <tr>
            <td>Session Name</td>
            <td><?= session_name() ?></td>
        </tr>
    </table>

    <h2>V3 Session Variables (hub_*)</h2>
    <table>
        <tr>
            <th>Variable</th>
            <th>Value</th>
        </tr>
        <tr>
            <td>hub_user_id</td>
            <td><?= isset($_SESSION['hub_user_id']) ? htmlspecialchars($_SESSION['hub_user_id']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>hub_user_email</td>
            <td><?= isset($_SESSION['hub_user_email']) ? htmlspecialchars($_SESSION['hub_user_email']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>hub_user_name</td>
            <td><?= isset($_SESSION['hub_user_name']) ? htmlspecialchars($_SESSION['hub_user_name']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>hub_user_role</td>
            <td><?= isset($_SESSION['hub_user_role']) ? htmlspecialchars($_SESSION['hub_user_role']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>hub_logged_in_at</td>
            <td><?= isset($_SESSION['hub_logged_in_at']) ? date('Y-m-d H:i:s', $_SESSION['hub_logged_in_at']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>hub_is_admin</td>
            <td><?= isset($_SESSION['hub_is_admin']) ? ($_SESSION['hub_is_admin'] ? 'true' : 'false') : '<span class="error">NOT SET</span>' ?></td>
        </tr>
    </table>

    <h2>Admin Session Variables (admin_*)</h2>
    <table>
        <tr>
            <th>Variable</th>
            <th>Value</th>
        </tr>
        <tr>
            <td>admin_logged_in</td>
            <td><?= isset($_SESSION['admin_logged_in']) ? ($_SESSION['admin_logged_in'] ? '<span class="success">true</span>' : 'false') : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>admin_id</td>
            <td><?= isset($_SESSION['admin_id']) ? htmlspecialchars($_SESSION['admin_id']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>admin_username</td>
            <td><?= isset($_SESSION['admin_username']) ? htmlspecialchars($_SESSION['admin_username']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>admin_role</td>
            <td><?= isset($_SESSION['admin_role']) ? htmlspecialchars($_SESSION['admin_role']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>admin_name</td>
            <td><?= isset($_SESSION['admin_name']) ? htmlspecialchars($_SESSION['admin_name']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
    </table>

    <h2>V2 Session Variables (rider_*)</h2>
    <table>
        <tr>
            <th>Variable</th>
            <th>Value</th>
        </tr>
        <tr>
            <td>rider_id</td>
            <td><?= isset($_SESSION['rider_id']) ? htmlspecialchars($_SESSION['rider_id']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
        <tr>
            <td>rider_email</td>
            <td><?= isset($_SESSION['rider_email']) ? htmlspecialchars($_SESSION['rider_email']) : '<span class="error">NOT SET</span>' ?></td>
        </tr>
    </table>

    <h2>Authentication Functions</h2>
    <?php
    require_once __DIR__ . '/v3-config.php';
    ?>
    <table>
        <tr>
            <th>Function</th>
            <th>Result</th>
        </tr>
        <tr>
            <td>hub_is_logged_in()</td>
            <td><?= hub_is_logged_in() ? '<span class="success">true</span>' : '<span class="error">false</span>' ?></td>
        </tr>
        <tr>
            <td>hub_is_admin()</td>
            <td><?= hub_is_admin() ? '<span class="success">true</span>' : '<span class="error">false</span>' ?></td>
        </tr>
        <tr>
            <td>isLoggedIn() (admin)</td>
            <td><?= isLoggedIn() ? '<span class="success">true</span>' : '<span class="error">false</span>' ?></td>
        </tr>
        <tr>
            <td>hasRole('admin')</td>
            <td><?= hasRole('admin') ? '<span class="success">true</span>' : '<span class="error">false</span>' ?></td>
        </tr>
        <tr>
            <td>hasRole('super_admin')</td>
            <td><?= hasRole('super_admin') ? '<span class="success">true</span>' : '<span class="error">false</span>' ?></td>
        </tr>
    </table>

    <h2>Raw Session Data</h2>
    <pre><?= htmlspecialchars(print_r($_SESSION, true)) ?></pre>

    <h2>Actions</h2>
    <p>
        <a href="/login">Go to Login</a> |
        <a href="/logout">Logout</a> |
        <a href="/admin/dashboard.php">Admin Dashboard</a> |
        <a href="/calendar">Calendar</a>
    </p>
</body>
</html>
