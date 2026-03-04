<?php
/**
 * Standardized Layout Footer for TheHUB
 *
 * Closes main tag, includes scripts, and closes body/html
 *
 * Variables:
 *   - $additionalScripts (optional): Additional JavaScript code to run
 */
?>
    </main>

    <!-- Global Sponsor: Footer Position -->
    <?php
    // Determine page type for footer sponsors
    $footerPageType = 'all';
    if (defined('HUB_PAGE_TYPE')) {
        $footerPageType = HUB_PAGE_TYPE;
    }
    if (function_exists('render_global_sponsors')) {
        echo render_global_sponsors($footerPageType, 'footer', '');
    }
    ?>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <?php
            $versionInfo = getVersionInfo();
            ?>
            <p class="footer-version">
                TheHUB v<?= h($versionInfo['version']) ?>
                <?php if (!empty($versionInfo['build'])): ?>
                    <strong>[<?= h($versionInfo['build']) ?>.<?= str_pad($versionInfo['deployment'], 3, '0', STR_PAD_LEFT) ?>]</strong>
                <?php endif; ?>
                • <?= h($versionInfo['name']) ?>
                <?php if ($versionInfo['commit']): ?>
                    • <?= h($versionInfo['commit']) ?>
                <?php endif; ?>
            </p>
        </div>
    </footer>

    <!-- Bottom Navigation (V2.5 - visible on mobile only, NOT on admin pages) -->
    <?php
    $isAdminPage = strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
    if (!$isAdminPage) {
        include __DIR__ . '/nav-bottom.php';
    }
    ?>

    <!-- Theme System -->
    <script src="/assets/js/theme.js?v=<?= filemtime(__DIR__ . '/../assets/js/theme.js') ?>"></script>

    <!-- Global Shopping Cart -->
    <script src="/assets/js/global-cart.js?v=<?= filemtime(__DIR__ . '/../assets/js/global-cart.js') ?>"></script>

    <!-- FOUC Prevention: Reveal content after CSS is ready -->
    <script>
        (function() {
            var main = document.querySelector('.main-content');
            if (main) {
                // Use double rAF to ensure CSS is computed
                requestAnimationFrame(function() {
                    requestAnimationFrame(function() {
                        main.classList.add('css-ready');
                    });
                });
            }
        })();
    </script>

    <!-- Lucide Icons (same version as head.php, deferred) -->
    <script defer src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>
    <script>
        // Initialize Lucide icons when loaded
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') lucide.createIcons();
            else document.querySelector('script[src*="lucide"]')?.addEventListener('load', function() { lucide.createIcons(); });
        });

        // Mobile menu toggle
        function toggleMenu() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');

            if (!sidebar || !overlay) return;

            const isOpen = sidebar.classList.contains('open');

            if (isOpen) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            } else {
                sidebar.classList.add('open');
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeMenu() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');

            if (sidebar && overlay) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Close menu when clicking on links
        document.addEventListener('DOMContentLoaded', function() {
            const menuLinks = document.querySelectorAll('.sidebar a');
            menuLinks.forEach(link => {
                link.addEventListener('click', closeMenu);
            });
        });

        // AUTO-CLOSE menu when resizing to desktop
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                // If window is now desktop width, force close mobile menu
                if (window.innerWidth >= 1024) {
                    closeMenu();
                    document.body.style.overflow = ''; // Restore scroll
                }
            }, 250);
        });

        // On page load, ensure menu is closed if on desktop
        window.addEventListener('load', function() {
            if (window.innerWidth >= 1024) {
                closeMenu();
            }
        });

        <?php if (isset($additionalScripts)): ?>
        // Additional page-specific scripts
        <?= $additionalScripts ?>
        <?php endif; ?>
    </script>

    <script src="/assets/js/dropdown.js?v=<?= filemtime(__DIR__ . '/../assets/js/dropdown.js') ?>"></script>

    <!-- Viewport System - dynamic vh/vw for responsive heights -->
    <script src="/assets/js/viewport.js?v=<?= filemtime(__DIR__ . '/../assets/js/viewport.js') ?>"></script>
</body>
</html>
