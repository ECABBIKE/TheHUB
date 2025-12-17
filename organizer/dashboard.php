<?php
/**
 * Organizer App - Dashboard
 * Välj tävling att hantera
 */

require_once __DIR__ . '/config.php';
requireOrganizer();

// Hämta tillgängliga events
$events = getAccessibleEvents();
$currentAdmin = getCurrentAdmin();

$pageTitle = 'Välj tävling';
$showHeader = true;
$headerTitle = ORGANIZER_APP_NAME;
$headerSubtitle = 'Inloggad som ' . ($currentAdmin['name'] ?? $currentAdmin['username']);
$showLogout = true;

include __DIR__ . '/includes/header.php';
?>

<?php if (empty($events)): ?>
    <div class="org-card">
        <div class="org-card__body org-text-center" style="padding: 48px;">
            <i data-lucide="calendar-x" style="width: 64px; height: 64px; color: var(--color-text); margin-bottom: 24px;"></i>
            <h2 style="margin: 0 0 16px 0;">Inga tävlingar</h2>
            <p class="org-text-muted">Du har inga tilldelade tävlingar att hantera just nu.</p>
        </div>
    </div>
<?php else: ?>
    <div class="org-event-list">
        <?php foreach ($events as $event): ?>
            <?php
            $eventDate = new DateTime($event['date']);
            $isToday = $eventDate->format('Y-m-d') === date('Y-m-d');
            $isPast = $eventDate < new DateTime('today');
            ?>
            <a href="register.php?event=<?= $event['id'] ?>" class="org-event-card <?= $isToday ? 'org-card--highlight' : '' ?>">
                <div class="org-event-card__info">
                    <h2 class="org-event-card__name"><?= htmlspecialchars($event['name']) ?></h2>
                    <div class="org-event-card__meta">
                        <?php if ($isToday): ?>
                            <strong style="color: var(--color-accent);">IDAG</strong> &bull;
                        <?php endif; ?>
                        <?= $eventDate->format('j M Y') ?>
                        <?php if ($event['series_name']): ?>
                            &bull; <?= htmlspecialchars($event['series_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="org-event-card__count">
                    <?= (int)$event['registration_count'] ?> anmälda
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
