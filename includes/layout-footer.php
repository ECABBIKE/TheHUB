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

    <!-- Footer -->
    <footer class="gs-footer">
        <div class="gs-container">
            <?php
            $versionInfo = getVersionInfo();
            ?>
            <p class="gs-footer-version">
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

    <!-- Bottom Navigation (V2.5 - visible on mobile only) -->
    <?php include __DIR__ . '/nav-bottom.php'; ?>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Mobile menu toggle
        function toggleMenu() {
            const sidebar = document.querySelector('.gs-sidebar');
            const overlay = document.querySelector('.gs-sidebar-overlay');

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
            const sidebar = document.querySelector('.gs-sidebar');
            const overlay = document.querySelector('.gs-sidebar-overlay');

            if (sidebar && overlay) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Close menu when clicking on links
        document.addEventListener('DOMContentLoaded', function() {
            const menuLinks = document.querySelectorAll('.gs-sidebar a');
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

    <!-- V2.5 Modern Theme System Scripts -->
    <script src="/assets/js/theme.js?v=<?= filemtime(__DIR__ . '/../assets/js/theme.js') ?>"></script>
    <script src="/assets/js/dropdown.js?v=<?= filemtime(__DIR__ . '/../assets/js/dropdown.js') ?>"></script>
</body>
</html>
