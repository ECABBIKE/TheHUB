<?php
/**
 * TheHUB V3.5 - Linked Children
 * Manage parent-child relationships for registration
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /v3/profile/login');
    exit;
}

$pdo = hub_db();
$linkedChildren = hub_get_linked_children($currentUser['id']);
$message = $_GET['msg'] ?? '';
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/v3/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Kopplade barn</span>
    </nav>
    <h1 class="page-title">
        <span class="page-icon">👨‍👩‍👧</span>
        Kopplade barn
    </h1>
    <p class="page-subtitle">Hantera barn du kan anmäla till tävlingar</p>
</div>

<?php if ($message === 'added'): ?>
    <div class="alert alert-success">Barn har lagts till!</div>
<?php elseif ($message === 'removed'): ?>
    <div class="alert alert-success">Koppling har tagits bort.</div>
<?php endif; ?>

<?php if (empty($linkedChildren)): ?>
    <div class="empty-state card">
        <div class="empty-icon">👨‍👩‍👧</div>
        <h3>Inga kopplade barn</h3>
        <p>Du har inga barn kopplade till din profil ännu. Koppla barn för att kunna anmäla dem till tävlingar.</p>
    </div>
<?php else: ?>
    <div class="children-list">
        <?php foreach ($linkedChildren as $child): ?>
            <div class="child-card">
                <div class="child-avatar">
                    <?= strtoupper(substr($child['first_name'], 0, 1)) ?>
                </div>
                <div class="child-info">
                    <a href="/v3/database/rider/<?= $child['id'] ?>" class="child-name">
                        <?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?>
                    </a>
                    <?php if ($child['birth_year']): ?>
                        <span class="child-age">Född <?= $child['birth_year'] ?></span>
                    <?php endif; ?>
                </div>
                <div class="child-actions">
                    <a href="/v3/profile/edit-child/<?= $child['id'] ?>" class="btn btn-sm btn-outline">Redigera</a>
                    <button type="button" class="btn btn-sm btn-danger-outline"
                            onclick="confirmRemove(<?= $child['id'] ?>, '<?= htmlspecialchars($child['first_name']) ?>')">
                        Ta bort
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add Child Section -->
<div class="add-section card">
    <h2>Lägg till barn</h2>
    <p>Sök efter ditt barn i databasen eller skapa en ny profil.</p>

    <div class="add-options">
        <div class="add-option">
            <h3>Sök befintlig åkare</h3>
            <?php
            $searchType = 'riders';
            $placeholder = 'Sök barnets namn...';
            $onSelect = 'handleChildSelect';
            include HUB_V3_ROOT . '/components/search-live.php';
            ?>
        </div>

        <div class="add-divider">eller</div>

        <div class="add-option">
            <h3>Skapa ny profil</h3>
            <a href="/v3/profile/add-child" class="btn btn-outline">+ Skapa ny barnprofil</a>
        </div>
    </div>
</div>

<style>
.children-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    margin-bottom: var(--space-xl);
}
.child-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
}
.child-avatar {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-full);
    font-size: var(--text-lg);
    font-weight: var(--weight-bold);
}
.child-info {
    flex: 1;
}
.child-name {
    display: block;
    font-weight: var(--weight-medium);
    color: inherit;
    text-decoration: none;
}
.child-name:hover {
    color: var(--color-accent);
}
.child-age {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.child-actions {
    display: flex;
    gap: var(--space-xs);
}
.btn-sm {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-sm);
}
.btn-danger-outline {
    border-color: var(--color-error);
    color: var(--color-error);
}

.card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
}
.card h2 {
    font-size: var(--text-lg);
    margin-bottom: var(--space-sm);
}
.card p {
    color: var(--color-text-secondary);
    margin-bottom: var(--space-lg);
}

.add-options {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: var(--space-lg);
    align-items: start;
}
.add-option h3 {
    font-size: var(--text-md);
    margin-bottom: var(--space-md);
}
.add-divider {
    color: var(--color-text-secondary);
    padding-top: var(--space-xl);
}

.empty-state {
    text-align: center;
    padding: var(--space-2xl);
}
.empty-icon {
    font-size: 3rem;
    margin-bottom: var(--space-md);
}

.alert {
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}
.alert-success {
    background: var(--color-success-bg, rgba(34, 197, 94, 0.1));
    color: var(--color-success, #22c55e);
}

@media (max-width: 768px) {
    .add-options {
        grid-template-columns: 1fr;
    }
    .add-divider {
        text-align: center;
        padding: var(--space-md) 0;
    }
}
</style>

<script>
function handleChildSelect(data) {
    if (confirm(`Vill du koppla ${data.name} till din profil?`)) {
        window.location.href = `/v3/api/profile.php?action=link_child&child_id=${data.id}`;
    }
}

function confirmRemove(childId, name) {
    if (confirm(`Vill du ta bort kopplingen till ${name}? Barnets profil tas inte bort.`)) {
        window.location.href = `/v3/api/profile.php?action=unlink_child&child_id=${childId}`;
    }
}
</script>
<script src="<?= hub_asset('js/search.js') ?>"></script>
