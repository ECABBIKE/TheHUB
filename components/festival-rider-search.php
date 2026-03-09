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

<!-- Festival Rider Search Modal -->
<div id="festivalRiderSearchModal" style="display:none; position:fixed; inset:0; z-index:999999; background:rgba(0,0,0,0.6);">
    <div style="position:absolute; inset:0;" onclick="closeFestivalRiderSearch()"></div>
    <div style="position:relative; z-index:1; background:var(--color-bg-card); border-radius:var(--radius-lg) var(--radius-lg) 0 0; position:fixed; bottom:0; left:0; right:0; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 -4px 24px rgba(0,0,0,0.3);">
        <!-- Header -->
        <div style="display:flex; align-items:center; justify-content:space-between; padding:var(--space-md) var(--space-lg); border-bottom:1px solid var(--color-border); flex-shrink:0;">
            <h3 style="margin:0; font-family:var(--font-heading-secondary); font-size:1.1rem;">Sök deltagare</h3>
            <button type="button" onclick="closeFestivalRiderSearch()" style="background:none; border:none; color:var(--color-text-muted); cursor:pointer; padding:var(--space-xs);">
                <i data-lucide="x" style="width:20px; height:20px;"></i>
            </button>
        </div>
        <!-- Search input -->
        <div style="padding:var(--space-md) var(--space-lg); flex-shrink:0;">
            <div style="position:relative;">
                <i data-lucide="search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--color-text-muted);"></i>
                <input type="text" id="festivalRiderSearchInput"
                       placeholder="Skriv namn..."
                       autocomplete="off"
                       style="width:100%; padding:var(--space-sm) var(--space-md) var(--space-sm) 40px; background:var(--color-bg-surface); border:1px solid var(--color-border-strong); border-radius:var(--radius-md); color:var(--color-text-primary); font-size:16px; outline:none;">
            </div>
        </div>
        <!-- Results -->
        <div id="festivalRiderSearchResults" style="flex:1; overflow-y:auto; padding:0 var(--space-lg) var(--space-lg); min-height:200px;">
            <p style="color:var(--color-text-muted); text-align:center; padding:var(--space-xl) 0; font-size:0.9rem;">
                Skriv minst 2 tecken för att söka
            </p>
        </div>
    </div>
</div>

<script>
(function() {
    let _riderSearchCallback = null;
    let _searchTimeout = null;
    const modal = document.getElementById('festivalRiderSearchModal');
    const input = document.getElementById('festivalRiderSearchInput');
    const resultsDiv = document.getElementById('festivalRiderSearchResults');

    window.openFestivalRiderSearch = function(callback) {
        _riderSearchCallback = callback;
        modal.style.display = 'block';
        input.value = '';
        resultsDiv.innerHTML = '<p style="color:var(--color-text-muted); text-align:center; padding:var(--space-xl) 0; font-size:0.9rem;">Skriv minst 2 tecken för att söka</p>';
        setTimeout(() => input.focus(), 100);
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };

    window.closeFestivalRiderSearch = function() {
        modal.style.display = 'none';
        _riderSearchCallback = null;
    };

    // Search on input
    input.addEventListener('input', function() {
        clearTimeout(_searchTimeout);
        const q = this.value.trim();
        if (q.length < 2) {
            resultsDiv.innerHTML = '<p style="color:var(--color-text-muted); text-align:center; padding:var(--space-xl) 0; font-size:0.9rem;">Skriv minst 2 tecken för att söka</p>';
            return;
        }
        _searchTimeout = setTimeout(() => doSearch(q), 300);
    });

    // Close on Escape
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeFestivalRiderSearch();
    });

    function doSearch(q) {
        resultsDiv.innerHTML = '<p style="color:var(--color-text-muted); text-align:center; padding:var(--space-xl) 0;"><i data-lucide="loader-2" style="width:20px;height:20px;animation:spin 1s linear infinite;"></i></p>';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        fetch('/api/orders.php?action=search_riders&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.riders || data.riders.length === 0) {
                    resultsDiv.innerHTML = '<p style="color:var(--color-text-muted); text-align:center; padding:var(--space-xl) 0; font-size:0.9rem;">Inga deltagare hittades</p>';
                    return;
                }
                renderResults(data.riders);
            })
            .catch(() => {
                resultsDiv.innerHTML = '<p style="color:var(--color-error); text-align:center; padding:var(--space-xl) 0; font-size:0.9rem;">Sökningen misslyckades</p>';
            });
    }

    function renderResults(riders) {
        let html = '';
        riders.forEach(r => {
            const name = (r.firstname || '') + ' ' + (r.lastname || '');
            const club = r.club_name || '';
            const year = r.birth_year || '';
            html += '<div class="festival-rider-result" data-rider=\'' + JSON.stringify(r).replace(/'/g, '&#39;') + '\' style="display:flex; align-items:center; gap:var(--space-sm); padding:var(--space-sm) var(--space-md); border-radius:var(--radius-sm); cursor:pointer; border-bottom:1px solid var(--color-border);" onmouseover="this.style.background=\'var(--color-bg-hover)\'" onmouseout="this.style.background=\'none\'">';
            html += '<div style="flex:1; min-width:0;">';
            html += '<div style="font-weight:600; font-size:0.95rem; color:var(--color-text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + escHtml(name) + '</div>';
            html += '<div style="font-size:0.8rem; color:var(--color-text-muted);">';
            if (year) html += year;
            if (club) html += (year ? ' · ' : '') + escHtml(club);
            html += '</div>';
            html += '</div>';
            html += '<i data-lucide="chevron-right" style="width:16px; height:16px; color:var(--color-text-muted); flex-shrink:0;"></i>';
            html += '</div>';
        });
        resultsDiv.innerHTML = html;

        // Attach click handlers
        resultsDiv.querySelectorAll('.festival-rider-result').forEach(el => {
            el.addEventListener('click', function() {
                const rider = JSON.parse(this.getAttribute('data-rider'));
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
<style>
@keyframes spin { to { transform: rotate(360deg); } }
@media(min-width: 768px) {
    #festivalRiderSearchModal > div:last-child {
        bottom: auto !important;
        top: 50% !important;
        left: 50% !important;
        right: auto !important;
        transform: translate(-50%, -50%);
        max-width: 480px;
        width: 90%;
        border-radius: var(--radius-lg) !important;
        max-height: 70vh;
    }
}
</style>
