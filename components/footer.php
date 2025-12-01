<?php
/**
 * TheHUB V3.5 - Footer
 * Theme toggle removed - users can change theme in profile settings
 */
?>
<footer class="site-footer">
    <div class="footer-content">
        <p class="footer-copyright">&copy; <?= date('Y') ?> TheHUB</p>
    </div>
</footer>

<style>
.site-footer {
    padding: var(--space-lg) var(--space-md);
    text-align: center;
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    border-top: 1px solid var(--color-border);
    margin-top: auto;
}
.footer-content {
    max-width: 1200px;
    margin: 0 auto;
}
.footer-copyright {
    margin: 0;
}
</style>
