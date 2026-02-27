<?php
/**
 * TheHUB V1.0 - Mina Race Reports
 * Rider can create, edit, and manage their race reports
 */

$currentUser = hub_current_user();

if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();

// Include the race report manager
require_once HUB_ROOT . '/includes/RaceReportManager.php';
$reportManager = new RaceReportManager($pdo);

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_report') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $eventId = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
        $featuredImage = trim($_POST['featured_image'] ?? '');
        $youtubeUrl = trim($_POST['youtube_url'] ?? '');
        $instagramUrl = trim($_POST['instagram_url'] ?? '');
        $tags = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));

        if (empty($title)) {
            $error = 'Titel krävs';
        } elseif (empty($content) && empty($youtubeUrl) && empty($instagramUrl)) {
            $error = 'Lägg till innehåll, en YouTube-länk eller Instagram-länk';
        } else {
            // Check if user is admin (not a regular rider)
            $isAdminUser = !empty($currentUser['is_admin']) && empty($_SESSION['rider_id']);

            $reportData = [
                'event_id' => $eventId,
                'title' => $title,
                'content' => $content,
                'featured_image' => $featuredImage ?: null,
                'youtube_url' => $youtubeUrl ?: null,
                'instagram_url' => $instagramUrl ?: null,
                'status' => $isAdminUser ? 'published' : 'draft'
            ];

            if ($isAdminUser) {
                // Admin user - use admin_user_id, no rider_id
                $reportData['admin_user_id'] = $_SESSION['admin_id'] ?? $currentUser['id'];
                $reportData['rider_id'] = null;
            } else {
                // Regular rider
                $reportData['rider_id'] = $currentUser['id'];
            }

            $reportId = $reportManager->createReport($reportData, $tags);

            if ($reportId) {
                if ($isAdminUser) {
                    $message = 'Nyhet publicerad!';
                } else {
                    $message = 'Race report skapad! Den granskas av admin innan publicering.';
                }
            } else {
                $error = 'Kunde inte skapa race report.';
            }
        }
    } elseif ($action === 'update_report') {
        $reportId = (int)$_POST['report_id'];
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $eventId = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
        $featuredImage = trim($_POST['featured_image'] ?? '');
        $youtubeUrl = trim($_POST['youtube_url'] ?? '');
        $instagramUrl = trim($_POST['instagram_url'] ?? '');

        $report = $reportManager->getReport($reportId, false);

        // Check permission - rider owns post OR admin owns post OR admin's linked rider owns post
        $isAdminUser = !empty($currentUser['is_admin']) && empty($_SESSION['rider_id']);
        $adminId = $_SESSION['admin_id'] ?? $currentUser['id'];

        // Find linked rider for admin
        $updateLinkedRiderId = null;
        if ($isAdminUser && !empty($currentUser['email'])) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM riders WHERE email = ? LIMIT 1");
                $stmt->execute([$currentUser['email']]);
                $lr = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($lr) $updateLinkedRiderId = $lr['id'];
            } catch (Exception $e) {}
        }

        $canEdit = $report && (
            ($report['rider_id'] && $report['rider_id'] == $currentUser['id']) ||
            ($report['admin_user_id'] && $report['admin_user_id'] == $adminId) ||
            ($isAdminUser && $updateLinkedRiderId && $report['rider_id'] == $updateLinkedRiderId)
        );

        if (!$canEdit) {
            $error = 'Du har inte behörighet att redigera denna report.';
        } else {
            $updateData = [
                'title' => $title,
                'content' => $content,
                'event_id' => $eventId,
                'featured_image' => $featuredImage ?: null,
                'youtube_url' => $youtubeUrl ?: null,
                'instagram_url' => $instagramUrl ?: null
            ];

            // Rider posts go back to draft after editing, admin posts stay published
            if (!$isAdminUser && $report['status'] === 'published') {
                $updateData['status'] = 'draft';
            }

            $result = $reportManager->updateReport($reportId, $updateData);
            if ($result) {
                if (!$isAdminUser && $report['status'] === 'published') {
                    $message = 'Race report uppdaterad! Den måste godkännas igen innan publicering.';
                } else {
                    $message = 'Race report uppdaterad!';
                }
            } else {
                $error = 'Kunde inte uppdatera.';
            }
        }
    } elseif ($action === 'delete_report') {
        $reportId = (int)$_POST['report_id'];

        $report = $reportManager->getReport($reportId, false);

        // Check permission - rider owns post OR admin owns post OR admin's linked rider owns post
        $isAdminUser = !empty($currentUser['is_admin']) && empty($_SESSION['rider_id']);
        $adminId = $_SESSION['admin_id'] ?? $currentUser['id'];

        // Find linked rider for admin
        $deleteLinkedRiderId = null;
        if ($isAdminUser && !empty($currentUser['email'])) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM riders WHERE email = ? LIMIT 1");
                $stmt->execute([$currentUser['email']]);
                $lr = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($lr) $deleteLinkedRiderId = $lr['id'];
            } catch (Exception $e) {}
        }

        $canDelete = $report && (
            ($report['rider_id'] && $report['rider_id'] == $currentUser['id']) ||
            ($report['admin_user_id'] && $report['admin_user_id'] == $adminId) ||
            ($isAdminUser && $deleteLinkedRiderId && $report['rider_id'] == $deleteLinkedRiderId)
        );

        if (!$canDelete) {
            $error = 'Du har inte behörighet att ta bort denna report.';
        } elseif (!$isAdminUser && $report['status'] === 'published') {
            $error = 'Publicerade reports kan inte tas bort.';
        } else {
            if ($reportManager->deleteReport($reportId)) {
                $message = 'Race report borttagen.';
            } else {
                $error = 'Kunde inte ta bort.';
            }
        }
    }
}

// Get rider's reports (or admin's reports)
$isAdminUser = !empty($currentUser['is_admin']) && empty($_SESSION['rider_id']);

// For admin users, find linked rider_id via matching email
$linkedRiderId = null;
if ($isAdminUser && !empty($currentUser['email'])) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM riders WHERE email = ? LIMIT 1");
        $stmt->execute([$currentUser['email']]);
        $linkedRider = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($linkedRider) {
            $linkedRiderId = $linkedRider['id'];
        }
    } catch (Exception $e) {
        // Ignore
    }
}

// Build filters - for admin users we need to check both admin_user_id AND linked rider_id
$reportFilters = [
    'include_drafts' => true,
    'per_page' => 50
];

if ($isAdminUser) {
    // Admin user - pass both IDs for OR query
    $reportFilters['admin_user_id'] = $_SESSION['admin_id'] ?? $currentUser['id'];
    if ($linkedRiderId) {
        $reportFilters['linked_rider_id'] = $linkedRiderId;
    }
} else {
    $reportFilters['rider_id'] = $currentUser['id'];
}
$myReports = $reportManager->listReports($reportFilters);

// Get rider's recent events for dropdown (events they participated in + all recent events)
$recentEvents = [];
try {
    $stmt = $pdo->prepare("
        (SELECT DISTINCT e.id, e.name, e.date
        FROM events e
        INNER JOIN results r ON e.id = r.event_id
        WHERE r.cyclist_id = ?
        ORDER BY e.date DESC
        LIMIT 20)
        UNION
        (SELECT e.id, e.name, e.date
        FROM events e
        WHERE e.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        AND e.active = 1
        ORDER BY e.date DESC
        LIMIT 20)
        ORDER BY date DESC
        LIMIT 30
    ");
    $stmt->execute([$currentUser['id']]);
    $recentEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore
}

// Check if editing
$editReport = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editReport = $reportManager->getReport((int)$_GET['edit'], false);
    if ($editReport) {
        // Check permission - rider owns post OR admin owns post OR admin's linked rider owns post
        $adminId = $_SESSION['admin_id'] ?? $currentUser['id'];
        $canEdit = (
            ($editReport['rider_id'] && $editReport['rider_id'] == $currentUser['id']) ||
            ($editReport['admin_user_id'] && $editReport['admin_user_id'] == $adminId) ||
            ($isAdminUser && $linkedRiderId && $editReport['rider_id'] == $linkedRiderId)
        );
        if (!$canEdit) {
            $editReport = null;
        }
    }
}

// Count stats
$publishedCount = 0;
$draftCount = 0;
$totalViews = 0;
$totalLikes = 0;
foreach ($myReports['reports'] as $r) {
    if ($r['status'] === 'published') $publishedCount++;
    if ($r['status'] === 'draft') $draftCount++;
    $totalViews += $r['views'];
    $totalLikes += $r['likes'];
}
?>

<link rel="stylesheet" href="/assets/css/pages/race-reports.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/race-reports.css') ?>">

<?php if ($message): ?>
    <div class="alert alert-success mb-lg">
        <i data-lucide="check-circle"></i>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error mb-lg">
        <i data-lucide="alert-circle"></i>
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Quick Tips Bar -->
<div class="rr-tips-bar">
    <div class="rr-tip">
        <i data-lucide="camera"></i>
        <span>Ladda upp omslagsbild</span>
    </div>
    <div class="rr-tip">
        <i data-lucide="youtube"></i>
        <span>Dela video eller inlägg</span>
    </div>
    <div class="rr-tip">
        <i data-lucide="file-text"></i>
        <span>Skriv egen text</span>
    </div>
    <div class="rr-tip">
        <i data-lucide="link"></i>
        <span>Koppla till event</span>
    </div>
    <div class="rr-tip">
        <i data-lucide="clock"></i>
        <span>Granskning 1-2 dagar</span>
    </div>
</div>

<!-- Create/Edit Form -->
<div class="card rr-form-card">
    <div class="card-header">
        <h2>
            <i data-lucide="<?= $editReport ? 'edit-3' : 'plus-circle' ?>"></i>
            <?= $editReport ? 'Redigera Race Report' : 'Skriv ny Race Report' ?>
        </h2>
        <?php if ($editReport): ?>
        <a href="/profile/race-reports" class="btn btn-ghost btn-sm">
            <i data-lucide="x"></i>
            Avbryt
        </a>
        <?php endif; ?>
    </div>

    <form method="POST" class="card-body rr-form" id="rr-form">
        <?php if ($editReport): ?>
            <input type="hidden" name="action" value="update_report">
            <input type="hidden" name="report_id" value="<?= $editReport['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="action" value="create_report">
        <?php endif; ?>

        <!-- Section 1: Basic Info -->
        <div class="rr-section">
            <div class="rr-section-header">
                <span class="rr-section-number">1</span>
                <h3>Grundläggande info</h3>
            </div>
            <div class="rr-section-content">
                <div class="form-group">
                    <label class="form-label">Titel <span class="text-error">*</span></label>
                    <input type="text"
                           name="title"
                           class="form-input form-input-lg"
                           placeholder="T.ex. Min första Enduro-tävling i Åre"
                           value="<?= htmlspecialchars($editReport['title'] ?? '') ?>"
                           required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="filter-label">Kopplat event</label>
                        <select name="event_id" class="filter-select">
                            <option value="">Välj event (valfritt)</option>
                            <?php foreach ($recentEvents as $event): ?>
                                <option value="<?= $event['id'] ?>"
                                        <?= ($editReport && $editReport['event_id'] == $event['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-help">Koppla din report till en tävling du deltog i</small>
                    </div>

                    <?php if (!$editReport): ?>
                    <div class="form-group">
                        <label class="form-label">Taggar</label>
                        <input type="text"
                               name="tags"
                               class="form-input"
                               placeholder="enduro, åre, sm, 2026">
                        <small class="form-help">Kommaseparerade taggar</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section 2: Cover Image -->
        <div class="rr-section">
            <div class="rr-section-header">
                <span class="rr-section-number">2</span>
                <h3>Omslagsbild <span class="rr-optional">(valfritt)</span></h3>
            </div>
            <div class="rr-section-content">
                <input type="hidden" name="featured_image" id="featured_image_input" value="<?= htmlspecialchars($editReport['featured_image'] ?? '') ?>">
                <input type="file" id="cover_file_input" accept="image/*" style="display: none;">

                <div class="rr-cover-upload <?= !empty($editReport['featured_image']) ? 'has-image' : '' ?>" id="cover_upload_area" onclick="document.getElementById('cover_file_input').click()">
                    <?php if (!empty($editReport['featured_image'])): ?>
                        <img src="<?= htmlspecialchars($editReport['featured_image']) ?>" alt="Omslagsbild" id="cover_preview_img">
                        <div class="rr-cover-overlay">
                            <i data-lucide="camera"></i>
                            <span>Byt bild</span>
                        </div>
                        <button type="button" class="rr-cover-remove" id="cover_remove_btn" onclick="event.stopPropagation(); removeCoverImage()">
                            <i data-lucide="x"></i>
                        </button>
                    <?php else: ?>
                        <div class="rr-cover-placeholder" id="cover_placeholder">
                            <i data-lucide="image-plus"></i>
                            <span>Klicka för att ladda upp omslagsbild</span>
                        </div>
                    <?php endif; ?>
                </div>
                <small class="form-help">Rekommenderad storlek: 1200x675 px (16:9). Max 10 MB.</small>
            </div>
        </div>

        <!-- Section 3: Content -->
        <div class="rr-section">
            <div class="rr-section-header">
                <span class="rr-section-number">3</span>
                <h3>Innehåll</h3>
            </div>
            <div class="rr-section-content">
                <div class="form-group">
                    <label class="form-label">Din berättelse</label>
                    <textarea name="content"
                              class="form-textarea rr-content-textarea"
                              rows="10"
                              data-format-toolbar
                              placeholder="Berätta om din tävlingsupplevelse! Använd **fetstil** och *kursiv* för att formatera texten.

Hur förberedde du dig?
Hur gick det på tävlingen?
Vilka utmaningar mötte du?
Vad lärde du dig?"><?= htmlspecialchars($editReport['content'] ?? '') ?></textarea>
                    <small class="form-help">Använd **fetstil** och *kursiv* för formatering</small>
                </div>
            </div>
        </div>

        <!-- Section 4: Link Media -->
        <div class="rr-section">
            <div class="rr-section-header">
                <span class="rr-section-number">4</span>
                <h3>Länka media <span class="rr-optional">(valfritt)</span></h3>
            </div>
            <div class="rr-section-content">
                <?php
                $hasYoutube = !empty($editReport['youtube_url']);
                $hasInstagram = !empty($editReport['instagram_url']);
                $defaultMedia = $hasInstagram ? 'instagram' : 'youtube';
                ?>
                <div class="rr-media-toggle">
                    <button type="button" class="rr-media-toggle-btn rr-toggle-youtube <?= $defaultMedia === 'youtube' ? 'active' : '' ?>" onclick="toggleMediaType('youtube')">
                        <i data-lucide="youtube"></i>
                        YouTube
                    </button>
                    <button type="button" class="rr-media-toggle-btn rr-toggle-instagram <?= $defaultMedia === 'instagram' ? 'active' : '' ?>" onclick="toggleMediaType('instagram')">
                        <i data-lucide="instagram"></i>
                        Instagram
                    </button>
                </div>

                <!-- YouTube input -->
                <div class="rr-media-input-area <?= $defaultMedia === 'youtube' ? 'active' : '' ?>" id="media-youtube">
                    <div class="form-group">
                        <label class="form-label">YouTube-länk</label>
                        <input type="url"
                               name="youtube_url"
                               id="youtube_url"
                               class="form-input"
                               placeholder="https://www.youtube.com/watch?v=..."
                               value="<?= htmlspecialchars($editReport['youtube_url'] ?? '') ?>"
                               onchange="previewYoutube(this.value)">
                        <small class="form-help">Klistra in länken till din YouTube-video</small>
                    </div>
                    <div id="youtube-preview" class="rr-preview" style="display: none;">
                        <div class="rr-preview-label"><i data-lucide="eye"></i> Förhandsgranskning</div>
                        <div id="youtube-embed"></div>
                    </div>
                </div>

                <!-- Instagram input -->
                <div class="rr-media-input-area <?= $defaultMedia === 'instagram' ? 'active' : '' ?>" id="media-instagram">
                    <div class="form-group">
                        <label class="form-label">Instagram-länk</label>
                        <input type="url"
                               name="instagram_url"
                               id="instagram_url"
                               class="form-input"
                               placeholder="https://www.instagram.com/p/..."
                               value="<?= htmlspecialchars($editReport['instagram_url'] ?? '') ?>">
                        <small class="form-help">Klistra in länken till ditt Instagram-inlägg</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div class="rr-submit-section">
            <button type="submit" class="btn btn-primary btn-xl">
                <i data-lucide="<?= $editReport ? 'save' : 'send' ?>"></i>
                <?= $editReport ? 'Spara ändringar' : 'Skicka in för granskning' ?>
            </button>

            <?php if (!$editReport): ?>
            <p class="rr-submit-notice">
                <i data-lucide="shield-check"></i>
                Din report sparas som utkast och granskas av admin innan publicering.
            </p>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- My Reports List -->
<?php if (!empty($myReports['reports'])): ?>
<div class="card rr-list-card">
    <div class="card-header">
        <h2>
            <i data-lucide="folder"></i>
            Mina reports
        </h2>
        <div class="rr-header-stats">
            <span class="rr-mini-stat"><i data-lucide="check-circle"></i> <?= $publishedCount ?></span>
            <span class="rr-mini-stat"><i data-lucide="clock"></i> <?= $draftCount ?></span>
            <span class="rr-mini-stat"><i data-lucide="eye"></i> <?= number_format($totalViews) ?></span>
            <span class="rr-mini-stat"><i data-lucide="heart"></i> <?= number_format($totalLikes) ?></span>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="rr-list">
            <?php foreach ($myReports['reports'] as $report): ?>
                <?php
                $statusConfigs = [
                    'published' => ['class' => 'rr-status-published', 'icon' => 'check-circle', 'text' => 'Publicerad'],
                    'draft' => ['class' => 'rr-status-draft', 'icon' => 'clock', 'text' => 'Väntar'],
                    'archived' => ['class' => 'rr-status-archived', 'icon' => 'archive', 'text' => 'Arkiverad']
                ];
                $statusConfig = $statusConfigs[$report['status']] ?? ['class' => '', 'icon' => 'circle', 'text' => $report['status']];
                ?>
                <div class="rr-item <?= $statusConfig['class'] ?>">
                    <div class="rr-item-thumb">
                        <?php if ($report['featured_image']): ?>
                            <img src="<?= htmlspecialchars($report['featured_image']) ?>" alt="">
                        <?php elseif ($report['youtube_video_id']): ?>
                            <img src="https://img.youtube.com/vi/<?= htmlspecialchars($report['youtube_video_id']) ?>/mqdefault.jpg" alt="">
                            <span class="rr-item-play"><i data-lucide="play"></i></span>
                        <?php else: ?>
                            <div class="rr-item-placeholder">
                                <i data-lucide="file-text"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="rr-item-body">
                        <div class="rr-item-top">
                            <h3 class="rr-item-title"><?= htmlspecialchars($report['title']) ?></h3>
                            <span class="rr-item-status">
                                <i data-lucide="<?= $statusConfig['icon'] ?>"></i>
                                <?= $statusConfig['text'] ?>
                            </span>
                        </div>
                        <div class="rr-item-meta">
                            <span><i data-lucide="calendar"></i> <?= date('j M Y', strtotime($report['created_at'])) ?></span>
                            <span><i data-lucide="eye"></i> <?= number_format($report['views']) ?></span>
                            <span><i data-lucide="heart"></i> <?= number_format($report['likes']) ?></span>
                        </div>
                    </div>
                    <div class="rr-item-actions">
                        <?php if ($report['status'] === 'published'): ?>
                            <a href="/news/<?= htmlspecialchars($report['slug']) ?>" class="btn btn-sm btn-primary" target="_blank" title="Visa">
                                <i data-lucide="external-link"></i>
                            </a>
                        <?php endif; ?>
                        <a href="?edit=<?= $report['id'] ?>" class="btn btn-sm btn-secondary" title="Redigera">
                            <i data-lucide="edit-2"></i>
                        </a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Ta bort denna report?');">
                            <input type="hidden" name="action" value="delete_report">
                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-ghost text-error" title="Ta bort">
                                <i data-lucide="trash-2"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="rr-empty-state">
    <div class="rr-empty-icon">
        <i data-lucide="pen-tool"></i>
    </div>
    <h3>Inga reports ännu</h3>
    <p>Du har inte skrivit några race reports. Fyll i formuläret ovan för att dela din första tävlingsupplevelse!</p>
</div>
<?php endif; ?>

<?php include HUB_ROOT . '/admin/components/format-toolbar.php'; ?>

<script>
// Media type toggle (YouTube OR Instagram)
function toggleMediaType(type) {
    // Toggle buttons
    document.querySelectorAll('.rr-media-toggle-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.rr-toggle-' + type).classList.add('active');

    // Toggle input areas
    document.querySelectorAll('.rr-media-input-area').forEach(a => a.classList.remove('active'));
    document.getElementById('media-' + type).classList.add('active');

    // Clear the hidden one so only one gets submitted
    if (type === 'youtube') {
        document.getElementById('instagram_url').value = '';
    } else {
        document.getElementById('youtube_url').value = '';
        // Clear YouTube preview
        const preview = document.getElementById('youtube-preview');
        if (preview) preview.style.display = 'none';
    }
}

// Cover image upload
document.getElementById('cover_file_input').addEventListener('change', async function() {
    const file = this.files[0];
    if (!file) return;

    // Validate
    if (!file.type.startsWith('image/')) {
        alert('Välj en bildfil (JPG, PNG, etc.)');
        return;
    }
    if (file.size > 10 * 1024 * 1024) {
        alert('Bilden är för stor. Max 10 MB.');
        return;
    }

    const area = document.getElementById('cover_upload_area');

    // Show loading
    area.innerHTML = '<div class="rr-cover-loading"><i data-lucide="loader-2" class="spin" style="width:32px;height:32px;"></i></div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder', 'general');

        const response = await fetch('/api/media.php?action=upload', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success && result.url) {
            const imageUrl = result.url.startsWith('/') ? result.url : '/' + result.url;
            document.getElementById('featured_image_input').value = imageUrl;

            area.classList.add('has-image');
            area.innerHTML = `
                <img src="${imageUrl}" alt="Omslagsbild" id="cover_preview_img">
                <div class="rr-cover-overlay">
                    <i data-lucide="camera"></i>
                    <span>Byt bild</span>
                </div>
                <button type="button" class="rr-cover-remove" onclick="event.stopPropagation(); removeCoverImage()">
                    <i data-lucide="x"></i>
                </button>
            `;
        } else {
            throw new Error(result.error || 'Uppladdning misslyckades');
        }
    } catch (err) {
        // Restore placeholder
        area.classList.remove('has-image');
        area.innerHTML = `
            <div class="rr-cover-placeholder" id="cover_placeholder">
                <i data-lucide="image-plus"></i>
                <span>Klicka för att ladda upp omslagsbild</span>
            </div>
        `;
        alert('Kunde inte ladda upp bilden: ' + err.message);
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
    // Reset file input
    this.value = '';
});

function removeCoverImage() {
    document.getElementById('featured_image_input').value = '';
    const area = document.getElementById('cover_upload_area');
    area.classList.remove('has-image');
    area.innerHTML = `
        <div class="rr-cover-placeholder" id="cover_placeholder">
            <i data-lucide="image-plus"></i>
            <span>Klicka för att ladda upp omslagsbild</span>
        </div>
    `;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// YouTube preview
function previewYoutube(url) {
    const previewContainer = document.getElementById('youtube-preview');
    const embedContainer = document.getElementById('youtube-embed');

    if (!url) {
        previewContainer.style.display = 'none';
        embedContainer.innerHTML = '';
        return;
    }

    let videoId = null;
    const patterns = [
        /youtube\.com\/watch\?v=([^&\s]+)/,
        /youtu\.be\/([^?\s]+)/,
        /youtube\.com\/embed\/([^?\s]+)/,
        /youtube\.com\/shorts\/([^?\s]+)/
    ];

    for (const pattern of patterns) {
        const match = url.match(pattern);
        if (match) {
            videoId = match[1];
            break;
        }
    }

    if (!videoId) {
        previewContainer.style.display = 'block';
        embedContainer.innerHTML = '<p class="text-muted">Kunde inte tolka YouTube-länken.</p>';
        return;
    }

    previewContainer.style.display = 'block';
    embedContainer.innerHTML = `
        <a href="${url}" target="_blank" class="rr-youtube-card">
            <div class="rr-youtube-thumbnail">
                <img src="https://img.youtube.com/vi/${videoId}/maxresdefault.jpg"
                     alt="YouTube video"
                     onerror="this.src='https://img.youtube.com/vi/${videoId}/hqdefault.jpg'">
                <div class="rr-youtube-play">
                    <svg viewBox="0 0 68 48" width="68" height="48">
                        <path fill="#f00" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55c-2.93.78-4.63 3.26-5.42 6.19C.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z"/>
                        <path fill="#fff" d="M45 24L27 14v20"/>
                    </svg>
                </div>
            </div>
            <div class="rr-youtube-info">Klicka för att öppna på YouTube</div>
        </a>
    `;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// Init on load
document.addEventListener('DOMContentLoaded', function() {
    const youtubeInput = document.getElementById('youtube_url');
    if (youtubeInput && youtubeInput.value) {
        previewYoutube(youtubeInput.value);
    }
});
</script>
