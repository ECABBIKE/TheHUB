/**
 * SCROLL-AWARE HEADER
 *
 * Hides header when scrolling down, shows when scrolling up.
 * Optimized for performance using requestAnimationFrame.
 */

(function() {
  'use strict';

  // Configuration
  const SCROLL_THRESHOLD = 10; // Minimum scroll distance to trigger hide/show
  const DEBOUNCE_DELAY = 100; // ms between scroll checks

  // State
  let lastScrollTop = 0;
  let isScrolling = false;
  let ticking = false;

  // Get header element
  const header = document.querySelector('.main-header');

  // Exit if no header found
  if (!header) return;

  /**
   * Update header visibility based on scroll direction
   */
  function updateHeader() {
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const scrollDelta = scrollTop - lastScrollTop;

    // Update body class for "at top" state
    if (scrollTop <= 10) {
      document.body.classList.add('at-top');
      header.classList.remove('header-hidden', 'header-visible');
    } else {
      document.body.classList.remove('at-top');

      // Only update if scroll distance exceeds threshold
      if (Math.abs(scrollDelta) > SCROLL_THRESHOLD) {
        if (scrollDelta > 0) {
          // Scrolling down - hide header
          header.classList.add('header-hidden');
          header.classList.remove('header-visible');
        } else {
          // Scrolling up - show header
          header.classList.add('header-visible');
          header.classList.remove('header-hidden');
        }
      }
    }

    lastScrollTop = scrollTop <= 0 ? 0 : scrollTop; // Prevent negative values
    ticking = false;
  }

  /**
   * Request animation frame for smooth updates
   */
  function requestTick() {
    if (!ticking) {
      window.requestAnimationFrame(updateHeader);
      ticking = true;
    }
  }

  /**
   * Debounced scroll handler
   */
  let scrollTimeout;
  function onScroll() {
    // Clear previous timeout
    clearTimeout(scrollTimeout);

    // Set scrolling state
    isScrolling = true;

    // Request update
    requestTick();

    // Debounce: mark scrolling as finished after delay
    scrollTimeout = setTimeout(() => {
      isScrolling = false;
    }, DEBOUNCE_DELAY);
  }

  /**
   * Initialize gradient data attributes
   */
  function initGradientAttributes() {
    // Get computed gradient settings
    const root = document.documentElement;
    const computedStyle = getComputedStyle(root);

    // Read gradient-enabled and gradient-type from CSS variables
    const gradientEnabled = computedStyle.getPropertyValue('--gradient-enabled').trim();
    const gradientType = computedStyle.getPropertyValue('--gradient-type').trim();

    // Set data attributes on body
    if (gradientEnabled === '0') {
      document.body.setAttribute('data-gradient', 'disabled');
    }

    if (gradientType === 'radial') {
      document.body.setAttribute('data-gradient-type', 'radial');
    }
  }

  /**
   * Initialize
   */
  function init() {
    // Set initial state
    if (window.pageYOffset <= 10) {
      document.body.classList.add('at-top');
    }

    // Initialize gradient attributes
    initGradientAttributes();

    // Add scroll listener (passive for better performance)
    window.addEventListener('scroll', onScroll, { passive: true });

    // Handle resize (update on orientation change)
    let resizeTimeout;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(updateHeader, DEBOUNCE_DELAY);
    }, { passive: true });
  }

  /**
   * Start when DOM is ready
   */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Debug helper (enable with ?debug_scroll in URL)
  if (window.location.search.includes('debug_scroll')) {
    console.log('[Scroll Header] Initialized');
    window.addEventListener('scroll', () => {
      console.log('Scroll:', {
        top: window.pageYOffset,
        delta: window.pageYOffset - lastScrollTop,
        headerState: header.classList.contains('header-hidden') ? 'hidden' : 'visible'
      });
    }, { passive: true });
  }
})();
