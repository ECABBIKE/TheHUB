<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Event Ticketing Management
 * Configure ticketing settings for a specific event
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get event ID
$eventId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($eventId <= 0) {
    $_SESSION['flash_message'] = 'Välj ett event från ticketing-dashboarden';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/ticketing.php');
    exit;
}

// Fetch event
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);

if (!$event) {
    $_SESSION['flash_message'] = 'Event hittades inte';
    $_SESSION['flash_type'] = 'error';
    header('Location: /admin/ticketing.php');
    exit;
}

// Initialize message
$message = '';
$messageType = 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "DEBUG: POST received<br>";
    echo "DEBUG: Event ID = $eventId<br>";

    try {
        checkCsrf();
        echo "DEBUG: CSRF OK<br>";
    } catch (Exception $e) {
        die("CSRF ERROR: " . $e->getMessage());
    }

    $action = $_POST['action'] ?? '';
    echo "DEBUG: Action = $action<br>";

    if ($action === 'save_settings') {
        $enabled = isset($_POST['ticketing_enabled']) ? 1 : 0;
        $deadlineDays = intval($_POST['ticket_deadline_days'] ?? 7);
        $wooProductId = trim($_POST['woo_product_id'] ?? '');

        echo "DEBUG: enabled=$enabled, days=$deadlineDays, woo=$wooProductId<br>";

        try {
            $db->execute("
                UPDATE events
                SET ticketing_enabled = ?, ticket_deadline_days = ?, woo_product_id = ?
                WHERE id = ?
            ", [$enabled, $deadlineDays, $wooProductId ?: null, $eventId]);
            echo "DEBUG: Update OK<br>";
        } catch (Exception $e) {
            die("DB ERROR: " . $e->getMessage());
        }

        $event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);
        $message = 'Inställningar sparade!';
        $messageType = 'success';
    }
}

// Get classes for pricing
$classes = $db->getAll("SELECT id, name, display_name FROM classes ORDER BY sort_order ASC");

$pageTitle = 'Ticketing - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <div class="gs-flex gs-justify-between gs-items-center">
                    <div>
                        <h1 class="gs-h3">
                            <i data-lucide="ticket"></i>
                            <?= htmlspecialchars($event['name']) ?>
                        </h1>
                        <p class="gs-text-secondary gs-text-sm">
                            <?= date('d M Y', strtotime($event['date'])) ?> - <?= htmlspecialchars($event['location'] ?? '') ?>
                        </p>
                    </div>
                    <a href="/admin/ticketing.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="arrow-left"></i>
                        Tillbaka
                    </a>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Settings Form -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h5">
                    <i data-lucide="settings"></i>
                    Ticketing-inställningar
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_settings">

                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                        <!-- Enable Ticketing -->
                        <div class="gs-form-group">
                            <label class="gs-checkbox-label">
                                <input type="checkbox" name="ticketing_enabled" value="1"
                                    <?= ($event['ticketing_enabled'] ?? 0) ? 'checked' : '' ?>>
                                <span>Aktivera ticketing för detta event</span>
                            </label>
                        </div>

                        <!-- Deadline Days -->
                        <div class="gs-form-group">
                            <label class="gs-label">Anmälningsfrist (dagar före)</label>
                            <input type="number" name="ticket_deadline_days" class="gs-input"
                                value="<?= $event['ticket_deadline_days'] ?? 7 ?>" min="0" max="90">
                            <small class="gs-text-secondary">Antal dagar före event då anmälan stänger</small>
                        </div>

                        <!-- WooCommerce Product ID -->
                        <div class="gs-form-group gs-col-span-2">
                            <label class="gs-label">WooCommerce Product ID</label>
                            <input type="text" name="woo_product_id" class="gs-input"
                                value="<?= htmlspecialchars($event['woo_product_id'] ?? '') ?>"
                                placeholder="T.ex. 12345">
                            <small class="gs-text-secondary">
                                Product ID från WooCommerce för köp-knappen på eventsidan
                            </small>
                        </div>
                    </div>

                    <div class="gs-mt-lg">
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="save"></i>
                            Spara inställningar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Current Status -->
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h2 class="gs-h5">
                    <i data-lucide="info"></i>
                    Nuvarande status
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-md">
                    <div>
                        <strong>Ticketing:</strong><br>
                        <?php if ($event['ticketing_enabled'] ?? 0): ?>
                            <span class="gs-badge gs-badge-success">Aktiverat</span>
                        <?php else: ?>
                            <span class="gs-badge gs-badge-secondary">Inaktiverat</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong>Anmälningsfrist:</strong><br>
                        <?= $event['ticket_deadline_days'] ?? 7 ?> dagar före event
                    </div>
                    <div>
                        <strong>WooCommerce ID:</strong><br>
                        <?= $event['woo_product_id'] ? htmlspecialchars($event['woo_product_id']) : '<span class="gs-text-secondary">Ej satt</span>' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
