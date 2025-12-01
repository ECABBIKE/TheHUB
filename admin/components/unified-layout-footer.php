            </div><!-- .page-content -->
        </main>
    </div>

    <?php include __DIR__ . '/admin-mobile-nav.php'; ?>
    <?php include HUB_V3_ROOT . '/components/footer.php'; ?>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
        });
    </script>

    <!-- Theme.js removed - always light theme -->
    <script src="<?= hub_asset('js/app.js') ?>"></script>
</body>
</html>
