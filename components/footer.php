<?php
/**
 * TheHUB Footer Component
 */
$versionInfo = function_exists('getVersionInfo') ? getVersionInfo() : ['version' => '2.0', 'name' => 'TheHUB', 'build' => '', 'deployment' => 0, 'commit' => ''];
?>
<footer class="footer">
    <div class="container">
        <p class="footer-version">
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
