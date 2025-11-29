/**
 * TheHUB SPA Router
 * Handles AJAX-based navigation for seamless page transitions
 *
 * Based on V3's router architecture
 */
const HubRouter = (function() {
    'use strict';

    const content = document.getElementById('page-content');
    const main = document.getElementById('main-content');
    let navigating = false;

    // Routes that should use SPA navigation
    const spaRoutes = [
        '/home', '/calendar', '/events', '/results', '/series',
        '/database', '/riders', '/clubs', '/ranking', '/profile',
        '/rider/', '/event/', '/club/'
    ];

    /**
     * Check if URL should use SPA navigation
     */
    function isSpaRoute(url) {
        if (!url || url.startsWith('http') || url.startsWith('mailto:')) {
            return false;
        }

        // Skip .php files - use full page reload for legacy
        if (url.includes('.php')) {
            return false;
        }

        // Skip admin routes
        if (url.startsWith('/admin')) {
            return false;
        }

        // Skip v3 routes
        if (url.startsWith('/v3')) {
            return false;
        }

        // Skip API routes
        if (url.startsWith('/api')) {
            return false;
        }

        // Check against known SPA routes
        return spaRoutes.some(route => url.startsWith(route) || url === '/');
    }

    /**
     * Fetch page content via AJAX
     */
    async function fetchContent(url) {
        const response = await fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        return {
            html: await response.text(),
            title: response.headers.get('X-Page-Title') || 'TheHUB',
            section: response.headers.get('X-Page-Section') || ''
        };
    }

    /**
     * Navigate to a new page
     */
    async function navigate(url, pushState = true) {
        if (navigating || !isSpaRoute(url)) {
            // Fall back to regular navigation
            if (!isSpaRoute(url)) {
                window.location.href = url;
            }
            return false;
        }

        navigating = true;

        try {
            // Show loading state
            if (content) {
                content.classList.add('loading');
            }

            // Fetch new content
            const { html, title, section } = await fetchContent(url);

            // Update page content
            if (content) {
                content.innerHTML = html;
            }

            // Update browser history
            if (pushState) {
                history.pushState({ url }, title, url);
            }

            // Update document title
            document.title = title;

            // Update navigation active state
            updateNavigation(section || getBaseRoute(url));

            // Scroll to top
            if (main) {
                main.scrollTop = 0;
            }
            window.scrollTo(0, 0);

            // Focus main content for accessibility
            if (main) {
                main.focus();
            }

            // Reinitialize Lucide icons for new content
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            // Dispatch custom event for other scripts
            document.dispatchEvent(new CustomEvent('hub:contentloaded', {
                detail: { url, section }
            }));

        } catch (error) {
            console.error('Navigation error:', error);
            // Fall back to regular navigation on error
            window.location.href = url;
        } finally {
            if (content) {
                content.classList.remove('loading');
            }
            navigating = false;
        }

        return true;
    }

    /**
     * Get base route from URL
     */
    function getBaseRoute(url) {
        const path = url.replace(/^\//, '').split('/')[0];
        return path || 'home';
    }

    /**
     * Update navigation active states
     */
    function updateNavigation(section) {
        // Remove current active states
        document.querySelectorAll('.sidebar-link, .mobile-nav-link').forEach(link => {
            link.classList.remove('active');
            link.removeAttribute('aria-current');
        });

        // Set new active state
        document.querySelectorAll(`[data-nav="${section}"]`).forEach(link => {
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
        });
    }

    /**
     * Handle link clicks
     */
    function handleClick(event) {
        const link = event.target.closest('a[href]');
        if (!link) return;

        const href = link.getAttribute('href');

        // Skip if modifier key pressed or target specified
        if (event.ctrlKey || event.metaKey || event.shiftKey || link.hasAttribute('target')) {
            return;
        }

        // Skip external links
        if (!href || href.startsWith('http') || href.startsWith('mailto:') || href.startsWith('tel:')) {
            return;
        }

        // Use SPA navigation for eligible routes
        if (isSpaRoute(href)) {
            event.preventDefault();
            navigate(href);

            // Close mobile menu if open
            if (typeof closeMenu === 'function') {
                closeMenu();
            }
        }
    }

    /**
     * Handle browser back/forward
     */
    function handlePopState(event) {
        if (event.state && event.state.url) {
            navigate(event.state.url, false);
        }
    }

    /**
     * Handle form submissions
     */
    function handleSubmit(event) {
        const form = event.target;
        if (form.tagName !== 'FORM') return;

        const action = form.getAttribute('action') || window.location.pathname;
        const method = form.getAttribute('method')?.toUpperCase() || 'GET';

        // Only intercept GET forms on SPA routes
        if (method === 'GET' && isSpaRoute(action)) {
            event.preventDefault();

            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            const url = action + (params.toString() ? '?' + params.toString() : '');

            navigate(url);
        }
    }

    /**
     * Initialize router
     */
    function init() {
        // Set initial state
        history.replaceState({ url: window.location.pathname }, document.title);

        // Add event listeners
        document.addEventListener('click', handleClick);
        document.addEventListener('submit', handleSubmit);
        window.addEventListener('popstate', handlePopState);

        console.log('HubRouter initialized');
    }

    // Public API
    return {
        init,
        navigate,
        isSpaRoute
    };
})();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', HubRouter.init);
} else {
    HubRouter.init();
}
