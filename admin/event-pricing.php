<?php
/**
 * Event Pricing - Visa priser från mall
 * Visar vilka klasser och priser som gäller för eventet baserat på tilldelad prismall
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();

// Get event ID
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

// Get pricing template info
$template = null;
$templatePrices = [];

if (!empty($event['pricing_template_id'])) {
    // Fetch template
    $template = $db->getRow("SELECT * FROM pricing_templates WHERE id = ?", [$event['pricing_template_id']]);

    if ($template) {
        // Fetch prices from template
        $templatePrices = $db->getAll("
            SELECT ptr.*, c.name as class_name, c.display_name
            FROM pricing_template_rules ptr
            JOIN classes c ON c.id = ptr.class_id
            WHERE ptr.template_id = ?
            ORDER BY c.sort_order ASC, c.name ASC
        ", [$event['pricing_template_id']]);
    }
}

// Page setup
$page_title = 'Priser - ' . htmlspecialchars($event['name']);
include __DIR__ . '/components/unified-layout.php';
?>

<div class="admin-content">
    <!-- Back link -->
    <div class="mb-md">
        <a href="/admin/events.php" class="btn btn-ghost">
            <i data-lucide="arrow-left"></i> Tillbaka till events
        </a>
    </div>

    <!-- Event info -->
    <div class="card mb-lg">
        <div class="card-header">
            <h3><i data-lucide="calendar"></i> <?= htmlspecialchars($event['name']) ?></h3>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-md);">
                <div>
                    <strong>Datum:</strong><br>
                    <?= $event['date'] ? date('Y-m-d', strtotime($event['date'])) : 'Ej satt' ?>
                </div>
                <div>
                    <strong>Plats:</strong><br>
                    <?= htmlspecialchars($event['location'] ?? 'Ej angiven') ?>
                </div>
                <div>
                    <strong>Prismall:</strong><br>
                    <?php if ($template): ?>
                        <span class="badge badge-success"><?= htmlspecialchars($template['name']) ?></span>
                    <?php else: ?>
                        <span class="badge badge-warning">Ingen mall vald</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$template): ?>
        <!-- No template assigned -->
        <div class="card">
            <div class="card-body" style="text-align: center; padding: var(--space-xl);">
                <i data-lucide="alert-circle" style="width: 48px; height: 48px; color: var(--color-warning); margin-bottom: var(--space-md);"></i>
                <h2>Ingen prismall tilldelad</h2>
                <p style="color: var(--color-text-secondary); margin: var(--space-md) 0;">
                    Detta event har ingen prismall. Tilldela en prismall i event-inställningarna för att aktivera prissättning.
                </p>
                <a href="/admin/event-edit.php?id=<?= $eventId ?>" class="btn btn-primary">
                    <i data-lucide="settings"></i> Redigera event
                </a>
            </div>
        </div>
    <?php elseif (empty($templatePrices)): ?>
        <!-- Template exists but no prices -->
        <div class="card">
            <div class="card-body" style="text-align: center; padding: var(--space-xl);">
                <i data-lucide="tag" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
                <h2>Inga priser i mallen</h2>
                <p style="color: var(--color-text-secondary); margin: var(--space-md) 0;">
                    Prismallen "<?= htmlspecialchars($template['name']) ?>" har inga klasspriser definierade.
                </p>
                <a href="/admin/pricing-templates.php" class="btn btn-primary">
                    <i data-lucide="file-text"></i> Hantera prismallar
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Show prices from template -->
        <div class="card">
            <div class="card-header">
                <h3><i data-lucide="tag"></i> Priser från mall: <?= htmlspecialchars($template['name']) ?></h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Klass</th>
                                <th style="text-align: right;">Pris</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templatePrices as $price): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($price['display_name'] ?? $price['class_name']) ?></strong>
                                    <?php if ($price['class_name'] !== ($price['display_name'] ?? $price['class_name'])): ?>
                                    <span class="text-secondary text-sm">(<?= htmlspecialchars($price['class_name']) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <strong style="font-size: 1.1em;"><?= number_format($price['base_price'], 0) ?> kr</strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($template['early_bird_percent']) && $template['early_bird_percent'] > 0): ?>
                <div class="alert alert-info mt-md">
                    <i data-lucide="clock"></i>
                    <strong>Early-bird rabatt:</strong> <?= (int)$template['early_bird_percent'] ?>%
                    <?php if (!empty($template['early_bird_days_before'])): ?>
                    (t.o.m. <?= (int)$template['early_bird_days_before'] ?> dagar innan eventet)
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary -->
        <div class="card mt-lg">
            <div class="card-header">
                <h3><i data-lucide="info"></i> Sammanfattning</h3>
            </div>
            <div class="card-body">
                <ul style="margin: 0; padding-left: var(--space-lg);">
                    <li><strong><?= count($templatePrices) ?></strong> klasser med prissättning</li>
                    <li>Priser hämtas från mallen <strong>"<?= htmlspecialchars($template['name']) ?>"</strong></li>
                    <li>Ändra prismall via <a href="/admin/event-edit.php?id=<?= $eventId ?>">event-inställningar</a></li>
                    <li>Hantera prismallar via <a href="/admin/pricing-templates.php">Prismallar</a></li>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>if(typeof lucide !== 'undefined') lucide.createIcons();</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
