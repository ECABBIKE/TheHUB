<?php
/**
 * STANDALONE Migration Runner
 * Works without admin login requirement
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Minimal database connection
define('DB_HOST', 'sql111.infinityfree.me');
define('DB_NAME', 'if0_37997459_thehub');
define('DB_USER', 'if0_37997459');
define('DB_PASS', 'cXtRDYO0cQ7HL');

$pdo = null;
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$success = [];
$errors = [];

// Migration steps
$migrations = [
    "ALTER TABLE riders ADD COLUMN personnummer VARCHAR(15) AFTER birth_year" => "Add personnummer field",
    "ALTER TABLE riders ADD COLUMN address VARCHAR(255) AFTER city" => "Add address field",
    "ALTER TABLE riders ADD COLUMN postal_code VARCHAR(10) AFTER address" => "Add postal_code field",
    "ALTER TABLE riders ADD COLUMN country VARCHAR(100) DEFAULT 'Sverige' AFTER postal_code" => "Add country field",
    "ALTER TABLE riders ADD COLUMN emergency_contact VARCHAR(255) AFTER phone" => "Add emergency_contact field",
    "ALTER TABLE riders ADD COLUMN district VARCHAR(100) AFTER country" => "Add district field",
    "ALTER TABLE riders ADD COLUMN team VARCHAR(255) AFTER club_id" => "Add team field",
    "ALTER TABLE riders ADD COLUMN disciplines JSON AFTER discipline" => "Add disciplines JSON field",
    "ALTER TABLE riders ADD COLUMN license_year INT AFTER license_valid_until" => "Add license_year field",
];

// Run migrations
foreach ($migrations as $sql => $description) {
    try {
        $pdo->exec($sql);
        $success[] = $description;
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
            $success[] = $description . " (already exists)";
        } else {
            $errors[] = $description . ": " . $e->getMessage();
        }
    }
}

// Add indexes
$indexes = [
    "ALTER TABLE riders ADD INDEX idx_personnummer (personnummer)" => "Add personnummer index",
    "ALTER TABLE riders ADD INDEX idx_postal_code (postal_code)" => "Add postal_code index",
    "ALTER TABLE riders ADD INDEX idx_district (district)" => "Add district index",
];

foreach ($indexes as $sql => $description) {
    try {
        $pdo->exec($sql);
        $success[] = $description;
    } catch (PDOException $e) {
        if ($e->getCode() == '42000' || strpos($e->getMessage(), 'Duplicate key') !== false) {
            $success[] = $description . " (already exists)";
        } else {
            $errors[] = $description . ": " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration Results</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .content {
            padding: 30px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h2 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #2d3748;
        }
        .success-box {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .error-box {
            background: #fee;
            border-left: 4px solid #ef4444;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .item {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        .item:last-child {
            border-bottom: none;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat {
            background: #f3f4f6;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            margin-top: 5px;
        }
        .btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #5568d3;
        }
        .footer {
            background: #f9fafb;
            padding: 20px 30px;
            text-align: center;
            font-size: 14px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Migration Results</h1>
            <p>Extended Rider Fields Migration</p>
        </div>

        <div class="content">
            <div class="stats">
                <div class="stat">
                    <div class="stat-number"><?= count($success) ?></div>
                    <div class="stat-label">Successful</div>
                </div>
                <div class="stat">
                    <div class="stat-number"><?= count($errors) ?></div>
                    <div class="stat-label">Errors</div>
                </div>
                <div class="stat">
                    <div class="stat-number"><?= count($migrations) + count($indexes) ?></div>
                    <div class="stat-label">Total Steps</div>
                </div>
            </div>

            <?php if (!empty($success)): ?>
                <div class="section">
                    <h2>✅ Successful Operations</h2>
                    <div class="success-box">
                        <?php foreach ($success as $item): ?>
                            <div class="item">✓ <?= htmlspecialchars($item) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="section">
                    <h2>❌ Errors</h2>
                    <div class="error-box">
                        <?php foreach ($errors as $error): ?>
                            <div class="item">✗ <?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (count($errors) === 0): ?>
                <div style="text-align: center; padding: 20px;">
                    <h3 style="color: #10b981; margin-bottom: 15px;">🎉 Migration Complete!</h3>
                    <p style="color: #6b7280; margin-bottom: 20px;">
                        All database fields have been added successfully.
                    </p>
                    <a href="/admin/import-riders-extended.php" class="btn">
                        Go to Extended Import
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            Migration completed at <?= date('Y-m-d H:i:s') ?>
        </div>
    </div>
</body>
</html>
