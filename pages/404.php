<?php
/**
 * TheHUB 404 Page Module
 * Content-only - layout handled by app.php
 */

$requested = $pageInfo['params']['requested'] ?? $_SERVER['REQUEST_URI'] ?? '';
?>

<div class="container">
    <div class="card text-center" style="padding: var(--space-2xl);">
        <i data-lucide="alert-triangle" style="width: 64px; height: 64px; color: var(--color-warning); margin-bottom: var(--space-lg);"></i>
        <h1 class="mb-md">Sidan hittades inte</h1>
        <p class="text-secondary mb-lg">
            Sidan du soker finns inte eller har flyttats.
            <?php if ($requested): ?>
            <br><code class="text-sm"><?= h($requested) ?></code>
            <?php endif; ?>
        </p>
        <div class="flex gap-md justify-center flex-wrap">
            <a href="/" class="btn btn-primary">
                <i data-lucide="home"></i>
                Till startsidan
            </a>
            <a href="/results" class="btn btn-secondary">
                <i data-lucide="trophy"></i>
                Se resultat
            </a>
        </div>
    </div>
</div>
