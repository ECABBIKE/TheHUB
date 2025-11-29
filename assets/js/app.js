/**
 * TheHUB Application JavaScript
 * Handles global functionality and event listeners
 */

(function() {
    'use strict';

    /**
     * Initialize application
     */
    function init() {
        // Initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        // Setup dynamic content handlers
        setupDynamicContent();

        console.log('TheHUB app initialized');
    }

    /**
     * Setup handlers for dynamically loaded content
     */
    function setupDynamicContent() {
        // Reinitialize Lucide icons after AJAX content loads
        document.addEventListener('hub:contentloaded', function(event) {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            // Reinitialize any dropdowns in new content
            initDropdowns();
        });
    }

    /**
     * Initialize dropdown menus
     */
    function initDropdowns() {
        document.querySelectorAll('[data-dropdown]').forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                const target = document.querySelector(this.dataset.dropdown);
                if (target) {
                    target.classList.toggle('open');
                }
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            document.querySelectorAll('.dropdown-menu.open').forEach(menu => {
                menu.classList.remove('open');
            });
        });
    }

    /**
     * Toast notification system
     */
    window.HubToast = {
        show: function(message, type = 'info', duration = 3000) {
            const container = document.getElementById('toast-container') ||
                              this.createContainer();

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <span class="toast-message">${message}</span>
                <button class="toast-close" onclick="this.parentElement.remove()">×</button>
            `;

            container.appendChild(toast);

            // Auto-remove after duration
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        },

        createContainer: function() {
            const container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
            return container;
        },

        success: function(message) { this.show(message, 'success'); },
        error: function(message) { this.show(message, 'error'); },
        warning: function(message) { this.show(message, 'warning'); },
        info: function(message) { this.show(message, 'info'); }
    };

    /**
     * Copy to clipboard utility
     */
    window.copyToClipboard = async function(text) {
        try {
            await navigator.clipboard.writeText(text);
            HubToast.success('Kopierat!');
        } catch (err) {
            console.error('Failed to copy:', err);
            HubToast.error('Kunde inte kopiera');
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
