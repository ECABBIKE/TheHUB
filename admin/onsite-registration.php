<?php
/**
 * TheHUB On-Site Registration (POS)
 *
 * QR-baserad självregistrering på tävlingsplatsen:
 * - Visa QR-kod som deltagare scannar
 * - Deltagare registrerar sig på egen telefon
 * - Betalning sker direkt via Swish/kort
 */
require_once __DIR__ . '/../config.php';
require_admin();

// Allow promotors and admins
if (!hasRole('promotor')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/');
}

$db = getDB();
$currentUser = getCurrentAdmin();
$userId = $currentUser['id'] ?? 0;
$isPromotorOnly = isRole('promotor') && !hasRole('admin');

// Get selected event from URL
$selectedEventId = intval($_GET['event'] ?? 0);
$selectedEvent = null;

// Get user's assigned events (for promotors) or all upcoming events (for admins)
$upcomingEvents = [];
try {
    if ($isPromotorOnly) {
        $upcomingEvents = $db->getAll("
            SELECT DISTINCT e.id, e.name, e.date, e.location, s.name as series_name, s.logo as series_logo,
                   e.registration_open, e.registration_deadline,
                   COALESCE(reg.count, 0) as registration_count
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            LEFT JOIN promotor_events pe ON pe.event_id = e.id AND pe.user_id = ?
            LEFT JOIN promotor_series ps ON e.series_id = ps.series_id AND ps.user_id = ?
            LEFT JOIN (
                SELECT event_id, COUNT(*) as count
                FROM event_registrations WHERE status != 'cancelled'
                GROUP BY event_id
            ) reg ON reg.event_id = e.id
            WHERE (pe.user_id IS NOT NULL OR ps.user_id IS NOT NULL)
              AND e.date >= CURDATE()
            ORDER BY e.date ASC
            LIMIT 30
        ", [$userId, $userId]);
    } else {
        $upcomingEvents = $db->getAll("
            SELECT e.id, e.name, e.date, e.location, s.name as series_name, s.logo as series_logo,
                   e.registration_open, e.registration_deadline,
                   COALESCE(reg.count, 0) as registration_count
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            LEFT JOIN (
                SELECT event_id, COUNT(*) as count
                FROM event_registrations WHERE status != 'cancelled'
                GROUP BY event_id
            ) reg ON reg.event_id = e.id
            WHERE e.date >= CURDATE()
            ORDER BY e.date ASC
            LIMIT 30
        ");
    }

    // Get selected event details
    if ($selectedEventId) {
        foreach ($upcomingEvents as $e) {
            if ($e['id'] == $selectedEventId) {
                $selectedEvent = $e;
                break;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching events for on-site registration: " . $e->getMessage());
}

// Build registration URL for QR code
$baseUrl = rtrim(SITE_URL, '/');
$registrationUrl = $selectedEventId
    ? $baseUrl . '/register/event/' . $selectedEventId . '?onsite=1'
    : '';

$page_title = 'Direktanmälan';
$breadcrumbs = [
    ['label' => 'Direktanmälan']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.pos-grid {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: var(--space-xl);
    align-items: start;
}

@media (max-width: 1024px) {
    .pos-grid {
        grid-template-columns: 1fr;
    }
}

/* Event selector */
.event-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.event-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
    color: inherit;
}

.event-item:hover {
    border-color: var(--color-accent);
}

.event-item.selected {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}

.event-logo {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    background: var(--color-bg-hover);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
}

.event-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.event-logo i {
    color: var(--color-text-muted);
}

.event-info {
    flex: 1;
    min-width: 0;
}

.event-name {
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 2px;
}

.event-meta {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.event-count {
    text-align: right;
}

.event-count-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-accent);
}

.event-count-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}

/* QR Card */
.qr-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: var(--space-xl);
    text-align: center;
    position: sticky;
    top: var(--space-lg);
}

.qr-header {
    margin-bottom: var(--space-lg);
}

.qr-header h2 {
    margin: 0 0 var(--space-xs) 0;
    font-size: var(--text-lg);
}

.qr-header p {
    margin: 0;
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
}

.qr-container {
    background: white;
    padding: var(--space-lg);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-lg);
    display: inline-block;
}

#qrcode {
    width: 250px;
    height: 250px;
    margin: 0 auto;
}

#qrcode canvas {
    max-width: 100%;
    height: auto !important;
}

.qr-event-name {
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: var(--space-xs);
}

.qr-event-date {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
}

.qr-url {
    background: var(--color-bg-hover);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    word-break: break-all;
    margin-bottom: var(--space-lg);
}

.qr-actions {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.qr-empty {
    padding: var(--space-2xl);
    color: var(--color-text-muted);
}

.qr-empty i {
    width: 64px;
    height: 64px;
    margin-bottom: var(--space-md);
    opacity: 0.3;
}

/* Fullscreen QR */
.qr-fullscreen {
    position: fixed;
    inset: 0;
    background: white;
    z-index: 9999;
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: var(--space-xl);
}

.qr-fullscreen.active {
    display: flex;
}

.qr-fullscreen-content {
    text-align: center;
    max-width: 600px;
}

.qr-fullscreen h1 {
    color: #0b131e;
    margin-bottom: var(--space-sm);
    font-size: 2rem;
}

.qr-fullscreen p {
    color: #495057;
    margin-bottom: var(--space-xl);
    font-size: 1.25rem;
}

.qr-fullscreen #qrcode-fullscreen {
    margin-bottom: var(--space-xl);
}

.qr-fullscreen #qrcode-fullscreen canvas {
    width: 400px !important;
    height: 400px !important;
}

.qr-fullscreen .event-details {
    background: #f8f9fa;
    padding: var(--space-lg);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-xl);
}

.qr-fullscreen .event-details h2 {
    color: #0b131e;
    margin: 0 0 var(--space-xs) 0;
}

.qr-fullscreen .event-details p {
    color: #495057;
    margin: 0;
    font-size: 1rem;
}

.qr-fullscreen .close-btn {
    position: absolute;
    top: var(--space-lg);
    right: var(--space-lg);
    width: 48px;
    height: 48px;
    border-radius: var(--radius-full);
    background: #e9ecef;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.qr-fullscreen .close-btn:hover {
    background: #dee2e6;
}

.qr-fullscreen .instructions {
    color: #868e96;
    font-size: var(--text-sm);
}

/* Stats */
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}

.stat-box {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-md);
    text-align: center;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-accent);
}

.stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}

@media (max-width: 767px) {
    .qr-card {
        position: static;
    }

    .pos-grid {
        gap: var(--space-lg);
    }

    .stats-row {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if (empty($upcomingEvents)): ?>
<div class="card">
    <div class="card-body text-center" style="padding: var(--space-2xl);">
        <i data-lucide="calendar-x" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <h3>Inga kommande tävlingar</h3>
        <p class="text-muted">Det finns inga tävlingar att visa direktanmälan för.</p>
    </div>
</div>
<?php else: ?>

<div class="pos-grid">
    <!-- Event List -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><i data-lucide="calendar"></i> Välj tävling</h3>
            </div>
            <div class="card-body">
                <div class="event-list">
                    <?php foreach ($upcomingEvents as $event): ?>
                    <a href="?event=<?= $event['id'] ?>"
                       class="event-item <?= $selectedEventId == $event['id'] ? 'selected' : '' ?>">
                        <div class="event-logo">
                            <?php if ($event['series_logo']): ?>
                                <img src="<?= h($event['series_logo']) ?>" alt="">
                            <?php else: ?>
                                <i data-lucide="flag"></i>
                            <?php endif; ?>
                        </div>
                        <div class="event-info">
                            <div class="event-name"><?= h($event['name']) ?></div>
                            <div class="event-meta">
                                <?= date('j M Y', strtotime($event['date'])) ?>
                                <?php if ($event['location']): ?>
                                    &bull; <?= h($event['location']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="event-count">
                            <div class="event-count-value"><?= $event['registration_count'] ?></div>
                            <div class="event-count-label">anmälda</div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Code Panel -->
    <div class="qr-card">
        <?php if ($selectedEvent): ?>
        <div class="qr-header">
            <h2>Scanna för att anmäla dig</h2>
            <p>Öppna kameran och scanna QR-koden</p>
        </div>

        <div class="qr-container">
            <div id="qrcode"></div>
        </div>

        <div class="qr-event-name"><?= h($selectedEvent['name']) ?></div>
        <div class="qr-event-date"><?= date('j M Y', strtotime($selectedEvent['date'])) ?></div>

        <div class="qr-url"><?= h($registrationUrl) ?></div>

        <div class="qr-actions">
            <button class="btn btn--primary btn--lg" onclick="showFullscreen()">
                <i data-lucide="maximize-2"></i>
                Visa i helskärm
            </button>
            <button class="btn btn--secondary" onclick="printQR()">
                <i data-lucide="printer"></i>
                Skriv ut QR-kod
            </button>
            <button class="btn btn--secondary" onclick="copyLink()">
                <i data-lucide="copy"></i>
                Kopiera länk
            </button>
        </div>
        <?php else: ?>
        <div class="qr-empty">
            <i data-lucide="qr-code"></i>
            <h3>Välj en tävling</h3>
            <p>Välj en tävling i listan för att visa QR-koden</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<!-- Fullscreen QR Overlay -->
<div class="qr-fullscreen" id="qr-fullscreen">
    <button class="close-btn" onclick="hideFullscreen()">
        <i data-lucide="x"></i>
    </button>
    <div class="qr-fullscreen-content">
        <h1>Anmäl dig här!</h1>
        <p>Scanna QR-koden med din telefon</p>
        <div id="qrcode-fullscreen"></div>
        <?php if ($selectedEvent): ?>
        <div class="event-details">
            <h2><?= h($selectedEvent['name']) ?></h2>
            <p><?= date('j M Y', strtotime($selectedEvent['date'])) ?></p>
        </div>
        <?php endif; ?>
        <p class="instructions">
            Öppna kameran på din telefon och rikta den mot QR-koden.<br>
            Klicka på länken som visas för att anmäla dig och betala.
        </p>
    </div>
</div>

<!-- QRCode.js library -->
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>

<script>
const registrationUrl = <?= json_encode($registrationUrl) ?>;

// Generate QR codes
if (registrationUrl) {
    // Small QR
    QRCode.toCanvas(document.createElement('canvas'), registrationUrl, {
        width: 250,
        margin: 0,
        color: { dark: '#0b131e', light: '#ffffff' }
    }, function(error, canvas) {
        if (!error) {
            document.getElementById('qrcode').appendChild(canvas);
        }
    });

    // Fullscreen QR
    QRCode.toCanvas(document.createElement('canvas'), registrationUrl, {
        width: 400,
        margin: 0,
        color: { dark: '#0b131e', light: '#ffffff' }
    }, function(error, canvas) {
        if (!error) {
            document.getElementById('qrcode-fullscreen').appendChild(canvas);
        }
    });
}

function showFullscreen() {
    document.getElementById('qr-fullscreen').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function hideFullscreen() {
    document.getElementById('qr-fullscreen').classList.remove('active');
    document.body.style.overflow = '';
}

// Close fullscreen with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideFullscreen();
    }
});

function copyLink() {
    navigator.clipboard.writeText(registrationUrl).then(function() {
        alert('Länk kopierad!');
    });
}

function printQR() {
    const printWindow = window.open('', '_blank');
    const canvas = document.querySelector('#qrcode canvas');
    const eventName = <?= json_encode($selectedEvent['name'] ?? '') ?>;
    const eventDate = <?= json_encode($selectedEvent ? date('j M Y', strtotime($selectedEvent['date'])) : '') ?>;

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>QR-kod - ${eventName}</title>
            <style>
                body {
                    font-family: system-ui, -apple-system, sans-serif;
                    text-align: center;
                    padding: 40px;
                }
                h1 { font-size: 24px; margin-bottom: 8px; }
                p { color: #666; margin-bottom: 32px; }
                img { max-width: 300px; margin-bottom: 32px; }
                .event { background: #f5f5f5; padding: 16px; border-radius: 8px; display: inline-block; }
                .event h2 { margin: 0 0 4px 0; font-size: 18px; }
                .event p { margin: 0; font-size: 14px; }
                .url { margin-top: 24px; font-size: 10px; color: #999; word-break: break-all; }
            </style>
        </head>
        <body>
            <h1>Anmäl dig här!</h1>
            <p>Scanna QR-koden med din telefon</p>
            <img src="${canvas.toDataURL()}" alt="QR Code">
            <div class="event">
                <h2>${eventName}</h2>
                <p>${eventDate}</p>
            </div>
            <div class="url">${registrationUrl}</div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// Initialize Lucide icons
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
