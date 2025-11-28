<header class="admin-header">
    <button class="admin-menu-toggle" aria-label="Vaxla meny">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <h1 class="admin-page-title">
        <?php
        $titles = [
            'dashboard' => 'Dashboard',
            'events' => 'Tavlingar',
            'series' => 'Serier',
            'riders' => 'Deltagare',
            'clubs' => 'Klubbar',
            'config' => 'Konfiguration',
            'import' => 'Import',
            'system' => 'System'
        ];
        echo $titles[$route['section'] ?? 'dashboard'] ?? 'Admin';
        ?>
    </h1>

    <div class="admin-header-actions">
        <span class="admin-user-name"><?= htmlspecialchars($currentUser['firstname'] ?? '') ?></span>
        <div class="admin-user-avatar">
            <?= strtoupper(substr($currentUser['firstname'] ?? 'A', 0, 1)) ?>
        </div>
    </div>
</header>
