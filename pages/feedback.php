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
?>

<div class="page-header">
    <h1><i data-lucide="message-circle"></i> Rapportera problem</h1>
    <p style="color: var(--color-text-secondary); margin-top: var(--space-xs);">
        Hittade du ett fel eller vill du rapportera något? Berätta för oss!
    </p>
</div>

<div class="container container--sm">

    <!-- Success message -->
    <div id="feedback-success" class="alert alert-success" style="display: none;">
        <i data-lucide="check-circle"></i>
        <span id="feedback-success-text">Tack för din rapport!</span>
    </div>

    <!-- Error message -->
    <div id="feedback-error" class="alert alert-danger" style="display: none;">
        <i data-lucide="alert-circle"></i>
        <span id="feedback-error-text"></span>
    </div>

    <div class="card">
        <div class="card-body">
            <form id="feedback-form" method="POST">

                <!-- Category -->
                <div class="form-group">
                    <label class="form-label">Vad gäller det?</label>
                    <div class="feedback-category-grid">
                        <label class="feedback-category-option">
                            <input type="radio" name="category" value="profile">
                            <span class="feedback-category-card">
                                <i data-lucide="user"></i>
                                <span>Profil</span>
                            </span>
                        </label>
                        <label class="feedback-category-option">
                            <input type="radio" name="category" value="results">
                            <span class="feedback-category-card">
                                <i data-lucide="flag"></i>
                                <span>Resultat</span>
                            </span>
                        </label>
                        <label class="feedback-category-option">
                            <input type="radio" name="category" value="other" checked>
                            <span class="feedback-category-card">
                                <i data-lucide="message-square"></i>
                                <span>Övrigt</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Profile: Rider search (shown when category=profile) -->
                <div id="section-profile" class="form-group" style="display: none;">
                    <label class="form-label">Vilka profiler gäller det? <small style="color: var(--color-text-muted);">(max 4)</small></label>
                    <div class="rider-search-wrapper">
                        <input type="text" id="rider-search-input" class="form-input" placeholder="Sök deltagare..." autocomplete="off">
                        <div id="rider-search-results" class="rider-search-dropdown" style="display: none;"></div>
                    </div>
                    <div id="selected-riders" class="selected-riders-list"></div>
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
                    <label class="form-label" for="feedback-title">Titel <span style="color: var(--color-error);">*</span></label>
                    <input type="text" id="feedback-title" name="title" class="form-input"
                           placeholder="Kort beskrivning av problemet..." maxlength="255" required>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label class="form-label" for="feedback-description">Beskrivning <span style="color: var(--color-error);">*</span></label>
                    <textarea id="feedback-description" name="description" class="form-input"
                              rows="5" placeholder="Beskriv vad som är fel eller vad du vill rapportera..." maxlength="5000" required></textarea>
                    <small style="color: var(--color-text-muted);"><span id="desc-count">0</span> / 5000</small>
                </div>

                <!-- Email -->
                <?php if (!$isLoggedIn): ?>
                <div class="form-group">
                    <label class="form-label" for="feedback-email">E-post (valfritt)</label>
                    <input type="email" id="feedback-email" name="email" class="form-input"
                           placeholder="Din e-post om du vill ha svar...">
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
    grid-template-columns: repeat(3, 1fr);
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

/* Rider search */
.rider-search-wrapper {
    position: relative;
}
.rider-search-dropdown {
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
.rider-search-item {
    padding: var(--space-sm) var(--space-md);
    cursor: pointer;
    font-size: var(--text-sm);
    color: var(--color-text-primary);
    border-bottom: 1px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.rider-search-item:last-child {
    border-bottom: none;
}
.rider-search-item:hover {
    background: var(--color-bg-hover);
}
.rider-search-item .rider-club {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}
.rider-search-none {
    padding: var(--space-sm) var(--space-md);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    text-align: center;
}

/* Selected riders */
.selected-riders-list {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
    margin-top: var(--space-xs);
}
.selected-rider-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: var(--space-2xs) var(--space-sm);
    background: var(--color-accent-light);
    color: var(--color-accent-text);
    border-radius: var(--radius-full);
    font-size: var(--text-sm);
}
.selected-rider-tag button {
    background: none;
    border: none;
    color: var(--color-accent-text);
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    opacity: 0.7;
}
.selected-rider-tag button:hover {
    opacity: 1;
}
.selected-rider-tag button i {
    width: 14px;
    height: 14px;
}

@media (max-width: 767px) {
    .container--sm {
        max-width: 100%;
    }
}
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

    // Category switching - show/hide conditional sections
    var categoryRadios = form.querySelectorAll('input[name="category"]');
    categoryRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            sectionProfile.style.display = this.value === 'profile' ? 'block' : 'none';
            sectionResults.style.display = this.value === 'results' ? 'block' : 'none';
        });
    });

    // ========================
    // RIDER SEARCH (profile)
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
                    // Filter out already selected
                    results = results.filter(function(r) {
                        return selectedRiders.findIndex(function(s) { return s.id === r.id; }) === -1;
                    });
                    if (results.length === 0) {
                        searchDropdown.innerHTML = '<div class="rider-search-none">Inga träffar</div>';
                    } else {
                        searchDropdown.innerHTML = results.map(function(r) {
                            return '<div class="rider-search-item" data-id="' + r.id + '" data-name="' + (r.firstname + ' ' + r.lastname).replace(/"/g, '&quot;') + '">'
                                + '<span>' + r.firstname + ' ' + r.lastname + '</span>'
                                + (r.club_name ? '<span class="rider-club">' + r.club_name + '</span>' : '')
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

    // Click on search result
    searchDropdown.addEventListener('click', function(e) {
        var item = e.target.closest('.rider-search-item');
        if (!item) return;
        if (selectedRiders.length >= 4) return;

        var id = parseInt(item.dataset.id);
        var name = item.dataset.name;
        selectedRiders.push({ id: id, name: name });
        updateSelectedRiders();
        searchInput.value = '';
        searchDropdown.style.display = 'none';
    });

    // Close dropdown on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.rider-search-wrapper')) {
            searchDropdown.style.display = 'none';
        }
    });

    function updateSelectedRiders() {
        selectedContainer.innerHTML = selectedRiders.map(function(r, i) {
            return '<span class="selected-rider-tag">'
                + r.name
                + '<button type="button" onclick="window._removeRider(' + i + ')" title="Ta bort"><i data-lucide="x"></i></button>'
                + '</span>';
        }).join('');
        hiddenRiderIds.value = JSON.stringify(selectedRiders.map(function(r) { return r.id; }));
        if (typeof lucide !== 'undefined') lucide.createIcons();

        // Hide search if 4 selected
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
            related_event_id: category === 'results' ? (document.getElementById('related-event').value || null) : null
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
                form.querySelector('input[name="category"][value="other"]').checked = true;
                sectionProfile.style.display = 'none';
                sectionResults.style.display = 'none';
                selectedRiders = [];
                updateSelectedRiders();
                searchInput.style.display = 'block';
                if (typeof lucide !== 'undefined') lucide.createIcons();
                // Scroll to top
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
