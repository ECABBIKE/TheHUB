<?php
/**
 * Email Profile Groups Tool
 *
 * Shows all riders sharing the same email address.
 * These are automatically grouped as shared accounts.
 *
 * @package TheHUB Admin Tools
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$db = getDB();

// Preview mode - show what "Mina profiler" would look like
$previewEmail = trim($_GET['preview'] ?? '');
if ($previewEmail) {
    $previewProfiles = $db->getAll(
        "SELECT r.*, c.name as club_name,
                (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count,
                (SELECT COUNT(*) FROM event_registrations WHERE rider_id = r.id AND status != 'cancelled') as registration_count
         FROM riders r
         LEFT JOIN clubs c ON r.club_id = c.id
         WHERE r.email = ? AND r.active = 1
         ORDER BY r.birth_year DESC",
        [$previewEmail]
    );

    $page_title = 'Forhandsgranska: Mina profiler';
    $breadcrumbs = [
        ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
        ['label' => 'E-post profilgrupper', 'url' => '/admin/tools/email-profiles.php'],
        ['label' => 'Forhandsgranska']
    ];

    include __DIR__ . '/../components/unified-layout.php';
    ?>

    <div class="page-header mb-lg">
        <a href="?" class="btn btn-secondary mb-md">
            <i data-lucide="arrow-left"></i> Tillbaka
        </a>
        <h1><i data-lucide="eye"></i> Forhandsgranska: Mina profiler</h1>
        <p class="text-secondary">Sa har ser det ut for <?= htmlspecialchars($previewEmail) ?></p>
    </div>

    <div class="preview-container">
        <div class="preview-frame">
            <div class="preview-header">
                <span class="preview-label">Anvandarvy - "Mina profiler"</span>
            </div>
            <div class="preview-content">
                <?php if (empty($previewProfiles)): ?>
                    <p class="text-muted">Inga profiler hittades for denna e-post.</p>
                <?php else: ?>
                    <div class="info-box mb-lg">
                        <i data-lucide="info"></i>
                        <div>
                            <strong>Du har <?= count($previewProfiles) ?> profiler</strong><br>
                            Klicka pa "Byt till denna" for att hantera en annan profil.
                        </div>
                    </div>

                    <div class="profiles-list">
                        <?php foreach ($previewProfiles as $i => $profile): ?>
                            <?php $isFirst = ($i === 0); ?>
                            <div class="profile-card <?= $isFirst ? 'profile-card--active' : '' ?>">
                                <div class="profile-card-header">
                                    <div class="profile-avatar">
                                        <?= strtoupper(substr($profile['firstname'], 0, 1) . substr($profile['lastname'], 0, 1)) ?>
                                    </div>
                                    <div class="profile-main-info">
                                        <h3 class="profile-name">
                                            <?= htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']) ?>
                                            <?php if ($isFirst): ?>
                                                <span class="badge badge-success">Aktiv</span>
                                            <?php endif; ?>
                                        </h3>
                                        <?php if ($profile['birth_year']): ?>
                                            <span class="profile-meta">
                                                <i data-lucide="calendar" class="icon-xs"></i>
                                                Fodd <?= $profile['birth_year'] ?> (<?= date('Y') - $profile['birth_year'] ?> ar)
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($profile['club_name']): ?>
                                            <span class="profile-meta">
                                                <i data-lucide="shield" class="icon-xs"></i>
                                                <?= htmlspecialchars($profile['club_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="profile-card-stats">
                                    <div class="stat">
                                        <span class="stat-value"><?= $profile['result_count'] ?></span>
                                        <span class="stat-label">Resultat</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-value"><?= $profile['registration_count'] ?></span>
                                        <span class="stat-label">Anmalningar</span>
                                    </div>
                                </div>
                                <div class="profile-card-actions">
                                    <?php if ($isFirst): ?>
                                        <span class="btn btn-primary disabled">
                                            <i data-lucide="pencil"></i> Redigera profil
                                        </span>
                                    <?php else: ?>
                                        <span class="btn btn-primary disabled">
                                            <i data-lucide="repeat"></i> Byt till denna
                                        </span>
                                    <?php endif; ?>
                                    <span class="btn btn-outline disabled">
                                        <i data-lucide="flag"></i> Visa resultat
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
    .preview-container {
        max-width: 600px;
    }
    .preview-frame {
        border: 2px solid var(--color-accent);
        border-radius: var(--radius-lg);
        overflow: hidden;
        background: var(--color-bg-page);
    }
    .preview-header {
        background: var(--color-accent);
        color: #000;
        padding: var(--space-sm) var(--space-md);
        font-weight: 600;
        font-size: 0.85rem;
    }
    .preview-content {
        padding: var(--space-lg);
    }
    .profiles-list {
        display: flex;
        flex-direction: column;
        gap: var(--space-md);
    }
    .profile-card {
        background: var(--color-bg-card);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-lg);
        padding: var(--space-lg);
    }
    .profile-card--active {
        border-color: var(--color-accent);
        box-shadow: 0 0 0 1px var(--color-accent);
    }
    .profile-card-header {
        display: flex;
        align-items: flex-start;
        gap: var(--space-md);
        margin-bottom: var(--space-md);
    }
    .profile-avatar {
        width: 48px;
        height: 48px;
        border-radius: var(--radius-full);
        background: var(--color-accent-light);
        color: var(--color-accent);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        flex-shrink: 0;
    }
    .profile-name {
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0 0 var(--space-xs) 0;
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        flex-wrap: wrap;
    }
    .profile-meta {
        display: inline-flex;
        align-items: center;
        gap: var(--space-2xs);
        color: var(--color-text-secondary);
        font-size: 0.85rem;
        margin-right: var(--space-md);
    }
    .profile-card-stats {
        display: flex;
        gap: var(--space-xl);
        padding: var(--space-sm) 0;
        border-top: 1px solid var(--color-border);
        border-bottom: 1px solid var(--color-border);
        margin-bottom: var(--space-md);
    }
    .stat { text-align: center; }
    .stat-value { display: block; font-weight: 600; }
    .stat-label { font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; }
    .profile-card-actions {
        display: flex;
        gap: var(--space-sm);
        flex-wrap: wrap;
    }
    .btn.disabled {
        opacity: 0.7;
        cursor: default;
        pointer-events: none;
    }
    .info-box {
        display: flex;
        align-items: flex-start;
        gap: var(--space-md);
        padding: var(--space-md);
        background: var(--color-accent-light);
        border-radius: var(--radius-md);
        color: var(--color-accent-text);
    }
    </style>
    <?php
    exit;
}

// Get filter
$minProfiles = (int)($_GET['min'] ?? 2);
$searchEmail = trim($_GET['search'] ?? '');

// Find all emails with multiple riders
$query = "
    SELECT
        r.email,
        COUNT(*) as profile_count,
        GROUP_CONCAT(r.id ORDER BY r.birth_year DESC) as rider_ids,
        MAX(CASE WHEN r.password IS NOT NULL AND r.password != '' THEN 1 ELSE 0 END) as has_login
    FROM riders r
    WHERE r.email IS NOT NULL
      AND r.email != ''
      AND r.active = 1
";

$params = [];

if ($searchEmail) {
    $query .= " AND r.email LIKE ?";
    $params[] = "%{$searchEmail}%";
}

$query .= "
    GROUP BY r.email
    HAVING COUNT(*) >= ?
    ORDER BY profile_count DESC, r.email
    LIMIT 500
";
$params[] = $minProfiles;

$emailGroups = $db->getAll($query, $params);

// Get statistics
$stats = $db->getRow("
    SELECT
        COUNT(DISTINCT email) as unique_emails,
        (SELECT COUNT(*) FROM (
            SELECT email FROM riders
            WHERE email IS NOT NULL AND email != '' AND active = 1
            GROUP BY email HAVING COUNT(*) >= 2
        ) sub) as emails_with_multiple,
        (SELECT SUM(cnt) FROM (
            SELECT COUNT(*) as cnt FROM riders
            WHERE email IS NOT NULL AND email != '' AND active = 1
            GROUP BY email HAVING COUNT(*) >= 2
        ) sub2) as riders_in_groups
    FROM riders
    WHERE email IS NOT NULL AND email != '' AND active = 1
");

$page_title = 'E-post profilgrupper';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'E-post profilgrupper']
];

include __DIR__ . '/../components/unified-layout.php';
?>

<div class="page-header mb-lg">
    <h1><i data-lucide="users"></i> E-post profilgrupper</h1>
    <p class="text-secondary">Deltagare som delar e-postadress grupperas automatiskt som familj/foraldrakonton</p>
</div>

<div class="alert alert-info mb-lg">
    <i data-lucide="info"></i>
    <div>
        <strong>Automatisk kontogruppering</strong><br>
        Nar flera deltagare har samma e-postadress kan de logga in med ett losenord och hantera alla profiler under "Mina profiler".
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid mb-xl">
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['unique_emails']) ?></div>
        <div class="stat-label">Unika e-postadresser</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['emails_with_multiple']) ?></div>
        <div class="stat-label">Delade konton (familjer)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['riders_in_groups'] ?? 0) ?></div>
        <div class="stat-label">Deltagare i grupper</div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-lg">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Sok e-post</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchEmail) ?>"
                           placeholder="namn@example.com" class="form-input">
                </div>
                <div class="filter-group">
                    <label>Min antal profiler</label>
                    <select name="min" class="form-select">
                        <option value="2" <?= $minProfiles == 2 ? 'selected' : '' ?>>2+</option>
                        <option value="3" <?= $minProfiles == 3 ? 'selected' : '' ?>>3+</option>
                        <option value="4" <?= $minProfiles == 4 ? 'selected' : '' ?>>4+</option>
                        <option value="5" <?= $minProfiles == 5 ? 'selected' : '' ?>>5+</option>
                    </select>
                </div>
                <div class="filter-group filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="search"></i> Filtrera
                    </button>
                    <a href="?" class="btn btn-secondary">Rensa</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header">
        <h3>Delade konton (<?= count($emailGroups) ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($emailGroups)): ?>
            <p class="text-secondary">Inga e-postadresser med <?= $minProfiles ?>+ profiler hittades.</p>
        <?php else: ?>
            <div class="email-groups">
                <?php foreach ($emailGroups as $group): ?>
                    <?php
                    // Get full rider details for this group
                    $riderIds = explode(',', $group['rider_ids']);
                    $riders = $db->getAll(
                        "SELECT r.*, c.name as club_name,
                                (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count
                         FROM riders r
                         LEFT JOIN clubs c ON r.club_id = c.id
                         WHERE r.id IN (" . implode(',', array_fill(0, count($riderIds), '?')) . ")
                         ORDER BY r.birth_year DESC",
                        $riderIds
                    );
                    ?>
                    <div class="email-group <?= $group['has_login'] ? 'has-login' : 'no-login' ?>">
                        <div class="email-group-header">
                            <div class="email-info">
                                <span class="email-address"><?= htmlspecialchars($group['email']) ?></span>
                                <span class="profile-count"><?= $group['profile_count'] ?> profiler</span>
                                <?php if ($group['has_login']): ?>
                                    <span class="badge badge-success"><i data-lucide="check" class="icon-xs"></i> Kan logga in</span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><i data-lucide="alert-circle" class="icon-xs"></i> Inget losenord</span>
                                <?php endif; ?>
                                <a href="?preview=<?= urlencode($group['email']) ?>" class="btn btn-sm btn-secondary ml-auto">
                                    <i data-lucide="eye"></i> Forhandsgranska
                                </a>
                            </div>
                        </div>
                        <div class="email-group-riders">
                            <table class="table table-compact">
                                <thead>
                                    <tr>
                                        <th>Namn</th>
                                        <th>Fodelsear</th>
                                        <th>Klubb</th>
                                        <th>Resultat</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($riders as $rider): ?>
                                        <tr>
                                            <td>
                                                <a href="/admin/rider-edit.php?id=<?= $rider['id'] ?>">
                                                    <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?>
                                                </a>
                                                <?php if ($rider['gender']): ?>
                                                    <span class="text-muted text-xs">(<?= $rider['gender'] ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $rider['birth_year'] ?: '-' ?>
                                                <?php if ($rider['birth_year']): ?>
                                                    <span class="text-muted text-xs">(<?= date('Y') - $rider['birth_year'] ?> ar)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($rider['club_name'] ?? '-') ?></td>
                                            <td><?= $rider['result_count'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-md);
}

.stat-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-accent);
}

.stat-label {
    color: var(--color-text-secondary);
    font-size: 0.85rem;
    margin-top: var(--space-xs);
}

.filter-form .filter-row {
    display: flex;
    gap: var(--space-md);
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.filter-group label {
    font-size: 0.85rem;
    color: var(--color-text-secondary);
}

.filter-actions {
    flex-direction: row;
    gap: var(--space-sm);
}

.email-groups {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.email-group {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.email-group.has-login {
    border-left: 3px solid var(--color-success);
}

.email-group.no-login {
    border-left: 3px solid var(--color-warning);
}

.email-group-header {
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border-bottom: 1px solid var(--color-border);
}

.email-info {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex-wrap: wrap;
}

.email-address {
    font-weight: 600;
}

.profile-count {
    color: var(--color-text-secondary);
    font-size: 0.9rem;
}

.email-group-riders {
    padding: var(--space-sm);
}

.email-group-riders .table {
    margin: 0;
}

.table-compact td, .table-compact th {
    padding: var(--space-xs) var(--space-sm);
}

.ml-auto {
    margin-left: auto;
}

@media (max-width: 768px) {
    .email-group-riders {
        overflow-x: auto;
    }

    .filter-form .filter-row {
        flex-direction: column;
    }

    .filter-group {
        width: 100%;
    }
}
</style>
