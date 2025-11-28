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

    <!-- Floating Theme Switcher (V3.5 Compatible) -->
    <div class="theme-toggle theme-switcher" role="group" aria-label="Välj tema">
        <button type="button" class="theme-toggle-btn theme-btn" data-theme="light" aria-pressed="false" aria-label="Ljust tema" title="Ljust tema">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
            </svg>
        </button>
        <button type="button" class="theme-toggle-btn theme-btn" data-theme="auto" aria-pressed="false" aria-label="Automatiskt tema" title="Automatiskt">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect width="20" height="14" x="2" y="3" rx="2"/>
                <line x1="8" x2="16" y1="21" y2="21"/>
                <line x1="12" x2="12" y1="17" y2="21"/>
            </svg>
        </button>
        <button type="button" class="theme-toggle-btn theme-btn" data-theme="dark" aria-pressed="false" aria-label="Mörkt tema" title="Mörkt tema">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
            </svg>
        </button>
    </div>

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
