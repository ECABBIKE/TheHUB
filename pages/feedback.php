<?php
/**
 * TheHUB - Rapportera problem / Feedback
 * Public page for submitting bug reports and feedback
 * Accessible to both logged-in and anonymous users
 */

$currentUser = function_exists('hub_current_user') ? hub_current_user() : null;
$isLoggedIn = !empty($currentUser);

// Pre-fill email if logged in
$userEmail = '';
if ($isLoggedIn && !empty($currentUser['email'])) {
    $userEmail = $currentUser['email'];
}

// Get the referring page URL (where the user came from)
$referrerUrl = $_SERVER['HTTP_REFERER'] ?? '';
?>

<div class="page-header">
    <h1><i data-lucide="message-circle"></i> Rapportera problem</h1>
    <p style="color: var(--color-text-secondary); margin-top: var(--space-xs);">
        Hittade du en bugg eller har du ett förslag? Berätta för oss!
    </p>
</div>

<div class="container container--sm">

    <!-- Success message (hidden by default) -->
    <div id="feedback-success" class="alert alert-success" style="display: none;">
        <i data-lucide="check-circle"></i>
        <span id="feedback-success-text">Tack för din rapport!</span>
    </div>

    <!-- Error message (hidden by default) -->
    <div id="feedback-error" class="alert alert-danger" style="display: none;">
        <i data-lucide="alert-circle"></i>
        <span id="feedback-error-text"></span>
    </div>

    <div class="card">
        <div class="card-body">
            <form id="feedback-form" method="POST">

                <!-- Category -->
                <div class="form-group">
                    <label class="form-label">Kategori</label>
                    <div class="feedback-category-grid">
                        <label class="feedback-category-option">
                            <input type="radio" name="category" value="bug" checked>
                            <span class="feedback-category-card">
                                <i data-lucide="bug"></i>
                                <span>Bugg</span>
                            </span>
                        </label>
                        <label class="feedback-category-option">
                            <input type="radio" name="category" value="feature">
                            <span class="feedback-category-card">
                                <i data-lucide="lightbulb"></i>
                                <span>Förslag</span>
                            </span>
                        </label>
                        <label class="feedback-category-option">
                            <input type="radio" name="category" value="design">
                            <span class="feedback-category-card">
                                <i data-lucide="palette"></i>
                                <span>Design</span>
                            </span>
                        </label>
                        <label class="feedback-category-option">
                            <input type="radio" name="category" value="other">
                            <span class="feedback-category-card">
                                <i data-lucide="message-square"></i>
                                <span>Övrigt</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Title -->
                <div class="form-group">
                    <label class="form-label" for="feedback-title">Titel <span style="color: var(--color-error);">*</span></label>
                    <input type="text" id="feedback-title" name="title" class="form-input"
                           placeholder="Kort beskrivning av problemet..." maxlength="255" required>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label class="form-label" for="feedback-description">Beskrivning <span style="color: var(--color-error);">*</span></label>
                    <textarea id="feedback-description" name="description" class="form-input"
                              rows="5" placeholder="Beskriv vad som hände, vad du förväntade dig, och eventuella steg för att återskapa problemet..." maxlength="5000" required></textarea>
                    <small style="color: var(--color-text-muted);"><span id="desc-count">0</span> / 5000</small>
                </div>

                <!-- Email (optional for logged in, shown for anonymous) -->
                <?php if (!$isLoggedIn): ?>
                <div class="form-group">
                    <label class="form-label" for="feedback-email">E-post (valfritt)</label>
                    <input type="email" id="feedback-email" name="email" class="form-input"
                           placeholder="Din e-post om du vill ha svar..." value="<?= htmlspecialchars($userEmail) ?>">
                </div>
                <?php else: ?>
                <input type="hidden" name="email" value="<?= htmlspecialchars($userEmail) ?>">
                <?php endif; ?>

                <!-- Hidden fields -->
                <input type="hidden" id="feedback-page-url" name="page_url" value="<?= htmlspecialchars($referrerUrl) ?>">
                <input type="hidden" id="feedback-browser-info" name="browser_info" value="">

                <!-- Submit -->
                <div class="form-group" style="margin-top: var(--space-lg);">
                    <button type="submit" id="feedback-submit" class="btn btn-primary" style="width: 100%;">
                        <i data-lucide="send"></i> Skicka rapport
                    </button>
                </div>

            </form>
        </div>
    </div>

    <div style="text-align: center; margin-top: var(--space-md); color: var(--color-text-muted); font-size: var(--text-sm);">
        <i data-lucide="shield-check" style="width: 14px; height: 14px; vertical-align: middle;"></i>
        Dina rapporter behandlas konfidentiellt
    </div>

</div>

<style>
.container--sm {
    max-width: 600px;
    margin: 0 auto;
}

.feedback-category-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-sm);
}

.feedback-category-option input[type="radio"] {
    display: none;
}

.feedback-category-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-md) var(--space-sm);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: center;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    background: var(--color-bg-surface);
}

.feedback-category-card i {
    width: 24px;
    height: 24px;
}

.feedback-category-card:hover {
    border-color: var(--color-accent);
    color: var(--color-text-primary);
}

.feedback-category-option input[type="radio"]:checked + .feedback-category-card {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
    color: var(--color-accent-text);
}

#feedback-form textarea {
    resize: vertical;
    min-height: 120px;
}

#feedback-submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-xs);
}

#feedback-submit i {
    width: 18px;
    height: 18px;
}

#feedback-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

@media (max-width: 767px) {
    .container--sm {
        max-width: 100%;
    }

    .feedback-category-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
(function() {
    const form = document.getElementById('feedback-form');
    const submitBtn = document.getElementById('feedback-submit');
    const successDiv = document.getElementById('feedback-success');
    const successText = document.getElementById('feedback-success-text');
    const errorDiv = document.getElementById('feedback-error');
    const errorText = document.getElementById('feedback-error-text');
    const descField = document.getElementById('feedback-description');
    const descCount = document.getElementById('desc-count');
    const browserInfoField = document.getElementById('feedback-browser-info');

    // Set browser info
    browserInfoField.value = navigator.userAgent.substring(0, 500);

    // Character counter
    descField.addEventListener('input', function() {
        descCount.textContent = this.value.length;
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Hide previous messages
        successDiv.style.display = 'none';
        errorDiv.style.display = 'none';

        // Disable submit
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i data-lucide="loader"></i> Skickar...';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        // Collect data
        const data = {
            category: form.querySelector('input[name="category"]:checked').value,
            title: form.querySelector('#feedback-title').value.trim(),
            description: descField.value.trim(),
            email: form.querySelector('input[name="email"]').value.trim(),
            page_url: form.querySelector('#feedback-page-url').value,
            browser_info: browserInfoField.value
        };

        fetch('/api/feedback.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(res) { return res.json().then(function(d) { return { ok: res.ok, data: d }; }); })
        .then(function(result) {
            if (result.ok && result.data.success) {
                successText.textContent = result.data.message;
                successDiv.style.display = 'flex';
                form.reset();
                descCount.textContent = '0';
                // Re-check the default radio
                form.querySelector('input[name="category"][value="bug"]').checked = true;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            } else {
                errorText.textContent = result.data.error || 'Något gick fel. Försök igen.';
                errorDiv.style.display = 'flex';
            }
        })
        .catch(function() {
            errorText.textContent = 'Kunde inte nå servern. Kontrollera din anslutning.';
            errorDiv.style.display = 'flex';
        })
        .finally(function() {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i data-lucide="send"></i> Skicka rapport';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    });
})();
</script>
