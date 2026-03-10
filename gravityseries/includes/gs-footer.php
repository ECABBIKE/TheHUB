<?php
/**
 * GravitySeries — Site Footer
 */
$gsBaseUrl = $gsBaseUrl ?? '/gravityseries';
?>

<!-- FOOTER -->
<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-logo">GravitySeries</div>
    <div class="footer-links">
      <?php if (!empty($gsNavPages)): ?>
        <?php foreach ($gsNavPages as $navPage): ?>
          <a href="<?= $gsBaseUrl ?>/sida.php?slug=<?= htmlspecialchars($navPage['slug']) ?>">
            <?= htmlspecialchars($navPage['nav_label'] ?: $navPage['title']) ?>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <a href="<?= $gsBaseUrl ?>/sida.php?slug=om-oss">Om oss</a>
        <a href="<?= $gsBaseUrl ?>/sida.php?slug=kontakt">Kontakt</a>
        <a href="<?= $gsBaseUrl ?>/sida.php?slug=allmanna-villkor">Villkor</a>
      <?php endif; ?>
      <a href="https://thehub.gravityseries.se">TheHUB</a>
    </div>
    <div class="footer-copy">&copy; <?= date('Y') ?> GravitySeries</div>
  </div>
</footer>

<script>
function toggleMobileNav() {
  document.getElementById('mobileNavOverlay').classList.toggle('open');
  document.getElementById('mobileNavMenu').classList.toggle('open');
}
</script>
</body>
</html>
