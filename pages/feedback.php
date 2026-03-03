<?php
/**
 * TheHUB - Rapportera problem / Feedback
 * Public page for submitting bug reports and feedback
 * Categories: Profil, Resultat, Övrigt
 */

$currentUser = function_exists('hub_current_user') ? hub_current_user() : null;
$isLoggedIn = !empty($currentUser);

$userEmail = '';
if ($isLoggedIn && !empty($currentUser['email'])) {
    $userEmail = $currentUser['email'];
}

// Get referring page URL
$referrerUrl = $_SERVER['HTTP_REFERER'] ?? '';

// Load events for the results dropdown (last 12 months + upcoming)
$events = [];
try {
    $pdo = hub_db();
    $stmt = $pdo->query("
        SELECT id, name, date, location
        FROM events
        WHERE date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        AND active = 1
        ORDER BY date DESC
        LIMIT 100
    ");
    $events = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore - events dropdown will be empty
}

// Generate spam protection token
$formToken = bin2hex(random_bytes(16));
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['feedback_token'] = $formToken;
$_SESSION['feedback_token_time'] = time();
?>

<!-- Load form & auth CSS (not globally loaded on public pages) -->
<link rel="stylesheet" href="/assets/css/forms.css?v=<?= filemtime(HUB_ROOT . '/assets/css/forms.css') ?>">
<link rel="stylesheet" href="/assets/css/pages/auth.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/auth.css') ?>">

<div class="login-page">
    <div class="login-container" style="max-width: 520px;">
        <div class="login-card">

            <!-- Header -->
            <div class="login-header">
                <div class="login-logo">
                    <i data-lucide="bug" style="width: 36px; height: 36px;"></i>
                </div>
                <h1 class="login-title">Rapportera problem</h1>
                <p class="login-subtitle">Hittade du ett fel eller vill du ge feedback? Berätta för oss!</p>
            </div>

            <!-- Success message -->
            <div id="feedback-success" class="alert alert--success" style="display: none;">
                <i data-lucide="check-circle"></i>
                <span id="feedback-success-text">Tack för din rapport!</span>
            </div>

            <!-- Error message -->
            <div id="feedback-error" class="alert alert--error" style="display: none;">
                <i data-lucide="alert-circle"></i>
                <span id="feedback-error-text"></span>
            </div>

            <!-- Form -->
            <form id="feedback-form" method="POST" class="login-form">

                <!-- Category selector -->
                <div class="form-group">
                    <label class="form-label">Vad gäller det?</label>
                    <div class="fb-categories">
                        <label class="fb-cat">
                            <input type="radio" name="category" value="profile">
                            <span class="fb-cat-label">
                                <i data-lucide="user"></i> Profil
                            </span>
                        </label>
                        <label class="fb-cat">
                            <input type="radio" name="category" value="results">
                            <span class="fb-cat-label">
                                <i data-lucide="flag"></i> Resultat
                            </span>
                        </label>
                        <label class="fb-cat">
                            <input type="radio" name="category" value="other" checked>
                            <span class="fb-cat-label">
                                <i data-lucide="message-square"></i> Övrigt
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Profile: Rider search (shown when category=profile) -->
                <div id="section-profile" class="form-group" style="display: none;">
                    <label class="form-label">Vilka profiler gäller det? <small style="color: var(--color-text-muted); font-weight: normal;">(max 4)</small></label>
                    <div class="fb-search-wrap">
                        <input type="text" id="rider-search-input" class="form-input" placeholder="Sök deltagare..." autocomplete="off">
                        <div id="rider-search-results" class="fb-search-dropdown" style="display: none;"></div>
                    </div>
                    <div id="selected-riders" class="fb-selected"></div>
                    <input type="hidden" id="related-rider-ids" name="related_rider_ids" value="">
                </div>

                <!-- Results: Event selector (shown when category=results) -->
                <div id="section-results" class="form-group" style="display: none;">
                    <label class="form-label">Vilket event gäller det?</label>
                    <select id="related-event" name="related_event_id" class="form-select">
                        <option value="">Välj event...</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?= $ev['id'] ?>">
                                <?= htmlspecialchars($ev['name']) ?> — <?= date('Y-m-d', strtotime($ev['date'])) ?><?= $ev['location'] ? ', ' . htmlspecialchars($ev['location']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Title -->
                <div class="form-group">
                    <label class="form-label" for="feedback-title">Rubrik <span style="color: var(--color-error);">*</span></label>
                    <input type="text" id="feedback-title" name="title" class="form-input"
                           placeholder="Kort beskrivning av problemet..." maxlength="255" required>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label class="form-label" for="feedback-description">Beskrivning <span style="color: var(--color-error);">*</span></label>
                    <textarea id="feedback-description" name="description" class="form-textarea"
                              rows="5" placeholder="Beskriv vad som är fel eller vad du vill rapportera..." maxlength="5000" required></textarea>
                    <span class="form-help"><span id="desc-count">0</span> / 5000</span>
                </div>

                <!-- Email (only for anonymous) -->
                <?php if (!$isLoggedIn): ?>
                <div class="form-group">
                    <label class="form-label" for="feedback-email">E-post <small style="color: var(--color-text-muted); font-weight: normal;">(valfritt, om du vill ha svar)</small></label>
                    <input type="email" id="feedback-email" name="email" class="form-input"
                           placeholder="din@email.se">
                </div>
                <?php else: ?>
                <input type="hidden" name="email" value="<?= htmlspecialchars($userEmail) ?>">
                <?php endif; ?>

                <!-- Honeypot - hidden from real users -->
                <div style="position: absolute; left: -9999px;" aria-hidden="true">
                    <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
                </div>

                <!-- Hidden fields -->
                <input type="hidden" id="feedback-page-url" name="page_url" value="<?= htmlspecialchars($referrerUrl) ?>">
                <input type="hidden" id="feedback-browser-info" name="browser_info" value="">
                <input type="hidden" name="_token" value="<?= $formToken ?>">
                <input type="hidden" id="feedback-render-time" name="_render_time" value="<?= time() ?>">

                <!-- Submit -->
                <button type="submit" id="feedback-submit" class="btn btn--primary btn--block btn--lg">
                    <i data-lucide="send"></i>
                    Skicka rapport
                </button>

            </form>

            <!-- Footer -->
            <div class="login-footer">
                <i data-lucide="shield-check" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                Dina rapporter behandlas konfidentiellt
            </div>

        </div>
    </div>
</div>

<style>
/* Category selector - 3-column grid */
.fb-categories {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-xs);
}
.fb-cat input[type="radio"] { display: none; }
.fb-cat-label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2xs);
    padding: var(--space-sm);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s, color 0.15s;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}
.fb-cat-label i { width: 18px; height: 18px; }
.fb-cat-label:hover {
    border-color: var(--color-accent);
    color: var(--color-text-primary);
}
.fb-cat input:checked + .fb-cat-label {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
    color: var(--color-accent-text);
}

/* Rider search dropdown */
.fb-search-wrap { position: relative; }
.fb-search-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--color-bg-card);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-sm);
    max-height: 200px;
    overflow-y: auto;
    z-index: 100;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.fb-search-item {
    padding: var(--space-sm) var(--space-md);
    cursor: pointer;
    font-size: 0.875rem;
    color: var(--color-text-primary);
    border-bottom: 1px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.fb-search-item:last-child { border-bottom: none; }
.fb-search-item:hover { background: var(--color-bg-hover); }
.fb-search-item .fb-club {
    font-size: 0.75rem;
    color: var(--color-text-muted);
}
.fb-search-none {
    padding: var(--space-sm) var(--space-md);
    font-size: 0.875rem;
    color: var(--color-text-muted);
    text-align: center;
}

/* Selected rider tags */
.fb-selected {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
    margin-top: var(--space-xs);
}
.fb-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: var(--space-2xs) var(--space-sm);
    background: var(--color-accent-light);
    color: var(--color-accent-text);
    border-radius: var(--radius-full);
    font-size: 0.875rem;
}
.fb-tag button {
    background: none;
    border: none;
    color: var(--color-accent-text);
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    opacity: 0.7;
}
.fb-tag button:hover { opacity: 1; }
.fb-tag button i { width: 14px; height: 14px; }
</style>

<script>
(function() {
    var form = document.getElementById('feedback-form');
    var submitBtn = document.getElementById('feedback-submit');
    var successDiv = document.getElementById('feedback-success');
    var successText = document.getElementById('feedback-success-text');
    var errorDiv = document.getElementById('feedback-error');
    var errorText = document.getElementById('feedback-error-text');
    var descField = document.getElementById('feedback-description');
    var descCount = document.getElementById('desc-count');
    var browserInfoField = document.getElementById('feedback-browser-info');

    var sectionProfile = document.getElementById('section-profile');
    var sectionResults = document.getElementById('section-results');

    // Set browser info
    browserInfoField.value = navigator.userAgent.substring(0, 500);

    // Character counter
    descField.addEventListener('input', function() {
        descCount.textContent = this.value.length;
    });

    // Category switching
    var categoryRadios = form.querySelectorAll('input[name="category"]');
    categoryRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            sectionProfile.style.display = this.value === 'profile' ? 'block' : 'none';
            sectionResults.style.display = this.value === 'results' ? 'block' : 'none';
        });
    });

    // ========================
    // RIDER SEARCH
    // ========================
    var selectedRiders = [];
    var searchInput = document.getElementById('rider-search-input');
    var searchDropdown = document.getElementById('rider-search-results');
    var selectedContainer = document.getElementById('selected-riders');
    var hiddenRiderIds = document.getElementById('related-rider-ids');
    var searchTimeout = null;

    searchInput.addEventListener('input', function() {
        var q = this.value.trim();
        clearTimeout(searchTimeout);
        if (q.length < 2) {
            searchDropdown.style.display = 'none';
            return;
        }
        searchTimeout = setTimeout(function() {
            fetch('/api/search.php?q=' + encodeURIComponent(q) + '&type=riders&limit=8')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var results = (data.results || []).filter(function(r) { return r.type === 'rider'; });
                    results = results.filter(function(r) {
                        return selectedRiders.findIndex(function(s) { return s.id === r.id; }) === -1;
                    });
                    if (results.length === 0) {
                        searchDropdown.innerHTML = '<div class="fb-search-none">Inga träffar</div>';
                    } else {
                        searchDropdown.innerHTML = results.map(function(r) {
                            return '<div class="fb-search-item" data-id="' + r.id + '" data-name="' + (r.firstname + ' ' + r.lastname).replace(/"/g, '&quot;') + '">'
                                + '<span>' + r.firstname + ' ' + r.lastname + '</span>'
                                + (r.club_name ? '<span class="fb-club">' + r.club_name + '</span>' : '')
                                + '</div>';
                        }).join('');
                    }
                    searchDropdown.style.display = 'block';
                })
                .catch(function() {
                    searchDropdown.style.display = 'none';
                });
        }, 250);
    });

    searchDropdown.addEventListener('click', function(e) {
        var item = e.target.closest('.fb-search-item');
        if (!item) return;
        if (selectedRiders.length >= 4) return;
        selectedRiders.push({ id: parseInt(item.dataset.id), name: item.dataset.name });
        updateSelectedRiders();
        searchInput.value = '';
        searchDropdown.style.display = 'none';
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.fb-search-wrap')) {
            searchDropdown.style.display = 'none';
        }
    });

    function updateSelectedRiders() {
        selectedContainer.innerHTML = selectedRiders.map(function(r, i) {
            return '<span class="fb-tag">'
                + r.name
                + '<button type="button" onclick="window._removeRider(' + i + ')" title="Ta bort"><i data-lucide="x"></i></button>'
                + '</span>';
        }).join('');
        hiddenRiderIds.value = JSON.stringify(selectedRiders.map(function(r) { return r.id; }));
        if (typeof lucide !== 'undefined') lucide.createIcons();
        searchInput.style.display = selectedRiders.length >= 4 ? 'none' : 'block';
    }

    window._removeRider = function(index) {
        selectedRiders.splice(index, 1);
        updateSelectedRiders();
    };

    // ========================
    // FORM SUBMIT
    // ========================
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        successDiv.style.display = 'none';
        errorDiv.style.display = 'none';

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i data-lucide="loader"></i> Skickar...';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        var category = form.querySelector('input[name="category"]:checked').value;

        var data = {
            category: category,
            title: form.querySelector('#feedback-title').value.trim(),
            description: descField.value.trim(),
            email: (form.querySelector('input[name="email"]') || {}).value || '',
            page_url: form.querySelector('#feedback-page-url').value,
            browser_info: browserInfoField.value,
            related_rider_ids: category === 'profile' ? selectedRiders.map(function(r) { return r.id; }) : [],
            related_event_id: category === 'results' ? (document.getElementById('related-event').value || null) : null,
            _token: form.querySelector('input[name="_token"]').value,
            _render_time: form.querySelector('input[name="_render_time"]').value,
            website_url: form.querySelector('input[name="website_url"]').value
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
                form.style.display = 'none';
                window.scrollTo({ top: 0, behavior: 'smooth' });
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
