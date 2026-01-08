<?php
/**
 * TheHUB On-Site Registration
 * Allows promotors to register participants at the event venue
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Allow promotors and admins
if (!hasRole('promotor')) {
    header('Location: /login');
    exit;
}

$pageTitle = 'Direktanmälan';
include __DIR__ . '/../includes/admin-header.php';

// Get user's assigned events (for promotors) or all upcoming events (for admins)
$userId = $_SESSION['user_id'] ?? 0;
$isPromotorOnly = isRole('promotor') && !hasRole('admin');

$upcomingEvents = [];
try {
    if ($isPromotorOnly) {
        // Get promotor's assigned events that haven't happened yet
        $stmt = $pdo->prepare("
            SELECT e.id, e.name, e.date, e.location, s.name as series_name
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            JOIN promotor_series ps ON e.series_id = ps.series_id
            WHERE ps.user_id = ? AND e.date >= CURDATE()
            ORDER BY e.date ASC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
    } else {
        // Admins see all upcoming events
        $stmt = $pdo->query("
            SELECT e.id, e.name, e.date, e.location, s.name as series_name
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            WHERE e.date >= CURDATE()
            ORDER BY e.date ASC
            LIMIT 20
        ");
    }
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching events for on-site registration: " . $e->getMessage());
}
?>

<div class="admin-content">
    <div class="page-header">
        <h1><?= $pageTitle ?></h1>
        <p class="text-muted">Registrera deltagare direkt på tävlingsplatsen</p>
    </div>

    <?php if (empty($upcomingEvents)): ?>
    <div class="card">
        <div class="card-body">
            <p class="text-muted">Inga kommande tävlingar att visa.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header">
            <h3>Välj tävling</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Kommande tävlingar</label>
                <select class="form-select" id="event-select">
                    <option value="">-- Välj tävling --</option>
                    <?php foreach ($upcomingEvents as $event): ?>
                    <option value="<?= $event['id'] ?>">
                        <?= htmlspecialchars($event['name']) ?>
                        (<?= date('d M Y', strtotime($event['date'])) ?>)
                        <?= $event['series_name'] ? ' - ' . htmlspecialchars($event['series_name']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card" id="registration-form" style="display: none;">
        <div class="card-header">
            <h3>Registrera deltagare</h3>
        </div>
        <div class="card-body">
            <p class="alert alert-info">
                Denna funktion är under utveckling. Här kommer du kunna:
            </p>
            <ul style="margin-left: var(--space-lg); color: var(--color-text-secondary);">
                <li>Söka efter befintliga deltagare i databasen</li>
                <li>Registrera nya deltagare direkt på plats</li>
                <li>Hantera betalning via Swish</li>
                <li>Skriva ut startnummer</li>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('event-select')?.addEventListener('change', function() {
    const form = document.getElementById('registration-form');
    if (this.value) {
        form.style.display = 'block';
    } else {
        form.style.display = 'none';
    }
});
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
