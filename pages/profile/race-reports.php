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
require_once HUB_V3_ROOT . '/includes/RaceReportManager.php';
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
        $tags = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));

        if (empty($title)) {
            $error = 'Titel krävs';
        } elseif (empty($content)) {
            $error = 'Innehåll krävs';
        } else {
            $reportId = $reportManager->createReport([
                'rider_id' => $currentUser['id'],
                'event_id' => $eventId,
                'title' => $title,
                'content' => $content,
                'featured_image' => $featuredImage ?: null,
                'status' => 'draft'
            ], $tags);

            if ($reportId) {
                $message = 'Race report skapad! Den granskas av admin innan publicering.';
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

        $report = $reportManager->getReport($reportId);
        if (!$report || $report['rider_id'] != $currentUser['id']) {
            $error = 'Du har inte behörighet att redigera denna report.';
        } elseif ($report['status'] === 'published') {
            $error = 'Publicerade reports kan inte redigeras.';
        } else {
            $result = $reportManager->updateReport($reportId, [
                'title' => $title,
                'content' => $content,
                'event_id' => $eventId,
                'featured_image' => $featuredImage ?: null
            ]);
            if ($result) {
                $message = 'Race report uppdaterad!';
            } else {
                $error = 'Kunde inte uppdatera.';
            }
        }
    } elseif ($action === 'delete_report') {
        $reportId = (int)$_POST['report_id'];

        $report = $reportManager->getReport($reportId);
        if (!$report || $report['rider_id'] != $currentUser['id']) {
            $error = 'Du har inte behörighet att ta bort denna report.';
        } elseif ($report['status'] === 'published') {
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

// Get rider's reports
$myReports = $reportManager->listReports([
    'rider_id' => $currentUser['id'],
    'include_drafts' => true,
    'per_page' => 50
]);

// Get rider's recent events for dropdown
$recentEvents = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT e.id, e.name, e.date
        FROM events e
        INNER JOIN results r ON e.id = r.event_id
        WHERE r.cyclist_id = ?
        ORDER BY e.date DESC
        LIMIT 20
    ");
    $stmt->execute([$currentUser['id']]);
    $recentEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore
}

// Check if editing
$editReport = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editReport = $reportManager->getReport((int)$_GET['edit']);
    if ($editReport && $editReport['rider_id'] != $currentUser['id']) {
        $editReport = null;
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

<!-- Page Header -->
<div class="rr-header">
    <div class="rr-header-content">
        <div class="rr-header-icon">
            <i data-lucide="file-text"></i>
        </div>
        <div>
            <h1 class="rr-title">Mina Race Reports</h1>
            <p class="rr-subtitle">Dela dina tävlingsupplevelser med communityn</p>
        </div>
    </div>
    <?php if (!empty($myReports['reports'])): ?>
    <div class="rr-stats">
        <div class="rr-stat">
            <span class="rr-stat-value"><?= $publishedCount ?></span>
            <span class="rr-stat-label">Publicerade</span>
        </div>
        <div class="rr-stat">
            <span class="rr-stat-value"><?= $draftCount ?></span>
            <span class="rr-stat-label">Utkast</span>
        </div>
        <div class="rr-stat">
            <span class="rr-stat-value"><?= number_format($totalViews) ?></span>
            <span class="rr-stat-label">Visningar</span>
        </div>
        <div class="rr-stat">
            <span class="rr-stat-value"><?= number_format($totalLikes) ?></span>
            <span class="rr-stat-label">Likes</span>
        </div>
    </div>
    <?php endif; ?>
</div>

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

<div class="rr-layout">
    <!-- Main Content -->
    <div class="rr-main">
        <!-- Create/Edit Form -->
        <div class="card rr-form-card">
            <div class="card-header">
                <h2>
                    <i data-lucide="<?= $editReport ? 'edit-3' : 'plus-circle' ?>"></i>
                    <?= $editReport ? 'Redigera Race Report' : 'Skriv ny Race Report' ?>
                </h2>
            </div>
            <form method="POST" class="card-body">
                <?php if ($editReport): ?>
                    <input type="hidden" name="action" value="update_report">
                    <input type="hidden" name="report_id" value="<?= $editReport['id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="action" value="create_report">
                <?php endif; ?>

                <div class="rr-form-grid">
                    <div class="form-group">
                        <label class="form-label">Titel <span class="text-error">*</span></label>
                        <input type="text"
                               name="title"
                               class="form-input"
                               placeholder="T.ex. Min första Enduro-tävling i Åre"
                               value="<?= htmlspecialchars($editReport['title'] ?? '') ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Kopplat event</label>
                        <select name="event_id" class="form-select">
                            <option value="">-- Välj event (valfritt) --</option>
                            <?php foreach ($recentEvents as $event): ?>
                                <option value="<?= $event['id'] ?>"
                                        <?= ($editReport && $editReport['event_id'] == $event['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Instagram, YouTube eller egen text -->
                <div class="rr-content-toggle">
                    <div class="rr-toggle-header">
                        <span class="rr-toggle-label">Välj innehållstyp:</span>
                        <div class="rr-toggle-buttons">
                            <button type="button" class="rr-toggle-btn active" data-content="instagram" onclick="toggleContentType('instagram')">
                                <i data-lucide="instagram"></i> Instagram
                            </button>
                            <button type="button" class="rr-toggle-btn" data-content="youtube" onclick="toggleContentType('youtube')">
                                <i data-lucide="youtube"></i> YouTube
                            </button>
                            <button type="button" class="rr-toggle-btn" data-content="text" onclick="toggleContentType('text')">
                                <i data-lucide="file-text"></i> Egen text
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Instagram Content -->
                <div id="content-instagram" class="rr-content-section">
                    <div class="form-group">
                        <label class="form-label">Instagram-länk</label>
                        <input type="url"
                               name="instagram_url"
                               id="instagram_url"
                               class="form-input"
                               placeholder="https://www.instagram.com/p/ABC123..."
                               value="<?= htmlspecialchars($editReport['instagram_url'] ?? '') ?>"
                               onchange="previewInstagram(this.value)">
                        <small class="form-help">Klistra in länken till ditt Instagram-inlägg. Texten och bilden visas automatiskt.</small>
                    </div>
                    <div id="instagram-preview" class="rr-instagram-preview" style="display: none;">
                        <div class="rr-preview-label"><i data-lucide="eye"></i> Förhandsgranskning</div>
                        <div id="instagram-embed"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kort sammanfattning (valfritt)</label>
                        <textarea name="content"
                                  class="form-textarea"
                                  rows="3"
                                  id="content_short"
                                  placeholder="Lägg till en kort intro om du vill (visas innan Instagram-inlägget)"><?= htmlspecialchars($editReport['content'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- YouTube Content -->
                <div id="content-youtube" class="rr-content-section" style="display: none;">
                    <div class="form-group">
                        <label class="form-label">YouTube-länk</label>
                        <input type="url"
                               name="youtube_url"
                               id="youtube_url"
                               class="form-input"
                               placeholder="https://www.youtube.com/watch?v=..."
                               value="<?= htmlspecialchars($editReport['youtube_url'] ?? '') ?>"
                               onchange="previewYoutube(this.value)">
                        <small class="form-help">Klistra in länken till din YouTube-video. Thumbnail och länk visas automatiskt.</small>
                    </div>
                    <div id="youtube-preview" class="rr-youtube-preview" style="display: none;">
                        <div class="rr-preview-label"><i data-lucide="eye"></i> Förhandsgranskning</div>
                        <div id="youtube-embed" class="rr-youtube-embed"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Beskrivning (valfritt)</label>
                        <textarea name="content_youtube"
                                  class="form-textarea"
                                  rows="3"
                                  id="content_youtube"
                                  placeholder="Lägg till en kort beskrivning av videon..."><?= htmlspecialchars($editReport['content'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Text Content -->
                <div id="content-text" class="rr-content-section" style="display: none;">
                    <div class="rr-form-grid">
                        <div class="form-group">
                            <label class="form-label">Bild-URL</label>
                            <input type="url"
                                   name="featured_image"
                                   class="form-input"
                                   placeholder="https://imgur.com/..."
                                   value="<?= htmlspecialchars($editReport['featured_image'] ?? '') ?>">
                            <small class="form-help">Länk till bild från Imgur, Google Photos etc.</small>
                        </div>
                        <?php if (!$editReport): ?>
                        <div class="form-group">
                            <label class="form-label">Taggar</label>
                            <input type="text"
                                   name="tags"
                                   class="form-input"
                                   placeholder="enduro, åre, sm, 2026">
                            <small class="form-help">Kommaseparerade</small>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Innehåll <span class="text-error">*</span></label>
                        <textarea name="content_full"
                                  class="form-textarea rr-textarea"
                                  rows="12"
                                  id="content_full"
                                  placeholder="Berätta om din tävling! Hur förberedde du dig? Hur gick det? Vad lärde du dig?"><?= htmlspecialchars($editReport['content'] ?? '') ?></textarea>
                    </div>
                </div>

                <input type="hidden" name="content_type" id="content_type" value="instagram">

                <div class="rr-form-actions">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i data-lucide="<?= $editReport ? 'save' : 'send' ?>"></i>
                        <?= $editReport ? 'Spara ändringar' : 'Skicka in för granskning' ?>
                    </button>
                    <?php if ($editReport): ?>
                        <a href="/profile/race-reports" class="btn btn-secondary">
                            <i data-lucide="x"></i>
                            Avbryt
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!$editReport): ?>
                <div class="rr-form-notice">
                    <i data-lucide="shield-check"></i>
                    <span>Din report sparas som utkast och granskas av admin innan publicering.</span>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- My Reports List -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <i data-lucide="folder"></i>
                    Mina reports
                </h2>
                <span class="badge"><?= count($myReports['reports']) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($myReports['reports'])): ?>
                    <div class="rr-empty">
                        <div class="rr-empty-icon">
                            <i data-lucide="pen-tool"></i>
                        </div>
                        <h3>Inga reports ännu</h3>
                        <p>Du har inte skrivit några race reports. Använd formuläret ovan för att dela din första tävlingsupplevelse!</p>
                    </div>
                <?php else: ?>
                    <div class="rr-list">
                        <?php foreach ($myReports['reports'] as $report): ?>
                            <?php
                            $statusConfig = match($report['status']) {
                                'published' => ['class' => 'rr-status-published', 'icon' => 'check-circle', 'text' => 'Publicerad'],
                                'draft' => ['class' => 'rr-status-draft', 'icon' => 'clock', 'text' => 'Väntar på granskning'],
                                'archived' => ['class' => 'rr-status-archived', 'icon' => 'archive', 'text' => 'Arkiverad'],
                                default => ['class' => '', 'icon' => 'circle', 'text' => $report['status']]
                            };
                            ?>
                            <div class="rr-item <?= $statusConfig['class'] ?>">
                                <div class="rr-item-image">
                                    <?php if ($report['featured_image']): ?>
                                        <img src="<?= htmlspecialchars($report['featured_image']) ?>" alt="">
                                    <?php else: ?>
                                        <div class="rr-item-placeholder">
                                            <i data-lucide="image"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="rr-item-content">
                                    <div class="rr-item-header">
                                        <h3 class="rr-item-title"><?= htmlspecialchars($report['title']) ?></h3>
                                        <div class="rr-item-status">
                                            <i data-lucide="<?= $statusConfig['icon'] ?>"></i>
                                            <?= $statusConfig['text'] ?>
                                        </div>
                                    </div>
                                    <div class="rr-item-meta">
                                        <span><i data-lucide="calendar"></i> <?= date('j M Y', strtotime($report['created_at'])) ?></span>
                                        <span><i data-lucide="eye"></i> <?= number_format($report['views']) ?></span>
                                        <span><i data-lucide="heart"></i> <?= number_format($report['likes']) ?></span>
                                    </div>
                                    <div class="rr-item-actions">
                                        <?php if ($report['status'] === 'published'): ?>
                                            <a href="/race-reports/<?= $report['slug'] ?>" class="btn btn-sm btn-primary" target="_blank">
                                                <i data-lucide="external-link"></i> Visa
                                            </a>
                                        <?php else: ?>
                                            <a href="?edit=<?= $report['id'] ?>" class="btn btn-sm btn-secondary">
                                                <i data-lucide="edit-2"></i> Redigera
                                            </a>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Ta bort denna report?');">
                                                <input type="hidden" name="action" value="delete_report">
                                                <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-ghost">
                                                    <i data-lucide="trash-2"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="rr-sidebar">
        <div class="card rr-tips-card">
            <div class="card-header">
                <h3><i data-lucide="lightbulb"></i> Skrivtips</h3>
            </div>
            <div class="card-body">
                <ul class="rr-tips">
                    <li>
                        <i data-lucide="instagram"></i>
                        <span>Klistra in länk till Instagram-inlägg</span>
                    </li>
                    <li>
                        <i data-lucide="youtube"></i>
                        <span>Eller dela en YouTube-video</span>
                    </li>
                    <li>
                        <i data-lucide="file-text"></i>
                        <span>Eller skriv egen text</span>
                    </li>
                    <li>
                        <i data-lucide="link"></i>
                        <span>Koppla till ett event du deltog i</span>
                    </li>
                    <li>
                        <i data-lucide="clock"></i>
                        <span>Granskning tar 1-2 dagar</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i data-lucide="help-circle"></i> Vanliga frågor</h3>
            </div>
            <div class="card-body">
                <div class="rr-faq">
                    <details>
                        <summary>Hur lång tid tar granskningen?</summary>
                        <p>Vanligtvis 1-2 dagar. Du får notis när den publiceras.</p>
                    </details>
                    <details>
                        <summary>Kan jag redigera efter publicering?</summary>
                        <p>Nej, kontakta admin om något behöver ändras.</p>
                    </details>
                    <details>
                        <summary>Var visas min report?</summary>
                        <p>På Race Reports-sidan och eventuellt på startsidan.</p>
                    </details>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Race Reports Page - Clean Modern Design */

/* Stats Cards Grid */
.rr-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}

.rr-stat-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    text-align: center;
    transition: all 0.2s ease;
}

.rr-stat-card:hover {
    border-color: var(--color-accent);
    transform: translateY(-2px);
}

.rr-stat-icon {
    width: 40px;
    height: 40px;
    margin: 0 auto var(--space-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent-light);
    border-radius: var(--radius-md);
    color: var(--color-accent);
}

.rr-stat-icon i {
    width: 20px;
    height: 20px;
}

.rr-stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-text-primary);
    line-height: 1;
}

.rr-stat-label {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: var(--space-xs);
}

/* Main Layout - Single Column for Focus */
.rr-layout {
    display: flex;
    flex-direction: column;
    gap: var(--space-xl);
}

/* Tips Row - Horizontal on Desktop */
.rr-tips-row {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: var(--space-sm);
    padding: var(--space-md);
    background: linear-gradient(135deg, var(--color-bg-card), var(--color-accent-light));
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
    margin-bottom: var(--space-lg);
}

.rr-tip {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--space-xs);
    padding: var(--space-sm);
}

.rr-tip i {
    width: 24px;
    height: 24px;
    color: var(--color-accent);
}

.rr-tip span {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    line-height: 1.3;
}

/* Form Card */
.rr-form-card {
    border: 2px solid var(--color-accent-light);
}

.rr-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}

.rr-textarea {
    font-family: var(--font-body);
    line-height: 1.6;
    resize: vertical;
    min-height: 200px;
}

.rr-form-actions {
    display: flex;
    gap: var(--space-md);
    margin-top: var(--space-lg);
}

.rr-form-notice {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-top: var(--space-lg);
    padding: var(--space-md);
    background: var(--color-accent-light);
    border-radius: var(--radius-md);
    font-size: 0.875rem;
    color: var(--color-accent-text);
}

.rr-form-notice i {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.form-help {
    display: block;
    margin-top: var(--space-xs);
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

/* Empty State */
.rr-empty {
    text-align: center;
    padding: var(--space-2xl) var(--space-lg);
}

.rr-empty-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto var(--space-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-hover);
    border-radius: 50%;
    color: var(--color-text-muted);
}

.rr-empty-icon i {
    width: 36px;
    height: 36px;
}

.rr-empty h3 {
    font-size: 1.25rem;
    margin: 0 0 var(--space-sm);
    color: var(--color-text-primary);
}

.rr-empty p {
    color: var(--color-text-secondary);
    max-width: 400px;
    margin: 0 auto;
}

/* Report List */
.rr-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.rr-item {
    display: flex;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-page);
    border-radius: var(--radius-md);
    border-left: 3px solid var(--color-border);
    transition: all 0.15s ease;
}

.rr-item:hover {
    background: var(--color-bg-hover);
}

.rr-status-published {
    border-left-color: var(--color-success);
}

.rr-status-draft {
    border-left-color: var(--color-warning);
}

.rr-item-image {
    width: 100px;
    height: 70px;
    flex-shrink: 0;
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.rr-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.rr-item-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-surface);
    color: var(--color-text-muted);
}

.rr-item-placeholder i {
    width: 24px;
    height: 24px;
}

.rr-item-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.rr-item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-sm);
}

.rr-item-title {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
    color: var(--color-text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rr-item-status {
    display: flex;
    align-items: center;
    gap: var(--space-2xs);
    font-size: 0.75rem;
    padding: var(--space-2xs) var(--space-sm);
    border-radius: var(--radius-full);
    white-space: nowrap;
    flex-shrink: 0;
}

.rr-status-published .rr-item-status {
    background: rgba(16, 185, 129, 0.1);
    color: var(--color-success);
}

.rr-status-draft .rr-item-status {
    background: rgba(251, 191, 36, 0.1);
    color: var(--color-warning);
}

.rr-item-status i {
    width: 12px;
    height: 12px;
}

.rr-item-meta {
    display: flex;
    gap: var(--space-md);
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.rr-item-meta span {
    display: flex;
    align-items: center;
    gap: var(--space-2xs);
}

.rr-item-meta i {
    width: 12px;
    height: 12px;
}

.rr-item-actions {
    display: flex;
    gap: var(--space-xs);
    margin-top: auto;
}

/* Tips Card */
.rr-tips-card {
    background: linear-gradient(135deg, var(--color-bg-card), var(--color-accent-light));
}

.rr-tips {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.rr-tips li {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.rr-tips li i {
    width: 16px;
    height: 16px;
    color: var(--color-accent);
    flex-shrink: 0;
    margin-top: 2px;
}

/* FAQ */
.rr-faq {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.rr-faq details {
    border-bottom: 1px solid var(--color-border);
    padding-bottom: var(--space-sm);
}

.rr-faq details:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.rr-faq summary {
    cursor: pointer;
    font-weight: 500;
    font-size: 0.875rem;
    color: var(--color-text-primary);
    padding: var(--space-xs) 0;
}

.rr-faq summary:hover {
    color: var(--color-accent);
}

.rr-faq p {
    margin: var(--space-xs) 0 0;
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    padding-left: var(--space-md);
}

/* Content Toggle */
.rr-content-toggle {
    margin-bottom: var(--space-lg);
    padding: var(--space-md);
    background: var(--color-bg-page);
    border-radius: var(--radius-md);
}

.rr-toggle-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md);
    flex-wrap: wrap;
}

.rr-toggle-label {
    font-weight: 500;
    color: var(--color-text-secondary);
}

.rr-toggle-buttons {
    display: flex;
    gap: var(--space-xs);
    background: var(--color-bg-surface);
    padding: var(--space-2xs);
    border-radius: var(--radius-md);
}

.rr-toggle-btn {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    border: none;
    background: transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    transition: all 0.15s ease;
}

.rr-toggle-btn:hover {
    background: var(--color-bg-hover);
    color: var(--color-text-primary);
}

.rr-toggle-btn.active {
    background: var(--color-accent);
    color: white;
}

.rr-toggle-btn i {
    width: 16px;
    height: 16px;
}

.rr-content-section {
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Instagram Preview */
.rr-instagram-preview {
    margin: var(--space-md) 0;
    padding: var(--space-md);
    background: var(--color-bg-page);
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
}

.rr-preview-label {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-muted);
    margin-bottom: var(--space-sm);
}

.rr-preview-label i {
    width: 14px;
    height: 14px;
}

#instagram-embed {
    max-width: 540px;
    margin: 0 auto;
}

#instagram-embed iframe {
    border-radius: var(--radius-md) !important;
}

/* YouTube Preview */
.rr-youtube-preview {
    margin: var(--space-md) 0;
    padding: var(--space-md);
    background: var(--color-bg-page);
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
}

.rr-youtube-card {
    display: block;
    text-decoration: none;
    max-width: 540px;
    margin: 0 auto;
    border-radius: var(--radius-md);
    overflow: hidden;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    transition: all 0.2s ease;
}

.rr-youtube-card:hover {
    border-color: var(--color-accent);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.rr-youtube-thumbnail {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #000;
}

.rr-youtube-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.rr-youtube-play {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    opacity: 0.9;
    transition: opacity 0.2s, transform 0.2s;
}

.rr-youtube-card:hover .rr-youtube-play {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1.1);
}

.rr-youtube-info {
    padding: var(--space-md);
    text-align: center;
}

.rr-youtube-link {
    color: var(--color-text-secondary);
    font-size: 0.875rem;
}

.rr-youtube-card:hover .rr-youtube-link {
    color: var(--color-accent);
}

/* Mobile Responsive */
@media (max-width: 1024px) {
    .rr-layout {
        grid-template-columns: 1fr;
    }

    .rr-sidebar {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--space-md);
    }
}

@media (max-width: 768px) {
    .rr-header {
        flex-direction: column;
    }

    .rr-stats {
        width: 100%;
        justify-content: space-around;
    }

    .rr-form-grid {
        grid-template-columns: 1fr;
    }

    .rr-sidebar {
        grid-template-columns: 1fr;
    }

    .rr-item {
        flex-direction: column;
    }

    .rr-item-image {
        width: 100%;
        height: 120px;
    }

    .rr-item-header {
        flex-direction: column;
        gap: var(--space-xs);
    }

    .rr-item-status {
        align-self: flex-start;
    }
}

@media (max-width: 480px) {
    .rr-header-icon {
        width: 48px;
        height: 48px;
    }

    .rr-header-icon i {
        width: 24px;
        height: 24px;
    }

    .rr-title {
        font-size: 1.5rem;
    }

    .rr-stats {
        flex-wrap: wrap;
        gap: var(--space-md);
    }

    .rr-stat {
        flex: 1;
        min-width: 60px;
    }

    .rr-form-actions {
        flex-direction: column;
    }

    .rr-form-actions .btn {
        width: 100%;
        justify-content: center;
    }

    .rr-toggle-header {
        flex-direction: column;
        align-items: stretch;
    }

    .rr-toggle-buttons {
        justify-content: center;
    }
}
</style>

<script>
// Content type toggle
function toggleContentType(type) {
    // Update buttons
    document.querySelectorAll('.rr-toggle-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.content === type);
    });

    // Show/hide sections
    document.getElementById('content-instagram').style.display = type === 'instagram' ? 'block' : 'none';
    document.getElementById('content-youtube').style.display = type === 'youtube' ? 'block' : 'none';
    document.getElementById('content-text').style.display = type === 'text' ? 'block' : 'none';

    // Update hidden field
    document.getElementById('content_type').value = type;

    // Update required fields
    if (type === 'text') {
        document.getElementById('content_full').setAttribute('required', 'required');
    } else {
        document.getElementById('content_full').removeAttribute('required');
    }

    // Re-init Lucide icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

// Instagram preview
function previewInstagram(url) {
    const previewContainer = document.getElementById('instagram-preview');
    const embedContainer = document.getElementById('instagram-embed');

    if (!url || !url.includes('instagram.com')) {
        previewContainer.style.display = 'none';
        embedContainer.innerHTML = '';
        return;
    }

    // Extract post ID from URL
    const match = url.match(/instagram\.com\/(?:p|reel)\/([^\/\?]+)/);
    if (!match) {
        previewContainer.style.display = 'none';
        embedContainer.innerHTML = '<p class="text-muted">Kunde inte tolka Instagram-länken. Kontrollera att det är en länk till ett inlägg.</p>';
        return;
    }

    const postId = match[1];
    previewContainer.style.display = 'block';

    // Create embed iframe
    embedContainer.innerHTML = `
        <blockquote class="instagram-media" data-instgrm-captioned data-instgrm-permalink="https://www.instagram.com/p/${postId}/" data-instgrm-version="14" style="background:#FFF; border:0; border-radius:3px; box-shadow:0 0 1px 0 rgba(0,0,0,0.5),0 1px 10px 0 rgba(0,0,0,0.15); margin: 1px; max-width:540px; min-width:326px; padding:0; width:100%;">
            <div style="padding:16px;">
                <a href="https://www.instagram.com/p/${postId}/" style="background:#FFFFFF; line-height:0; padding:0 0; text-align:center; text-decoration:none; width:100%;" target="_blank">
                    <div style="display:flex; flex-direction:row; align-items:center;">
                        <div style="background-color:#F4F4F4; border-radius:50%; flex-grow:0; height:40px; margin-right:14px; width:40px;"></div>
                        <div style="display:flex; flex-direction:column; flex-grow:1; justify-content:center;">
                            <div style="background-color:#F4F4F4; border-radius:4px; flex-grow:0; height:14px; margin-bottom:6px; width:100px;"></div>
                            <div style="background-color:#F4F4F4; border-radius:4px; flex-grow:0; height:14px; width:60px;"></div>
                        </div>
                    </div>
                    <div style="padding:19% 0;"></div>
                    <div style="display:block; height:50px; margin:0 auto 12px; width:50px;">
                        <svg width="50px" height="50px" viewBox="0 0 60 60" version="1.1" xmlns="https://www.w3.org/2000/svg">
                            <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                <g transform="translate(-511.000000, -20.000000)" fill="#000000">
                                    <g><path d="M556.869,30.41 C554.814,30.41 553.148,32.076 553.148,34.131 C553.148,36.186 554.814,37.852 556.869,37.852 C558.924,37.852 560.59,36.186 560.59,34.131 C560.59,32.076 558.924,30.41 556.869,30.41 M541,60.657 C535.114,60.657 530.342,55.887 530.342,50 C530.342,44.114 535.114,39.342 541,39.342 C546.887,39.342 551.658,44.114 551.658,50 C551.658,55.887 546.887,60.657 541,60.657 M541,33.886 C532.1,33.886 524.886,41.1 524.886,50 C524.886,58.899 532.1,66.113 541,66.113 C549.9,66.113 557.115,58.899 557.115,50 C557.115,41.1 549.9,33.886 541,33.886 M565.378,62.101 C565.244,65.022 564.756,66.606 564.346,67.663 C563.803,69.06 563.154,70.057 562.106,71.106 C561.058,72.155 560.06,72.803 558.662,73.347 C557.607,73.757 556.021,74.244 553.102,74.378 C549.944,74.521 548.997,74.552 541,74.552 C533.003,74.552 532.056,74.521 528.898,74.378 C525.979,74.244 524.393,73.757 523.338,73.347 C521.94,72.803 520.942,72.155 519.894,71.106 C518.846,70.057 518.197,69.06 517.654,67.663 C517.244,66.606 516.755,65.022 516.623,62.101 C516.479,58.943 516.448,57.996 516.448,50 C516.448,42.003 516.479,41.056 516.623,37.899 C516.755,34.978 517.244,33.391 517.654,32.338 C518.197,30.938 518.846,29.942 519.894,28.894 C520.942,27.846 521.94,27.196 523.338,26.654 C524.393,26.244 525.979,25.756 528.898,25.623 C532.057,25.479 533.004,25.448 541,25.448 C548.997,25.448 549.943,25.479 553.102,25.623 C556.021,25.756 557.607,26.244 558.662,26.654 C560.06,27.196 561.058,27.846 562.106,28.894 C563.154,29.942 563.803,30.938 564.346,32.338 C564.756,33.391 565.244,34.978 565.378,37.899 C565.522,41.056 565.552,42.003 565.552,50 C565.552,57.996 565.522,58.943 565.378,62.101 M570.82,37.631 C570.674,34.438 570.167,32.258 569.425,30.349 C568.659,28.377 567.633,26.702 565.965,25.035 C564.297,23.368 562.623,22.342 560.652,21.575 C558.743,20.834 556.562,20.326 553.369,20.18 C550.169,20.033 549.148,20 541,20 C532.853,20 531.831,20.033 528.631,20.18 C525.438,20.326 523.257,20.834 521.349,21.575 C519.376,22.342 517.703,23.368 516.035,25.035 C514.368,26.702 513.342,28.377 512.574,30.349 C511.834,32.258 511.326,34.438 511.181,37.631 C511.035,40.831 511,41.851 511,50 C511,58.147 511.035,59.17 511.181,62.369 C511.326,65.562 511.834,67.743 512.574,69.651 C513.342,71.625 514.368,73.296 516.035,74.965 C517.703,76.634 519.376,77.658 521.349,78.425 C523.257,79.167 525.438,79.673 528.631,79.82 C531.831,79.965 532.853,80.001 541,80.001 C549.148,80.001 550.169,79.965 553.369,79.82 C556.562,79.673 558.743,79.167 560.652,78.425 C562.623,77.658 564.297,76.634 565.965,74.965 C567.633,73.296 568.659,71.625 569.425,69.651 C570.167,67.743 570.674,65.562 570.82,62.369 C570.966,59.17 571,58.147 571,50 C571,41.851 570.966,40.831 570.82,37.631"></path></g>
                                </g>
                            </g>
                        </svg>
                    </div>
                    <div style="padding-top:8px;">
                        <div style="color:#3897f0; font-family:Arial,sans-serif; font-size:14px; font-style:normal; font-weight:550;">Visa inlägget på Instagram</div>
                    </div>
                </a>
            </div>
        </blockquote>
    `;

    // Load Instagram embed script
    if (!document.getElementById('instagram-embed-script')) {
        const script = document.createElement('script');
        script.id = 'instagram-embed-script';
        script.src = '//www.instagram.com/embed.js';
        script.async = true;
        document.body.appendChild(script);
    } else if (window.instgrm) {
        window.instgrm.Embeds.process();
    }

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
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

    // Extract video ID from URL
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
        embedContainer.innerHTML = '<p class="text-muted">Kunde inte tolka YouTube-länken. Kontrollera att det är en giltig länk.</p>';
        return;
    }

    previewContainer.style.display = 'block';

    // Show YouTube thumbnail with play overlay
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
            <div class="rr-youtube-info">
                <span class="rr-youtube-link">Klicka för att öppna på YouTube</span>
            </div>
        </a>
    `;

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

// Form submission handling
document.querySelector('.rr-form-card form').addEventListener('submit', function(e) {
    const contentType = document.getElementById('content_type').value;

    if (contentType === 'instagram') {
        // Use short content for Instagram posts
        const shortContent = document.getElementById('content_short').value;
        const instagramUrl = document.getElementById('instagram_url').value;

        if (!instagramUrl) {
            e.preventDefault();
            alert('Ange en Instagram-länk eller byt till "Skriv egen text"');
            return;
        }

        // Copy short content to main content field for submission
        const contentField = document.querySelector('textarea[name="content"]');
        if (contentField && !contentField.value && shortContent) {
            contentField.value = shortContent;
        }
    } else if (contentType === 'youtube') {
        // Use YouTube content
        const youtubeContent = document.getElementById('content_youtube').value;
        const youtubeUrl = document.getElementById('youtube_url').value;

        if (!youtubeUrl) {
            e.preventDefault();
            alert('Ange en YouTube-länk eller byt till en annan innehållstyp');
            return;
        }

        // Copy content to main content field
        const contentField = document.querySelector('textarea[name="content"]');
        if (contentField) {
            contentField.value = youtubeContent || '';
        }
    } else {
        // Use full content for text posts
        const fullContent = document.getElementById('content_full').value;
        if (!fullContent.trim()) {
            e.preventDefault();
            alert('Skriv innehåll för din race report');
            return;
        }

        // Copy full content to main content field
        const contentField = document.getElementById('content_short');
        if (contentField) {
            contentField.value = fullContent;
        }
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check if editing existing report with Instagram URL
    const instagramInput = document.getElementById('instagram_url');
    if (instagramInput && instagramInput.value) {
        previewInstagram(instagramInput.value);
    }

    // If editing and has content but no Instagram URL, switch to text mode
    const contentField = document.getElementById('content_short');
    if (contentField && contentField.value && (!instagramInput || !instagramInput.value)) {
        const hasSubstantialContent = contentField.value.length > 200;
        if (hasSubstantialContent) {
            toggleContentType('text');
            document.getElementById('content_full').value = contentField.value;
        }
    }
});
</script>
