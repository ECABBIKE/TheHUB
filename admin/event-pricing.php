<?php
/**
 * Event Pricing Setup
 * Configure ticket pricing per class for events
 * Uses Economy Tab System
 */

require_once __DIR__ . '/../config.php';

$db = getDB();

// Get event ID (supports both 'id' and 'event_id')
$eventId = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['event_id']) ? intval($_GET['event_id']) : 0);

if ($eventId <= 0) {
    set_flash('error', 'Ogiltigt event-ID');
    header('Location: /admin/events.php');
    exit;
}

// Fetch event data
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);

if (!$event) {
    set_flash('error', 'Event hittades inte');
    header('Location: /admin/events.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_pricing') {
        $classIds = $_POST['class_id'] ?? [];
        $basePrices = $_POST['base_price'] ?? [];
        $earlyBirdDiscounts = $_POST['early_bird_discount'] ?? [];
        $earlyBirdEndDates = $_POST['early_bird_end_date'] ?? [];

        $saved = 0;
        $errors = 0;

        foreach ($classIds as $index => $classId) {
            $basePrice = floatval($basePrices[$index] ?? 0);
            $earlyBirdDiscount = floatval($earlyBirdDiscounts[$index] ?? 0);
            $earlyBirdEndDate = trim($earlyBirdEndDates[$index] ?? '');

            if ($basePrice > 0) {
                $existing = $db->getRow("
                    SELECT id FROM event_pricing_rules
                    WHERE event_id = ? AND class_id = ?
                ", [$eventId, $classId]);

                if ($existing) {
                    $result = $db->execute("
                        UPDATE event_pricing_rules
                        SET base_price = ?,
                            early_bird_discount_percent = ?,
                            early_bird_end_date = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ", [$basePrice, $earlyBirdDiscount, $earlyBirdEndDate ?: null, $existing['id']]);
                } else {
                    $result = $db->execute("
                        INSERT INTO event_pricing_rules
                        (event_id, class_id, base_price, early_bird_discount_percent, early_bird_end_date, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ", [$eventId, $classId, $basePrice, $earlyBirdDiscount, $earlyBirdEndDate ?: null]);
                }

                if ($result) {
                    $saved++;
                } else {
                    $errors++;
                }
            } else {
                $db->execute("
                    DELETE FROM event_pricing_rules
                    WHERE event_id = ? AND class_id = ?
                ", [$eventId, $classId]);
            }
        }

        if ($errors > 0) {
            set_flash('warning', "Sparat $saved priser, men $errors fel uppstod");
        } else {
            set_flash('success', "Sparat $saved priser");
        }

        header("Location: /admin/event-pricing.php?id=$eventId");
        exit;
    }

    // Apply pricing template to event
    if ($action === 'apply_template') {
        $templateId = intval($_POST['template_id'] ?? 0);

        if ($templateId > 0) {
            // Fetch template settings
            $template = $db->getRow("SELECT * FROM pricing_templates WHERE id = ?", [$templateId]);

            if ($template) {
                // Fetch template rules
                $templateRules = $db->getAll("
                    SELECT class_id, base_price
                    FROM pricing_template_rules
                    WHERE template_id = ?
                ", [$templateId]);

                // Calculate default early-bird end date
                $eventDate = new DateTime($event['date']);
                $ebDays = $template['early_bird_days_before'] ?? 21;
                $earlyBirdEnd = clone $eventDate;
                $earlyBirdEnd->modify("-{$ebDays} days");
                $ebEndDate = $earlyBirdEnd->format('Y-m-d');

                $ebPercent = $template['early_bird_percent'] ?? 15;

                $applied = 0;
                foreach ($templateRules as $rule) {
                    // Check if rule already exists
                    $existing = $db->getRow("
                        SELECT id FROM event_pricing_rules
                        WHERE event_id = ? AND class_id = ?
                    ", [$eventId, $rule['class_id']]);

                    if ($existing) {
                        $db->execute("
                            UPDATE event_pricing_rules
                            SET base_price = ?, early_bird_discount_percent = ?, early_bird_end_date = ?, updated_at = NOW()
                            WHERE id = ?
                        ", [$rule['base_price'], $ebPercent, $ebEndDate, $existing['id']]);
                    } else {
                        $db->execute("
                            INSERT INTO event_pricing_rules
                            (event_id, class_id, base_price, early_bird_discount_percent, early_bird_end_date, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ", [$eventId, $rule['class_id'], $rule['base_price'], $ebPercent, $ebEndDate]);
                    }
                    $applied++;
                }

                // Update event's pricing_template_id
                $db->execute("UPDATE events SET pricing_template_id = ? WHERE id = ?", [$templateId, $eventId]);

                set_flash('success', "Applicerade {$applied} priser från mallen '{$template['name']}'");
            } else {
                set_flash('error', 'Prismallen hittades inte');
            }
        }

        header("Location: /admin/event-pricing.php?id=$eventId");
        exit;
    }
}

// Fetch only ACTIVE classes
$classes = $db->getAll("
    SELECT id, name, display_name, sort_order
    FROM classes
    WHERE active = 1
    ORDER BY sort_order ASC
");

// Fetch existing pricing rules for this event
$existingRules = $db->getAll("
    SELECT * FROM event_pricing_rules
    WHERE event_id = ?
", [$eventId]);

// Create a map for quick lookup
$rulesMap = [];
foreach ($existingRules as $rule) {
    $rulesMap[$rule['class_id']] = $rule;
}

// Calculate default early-bird end date (event date - 20 days)
$eventDate = new DateTime($event['date']);
$defaultEarlyBirdEnd = clone $eventDate;
$defaultEarlyBirdEnd->modify('-20 days');

// Fetch available pricing templates
$pricingTemplates = $db->getAll("
    SELECT id, name, is_default
    FROM pricing_templates
    ORDER BY is_default DESC, name ASC
");

// Get current template for event
$currentTemplateId = $event['pricing_template_id'] ?? null;

// Set page variables for economy layout
$economy_page_title = 'Prissättning';

include __DIR__ . '/components/economy-layout.php';
?>

<?php if (!empty($pricingTemplates)): ?>
<!-- Apply Template -->
<div class="card mb-lg">
    <div class="card-header">
        <h3><i data-lucide="file-text"></i> Applicera prismall</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="flex gap-md items-end flex-wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply_template">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Välj prismall</label>
                <select name="template_id" class="form-select" style="min-width: 200px;">
                    <option value="">-- Välj mall --</option>
                    <?php foreach ($pricingTemplates as $tpl): ?>
                    <option value="<?= $tpl['id'] ?>" <?= $currentTemplateId == $tpl['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tpl['name']) ?>
                        <?= $tpl['is_default'] ? '(Standard)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary" onclick="return confirm('Detta ersätter befintliga priser. Fortsätta?')">
                <i data-lucide="copy"></i>
                Applicera mall
            </button>
        </form>
        <p class="text-secondary text-sm mt-sm">
            Kopierar priser från vald mall till detta event. Befintliga priser ersätts.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Pricing Rules -->
<div class="card">
    <div class="card-header">
        <h3>Priser per klass</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_pricing">

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Klass</th>
                            <th>Ordinarie pris (kr)</th>
                            <th>Early-bird rabatt (%)</th>
                            <th>Early-bird t.o.m.</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                        <?php
                        $rule = $rulesMap[$class['id']] ?? null;
                        $hasPrice = $rule && $rule['base_price'] > 0;
                        ?>
                        <tr>
                            <td>
                                <input type="hidden" name="class_id[]" value="<?= $class['id'] ?>">
                                <strong><?= htmlspecialchars($class['display_name'] ?? $class['name']) ?></strong>
                                <?php if ($class['name'] !== ($class['display_name'] ?? $class['name'])): ?>
                                <span class="text-secondary text-sm">(<?= htmlspecialchars($class['name']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="number"
                                       name="base_price[]"
                                       class="form-input"
                                       value="<?= $rule ? htmlspecialchars($rule['base_price']) : '' ?>"
                                       placeholder="0"
                                       min="0"
                                       step="10"
                                       style="width: 100px;">
                            </td>
                            <td>
                                <input type="number"
                                       name="early_bird_discount[]"
                                       class="form-input"
                                       value="<?= $rule ? htmlspecialchars($rule['early_bird_discount_percent']) : '20' ?>"
                                       placeholder="20"
                                       min="0"
                                       max="100"
                                       style="width: 80px;">
                            </td>
                            <td>
                                <input type="date"
                                       name="early_bird_end_date[]"
                                       class="form-input"
                                       value="<?= $rule && $rule['early_bird_end_date'] ? htmlspecialchars($rule['early_bird_end_date']) : $defaultEarlyBirdEnd->format('Y-m-d') ?>"
                                       style="width: 150px;">
                            </td>
                            <td>
                                <?php if ($hasPrice): ?>
                                <span class="badge badge-success">Konfigurerad</span>
                                <?php else: ?>
                                <span class="badge badge-secondary">Ej satt</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-lg">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="save"></i>
                    Spara alla priser
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tips -->
<div class="card" class="mt-lg">
    <div class="card-header">
        <h3>Tips</h3>
    </div>
    <div class="card-body">
        <ul style="margin: 0; padding-left: var(--space-lg);">
            <li>Lämna prisfältet tomt eller sätt till 0 för att dölja en klass</li>
            <li>Early-bird-rabatten gäller till och med det angivna datumet</li>
            <li>Standardrabatt är 20% för early-bird</li>
        </ul>
    </div>
</div>

<?php include __DIR__ . '/components/economy-layout-footer.php'; ?>
