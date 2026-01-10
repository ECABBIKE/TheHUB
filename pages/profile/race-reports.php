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
                'status' => 'draft' // Always start as draft
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

        // Verify ownership
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

        // Verify ownership
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
    // Verify ownership
    if ($editReport && $editReport['rider_id'] != $currentUser['id']) {
        $editReport = null;
    }
}
?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="file-text" class="page-icon"></i>
        Mina Race Reports
    </h1>
    <p class="page-subtitle">Skriv och dela dina tävlingsupplevelser</p>
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

<!-- Create/Edit Form -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <i data-lucide="<?= $editReport ? 'edit' : 'plus' ?>"></i>
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

        <div class="form-group mb-md">
            <label class="form-label">Titel *</label>
            <input type="text"
                   name="title"
                   class="form-input"
                   placeholder="T.ex. Min första Enduro-tävling"
                   value="<?= htmlspecialchars($editReport['title'] ?? '') ?>"
                   required>
        </div>

        <div class="form-group mb-md">
            <label class="form-label">Kopplat event (valfritt)</label>
            <select name="event_id" class="form-select">
                <option value="">-- Välj event --</option>
                <?php foreach ($recentEvents as $event): ?>
                    <option value="<?= $event['id'] ?>"
                            <?= ($editReport && $editReport['event_id'] == $event['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Välj ett event du deltagit i</small>
        </div>

        <div class="form-group mb-md">
            <label class="form-label">Bild-URL (valfritt)</label>
            <input type="url"
                   name="featured_image"
                   class="form-input"
                   placeholder="https://..."
                   value="<?= htmlspecialchars($editReport['featured_image'] ?? '') ?>">
            <small class="text-muted">Länk till en bild (t.ex. från Instagram eller Imgur)</small>
        </div>

        <?php if (!$editReport): ?>
        <div class="form-group mb-md">
            <label class="form-label">Taggar (valfritt)</label>
            <input type="text"
                   name="tags"
                   class="form-input"
                   placeholder="enduro, åre, sm">
            <small class="text-muted">Kommaseparerade taggar</small>
        </div>
        <?php endif; ?>

        <div class="form-group mb-lg">
            <label class="form-label">Innehåll *</label>
            <textarea name="content"
                      class="form-textarea"
                      rows="10"
                      placeholder="Berätta om din tävling, hur det gick, vad du lärde dig..."
                      required><?= htmlspecialchars($editReport['content'] ?? '') ?></textarea>
        </div>

        <div class="flex gap-md">
            <button type="submit" class="btn btn-primary">
                <i data-lucide="<?= $editReport ? 'save' : 'send' ?>"></i>
                <?= $editReport ? 'Spara ändringar' : 'Skicka in' ?>
            </button>
            <?php if ($editReport): ?>
                <a href="/profile/race-reports" class="btn btn-secondary">Avbryt</a>
            <?php endif; ?>
        </div>

        <?php if (!$editReport): ?>
        <p class="text-muted text-sm mt-md">
            <i data-lucide="info" class="icon-sm"></i>
            Din report sparas som utkast och granskas av admin innan publicering.
        </p>
        <?php endif; ?>
    </form>
</div>

<!-- My Reports List -->
<div class="card">
    <div class="card-header">
        <h2>
            <i data-lucide="list"></i>
            Mina reports (<?= count($myReports['reports']) ?>)
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($myReports['reports'])): ?>
            <div class="text-center py-lg text-muted">
                <i data-lucide="file-text" class="icon-xl mb-md" style="opacity: 0.5;"></i>
                <p>Du har inga race reports ännu.</p>
                <p class="text-sm">Skriv din första report ovan!</p>
            </div>
        <?php else: ?>
            <div class="report-list">
                <?php foreach ($myReports['reports'] as $report): ?>
                    <div class="report-item">
                        <div class="report-item-main">
                            <?php if ($report['featured_image']): ?>
                                <img src="<?= htmlspecialchars($report['featured_image']) ?>"
                                     alt="" class="report-item-image">
                            <?php else: ?>
                                <div class="report-item-image report-item-image-placeholder">
                                    <i data-lucide="image"></i>
                                </div>
                            <?php endif; ?>
                            <div class="report-item-info">
                                <h3 class="report-item-title"><?= htmlspecialchars($report['title']) ?></h3>
                                <div class="report-item-meta">
                                    <span>
                                        <i data-lucide="calendar"></i>
                                        <?= date('Y-m-d', strtotime($report['created_at'])) ?>
                                    </span>
                                    <span>
                                        <i data-lucide="eye"></i>
                                        <?= number_format($report['views']) ?> visningar
                                    </span>
                                    <span>
                                        <i data-lucide="heart"></i>
                                        <?= number_format($report['likes']) ?> likes
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="report-item-actions">
                            <?php
                            $statusClass = match($report['status']) {
                                'published' => 'badge-success',
                                'draft' => 'badge-warning',
                                'archived' => 'badge-secondary',
                                default => 'badge-secondary'
                            };
                            $statusText = match($report['status']) {
                                'published' => 'Publicerad',
                                'draft' => 'Utkast',
                                'archived' => 'Arkiverad',
                                default => $report['status']
                            };
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>

                            <?php if ($report['status'] !== 'published'): ?>
                                <a href="?edit=<?= $report['id'] ?>" class="btn btn-sm btn-secondary">
                                    <i data-lucide="edit"></i>
                                </a>
                                <form method="POST" style="display: inline;"
                                      onsubmit="return confirm('Är du säker?');">
                                    <input type="hidden" name="action" value="delete_report">
                                    <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary">
                                        <i data-lucide="trash-2"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.report-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.report-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-page);
    border-radius: var(--radius-md);
    flex-wrap: wrap;
}

.report-item-main {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex: 1;
    min-width: 0;
}

.report-item-image {
    width: 80px;
    height: 50px;
    object-fit: cover;
    border-radius: var(--radius-sm);
    flex-shrink: 0;
}

.report-item-image-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-surface);
    color: var(--color-text-muted);
}

.report-item-info {
    flex: 1;
    min-width: 0;
}

.report-item-title {
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 var(--space-xs);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.report-item-meta {
    display: flex;
    gap: var(--space-md);
    font-size: 0.75rem;
    color: var(--color-text-muted);
    flex-wrap: wrap;
}

.report-item-meta span {
    display: flex;
    align-items: center;
    gap: var(--space-2xs);
}

.report-item-meta i {
    width: 12px;
    height: 12px;
}

.report-item-actions {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    flex-shrink: 0;
}

@media (max-width: 600px) {
    .report-item {
        flex-direction: column;
        align-items: stretch;
    }

    .report-item-actions {
        justify-content: flex-end;
        margin-top: var(--space-sm);
        padding-top: var(--space-sm);
        border-top: 1px solid var(--color-border);
    }
}
</style>
