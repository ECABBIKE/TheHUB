<?php
/**
 * Event Class Manager - Fix wrong classes per event
 * One-click to reassign all results from one class to another
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    checkCsrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'change_class') {
            $eventId = (int)$_POST['event_id'];
            $fromClassId = (int)$_POST['from_class_id'];
            $toClassId = (int)$_POST['to_class_id'];

            // Count before update
            $beforeCount = $db->getRow(
                "SELECT COUNT(*) as cnt FROM results WHERE event_id = ? AND class_id = ?",
                [$eventId, $fromClassId]
            );

            // Do the update using raw query
            $stmt = $db->query(
                "UPDATE results SET class_id = ? WHERE event_id = ? AND class_id = ?",
                [$toClassId, $eventId, $fromClassId]
            );

            $count = $stmt ? $stmt->rowCount() : $beforeCount['cnt'];
            echo json_encode(['success' => true, 'count' => $count]);
            exit;
        }

        if ($action === 'delete_class') {
            $classId = (int)$_POST['class_id'];

            // Check if used
            $count = $db->getRow("SELECT COUNT(*) as cnt FROM results WHERE class_id = ?", [$classId]);
            if ($count['cnt'] > 0) {
                echo json_encode(['success' => false, 'error' => 'Klassen har fortfarande resultat']);
                exit;
            }

            $db->delete('classes', 'id = ?', [$classId]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'global_replace') {
            $fromClassId = (int)$_POST['from_class_id'];
            $toClassId = (int)$_POST['to_class_id'];

            // Count before update
            $beforeCount = $db->getRow(
                "SELECT COUNT(*) as cnt FROM results WHERE class_id = ?",
                [$fromClassId]
            );

            $stmt = $db->query(
                "UPDATE results SET class_id = ? WHERE class_id = ?",
                [$toClassId, $fromClassId]
            );

            $count = $stmt ? $stmt->rowCount() : $beforeCount['cnt'];

            // Delete the now-empty class
            $db->delete('classes', 'id = ?', [$fromClassId]);

            echo json_encode(['success' => true, 'count' => $count]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get all events with their classes
$eventsWithClasses = $db->getAll("
    SELECT
        e.id as event_id,
        e.name as event_name,
        e.date as event_date,
        c.id as class_id,
        c.display_name as class_name,
        c.name as class_code,
        COUNT(r.id) as result_count
    FROM events e
    JOIN results r ON e.id = r.event_id
    JOIN classes c ON r.class_id = c.id
    GROUP BY e.id, c.id
    ORDER BY e.date DESC, c.sort_order, c.display_name
");

// Group by event
$events = [];
foreach ($eventsWithClasses as $row) {
    $eid = $row['event_id'];
    if (!isset($events[$eid])) {
        $events[$eid] = [
            'id' => $eid,
            'name' => $row['event_name'],
            'date' => $row['event_date'],
            'classes' => []
        ];
    }
    $events[$eid]['classes'][] = [
        'id' => $row['class_id'],
        'name' => $row['class_name'],
        'code' => $row['class_code'],
        'count' => $row['result_count']
    ];
}

// Get ALL classes for dropdown
$allClasses = $db->getAll("
    SELECT c.id, c.display_name, c.gender, c.sort_order, COUNT(r.id) as total_results
    FROM classes c
    LEFT JOIN results r ON c.id = r.class_id
    WHERE c.active = 1
    GROUP BY c.id
    ORDER BY c.sort_order, c.display_name
");
$goodClasses = $allClasses; // Use all classes in dropdowns

// Get "bad" classes (likely auto-created, few results, high sort_order)
$badClasses = $db->getAll("
    SELECT c.id, c.display_name, c.name, COUNT(r.id) as total_results
    FROM classes c
    LEFT JOIN results r ON c.id = r.class_id
    WHERE c.sort_order >= 900 OR c.id > (SELECT MIN(id) + 50 FROM classes)
    GROUP BY c.id
    ORDER BY c.display_name
");

$page_title = 'Fixa Klasser per Event';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import.php'],
    ['label' => 'Fixa Klasser']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.event-card {
    margin-bottom: var(--space-md);
}
.event-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    padding: var(--space-sm);
    background: var(--color-surface);
    border-radius: var(--radius-sm);
}
.event-header:hover {
    background: var(--color-surface-hover);
}
.class-row {
    display: grid;
    grid-template-columns: 1fr 80px 250px 80px;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    border-bottom: 1px solid var(--color-border);
    align-items: center;
}
.class-row:hover {
    background: var(--color-surface-hover);
}
.class-name {
    font-weight: 500;
}
.class-name.suspicious {
    color: var(--color-warning);
}
.bad-class-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-sm);
}
.bad-class-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-sm);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-left: 3px solid var(--color-warning);
    border-radius: var(--radius-sm);
}
</style>

<!-- Bad Classes Overview -->
<?php if (!empty($badClasses)): ?>
<div class="card mb-lg">
    <div class="card-header">
        <h2 class="flex items-center gap-sm">
            <i data-lucide="alert-triangle" class="text-warning"></i>
            Misstänkt felaktiga klasser (<?= count($badClasses) ?> st)
        </h2>
    </div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-md">
            Dessa klasser skapades troligen automatiskt vid import. Byt ut dem mot rätt klass:
        </p>
        <div class="bad-class-list">
            <?php foreach ($badClasses as $bc): ?>
            <div class="bad-class-item" data-class-id="<?= $bc['id'] ?>">
                <div>
                    <strong><?= h($bc['display_name']) ?></strong>
                    <br><span class="text-sm text-secondary"><?= $bc['total_results'] ?> resultat</span>
                </div>
                <div class="flex gap-xs">
                    <select class="input input-sm global-replace-target" style="width:140px;">
                        <option value="">Byt till...</option>
                        <?php foreach ($goodClasses as $gc): ?>
                        <option value="<?= $gc['id'] ?>"><?= h($gc['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn--primary btn--sm global-replace-btn" data-from="<?= $bc['id'] ?>">
                        <i data-lucide="arrow-right"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Events with Classes -->
<div class="card">
    <div class="card-header">
        <h2>Klasser per Event (<?= count($events) ?> events)</h2>
    </div>
    <div class="card-body p-0">
        <?php foreach ($events as $event): ?>
        <details class="event-card">
            <summary class="event-header">
                <div>
                    <strong><?= h($event['name']) ?></strong>
                    <span class="text-secondary ml-sm"><?= date('Y-m-d', strtotime($event['date'])) ?></span>
                </div>
                <span class="badge badge--secondary"><?= count($event['classes']) ?> klasser</span>
            </summary>
            <div class="event-classes">
                <div class="class-row" style="font-weight:600; background:var(--color-surface);">
                    <div>Klass</div>
                    <div>Resultat</div>
                    <div>Byt till</div>
                    <div></div>
                </div>
                <?php foreach ($event['classes'] as $class): ?>
                <?php
                $isSuspicious = !in_array($class['id'], array_column($goodClasses, 'id'));
                ?>
                <div class="class-row" data-event-id="<?= $event['id'] ?>" data-class-id="<?= $class['id'] ?>">
                    <div class="class-name <?= $isSuspicious ? 'suspicious' : '' ?>">
                        <?= h($class['name']) ?>
                        <?php if ($isSuspicious): ?>
                        <i data-lucide="alert-circle" style="width:14px;height:14px;color:var(--color-warning);"></i>
                        <?php endif; ?>
                    </div>
                    <div><?= $class['count'] ?></div>
                    <div>
                        <select class="input input-sm replace-target">
                            <option value="">-- Behåll --</option>
                            <?php foreach ($goodClasses as $gc): ?>
                            <?php if ($gc['id'] != $class['id']): ?>
                            <option value="<?= $gc['id'] ?>"><?= h($gc['display_name']) ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="button" class="btn btn--primary btn--sm replace-btn">Byt</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
</div>

<script>
const csrfToken = '<?= csrf_token() ?>';

// Per-event class replacement
document.querySelectorAll('.replace-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const row = this.closest('.class-row');
        const eventId = row.dataset.eventId;
        const fromClassId = row.dataset.classId;
        const toClassId = row.querySelector('.replace-target').value;

        if (!toClassId) {
            alert('Välj en klass att byta till');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'change_class');
        formData.append('csrf_token', csrfToken);
        formData.append('event_id', eventId);
        formData.append('from_class_id', fromClassId);
        formData.append('to_class_id', toClassId);

        const response = await fetch(location.href, { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            row.style.background = 'rgba(97, 206, 112, 0.2)';
            row.querySelector('.class-name').innerHTML = '✓ Bytt (' + result.count + ' resultat)';
            setTimeout(() => row.remove(), 1500);
        } else {
            alert('Fel: ' + result.error);
        }
    });
});

// Global class replacement
document.querySelectorAll('.global-replace-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const item = this.closest('.bad-class-item');
        const fromClassId = this.dataset.from;
        const toClassId = item.querySelector('.global-replace-target').value;

        if (!toClassId) {
            alert('Välj en klass att byta till');
            return;
        }

        const className = item.querySelector('strong').textContent;
        if (!confirm(`Byt ALLA resultat från "${className}" till vald klass?\n\nKlassen raderas efteråt.`)) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'global_replace');
        formData.append('csrf_token', csrfToken);
        formData.append('from_class_id', fromClassId);
        formData.append('to_class_id', toClassId);

        const response = await fetch(location.href, { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            item.style.background = 'rgba(97, 206, 112, 0.2)';
            item.innerHTML = '<span>✓ Bytt och raderad (' + result.count + ' resultat)</span>';
            setTimeout(() => item.remove(), 1500);
        } else {
            alert('Fel: ' + result.error);
        }
    });
});

if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
