<?php
/**
 * TheHUB - Feedback Conversation View
 * Public page for viewing and replying to a bug report conversation
 * Accessed via unique token: /feedback/view?token=xxx
 */

$pdo = hub_db();
$currentUser = function_exists('hub_current_user') ? hub_current_user() : null;
$isLoggedIn = !empty($currentUser);

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    header('Location: /feedback');
    exit;
}

// Load the bug report by token
$report = null;
try {
    $stmt = $pdo->prepare("
        SELECT br.*, r.firstname, r.lastname
        FROM bug_reports br
        LEFT JOIN riders r ON br.rider_id = r.id
        WHERE br.view_token = ?
    ");
    $stmt->execute([$token]);
    $report = $stmt->fetch();
} catch (Exception $e) {
    // Table might not exist
}

if (!$report) {
    $pageTitle = 'Ärende hittades inte';
    include HUB_ROOT . '/includes/header.php';
    echo '<main class="container" style="text-align: center; padding: var(--space-2xl);">';
    echo '<i data-lucide="search-x" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>';
    echo '<h2 style="color: var(--color-text-primary);">Ärendet hittades inte</h2>';
    echo '<p style="color: var(--color-text-muted);">Länken kan ha gått ut eller är ogiltig.</p>';
    echo '<a href="/feedback" style="color: var(--color-accent-text);">Rapportera ett nytt problem</a>';
    echo '</main>';
    include HUB_ROOT . '/includes/footer.php';
    return;
}

// Load conversation messages
$messages = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM bug_report_messages
        WHERE bug_report_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$report['id']]);
    $messages = $stmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
}

$categoryLabels = [
    'profile' => 'Profil',
    'results' => 'Resultat',
    'other' => 'Övrigt'
];

$statusLabels = [
    'new' => 'Ny',
    'in_progress' => 'Pågår',
    'resolved' => 'Löst',
    'wontfix' => 'Avvisad'
];

$statusColors = [
    'new' => 'var(--color-warning)',
    'in_progress' => 'var(--color-info)',
    'resolved' => 'var(--color-success)',
    'wontfix' => 'var(--color-error)'
];

$isResolved = in_array($report['status'], ['resolved', 'wontfix']);

$pageTitle = 'Ärende: ' . $report['title'];
?>

<link rel="stylesheet" href="/assets/css/forms.css?v=<?= filemtime(HUB_ROOT . '/assets/css/forms.css') ?>">
<link rel="stylesheet" href="/assets/css/pages/auth.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/auth.css') ?>">

<div class="login-page" style="min-height: auto; padding-top: var(--space-lg);">
    <div class="login-container" style="max-width: 640px;">
        <div class="login-card">

            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: var(--space-md); margin-bottom: var(--space-md);">
                <div>
                    <h1 style="font-size: 1.25rem; font-weight: 600; color: var(--color-text-primary); margin: 0 0 var(--space-xs) 0;">
                        <?= htmlspecialchars($report['title']) ?>
                    </h1>
                    <div style="display: flex; gap: var(--space-sm); flex-wrap: wrap; font-size: 0.8125rem; color: var(--color-text-muted);">
                        <span><?= $categoryLabels[$report['category']] ?? $report['category'] ?></span>
                        <span>&middot;</span>
                        <span><?= date('j M Y, H:i', strtotime($report['created_at'])) ?></span>
                    </div>
                </div>
                <span style="padding: var(--space-2xs) var(--space-sm); border-radius: var(--radius-full); font-size: 0.75rem; font-weight: 600; color: #fff; background: <?= $statusColors[$report['status']] ?? 'var(--color-text-muted)' ?>; white-space: nowrap;">
                    <?= $statusLabels[$report['status']] ?? $report['status'] ?>
                </span>
            </div>

            <!-- Original report -->
            <div class="fc-message fc-message--user">
                <div class="fc-message-header">
                    <i data-lucide="user" style="width: 16px; height: 16px;"></i>
                    <strong><?= htmlspecialchars(($report['firstname'] ?? '') . ' ' . ($report['lastname'] ?? 'Anonym')) ?></strong>
                    <span class="fc-message-time"><?= date('j M Y, H:i', strtotime($report['created_at'])) ?></span>
                </div>
                <div class="fc-message-body"><?= nl2br(htmlspecialchars($report['description'])) ?></div>
            </div>

            <!-- Conversation messages -->
            <?php foreach ($messages as $msg): ?>
                <div class="fc-message fc-message--<?= $msg['sender_type'] === 'admin' ? 'admin' : 'user' ?>">
                    <div class="fc-message-header">
                        <i data-lucide="<?= $msg['sender_type'] === 'admin' ? 'shield' : 'user' ?>" style="width: 16px; height: 16px;"></i>
                        <strong><?= htmlspecialchars($msg['sender_name'] ?? ($msg['sender_type'] === 'admin' ? 'TheHUB Support' : 'Du')) ?></strong>
                        <span class="fc-message-time"><?= date('j M Y, H:i', strtotime($msg['created_at'])) ?></span>
                    </div>
                    <div class="fc-message-body"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                </div>
            <?php endforeach; ?>

            <!-- Reply form -->
            <?php if (!$isResolved): ?>
                <div class="fc-reply-section">
                    <div id="reply-success" class="alert alert--success" style="display: none;">
                        <i data-lucide="check-circle"></i>
                        <span>Ditt svar har skickats!</span>
                    </div>
                    <div id="reply-error" class="alert alert--error" style="display: none;">
                        <i data-lucide="alert-circle"></i>
                        <span id="reply-error-text"></span>
                    </div>
                    <form id="reply-form">
                        <div class="form-group" style="margin-bottom: var(--space-sm);">
                            <textarea id="reply-message" class="form-textarea" rows="3" placeholder="Skriv ditt svar..." required></textarea>
                        </div>
                        <button type="submit" id="reply-submit" class="btn btn--primary btn--block">
                            <i data-lucide="send" style="width: 16px; height: 16px;"></i> Skicka svar
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: var(--space-lg) 0 var(--space-sm); color: var(--color-text-muted); font-size: 0.875rem;">
                    <i data-lucide="check-circle" style="width: 24px; height: 24px; color: var(--color-success); margin-bottom: var(--space-xs);"></i>
                    <p>Detta ärende är <?= $report['status'] === 'resolved' ? 'löst' : 'avslutat' ?>.</p>
                    <a href="/feedback" style="color: var(--color-accent-text);">Rapportera ett nytt problem</a>
                </div>
            <?php endif; ?>

            <div style="text-align: center; padding-top: var(--space-md); border-top: 1px solid var(--color-border); margin-top: var(--space-md);">
                <a href="/feedback" style="color: var(--color-accent-text); font-size: 0.8125rem; text-decoration: none;">
                    <i data-lucide="plus" style="width: 14px; height: 14px; vertical-align: middle;"></i> Rapportera ett nytt problem
                </a>
            </div>

        </div>
    </div>
</div>

<style>
.fc-message {
    padding: var(--space-md);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-sm);
}
.fc-message--user {
    background: var(--color-bg-hover);
    border-left: 3px solid var(--color-border-strong);
}
.fc-message--admin {
    background: var(--color-accent-light);
    border-left: 3px solid var(--color-accent);
}
.fc-message-header {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    margin-bottom: var(--space-xs);
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
}
.fc-message-header strong {
    color: var(--color-text-primary);
}
.fc-message-time {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--color-text-muted);
}
.fc-message-body {
    font-size: 0.875rem;
    line-height: 1.6;
    color: var(--color-text-primary);
    word-break: break-word;
}
.fc-reply-section {
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
}

@media (max-width: 767px) {
    .login-page {
        padding: var(--space-sm) 0 calc(var(--space-lg) + 70px) 0;
        align-items: flex-start;
        min-height: auto;
    }
    .login-container {
        max-width: 100% !important;
    }
    .login-card {
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        padding: var(--space-lg) var(--space-md);
        box-shadow: none;
    }
    .fc-message-time {
        display: none;
    }
}
</style>

<script>
(function() {
    var form = document.getElementById('reply-form');
    if (!form) return;

    var submitBtn = document.getElementById('reply-submit');
    var successDiv = document.getElementById('reply-success');
    var errorDiv = document.getElementById('reply-error');
    var errorText = document.getElementById('reply-error-text');
    var messageField = document.getElementById('reply-message');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = messageField.value.trim();
        if (!msg) return;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i data-lucide="loader"></i> Skickar...';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        successDiv.style.display = 'none';
        errorDiv.style.display = 'none';

        fetch('/api/bug-report-reply.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                token: <?= json_encode($token) ?>,
                message: msg
            })
        })
        .then(function(res) { return res.json().then(function(d) { return { ok: res.ok, data: d }; }); })
        .then(function(result) {
            if (result.ok && result.data.success) {
                successDiv.style.display = 'flex';
                form.style.display = 'none';
                // Add the new message to the conversation
                var msgDiv = document.createElement('div');
                msgDiv.className = 'fc-message fc-message--user';
                msgDiv.innerHTML = '<div class="fc-message-header">'
                    + '<i data-lucide="user" style="width: 16px; height: 16px;"></i>'
                    + '<strong>' + (result.data.sender_name || 'Du') + '</strong>'
                    + '<span class="fc-message-time">Just nu</span>'
                    + '</div>'
                    + '<div class="fc-message-body">' + msg.replace(/\n/g, '<br>') + '</div>';
                document.querySelector('.fc-reply-section').insertBefore(msgDiv, successDiv);
                if (typeof lucide !== 'undefined') lucide.createIcons();
                messageField.value = '';
            } else {
                errorText.textContent = result.data.error || 'Något gick fel.';
                errorDiv.style.display = 'flex';
            }
        })
        .catch(function() {
            errorText.textContent = 'Kunde inte nå servern.';
            errorDiv.style.display = 'flex';
        })
        .finally(function() {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i data-lucide="send" style="width: 16px; height: 16px;"></i> Skicka svar';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    });
})();
</script>
