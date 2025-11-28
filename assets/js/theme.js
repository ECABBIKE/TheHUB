/**
 * TheHUB Theme Switcher
 */
const Theme = {
    STORAGE_KEY: 'thehub-theme',

    init() {
        // Sätt tema från localStorage eller system
        const saved = localStorage.getItem(this.STORAGE_KEY);

        if (saved && saved !== 'auto') {
            this.setTheme(saved, false);
        } else {
            this.setTheme('auto', false);
        }

        // Lyssna på system-ändringar
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (this.getCurrent() === 'auto') {
                document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
            }
        });

        // Bind klick på tema-knappar
        document.querySelectorAll('[data-theme-set]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.setTheme(btn.dataset.themeSet);
            });
        });
    },

    getCurrent() {
        return localStorage.getItem(this.STORAGE_KEY) || 'auto';
    },

    getEffective() {
        const current = this.getCurrent();
        if (current === 'auto') {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return current;
    },

    setTheme(theme, save = true) {
        if (theme === 'auto') {
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-theme', systemDark ? 'dark' : 'light');
        } else {
            document.documentElement.setAttribute('data-theme', theme);
        }

        if (save) {
            localStorage.setItem(this.STORAGE_KEY, theme);
        }

        // Uppdatera aktiv-status på knappar
        document.querySelectorAll('[data-theme-set]').forEach(btn => {
            btn.classList.toggle('is-active', btn.dataset.themeSet === theme);
        });

        // Dispatch event
        window.dispatchEvent(new CustomEvent('themechange', { detail: { theme } }));
    },

    toggle() {
        const current = this.getEffective();
        this.setTheme(current === 'dark' ? 'light' : 'dark');
    }
};

// Auto-init
document.addEventListener('DOMContentLoaded', () => Theme.init());

// Prevent flash of wrong theme
(function() {
    const saved = localStorage.getItem('thehub-theme');
    let theme = 'light';

    if (saved === 'dark') {
        theme = 'dark';
    } else if (saved === 'auto' || !saved) {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    document.documentElement.setAttribute('data-theme', theme);
})();
