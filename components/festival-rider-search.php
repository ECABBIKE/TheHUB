<?php
/**
 * Festival Rider Search Modal
 *
 * Delad komponent för att söka och välja deltagare på festivalsidor.
 * Inkludera denna i alla festival-sidor som har köpknappar.
 *
 * Exponerar globala JS-funktioner:
 *   - openFestivalRiderSearch(callback) — öppnar modalen, callback(rider) anropas vid val
 *   - closeFestivalRiderSearch() — stänger modalen
 *
 * Rider-objekt som returneras till callback:
 *   { id, firstname, lastname, birth_year, club_name, ... }
 */
?>

<!-- Festival Rider Search Modal — fullscreen overlay -->
<div id="festivalRiderSearchModal" class="frs-overlay" style="display:none;">
    <div class="frs-container">
        <!-- Header -->
        <div class="frs-header">
            <h3 class="frs-title" id="frsTitle">Sök deltagare</h3>
            <button type="button" onclick="closeFestivalRiderSearch()" class="frs-close">
                <i data-lucide="x" style="width:20px; height:20px;"></i>
            </button>
        </div>

        <!-- Search view -->
        <div id="frsSearchView">
            <!-- Search input -->
            <div class="frs-search">
                <div class="frs-search-wrap">
                    <i data-lucide="search" class="frs-search-icon"></i>
                    <input type="text" id="festivalRiderSearchInput"
                           placeholder="Skriv namn eller UCI ID..."
                           autocomplete="off"
                           class="frs-input"
                           inputmode="search"
                           enterkeyhint="search">
                </div>
            </div>
            <!-- Create rider link -->
            <div style="padding: 0 var(--space-lg); margin-bottom: var(--space-xs);">
                <button type="button" onclick="frsShowCreateForm()" style="background: none; border: none; color: var(--color-accent); cursor: pointer; font-size: 0.875rem; display: inline-flex; align-items: center; gap: var(--space-2xs); padding: 0; font-weight: 600;">
                    <i data-lucide="user-plus" style="width: 16px; height: 16px;"></i> Skapa ny deltagare
                </button>
            </div>
            <!-- Results -->
            <div id="festivalRiderSearchResults" class="frs-results">
                <p class="frs-hint">Skriv minst 2 tecken för att söka</p>
            </div>
        </div>

        <!-- Create rider view (hidden by default) -->
        <div id="frsCreateView" style="display:none; flex:1; overflow:hidden; flex-direction:column;">
            <div style="padding: var(--space-sm) var(--space-lg); border-bottom: 1px solid var(--color-border); flex-shrink: 0;">
                <button type="button" onclick="frsBackToSearch()" style="background: none; border: none; color: var(--color-accent); cursor: pointer; font-size: 0.875rem; display: inline-flex; align-items: center; gap: var(--space-2xs); padding: var(--space-xs) 0;">
                    <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i> Tillbaka till sök
                </button>
            </div>
            <div style="flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: var(--space-lg);">
                <div style="display: flex; flex-direction: column; gap: var(--space-sm);">
                    <div>
                        <label class="frs-label">Förnamn *</label>
                        <input type="text" id="frsNewFirstname" class="frs-form-input" required>
                    </div>
                    <div>
                        <label class="frs-label">Efternamn *</label>
                        <input type="text" id="frsNewLastname" class="frs-form-input" required>
                    </div>
                    <div>
                        <label class="frs-label">E-post *</label>
                        <input type="email" id="frsNewEmail" class="frs-form-input" placeholder="namn@exempel.se" required>
                    </div>
                    <div>
                        <label class="frs-label">Telefon *</label>
                        <input type="tel" id="frsNewPhone" class="frs-form-input" placeholder="070-123 45 67" required>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm);">
                        <div>
                            <label class="frs-label">Födelseår *</label>
                            <input type="number" id="frsNewBirthYear" class="frs-form-input" placeholder="t.ex. 1990" min="1920" max="2025" inputmode="numeric" required>
                        </div>
                        <div>
                            <label class="frs-label">Kön *</label>
                            <select id="frsNewGender" class="frs-form-input" required>
                                <option value="">Välj...</option>
                                <option value="M">Man</option>
                                <option value="F">Kvinna</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="frs-label">Nationalitet</label>
                        <select id="frsNewNationality" class="frs-form-input">
                            <option value="SWE" selected>Sverige</option>
                            <option value="NOR">Norge</option>
                            <option value="DNK">Danmark</option>
                            <option value="FIN">Finland</option>
                            <option value="DEU">Tyskland</option>
                            <option value="GBR">Storbritannien</option>
                            <option value="USA">USA</option>
                            <option value="">Annan</option>
                        </select>
                    </div>
                    <div>
                        <label class="frs-label">Klubb</label>
                        <div style="position: relative;">
                            <input type="text" id="frsNewClubSearch" class="frs-form-input" placeholder="Sök klubb..." autocomplete="off">
                            <input type="hidden" id="frsNewClubId" value="">
                            <div id="frsClubResults" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:100; background:var(--color-bg-card); border:1px solid var(--color-border); border-top:none; border-radius:0 0 var(--radius-sm) var(--radius-sm); max-height:200px; overflow-y:auto; box-shadow: 0 4px 12px rgba(0,0,0,0.3);"></div>
                        </div>
                    </div>
                </div>

                <div style="margin: var(--space-md) 0; padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                    <span style="color: var(--color-text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Nödkontakt (ICE)</span>
                </div>
                <div style="display: flex; flex-direction: column; gap: var(--space-sm);">
                    <div>
                        <label class="frs-label">Namn *</label>
                        <input type="text" id="frsNewIceName" class="frs-form-input" placeholder="Förnamn Efternamn" required>
                    </div>
                    <div>
                        <label class="frs-label">Telefon *</label>
                        <input type="tel" id="frsNewIcePhone" class="frs-form-input" placeholder="070-123 45 67" required>
                    </div>
                </div>

                <button type="button" id="frsCreateBtn" onclick="frsHandleCreate()" style="margin-top: var(--space-lg); padding: var(--space-md); width: 100%; background: var(--color-accent); color: var(--color-bg-page); border: none; border-radius: var(--radius-sm); font-size: 1rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: var(--space-xs); min-height: 48px;">
                    <i data-lucide="user-plus"></i> Skapa och välj
                </button>
                <div id="frsCreateError" style="display:none; margin-top: var(--space-sm); font-size: 0.875rem;"></div>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes frs-spin { to { transform: rotate(360deg); } }

/* Dölj ALL navigation när sökmodalen är öppen */
html.lightbox-open .header,
html.lightbox-open .sidebar,
html.lightbox-open .nav-bottom,
html.lightbox-open .mobile-nav,
html.lightbox-open .admin-mobile-nav {
    display: none !important;
}
html.lightbox-open body {
    overflow: hidden;
}

/* Overlay: fullskärm, ovanför ALLT */
.frs-overlay {
    position: fixed;
    inset: 0;
    z-index: 2000000;
    background: var(--color-bg-page);
}
.frs-container {
    display: flex;
    flex-direction: column;
    height: 100%;
    max-width: 540px;
    margin: 0 auto;
}
.frs-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}
.frs-title {
    margin: 0;
    font-family: var(--font-heading-secondary);
    font-size: 1.1rem;
}
.frs-close {
    background: none;
    border: none;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: var(--space-xs);
    min-width: 44px;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.frs-search {
    padding: var(--space-md) var(--space-lg);
    flex-shrink: 0;
}
.frs-search-wrap {
    position: relative;
}
.frs-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: var(--color-text-muted);
}
.frs-input {
    width: 100%;
    padding: var(--space-sm) var(--space-md) var(--space-sm) 40px;
    background: var(--color-bg-surface);
    border: 2px solid var(--color-border-strong);
    border-radius: var(--radius-md);
    color: var(--color-text-primary);
    font-size: 16px;
    outline: none;
    min-height: 48px;
}
.frs-input:focus {
    border-color: var(--color-accent);
}
.frs-results {
    flex: 1;
    overflow-y: auto;
    padding: 0 var(--space-lg) var(--space-lg);
    -webkit-overflow-scrolling: touch;
}
.frs-hint {
    color: var(--color-text-muted);
    text-align: center;
    padding: var(--space-xl) 0;
    font-size: 0.9rem;
}
.frs-result-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-sm);
    cursor: pointer;
    border-bottom: 1px solid var(--color-border);
    min-height: 48px;
}
.frs-result-item:active,
.frs-result-item:hover {
    background: var(--color-bg-hover);
}
.frs-result-name {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--color-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.frs-result-meta {
    font-size: 0.8rem;
    color: var(--color-text-muted);
}
.frs-spinner {
    width: 20px;
    height: 20px;
    animation: frs-spin 1s linear infinite;
}
.frs-label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--color-text-muted);
    margin-bottom: var(--space-2xs);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.frs-form-input {
    width: 100%;
    padding: 10px 12px;
    font-size: 16px;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    color: var(--color-text-primary);
    box-sizing: border-box;
    min-height: 44px;
}
.frs-form-input:focus {
    border-color: var(--color-accent);
    outline: none;
}

/* Desktop: centrerad dialog istället för fullskärm */
@media(min-width: 768px) {
    .frs-overlay {
        background: rgba(0,0,0,0.6);
    }
    .frs-container {
        margin-top: 10vh;
        height: auto;
        max-height: 70vh;
        border-radius: var(--radius-lg);
        background: var(--color-bg-card);
        box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        overflow: hidden;
    }
    .frs-results {
        min-height: 200px;
    }
}
</style>

<script>
(function() {
    let _riderSearchCallback = null;
    let _searchTimeout = null;
    let _clubSearchTimeout = null;
    const modal = document.getElementById('festivalRiderSearchModal');
    const input = document.getElementById('festivalRiderSearchInput');
    const resultsDiv = document.getElementById('festivalRiderSearchResults');
    const searchView = document.getElementById('frsSearchView');
    const createView = document.getElementById('frsCreateView');
    const titleEl = document.getElementById('frsTitle');

    window.openFestivalRiderSearch = function(callback) {
        _riderSearchCallback = callback;
        modal.style.display = 'block';
        document.documentElement.classList.add('lightbox-open');
        // Always show search view
        frsBackToSearch();
        input.value = '';
        resultsDiv.innerHTML = '<p class="frs-hint">Skriv minst 2 tecken för att söka</p>';
        setTimeout(() => {
            input.focus();
            input.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }, 150);
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };

    window.closeFestivalRiderSearch = function() {
        modal.style.display = 'none';
        document.documentElement.classList.remove('lightbox-open');
        _riderSearchCallback = null;
        input.blur();
    };

    window.frsShowCreateForm = function() {
        searchView.style.display = 'none';
        createView.style.display = 'flex';
        titleEl.textContent = 'Skapa ny deltagare';

        // Pre-fill name from search
        const searchVal = input.value.trim();
        if (searchVal) {
            const parts = searchVal.split(' ');
            document.getElementById('frsNewFirstname').value = parts[0] || '';
            document.getElementById('frsNewLastname').value = parts.slice(1).join(' ') || '';
        }

        document.getElementById('frsCreateError').style.display = 'none';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };

    window.frsBackToSearch = function() {
        createView.style.display = 'none';
        searchView.style.display = '';
        titleEl.textContent = 'Sök deltagare';
    };

    window.frsHandleCreate = async function() {
        const firstname = document.getElementById('frsNewFirstname').value.trim();
        const lastname = document.getElementById('frsNewLastname').value.trim();
        const email = document.getElementById('frsNewEmail').value.trim();
        const phone = document.getElementById('frsNewPhone').value.trim();
        const birthYear = document.getElementById('frsNewBirthYear').value.trim();
        const gender = document.getElementById('frsNewGender').value;
        const nationality = document.getElementById('frsNewNationality').value;
        const clubId = document.getElementById('frsNewClubId').value || null;
        const iceName = document.getElementById('frsNewIceName').value.trim();
        const icePhone = document.getElementById('frsNewIcePhone').value.trim();
        const errorDiv = document.getElementById('frsCreateError');
        const btn = document.getElementById('frsCreateBtn');

        // Validate
        if (!firstname || !lastname) { frsShowError('Förnamn och efternamn krävs.'); return; }
        if (!email) { frsShowError('E-post krävs.'); return; }
        if (!birthYear) { frsShowError('Födelseår krävs.'); return; }
        if (!gender) { frsShowError('Kön krävs.'); return; }
        if (!phone) { frsShowError('Telefonnummer krävs.'); return; }
        if (!iceName || !icePhone) { frsShowError('Nödkontakt (namn och telefon) krävs.'); return; }

        btn.disabled = true;
        btn.textContent = 'Skapar...';
        errorDiv.style.display = 'none';

        try {
            const response = await fetch('/api/orders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create_rider',
                    rider: {
                        firstname, lastname, email,
                        birth_year: birthYear,
                        gender, nationality, phone,
                        club_id: clubId,
                        ice_name: iceName,
                        ice_phone: icePhone
                    }
                })
            });
            const data = await response.json();

            if (data.success && data.rider) {
                if (_riderSearchCallback) {
                    _riderSearchCallback(data.rider);
                }
                closeFestivalRiderSearch();
            } else {
                if (data.code === 'email_exists_active') {
                    errorDiv.innerHTML = '<span style="color: var(--color-warning);">' + (data.error || '') + '</span>' +
                        '<br><a href="/login" style="color: var(--color-accent); text-decoration: underline; font-weight: 500;">Logga in här</a>';
                } else if (data.code === 'email_exists_inactive') {
                    errorDiv.innerHTML = '<span style="color: var(--color-warning);">' + (data.error || '') + '</span>' +
                        '<br><span style="color: var(--color-text-secondary);">Sök på namnet istället för att hitta profilen.</span>';
                } else if (data.code === 'name_duplicate') {
                    errorDiv.innerHTML = '<span style="color: var(--color-warning);">' + (data.error || '') + '</span>' +
                        '<br><button type="button" onclick="frsBackToSearch()" ' +
                        'style="color: var(--color-accent); background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 500; padding: 0; margin-top: var(--space-xs);">' +
                        'Tillbaka till sök</button>';
                } else {
                    errorDiv.innerHTML = data.error || 'Kunde inte skapa deltagare.';
                    errorDiv.style.color = 'var(--color-error)';
                }
                errorDiv.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="user-plus"></i> Skapa och välj';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        } catch (e) {
            console.error('Create rider failed:', e);
            frsShowError('Något gick fel. Försök igen.');
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="user-plus"></i> Skapa och välj';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    };

    function frsShowError(msg) {
        const errorDiv = document.getElementById('frsCreateError');
        errorDiv.innerHTML = msg;
        errorDiv.style.color = 'var(--color-error)';
        errorDiv.style.display = 'block';
    }

    // Club search (typeahead)
    const clubInput = document.getElementById('frsNewClubSearch');
    const clubIdInput = document.getElementById('frsNewClubId');
    const clubResults = document.getElementById('frsClubResults');

    clubInput.addEventListener('input', function() {
        clearTimeout(_clubSearchTimeout);
        const q = this.value.trim();
        clubIdInput.value = '';
        if (q.length < 2) { clubResults.style.display = 'none'; return; }
        _clubSearchTimeout = setTimeout(() => {
            fetch('/api/search.php?type=clubs&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if (!data.results || data.results.length === 0) {
                        clubResults.style.display = 'none';
                        return;
                    }
                    let html = '';
                    data.results.forEach(c => {
                        html += '<div style="padding: var(--space-xs) var(--space-sm); cursor: pointer; font-size: 0.9rem; border-bottom: 1px solid var(--color-border);" ' +
                            'onmouseover="this.style.background=\'var(--color-bg-hover)\'" onmouseout="this.style.background=\'\'" ' +
                            'data-club-id="' + c.id + '" data-club-name="' + escHtml(c.name) + '">' +
                            escHtml(c.name) + (c.city ? ' <span style="color:var(--color-text-muted);">(' + escHtml(c.city) + ')</span>' : '') +
                            '</div>';
                    });
                    clubResults.innerHTML = html;
                    clubResults.style.display = '';
                    clubResults.querySelectorAll('[data-club-id]').forEach(el => {
                        el.addEventListener('click', function() {
                            clubIdInput.value = this.dataset.clubId;
                            clubInput.value = this.dataset.clubName;
                            clubResults.style.display = 'none';
                        });
                    });
                })
                .catch(() => { clubResults.style.display = 'none'; });
        }, 300);
    });

    // Hide club results on blur (with delay for click)
    clubInput.addEventListener('blur', function() {
        setTimeout(() => { clubResults.style.display = 'none'; }, 200);
    });

    // Search on input
    input.addEventListener('input', function() {
        clearTimeout(_searchTimeout);
        const q = this.value.trim();
        if (q.length < 2) {
            resultsDiv.innerHTML = '<p class="frs-hint">Skriv minst 2 tecken för att söka</p>';
            return;
        }
        _searchTimeout = setTimeout(() => doSearch(q), 300);
    });

    // Close on Escape
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeFestivalRiderSearch();
    });

    // Handle visual viewport changes (mobile keyboard)
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function() {
            if (modal.style.display !== 'none') {
                const container = modal.querySelector('.frs-container');
                if (container && window.innerWidth < 768) {
                    container.style.height = window.visualViewport.height + 'px';
                }
            }
        });
    }

    function doSearch(q) {
        resultsDiv.innerHTML = '<p class="frs-hint"><i data-lucide="loader-2" class="frs-spinner"></i></p>';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        fetch('/api/orders.php?action=search_riders&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.riders || data.riders.length === 0) {
                    resultsDiv.innerHTML = '<p class="frs-hint">Inga deltagare hittades</p>' +
                        '<p style="text-align:center;"><button type="button" onclick="frsShowCreateForm()" style="background:none; border:none; color:var(--color-accent); cursor:pointer; font-size:0.875rem; font-weight:600;">' +
                        '<i data-lucide="user-plus" style="width:14px; height:14px; vertical-align:-2px;"></i> Skapa ny deltagare</button></p>';
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                    return;
                }
                renderResults(data.riders);
            })
            .catch(() => {
                resultsDiv.innerHTML = '<p class="frs-hint" style="color:var(--color-error);">Sökningen misslyckades</p>';
            });
    }

    function renderResults(riders) {
        let html = '';
        riders.forEach(r => {
            const name = (r.firstname || '') + ' ' + (r.lastname || '');
            const club = r.club_name || '';
            const year = r.birth_year || '';
            const dataAttr = JSON.stringify(r).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
            html += '<div class="frs-result-item" data-rider="' + dataAttr + '">';
            html += '<div style="flex:1; min-width:0;">';
            html += '<div class="frs-result-name">' + escHtml(name) + '</div>';
            html += '<div class="frs-result-meta">';
            if (year) html += year;
            if (club) html += (year ? ' · ' : '') + escHtml(club);
            html += '</div>';
            html += '</div>';
            html += '<i data-lucide="chevron-right" style="width:16px; height:16px; color:var(--color-text-muted); flex-shrink:0;"></i>';
            html += '</div>';
        });
        resultsDiv.innerHTML = html;

        // Attach click handlers
        resultsDiv.querySelectorAll('.frs-result-item').forEach(el => {
            el.addEventListener('click', function() {
                const rider = JSON.parse(this.getAttribute('data-rider').replace(/&quot;/g, '"').replace(/&#39;/g, "'"));
                if (_riderSearchCallback) {
                    _riderSearchCallback(rider);
                }
                closeFestivalRiderSearch();
            });
        });

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function escHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
})();
</script>
