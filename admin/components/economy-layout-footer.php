            </div><!-- .page-content -->
        </main>
    </div>

    <?php include __DIR__ . '/admin-mobile-nav.php'; ?>
    <?php include HUB_V3_ROOT . '/components/footer.php'; ?>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- Lucide Icons (pinned version with SRI for security) -->
    <script src="https://unpkg.com/lucide@0.263.1/dist/umd/lucide.min.js"
            integrity="sha384-5wnXeGaKKM8t+1xSmT9SzNz2R3YVHHdHKpzr6ZYRQyDdNsXLqwVG+S0c5qK6V3JL"
            crossorigin="anonymous"></script>
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
