<?php
/**
 * My Clubs - Club Admin Dashboard
 * Allows users with club_admin assignments to view and manage their clubs
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$currentUser = getCurrentAdmin();

// Get clubs this user can manage
$myClubs = getUserManagedClubs();

if (empty($myClubs) && !hasRole('admin')) {
    // No clubs assigned - show message
    $page_title = 'Mina Klubbar';
    $breadcrumbs = [['label' => 'Mina Klubbar']];
    include __DIR__ . '/components/unified-layout.php';
    ?>
    <div class="card">
        <div class="card-body text-center py-2xl">
            <i data-lucide="building" style="width: 64px; height: 64px; color: var(--color-text-secondary); margin-bottom: var(--space-md);"></i>
            <h2 class="mb-md">Inga klubbar tilldelade</h2>
            <p class="text-secondary mb-lg">Du har inte tilldelats som administratör för några klubbar ännu.</p>
            <p class="text-secondary">Kontakta en systemadministratör om du behöver hantera en klubbs information.</p>
        </div>
    </div>
    <?php
    include __DIR__ . '/components/unified-layout-footer.php';
    exit;
}

$page_title = 'Mina Klubbar';
$breadcrumbs = [['label' => 'Mina Klubbar']];

include __DIR__ . '/components/unified-layout.php';
?>

<div class="mb-lg">
    <p class="text-secondary">
        Här kan du hantera de klubbar du är tilldelad som administratör för.
    </p>
</div>

<div class="grid gap-lg" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));">
    <?php foreach ($myClubs as $club): ?>
    <div class="card">
        <div class="card-body">
            <div class="flex items-start gap-md">
                <?php if (!empty($club['logo_url'])): ?>
                <img src="<?= h($club['logo_url']) ?>" alt="<?= h($club['name']) ?>" style="width: 60px; height: 60px; object-fit: contain; border-radius: var(--radius-sm); background: var(--color-bg-sunken);">
                <?php else: ?>
                <div style="width: 60px; height: 60px; border-radius: var(--radius-sm); background: var(--color-bg-sunken); display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="building" style="width: 24px; height: 24px; color: var(--color-text-secondary);"></i>
                </div>
                <?php endif; ?>
                <div style="flex: 1;">
                    <h3 class="mb-xs"><?= h($club['name']) ?></h3>
                    <?php if ($club['short_name']): ?>
                    <span class="badge badge-secondary mb-sm"><?= h($club['short_name']) ?></span>
                    <?php endif; ?>
                    <p class="text-sm text-secondary">
                        <?= h($club['city'] ?? '') ?><?= $club['city'] && $club['country'] ? ', ' : '' ?><?= h($club['country'] ?? '') ?>
                    </p>
                </div>
            </div>

            <?php if (!hasRole('admin')): ?>
            <div class="mt-md pt-md" style="border-top: 1px solid var(--color-border);">
                <p class="text-xs text-secondary mb-sm">Dina behörigheter:</p>
                <div class="flex gap-sm flex-wrap">
                    <?php if ($club['can_edit_profile'] ?? false): ?>
                    <span class="badge badge-success"><i data-lucide="pencil" class="icon-xs"></i> Redigera</span>
                    <?php endif; ?>
                    <?php if ($club['can_upload_logo'] ?? false): ?>
                    <span class="badge badge-success"><i data-lucide="image" class="icon-xs"></i> Logo</span>
                    <?php endif; ?>
                    <?php if ($club['can_manage_members'] ?? false): ?>
                    <span class="badge badge-success"><i data-lucide="users" class="icon-xs"></i> Medlemmar</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="mt-md">
                <a href="/admin/my-club-edit.php?id=<?= $club['id'] ?>" class="btn btn--primary w-full">
                    <i data-lucide="pencil"></i>
                    Redigera klubb
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
