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

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
        });

        // Mobile menu toggle
        function toggleMenu() {
            const sidebar = document.querySelector('.gs-sidebar');
            const overlay = document.querySelector('.gs-sidebar-overlay');
            const body = document.body;

            if (sidebar && overlay) {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                body.classList.toggle('menu-open');
            }
        }

        function closeMenu() {
            const sidebar = document.querySelector('.gs-sidebar');
            const overlay = document.querySelector('.gs-sidebar-overlay');
            const body = document.body;

            if (sidebar && overlay) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                body.classList.remove('menu-open');
            }
        }

        // Close menu on window resize to desktop size
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                closeMenu();
            }
        });

        <?php if (isset($additionalScripts)): ?>
        // Additional page-specific scripts
        <?= $additionalScripts ?>
        <?php endif; ?>
    </script>
</body>
</html>
