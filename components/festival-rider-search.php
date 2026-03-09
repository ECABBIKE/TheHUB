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
            <h3 class="frs-title">Sök deltagare</h3>
            <button type="button" onclick="closeFestivalRiderSearch()" class="frs-close">
                <i data-lucide="x" style="width:20px; height:20px;"></i>
            </button>
        </div>
        <!-- Search input -->
        <div class="frs-search">
            <div class="frs-search-wrap">
                <i data-lucide="search" class="frs-search-icon"></i>
                <input type="text" id="festivalRiderSearchInput"
                       placeholder="Skriv namn..."
                       autocomplete="off"
                       class="frs-input"
                       inputmode="search"
                       enterkeyhint="search">
            </div>
        </div>
        <!-- Results -->
        <div id="festivalRiderSearchResults" class="frs-results">
            <p class="frs-hint">Skriv minst 2 tecken för att söka</p>
        </div>
    </div>
</div>

<style>
@keyframes frs-spin { to { transform: rotate(360deg); } }

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
    const modal = document.getElementById('festivalRiderSearchModal');
    const input = document.getElementById('festivalRiderSearchInput');
    const resultsDiv = document.getElementById('festivalRiderSearchResults');

    window.openFestivalRiderSearch = function(callback) {
        _riderSearchCallback = callback;
        modal.style.display = 'block';
        document.documentElement.classList.add('lightbox-open');
        input.value = '';
        resultsDiv.innerHTML = '<p class="frs-hint">Skriv minst 2 tecken för att söka</p>';
        // Slight delay so modal is rendered before focus
        setTimeout(() => {
            input.focus();
            // Scroll input into view on mobile (above keyboard)
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
                // Adjust container height to match visible viewport
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
                    resultsDiv.innerHTML = '<p class="frs-hint">Inga deltagare hittades</p>';
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
