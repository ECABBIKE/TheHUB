<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' - ' : '' ?>TheHUB</title>
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body>
    <?php $flash = getFlash(); ?>
    <?php if ($flash): ?>
        <div class="flash flash-<?= h($flash['type']) ?>">
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>
