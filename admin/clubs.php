<?php
/**
 * Admin Clubs - V3 Design System
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Initialize message variables
$message = '';
$messageType = 'info';

// Nationality code to country name mapping
$nationalityToCountry = [
    'SWE' => 'Sverige',
    'NOR' => 'Norge',
    'DNK' => 'Danmark',
    'FIN' => 'Finland',
    'DEU' => 'Tyskland',
    'FRA' => 'Frankrike',
    'CHE' => 'Schweiz',
    'AUT' => 'Österrike',
    'ITA' => 'Italien',
    'ESP' => 'Spanien',
    'GBR' => 'Storbritannien',
    'USA' => 'USA',
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    // Sync all club nationalities based on rider majority
    if ($action === 'sync_nationalities') {
        $updatedCount = 0;

        // Get all clubs with riders
        $allClubs = $db->getAll("
            SELECT cl.id, cl.name, cl.country,
                   COUNT(r.id) as rider_count
            FROM clubs cl
            LEFT JOIN riders r ON cl.id = r.club_id
            GROUP BY cl.id
            HAVING rider_count > 0
        ");

        foreach ($allClubs as $clubRow) {
            $clubId = $clubRow['id'];
            $riderCount = $clubRow['rider_count'];

            // Get majority nationality for this club
            $natResult = $db->getRow("
                SELECT nationality, COUNT(*) as cnt
                FROM riders
                WHERE club_id = ? AND nationality IS NOT NULL AND nationality != ''
                GROUP BY nationality
                ORDER BY cnt DESC
                LIMIT 1
            ", [$clubId]);

            if ($natResult && $natResult['nationality'] !== 'SWE') {
                $majorityNat = $natResult['nationality'];
                $majorityCount = $natResult['cnt'];

                // Check if majority (>50%) have this nationality
                if (($majorityCount / $riderCount) > 0.5) {
                    $majorityCountry = $nationalityToCountry[$majorityNat] ?? null;

                    if ($majorityCountry && $clubRow['country'] !== $majorityCountry) {
                        $db->update('clubs', ['country' => $majorityCountry], 'id = ?', [$clubId]);
                        $updatedCount++;
                    }
                }
            }
        }

        $message = $updatedCount > 0
            ? "Uppdaterade land för $updatedCount klubbar baserat på medlemmarnas nationalitet."
            : "Inga klubbar behövde uppdateras.";
        $messageType = 'success';
    }

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            $message = 'Klubbnamn är obligatoriskt';
            $messageType = 'error';
        } else {
            $clubData = [
                'name' => $name,
                'short_name' => trim($_POST['short_name'] ?? ''),
                'region' => trim($_POST['region'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
                'country' => trim($_POST['country'] ?? 'Sverige'),
                'website' => trim($_POST['website'] ?? ''),
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            try {
                if ($action === 'create') {
                    $db->insert('clubs', $clubData);
                    $message = 'Klubb skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('clubs', $clubData, 'id = ?', [$id]);
                    $message = 'Klubb uppdaterad!';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        try {
            $db->delete('clubs', 'id = ?', [$id]);
            $message = 'Klubb borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'merge') {
        $keepId = intval($_POST['keep_id'] ?? 0);
        $mergeIds = $_POST['merge_ids'] ?? [];

        // Support both old format (single merge_id) and new format (array of merge_ids)
        if (empty($mergeIds) && !empty($_POST['merge_id'])) {
            $mergeIds = [intval($_POST['merge_id'])];
        }

        // Ensure mergeIds is array of integers
        $mergeIds = array_map('intval', (array)$mergeIds);
        $mergeIds = array_filter($mergeIds, fn($id) => $id > 0 && $id !== $keepId);

        if ($keepId && !empty($mergeIds)) {
            try {
                // Get club names for message
                $keepClub = $db->getRow("SELECT name FROM clubs WHERE id = ?", [$keepId]);
                if (!$keepClub) {
                    throw new Exception('Målklubben hittades inte');
                }

                $mergedNames = [];
                foreach ($mergeIds as $mergeId) {
                    $mergeClub = $db->getRow("SELECT name FROM clubs WHERE id = ?", [$mergeId]);
                    if ($mergeClub) {
                        $mergedNames[] = $mergeClub['name'];
                    }
                }

                if (empty($mergedNames)) {
                    throw new Exception('Inga klubbar att slå samman hittades');
                }

                $pdo = $db->getPdo();
                $pdo->beginTransaction();

                foreach ($mergeIds as $mergeId) {
                    // Move riders to keep club
                    $stmt = $pdo->prepare("UPDATE riders SET club_id = ? WHERE club_id = ?");
                    $stmt->execute([$keepId, $mergeId]);

                    // Move results to keep club (if column exists)
                    try {
                        $stmt = $pdo->prepare("UPDATE results SET club_id = ? WHERE club_id = ?");
                        $stmt->execute([$keepId, $mergeId]);
                    } catch (Exception $e) {
                        // Column might not exist, skip
                    }

                    // Move rider_club_seasons to keep club (if table exists)
                    try {
                        $stmt = $pdo->prepare("UPDATE rider_club_seasons SET club_id = ? WHERE club_id = ?");
                        $stmt->execute([$keepId, $mergeId]);
                    } catch (Exception $e) {
                        // Table might not exist, skip
                    }

                    // Delete the merged club
                    $pdo->prepare("DELETE FROM clubs WHERE id = ?")->execute([$mergeId]);
                }

                $pdo->commit();

                $count = count($mergedNames);
                if ($count === 1) {
                    $message = "Klubben \"{$mergedNames[0]}\" har slagits samman med \"{$keepClub['name']}\"";
                } else {
                    $message = "{$count} klubbar har slagits samman med \"{$keepClub['name']}\": " . implode(', ', $mergedNames);
                }
                $messageType = 'success';
            } catch (Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'Fel vid sammanslagning: ' . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = 'Välj minst två klubbar att slå samman';
            $messageType = 'error';
        }
    }
}

// Handle search and filters
$search = $_GET['search'] ?? '';
$countryFilter = $_GET['country'] ?? '';

// Handle URL messages
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'deleted':
            $message = 'Klubb borttagen!';
            $messageType = 'success';
            break;
    }
}

$where = [];
$params = [];

if ($search) {
    $where[] = "cl.name LIKE ?";
    $params[] = "%$search%";
}

if ($countryFilter) {
    $where[] = "cl.country = ?";
    $params[] = $countryFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get list of countries for filter dropdown
$countries = $db->getAll("SELECT DISTINCT country FROM clubs WHERE country IS NOT NULL AND country != '' ORDER BY country");

// Get clubs with rider count
$sql = "SELECT
    cl.id,
    cl.name,
    cl.short_name,
    cl.city,
    cl.country,
    cl.logo,
    cl.active,
    COUNT(DISTINCT c.id) as rider_count
FROM clubs cl
LEFT JOIN riders c ON cl.id = c.club_id AND c.active = 1
$whereClause
GROUP BY cl.id
ORDER BY cl.name";

$clubs = $db->getAll($sql, $params);

// Calculate stats
$totalClubs = count($clubs);
$activeCount = 0;
$totalMembers = 0;
foreach ($clubs as $c) {
    if ($c['active'] == 1) $activeCount++;
    $totalMembers += $c['rider_count'];
}

// Page config
$page_title = 'Klubbar';
$breadcrumbs = [
    ['label' => 'Klubbar']
];
$page_actions = '
<form method="POST" style="display: inline-block; margin-right: var(--space-sm);">
    ' . csrf_field() . '
    <input type="hidden" name="action" value="sync_nationalities">
    <button type="submit" class="btn btn--secondary" title="Uppdatera klubbars land baserat på medlemmarnas nationalitet">
        <i data-lucide="refresh-cw"></i>
        Synka land
    </button>
</form>
<a href="/admin/club-edit.php" class="btn btn--primary">
    <i data-lucide="plus"></i>
    Ny Klubb
</a>';

// Include unified layout (uses same layout as public site)
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <?php if ($messageType === 'success'): ?>
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            <?php elseif ($messageType === 'error'): ?>
                <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>
            <?php else: ?>
                <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/>
            <?php endif; ?>
        </svg>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-info-light); color: var(--color-info);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $totalClubs ?></div>
            <div class="admin-stat-label">Totalt klubbar</div>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-success-light); color: var(--color-success);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $activeCount ?></div>
            <div class="admin-stat-label">Aktiva</div>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-accent-light); color: var(--color-accent);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($totalMembers, 0, ',', ' ') ?></div>
            <div class="admin-stat-label">Totalt medlemmar</div>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<div class="admin-card">
    <div class="admin-card-body">
        <form method="GET" id="searchForm" class="admin-form-row" style="align-items: flex-end;">
            <div class="admin-form-group" style="flex: 1; margin-bottom: 0;">
                <label for="searchInput" class="admin-form-label">Sök</label>
                <input
                    type="text"
                    name="search"
                    id="searchInput"
                    class="admin-form-input"
                    placeholder="Sök efter klubbnamn..."
                    value="<?= htmlspecialchars($search) ?>"
                    autocomplete="off"
                >
            </div>
            <div class="admin-form-group" style="width: 180px; margin-bottom: 0;">
                <label for="countryFilter" class="admin-form-label">Land</label>
                <select name="country" id="countryFilter" class="admin-form-select" onchange="this.form.submit()">
                    <option value="">Alla länder</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?= htmlspecialchars($c['country']) ?>" <?= $countryFilter === $c['country'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['country']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($search || $countryFilter): ?>
                <a href="/admin/clubs" class="btn-admin btn-admin-sm btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    Rensa
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Clubs Table -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><?= count($clubs) ?> klubbar</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <?php if (empty($clubs)): ?>
            <div class="admin-empty-state">
                <i data-lucide="building-2" style="width: 48px; height: 48px; opacity: 0.5;"></i>
                <h3>Inga klubbar hittades</h3>
                <p>Prova att ändra sökning eller skapa en ny klubb.</p>
                <a href="/admin/club-edit.php" class="btn btn--primary">Skapa klubb</a>
            </div>
        <?php else: ?>
            <!-- Merge toolbar (hidden until 2+ selected) -->
            <div id="mergeToolbar" style="display: none; padding: var(--space-md); background: var(--color-accent-light); border-bottom: 1px solid var(--color-border);">
                <form method="POST" id="mergeForm" style="display: flex; align-items: center; gap: var(--space-md); flex-wrap: wrap;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="merge">
                    <input type="hidden" name="keep_id" id="keepId">
                    <div id="mergeIdsContainer"></div>
                    <span><strong id="selectedCount">0</strong> klubbar valda</span>
                    <div style="flex: 1; display: flex; align-items: center; gap: var(--space-sm);">
                        <label style="white-space: nowrap;">Behåll:</label>
                        <select id="keepSelect" class="form-select form-select-sm" style="min-width: 200px;"></select>
                    </div>
                    <span id="mergePreview" style="color: var(--color-text-secondary); font-size: 0.9em;"></span>
                    <button type="submit" class="btn btn--primary btn-sm">
                        <i data-lucide="git-merge"></i> Slå samman
                    </button>
                    <button type="button" class="btn btn--secondary btn-sm" onclick="clearSelection()">Avbryt</button>
                </form>
            </div>

            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAllClubs" title="Markera alla"></th>
                            <th style="width: 50px;"></th>
                            <th>Namn</th>
                            <th>Förkortning</th>
                            <th>Stad</th>
                            <th>Land</th>
                            <th>Medlemmar</th>
                            <th>Status</th>
                            <th style="width: 120px;">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clubs as $club): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="club-checkbox"
                                           data-id="<?= $club['id'] ?>"
                                           data-name="<?= htmlspecialchars($club['name']) ?>"
                                           data-members="<?= $club['rider_count'] ?>">
                                </td>
                                <td>
                                    <?php if (!empty($club['logo'])): ?>
                                        <img src="<?= htmlspecialchars($club['logo']) ?>" alt="<?= htmlspecialchars($club['name']) ?>"
                                             style="width: 36px; height: 36px; object-fit: contain; border-radius: var(--radius-sm); background: var(--color-bg-secondary);">
                                    <?php else: ?>
                                        <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: var(--color-bg-secondary); display: flex; align-items: center; justify-content: center;">
                                            <i data-lucide="building-2" style="width: 18px; height: 18px; opacity: 0.4;"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/admin/club-edit.php?id=<?= $club['id'] ?>" style="color: var(--color-accent); text-decoration: none; font-weight: 500;">
                                        <?= htmlspecialchars($club['name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="admin-badge admin-badge-info">
                                        <?= htmlspecialchars($club['short_name'] ?? substr($club['name'], 0, 3)) ?>
                                    </span>
                                </td>
                                <td class="text-secondary"><?= htmlspecialchars($club['city'] ?? '-') ?></td>
                                <td class="text-secondary"><?= htmlspecialchars($club['country'] ?? 'Sverige') ?></td>
                                <td>
                                    <a href="/admin/riders?club_id=<?= $club['id'] ?>" class="btn-admin btn-admin-sm btn-admin-secondary">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                        <?= $club['rider_count'] ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="admin-badge <?= $club['active'] ? 'admin-badge-success' : 'admin-badge-secondary' ?>">
                                        <?= $club['active'] ? 'Aktiv' : 'Inaktiv' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="/admin/club-edit.php?id=<?= $club['id'] ?>" class="btn btn--sm btn--secondary" title="Redigera">
                                            <i data-lucide="pencil"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Live search with debouncing
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('searchForm');
    if (!searchInput || !searchForm) return;

    let debounceTimer;
    let lastSearch = searchInput.value;

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(debounceTimer);

        if (query === lastSearch) return;

        debounceTimer = setTimeout(function() {
            if (query.length >= 2 || query.length === 0) {
                lastSearch = query;
                searchForm.submit();
            }
        }, 300);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            clearTimeout(debounceTimer);
            searchForm.submit();
        }
    });
});

// Club merge functionality
let selectedClubs = [];

document.querySelectorAll('.club-checkbox').forEach(cb => {
    cb.addEventListener('change', updateMergeToolbar);
});

document.getElementById('selectAllClubs')?.addEventListener('change', function() {
    document.querySelectorAll('.club-checkbox').forEach(cb => {
        cb.checked = this.checked;
    });
    updateMergeToolbar();
});

function updateMergeToolbar() {
    selectedClubs = [];
    document.querySelectorAll('.club-checkbox:checked').forEach(cb => {
        selectedClubs.push({
            id: cb.dataset.id,
            name: cb.dataset.name,
            members: parseInt(cb.dataset.members) || 0
        });
    });

    // Sort by members (most members first) for default keep selection
    selectedClubs.sort((a, b) => b.members - a.members);

    const toolbar = document.getElementById('mergeToolbar');
    const countEl = document.getElementById('selectedCount');
    const previewEl = document.getElementById('mergePreview');
    const keepSelect = document.getElementById('keepSelect');
    const mergeIdsContainer = document.getElementById('mergeIdsContainer');

    countEl.textContent = selectedClubs.length;

    if (selectedClubs.length >= 2) {
        toolbar.style.display = 'block';

        // Build keep dropdown
        keepSelect.innerHTML = selectedClubs.map(c =>
            `<option value="${c.id}">${c.name} (${c.members} medl.)</option>`
        ).join('');

        // Set default keep (most members)
        keepSelect.value = selectedClubs[0].id;
        document.getElementById('keepId').value = selectedClubs[0].id;

        updateMergePreview();
    } else {
        toolbar.style.display = 'none';
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function updateMergePreview() {
    const keepId = document.getElementById('keepSelect').value;
    const keepClub = selectedClubs.find(c => c.id === keepId);
    const mergeClubs = selectedClubs.filter(c => c.id !== keepId);

    document.getElementById('keepId').value = keepId;

    // Update hidden merge_ids inputs
    const container = document.getElementById('mergeIdsContainer');
    container.innerHTML = mergeClubs.map(c =>
        `<input type="hidden" name="merge_ids[]" value="${c.id}">`
    ).join('');

    // Update preview text
    const previewEl = document.getElementById('mergePreview');
    if (mergeClubs.length === 1) {
        previewEl.innerHTML = `"${mergeClubs[0].name}" → "${keepClub.name}"`;
    } else {
        previewEl.innerHTML = `${mergeClubs.length} klubbar → "${keepClub.name}"`;
    }
}

// Listen for keep selection changes
document.getElementById('keepSelect')?.addEventListener('change', updateMergePreview);

function clearSelection() {
    document.querySelectorAll('.club-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAllClubs').checked = false;
    selectedClubs = [];
    document.getElementById('mergeToolbar').style.display = 'none';
}

document.getElementById('mergeForm')?.addEventListener('submit', function(e) {
    if (selectedClubs.length < 2) {
        e.preventDefault();
        alert('Välj minst 2 klubbar för att slå samman.');
        return;
    }

    const keepId = document.getElementById('keepSelect').value;
    const keepClub = selectedClubs.find(c => c.id === keepId);
    const mergeClubs = selectedClubs.filter(c => c.id !== keepId);
    const mergeNames = mergeClubs.map(c => c.name).join(', ');

    let msg;
    if (mergeClubs.length === 1) {
        msg = `Slå samman "${mergeClubs[0].name}" med "${keepClub.name}"?\n\nAlla medlemmar och resultat flyttas till "${keepClub.name}".`;
    } else {
        msg = `Slå samman ${mergeClubs.length} klubbar med "${keepClub.name}"?\n\nKlubbar som tas bort:\n${mergeNames}\n\nAlla medlemmar och resultat flyttas till "${keepClub.name}".`;
    }

    if (!confirm(msg)) {
        e.preventDefault();
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
