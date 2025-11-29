<?php
/**
 * TheHUB 404 Error Page
 */

require_once __DIR__ . '/config.php';

$pageTitle = 'Sidan hittades inte';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="main-content">
    <div class="container">
        <div class="card text-center" style="padding: var(--space-2xl);">
            <div style="font-size: 64px; margin-bottom: var(--space-lg);">🔍</div>
            <h1 class="mb-md">Sidan hittades inte</h1>
            <p class="text-secondary mb-lg">
                Sidan du söker finns inte eller har flyttats.
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
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>
