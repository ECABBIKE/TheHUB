<?php
/**
 * Simple Reset Tool - No fancy layout
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_admin();

if (!hasRole('super_admin')) {
    die('Endast superadmin');
}

$db = getDB();
$message = '';
$error = '';

// Handle reset
if ($_POST['confirm'] ?? '' === 'RADERA ALLT') {
    try {
        $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        $tables = [
            'ranking_snapshots',
            'club_ranking_snapshots',
            'ranking_history',
            'series_results',
            'rider_club_seasons',
            'rider_parents',
            'rider_claims',
            'results',
            'registrations',
            'club_points',
            'club_points_riders',
            'riders',
            'clubs',
        ];

        foreach ($tables as $t) {
            try {
                $db->pdo->exec("DELETE FROM `$t`");
                $db->pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
            } catch (Exception $e) {
                // skip
            }
        }

        $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        $message = 'Data raderad!';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Count
$riders = $db->getRow("SELECT COUNT(*) as c FROM riders")['c'] ?? 0;
$clubs = $db->getRow("SELECT COUNT(*) as c FROM clubs")['c'] ?? 0;
$results = $db->getRow("SELECT COUNT(*) as c FROM results")['c'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Data</title>
    <style>
        body { font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; }
        .danger { color: red; }
        .success { color: green; }
        input[type=text] { padding: 10px; font-size: 16px; width: 200px; }
        button { padding: 10px 20px; font-size: 16px; background: red; color: white; border: none; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
    <h1>Reset Data</h1>

    <?php if ($message): ?>
        <p class="success"><strong><?= $message ?></strong></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="danger"><strong>Fel: <?= $error ?></strong></p>
    <?php endif; ?>

    <h2>Nuvarande data:</h2>
    <table>
        <tr><td>Åkare</td><td><strong><?= number_format($riders) ?></strong></td></tr>
        <tr><td>Klubbar</td><td><strong><?= number_format($clubs) ?></strong></td></tr>
        <tr><td>Resultat</td><td><strong><?= number_format($results) ?></strong></td></tr>
    </table>

    <?php if ($riders > 0 || $clubs > 0): ?>
    <h2 class="danger">Radera all data</h2>
    <p>Skriv <strong>RADERA ALLT</strong> för att bekräfta:</p>
    <form method="POST">
        <input type="text" name="confirm" placeholder="Skriv här...">
        <button type="submit">Radera</button>
    </form>
    <?php else: ?>
    <p class="success">Databasen är tom - redo för import!</p>
    <?php endif; ?>

    <p><a href="/admin/import.php">Gå till Import</a></p>
</body>
</html>
