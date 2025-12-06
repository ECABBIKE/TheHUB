            </div><!-- .page-content -->
        </main>
    </div>

    <?php include __DIR__ . '/admin-mobile-nav.php'; ?>
    <?php include HUB_V3_ROOT . '/components/footer.php'; ?>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script>
        function initLucideIcons() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            } else {
                setTimeout(initLucideIcons, 50);
            }
        }
        initLucideIcons();
        document.addEventListener('DOMContentLoaded', initLucideIcons);
        window.addEventListener('load', initLucideIcons);
    </script>

    <script src="<?= hub_asset('js/app.js') ?>"></script>
</body>
</html>
