/**
 * TheHUB Theme System
 * - Default: följer system (auto)
 * - Sparar i localStorage för alla
 * - Synkar med profil för inloggade användare
 */
const Theme = {
    STORAGE_KEY: 'thehub-theme',

    init() {
        // Hämta sparad preferens
        const saved = this.getSaved();

        // Sätt tema (auto = följ system)
        this.apply(saved);

        // Lyssna på system-ändringar
        this.watchSystem();

        // Bind tema-knappar
        this.bindButtons();

        // Uppdatera aktiva knappar
        this.updateButtons();
    },

    getSaved() {
        // Kolla localStorage först
        const local = localStorage.getItem(this.STORAGE_KEY);
        if (local) return local;

        // Default = auto (följ system)
        return 'auto';
    },

    getEffective() {
        const saved = this.getSaved();
        if (saved === 'auto') {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return saved;
    },

    apply(theme) {
        const effective = theme === 'auto'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : theme;

        document.documentElement.setAttribute('data-theme', effective);

        // Uppdatera theme-color meta för mobila webbläsare
        const metaTheme = document.querySelector('meta[name="theme-color"]');
        if (metaTheme) {
            metaTheme.content = effective === 'dark' ? '#1E293B' : '#FFFFFF';
        }
    },

    set(theme, saveToProfile = true) {
        // Spara lokalt
        localStorage.setItem(this.STORAGE_KEY, theme);

        // Applicera
        this.apply(theme);

        // Uppdatera knappar
        this.updateButtons();

        // Spara till profil om inloggad
        if (saveToProfile && window.HUB?.isLoggedIn) {
            this.saveToProfile(theme);
        }

        // Event för andra komponenter
        window.dispatchEvent(new CustomEvent('themechange', {
            detail: { theme, effective: this.getEffective() }
        }));
    },

    saveToProfile(theme) {
        // AJAX till server för att spara preferens
        fetch('/api/user/preferences.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme })
        }).catch(() => {
            // Ignorera fel - localStorage är backup
        });
    },

    watchSystem() {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (this.getSaved() === 'auto') {
                this.apply('auto');
            }
        });
    },

    bindButtons() {
        document.querySelectorAll('[data-theme-set]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.set(btn.dataset.themeSet);
            });
        });
    },

    updateButtons() {
        const current = this.getSaved();
        document.querySelectorAll('[data-theme-set]').forEach(btn => {
            btn.classList.toggle('is-active', btn.dataset.themeSet === current);
        });
    },

    toggle() {
        const effective = this.getEffective();
        this.set(effective === 'dark' ? 'light' : 'dark');
    }
};

// Auto-init när DOM är redo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Theme.init());
} else {
    Theme.init();
}

// Förhindra flash - körs direkt
(function() {
    const saved = localStorage.getItem('thehub-theme') || 'auto';
    let theme;

    if (saved === 'auto') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } else {
        theme = saved;
    }

    document.documentElement.setAttribute('data-theme', theme);
})();
