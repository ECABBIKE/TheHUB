<?php
/**
 * Bug Reports / Feedback Management - Admin Page
 * TheHUB - View and manage user-submitted bug reports and feedback
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

global $pdo;

// Require admin
if (!hasRole('admin') && !hasRole('super_admin')) {
    header('Location: /admin/');
    exit;
}

// Handle POST actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reportId = (int)($_POST['report_id'] ?? 0);

    if ($action === 'update_status' && $reportId) {
        $newStatus = $_POST['status'] ?? '';
        if (in_array($newStatus, ['new', 'in_progress', 'resolved', 'wontfix'])) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE bug_reports
                    SET status = ?,
                        resolved_at = " . (in_array($newStatus, ['resolved', 'wontfix']) ? 'NOW()' : 'NULL') . ",
                        resolved_by = " . (in_array($newStatus, ['resolved', 'wontfix']) ? '?' : 'NULL') . "
                    WHERE id = ?
                ");
                if (in_array($newStatus, ['resolved', 'wontfix'])) {
                    $stmt->execute([$newStatus, (int)($_SESSION['admin_user_id'] ?? 0), $reportId]);
                } else {
                    $stmt->execute([$newStatus, $reportId]);
                }
                $message = 'Status uppdaterad!';
            } catch (Exception $e) {
                $error = 'Kunde inte uppdatera status: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_notes' && $reportId) {
        $notes = trim($_POST['admin_notes'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE bug_reports SET admin_notes = ? WHERE id = ?");
            $stmt->execute([$notes, $reportId]);
            $message = 'Anteckning sparad!';
        } catch (Exception $e) {
            $error = 'Kunde inte spara anteckning.';
        }
    } elseif ($action === 'send_reply' && $reportId) {
        $replyMessage = trim($_POST['reply_message'] ?? '');
        $replyEmail = trim($_POST['reply_email'] ?? '');
        $autoResolve = !empty($_POST['auto_resolve']);

        if (empty($replyMessage) || empty($replyEmail)) {
            $error = 'Meddelande och e-postadress krävs.';
        } else {
            require_once __DIR__ . '/../includes/mail.php';

            // Get report title for subject
            try {
                $rStmt = $pdo->prepare("SELECT title FROM bug_reports WHERE id = ?");
                $rStmt->execute([$reportId]);
                $reportTitle = $rStmt->fetchColumn() ?: 'Din felrapport';
            } catch (Exception $e) {
                $reportTitle = 'Din felrapport';
            }

            $subject = 'Re: ' . $reportTitle . ' - TheHUB';
            $htmlBody = '<div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">'
                . '<div style="background: #0e1621; padding: 20px; text-align: center;">'
                . '<h2 style="color: #37d4d6; margin: 0;">TheHUB</h2>'
                . '</div>'
                . '<div style="padding: 24px; background: #f8f9fa; color: #333;">'
                . '<p style="color: #666; font-size: 14px; margin-top: 0;">Svar på din felrapport: <strong>' . htmlspecialchars($reportTitle) . '</strong></p>'
                . '<div style="white-space: pre-wrap; line-height: 1.6;">' . htmlspecialchars($replyMessage) . '</div>'
                . '</div>'
                . '<div style="padding: 16px; text-align: center; font-size: 12px; color: #999;">'
                . 'Detta mail skickades från TheHUB · gravityseries.se'
                . '</div></div>';

            $adminEmail = $_SESSION['admin_email'] ?? $_SESSION['hub_user_email'] ?? env('MAIL_FROM_ADDRESS', 'info@gravityseries.se');
            $sent = hub_send_email($replyEmail, $subject, $htmlBody, [
                'reply_to' => $adminEmail
            ]);

            if ($sent) {
                $message = 'Svar skickat till ' . htmlspecialchars($replyEmail) . '!';

                // Save reply as admin note
                try {
                    $existingNotes = '';
                    $nStmt = $pdo->prepare("SELECT admin_notes FROM bug_reports WHERE id = ?");
                    $nStmt->execute([$reportId]);
                    $existingNotes = $nStmt->fetchColumn() ?: '';

                    $replyNote = '[Svar skickat ' . date('Y-m-d H:i') . "]\n" . $replyMessage;
                    $newNotes = $existingNotes ? $existingNotes . "\n\n" . $replyNote : $replyNote;

                    $stmt = $pdo->prepare("UPDATE bug_reports SET admin_notes = ? WHERE id = ?");
                    $stmt->execute([$newNotes, $reportId]);
                } catch (Exception $e) {
                    // Note save failed, mail still sent
                }

                // Auto-resolve if checked
                if ($autoResolve) {
                    try {
                        $stmt = $pdo->prepare("UPDATE bug_reports SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE id = ?");
                        $stmt->execute([(int)($_SESSION['admin_user_id'] ?? 0), $reportId]);
                        $message .= ' Status ändrad till Löst.';
                    } catch (Exception $e) {
                        // Status update failed
                    }
                }
            } else {
                $error = 'Kunde inte skicka e-post. Kontrollera mailkonfigurationen.';
            }
        }
    } elseif ($action === 'delete' && $reportId) {
        try {
            $stmt = $pdo->prepare("DELETE FROM bug_reports WHERE id = ?");
            $stmt->execute([$reportId]);
            $message = 'Rapport borttagen!';
        } catch (Exception $e) {
            $error = 'Kunde inte ta bort rapport.';
        }
    }
}

// Filters
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = [];
$params = [];

if ($statusFilter) {
    $where[] = 'br.status = ?';
    $params[] = $statusFilter;
}
if ($categoryFilter) {
    $where[] = 'br.category = ?';
    $params[] = $categoryFilter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get stats
$stats = ['total' => 0, 'new' => 0, 'in_progress' => 0, 'resolved' => 0];
try {
    $statsStmt = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(status = 'new') as new_count,
            SUM(status = 'in_progress') as in_progress_count,
            SUM(status = 'resolved') as resolved_count
        FROM bug_reports
    ");
    $statsRow = $statsStmt->fetch();
    if ($statsRow) {
        $stats = [
            'total' => (int)$statsRow['total'],
            'new' => (int)$statsRow['new_count'],
            'in_progress' => (int)$statsRow['in_progress_count'],
            'resolved' => (int)$statsRow['resolved_count']
        ];
    }
} catch (Exception $e) {
    // Table might not exist
}

// Get total count for pagination
$countParams = $params;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM bug_reports br $whereClause");
    $countStmt->execute($countParams);
    $totalCount = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $totalCount = 0;
}

$totalPages = max(1, ceil($totalCount / $perPage));

// Get reports
$reports = [];
try {
    $queryParams = $params;
    $queryParams[] = $perPage;
    $queryParams[] = $offset;

    $stmt = $pdo->prepare("
        SELECT br.*,
               r.firstname, r.lastname, r.email as rider_email,
               ev.name as event_name, ev.date as event_date
        FROM bug_reports br
        LEFT JOIN riders r ON br.rider_id = r.id
        LEFT JOIN events ev ON br.related_event_id = ev.id
        $whereClause
        ORDER BY
            CASE br.status
                WHEN 'new' THEN 0
                WHEN 'in_progress' THEN 1
                WHEN 'resolved' THEN 2
                WHEN 'wontfix' THEN 3
            END ASC,
            br.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($queryParams);
    $reports = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Kunde inte läsa rapporter. Har migration 070 körts?';
}

// Pre-fetch related rider names for profile reports
$relatedRiderNames = [];
foreach ($reports as $report) {
    if ($report['category'] === 'profile' && !empty($report['related_rider_ids'])) {
        $ids = array_filter(array_map('intval', explode(',', $report['related_rider_ids'])));
        if (!empty($ids)) {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $rStmt = $pdo->prepare("SELECT id, firstname, lastname FROM riders WHERE id IN ($placeholders)");
                $rStmt->execute($ids);
                foreach ($rStmt->fetchAll() as $rr) {
                    $relatedRiderNames[$rr['id']] = $rr['firstname'] . ' ' . $rr['lastname'];
                }
            } catch (Exception $e) {
                // Ignore
            }
        }
    }
}

// Category labels
$categoryLabels = [
    'profile' => 'Profil',
    'results' => 'Resultat',
    'other' => 'Övrigt'
];

$categoryIcons = [
    'profile' => 'user',
    'results' => 'flag',
    'other' => 'message-square'
];

$statusLabels = [
    'new' => 'Ny',
    'in_progress' => 'Pågår',
    'resolved' => 'Löst',
    'wontfix' => 'Avvisad'
];

// Page config
$page_title = 'Felrapporter';
$breadcrumbs = [
    ['label' => 'System'],
    ['label' => 'Felrapporter']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.report-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}
.report-stat {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    text-align: center;
}
.report-stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--color-accent);
    font-family: var(--font-heading);
}
.report-stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-2xs);
}

.report-filters {
    display: flex;
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
    align-items: center;
}
.report-filters .form-select {
    min-width: 140px;
}

.report-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}
.report-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-md);
    margin-bottom: var(--space-sm);
}
.report-card-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}
.report-card-meta {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
    margin-bottom: var(--space-sm);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}
.report-card-meta span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.report-card-meta i {
    width: 14px;
    height: 14px;
}
.report-card-body {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
    margin-bottom: var(--space-md);
}
.report-card-actions {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
    align-items: center;
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
}

.report-related {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
    margin-bottom: var(--space-sm);
}
.report-related-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: var(--space-2xs) var(--space-sm);
    background: var(--color-accent-light);
    color: var(--color-accent-text);
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    text-decoration: none;
}
.report-related-tag:hover {
    text-decoration: underline;
}
.report-related-tag i {
    width: 12px;
    height: 12px;
}
.report-related-event {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: var(--space-2xs) var(--space-sm);
    background: rgba(251, 191, 36, 0.15);
    color: var(--color-warning);
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    text-decoration: none;
}
.report-related-event:hover {
    text-decoration: underline;
}
.report-related-event i {
    width: 12px;
    height: 12px;
}

.report-notes {
    margin-top: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-hover);
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.report-notes-label {
    font-weight: 600;
    color: var(--color-text-primary);
    font-size: var(--text-xs);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: var(--space-2xs);
}

.badge-profile { background: var(--color-info); color: #fff; }
.badge-results { background: var(--color-warning); color: #000; }
.badge-other { background: var(--color-text-muted); color: #fff; }

.badge-status-new { background: var(--color-warning); color: #000; }
.badge-status-in_progress { background: var(--color-info); color: #fff; }
.badge-status-resolved { background: var(--color-success); color: #fff; }
.badge-status-wontfix { background: var(--color-error); color: #fff; }

.notes-form {
    margin-top: var(--space-sm);
}
.notes-form textarea {
    width: 100%;
    min-height: 60px;
    margin-bottom: var(--space-xs);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: var(--space-xs);
    margin-top: var(--space-lg);
}
.pagination a, .pagination span {
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
    text-decoration: none;
}
.pagination a {
    background: var(--color-bg-card);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border);
}
.pagination a:hover {
    border-color: var(--color-accent);
}
.pagination .active {
    background: var(--color-accent);
    color: var(--color-bg-page);
    border: 1px solid var(--color-accent);
}

@media (max-width: 767px) {
    .report-card-header {
        flex-direction: column;
    }
    .report-card-actions {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="report-stats">
    <div class="report-stat">
        <div class="report-stat-value"><?= $stats['total'] ?></div>
        <div class="report-stat-label">Totalt</div>
    </div>
    <div class="report-stat">
        <div class="report-stat-value" style="color: var(--color-warning);"><?= $stats['new'] ?></div>
        <div class="report-stat-label">Nya</div>
    </div>
    <div class="report-stat">
        <div class="report-stat-value" style="color: var(--color-info);"><?= $stats['in_progress'] ?></div>
        <div class="report-stat-label">Pågår</div>
    </div>
    <div class="report-stat">
        <div class="report-stat-value" style="color: var(--color-success);"><?= $stats['resolved'] ?></div>
        <div class="report-stat-label">Lösta</div>
    </div>
</div>

<!-- Filters -->
<form class="report-filters" method="GET">
    <select name="status" class="form-select" onchange="this.form.submit()">
        <option value="">Alla statusar</option>
        <option value="new" <?= $statusFilter === 'new' ? 'selected' : '' ?>>Nya</option>
        <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>Pågår</option>
        <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Lösta</option>
        <option value="wontfix" <?= $statusFilter === 'wontfix' ? 'selected' : '' ?>>Avvisade</option>
    </select>
    <select name="category" class="form-select" onchange="this.form.submit()">
        <option value="">Alla kategorier</option>
        <option value="profile" <?= $categoryFilter === 'profile' ? 'selected' : '' ?>>Profil</option>
        <option value="results" <?= $categoryFilter === 'results' ? 'selected' : '' ?>>Resultat</option>
        <option value="other" <?= $categoryFilter === 'other' ? 'selected' : '' ?>>Övrigt</option>
    </select>
    <?php if ($statusFilter || $categoryFilter): ?>
        <a href="/admin/bug-reports.php" style="color: var(--color-accent-text); font-size: var(--text-sm);">Rensa filter</a>
    <?php endif; ?>
    <span style="margin-left: auto; font-size: var(--text-sm); color: var(--color-text-muted);">
        <?= $totalCount ?> rapport<?= $totalCount !== 1 ? 'er' : '' ?>
    </span>
</form>

<!-- Reports List -->
<?php if (empty($reports)): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: var(--space-2xl); color: var(--color-text-muted);">
            <i data-lucide="inbox" style="width: 48px; height: 48px; margin-bottom: var(--space-md);"></i>
            <p>Inga rapporter hittades<?= ($statusFilter || $categoryFilter) ? ' med valda filter' : '' ?>.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($reports as $report): ?>
        <div class="report-card" id="report-<?= $report['id'] ?>">
            <div class="report-card-header">
                <div>
                    <h3 class="report-card-title"><?= htmlspecialchars($report['title']) ?></h3>
                </div>
                <div style="display: flex; gap: var(--space-xs); flex-shrink: 0;">
                    <span class="badge badge-<?= htmlspecialchars($report['category']) ?>">
                        <i data-lucide="<?= $categoryIcons[$report['category']] ?? 'message-square' ?>" style="width: 12px; height: 12px; margin-right: 2px;"></i>
                        <?= $categoryLabels[$report['category']] ?? $report['category'] ?>
                    </span>
                    <span class="badge badge-status-<?= htmlspecialchars($report['status']) ?>">
                        <?= $statusLabels[$report['status']] ?? $report['status'] ?>
                    </span>
                </div>
            </div>

            <div class="report-card-meta">
                <span>
                    <i data-lucide="clock"></i>
                    <?= date('Y-m-d H:i', strtotime($report['created_at'])) ?>
                </span>
                <?php if ($report['rider_id'] && $report['firstname']): ?>
                    <span>
                        <i data-lucide="user"></i>
                        <a href="/admin/rider-edit.php?id=<?= $report['rider_id'] ?>" style="color: var(--color-accent-text); text-decoration: none;">
                            <?= htmlspecialchars($report['firstname'] . ' ' . $report['lastname']) ?>
                        </a>
                    </span>
                <?php endif; ?>
                <?php
                $displayEmail = $report['email'] ?? $report['rider_email'] ?? '';
                if ($displayEmail): ?>
                    <span>
                        <i data-lucide="mail"></i>
                        <a href="mailto:<?= htmlspecialchars($displayEmail) ?>" style="color: var(--color-accent-text); text-decoration: none;">
                            <?= htmlspecialchars($displayEmail) ?>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if (!empty($report['page_url'])): ?>
                    <span>
                        <i data-lucide="link"></i>
                        <a href="<?= htmlspecialchars($report['page_url']) ?>" style="color: var(--color-accent-text); text-decoration: none; font-size: var(--text-xs);" target="_blank">
                            <?= htmlspecialchars(strlen($report['page_url']) > 50 ? substr($report['page_url'], 0, 50) . '...' : $report['page_url']) ?>
                        </a>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Related riders (profile reports) -->
            <?php if ($report['category'] === 'profile' && !empty($report['related_rider_ids'])): ?>
                <div class="report-related">
                    <span style="font-size: var(--text-xs); color: var(--color-text-muted); margin-right: var(--space-2xs);">Profiler:</span>
                    <?php
                    $rIds = array_filter(array_map('intval', explode(',', $report['related_rider_ids'])));
                    foreach ($rIds as $rId):
                        $rName = $relatedRiderNames[$rId] ?? "Deltagare #$rId";
                    ?>
                        <a href="/rider/<?= $rId ?>" class="report-related-tag" target="_blank">
                            <i data-lucide="user"></i>
                            <?= htmlspecialchars($rName) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Related event (results reports) -->
            <?php if ($report['category'] === 'results' && !empty($report['related_event_id'])): ?>
                <div class="report-related">
                    <span style="font-size: var(--text-xs); color: var(--color-text-muted); margin-right: var(--space-2xs);">Event:</span>
                    <a href="/event/<?= $report['related_event_id'] ?>" class="report-related-event" target="_blank">
                        <i data-lucide="flag"></i>
                        <?= htmlspecialchars($report['event_name'] ?? 'Event #' . $report['related_event_id']) ?>
                        <?php if (!empty($report['event_date'])): ?>
                            (<?= date('Y-m-d', strtotime($report['event_date'])) ?>)
                        <?php endif; ?>
                    </a>
                </div>
            <?php endif; ?>

            <div class="report-card-body">
                <?= htmlspecialchars($report['description']) ?>
            </div>

            <?php if (!empty($report['browser_info'])): ?>
                <details style="margin-bottom: var(--space-sm);">
                    <summary style="font-size: var(--text-xs); color: var(--color-text-muted); cursor: pointer;">Webbläsarinfo</summary>
                    <code style="font-size: var(--text-xs); color: var(--color-text-muted); word-break: break-all; display: block; margin-top: var(--space-xs);">
                        <?= htmlspecialchars($report['browser_info']) ?>
                    </code>
                </details>
            <?php endif; ?>

            <?php if (!empty($report['admin_notes'])): ?>
                <div class="report-notes">
                    <div class="report-notes-label">Admin-anteckning</div>
                    <?= htmlspecialchars($report['admin_notes']) ?>
                </div>
            <?php endif; ?>

            <div class="report-card-actions">
                <!-- Status change -->
                <form method="POST" style="display: inline-flex; gap: var(--space-xs); align-items: center;">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                    <select name="status" class="form-select" style="min-width: 120px; padding: var(--space-xs) var(--space-sm); font-size: var(--text-sm);">
                        <option value="new" <?= $report['status'] === 'new' ? 'selected' : '' ?>>Ny</option>
                        <option value="in_progress" <?= $report['status'] === 'in_progress' ? 'selected' : '' ?>>Pågår</option>
                        <option value="resolved" <?= $report['status'] === 'resolved' ? 'selected' : '' ?>>Löst</option>
                        <option value="wontfix" <?= $report['status'] === 'wontfix' ? 'selected' : '' ?>>Avvisad</option>
                    </select>
                    <button type="submit" class="btn btn-secondary" style="padding: var(--space-xs) var(--space-sm); font-size: var(--text-sm);">Uppdatera</button>
                </form>

                <!-- Reply by email -->
                <?php
                $replyEmail = $report['email'] ?? $report['rider_email'] ?? '';
                if ($replyEmail): ?>
                <button type="button" class="btn btn-ghost" style="padding: var(--space-xs) var(--space-sm); font-size: var(--text-sm); color: var(--color-accent-text);"
                        onclick="toggleReply(<?= $report['id'] ?>)">
                    <i data-lucide="mail" style="width: 14px; height: 14px;"></i> Svara
                </button>
                <?php endif; ?>

                <!-- Notes toggle -->
                <button type="button" class="btn btn-ghost" style="padding: var(--space-xs) var(--space-sm); font-size: var(--text-sm);"
                        onclick="toggleNotes(<?= $report['id'] ?>)">
                    <i data-lucide="pencil" style="width: 14px; height: 14px;"></i> Anteckning
                </button>

                <!-- Delete -->
                <form method="POST" style="margin-left: auto;" onsubmit="return confirm('Vill du verkligen ta bort denna rapport?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                    <button type="submit" class="btn btn-ghost" style="padding: var(--space-xs) var(--space-sm); font-size: var(--text-sm); color: var(--color-error);">
                        <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                    </button>
                </form>
            </div>

            <!-- Notes form (hidden by default) -->
            <div class="notes-form" id="notes-<?= $report['id'] ?>" style="display: none;">
                <form method="POST">
                    <input type="hidden" name="action" value="save_notes">
                    <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                    <textarea name="admin_notes" class="form-input" placeholder="Skriv en anteckning..."><?= htmlspecialchars($report['admin_notes'] ?? '') ?></textarea>
                    <button type="submit" class="btn btn-primary" style="padding: var(--space-xs) var(--space-sm); font-size: var(--text-sm);">Spara anteckning</button>
                </form>
            </div>

            <!-- Reply form (hidden by default) -->
            <?php if (!empty($replyEmail)): ?>
            <div class="notes-form" id="reply-<?= $report['id'] ?>" style="display: none;">
                <form method="POST">
                    <input type="hidden" name="action" value="send_reply">
                    <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                    <input type="hidden" name="reply_email" value="<?= htmlspecialchars($replyEmail) ?>">
                    <div style="font-size: var(--text-sm); color: var(--color-text-muted); margin-bottom: var(--space-xs);">
                        <i data-lucide="mail" style="width: 12px; height: 12px; display: inline;"></i>
                        Till: <strong style="color: var(--color-text-primary);"><?= htmlspecialchars($replyEmail) ?></strong>
                    </div>
                    <textarea name="reply_message" class="form-input" placeholder="Skriv ditt svar..." style="min-height: 80px;"></textarea>
                    <div style="display: flex; gap: var(--space-sm); align-items: center; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary" style="padding: var(--space-xs) var(--space-sm); font-size: var(--text-sm);">
                            <i data-lucide="send" style="width: 14px; height: 14px;"></i> Skicka svar
                        </button>
                        <label style="font-size: var(--text-sm); color: var(--color-text-secondary); display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            <input type="checkbox" name="auto_resolve" value="1" <?= $report['status'] !== 'resolved' ? 'checked' : '' ?>>
                            Markera som löst
                        </label>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $queryParams = [];
            if ($statusFilter) $queryParams['status'] = $statusFilter;
            if ($categoryFilter) $queryParams['category'] = $categoryFilter;

            for ($i = 1; $i <= $totalPages; $i++):
                $queryParams['page'] = $i;
                $url = '/admin/bug-reports.php?' . http_build_query($queryParams);
            ?>
                <?php if ($i === $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($url) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
function toggleNotes(id) {
    var el = document.getElementById('notes-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    if (el.style.display === 'block') {
        el.querySelector('textarea').focus();
    }
}
function toggleReply(id) {
    var el = document.getElementById('reply-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    if (el.style.display === 'block') {
        el.querySelector('textarea').focus();
    }
}
lucide.createIcons();
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
