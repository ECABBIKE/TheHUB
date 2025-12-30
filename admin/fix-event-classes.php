<?php
/**
 * Event Class Manager - Fix wrong classes per event
 * Filter by event, see classes with counts, one-click to change
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Use verify_csrf_token() which returns bool instead of die()
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        echo json_encode(['success' => false, 'error' => 'CSRF-token ogiltigt. Ladda om sidan.']);
        exit;
    }

    $action = $_POST['action'];

    try {
        // Debug action to test if AJAX works
        if ($action === 'test') {
            echo json_encode(['success' => true, 'message' => 'AJAX works!']);
            exit;
        }

        if ($action === 'change_class') {
            $eventId = (int)$_POST['event_id'];
            $fromClassId = (int)$_POST['from_class_id'];
            $toClassId = (int)$_POST['to_class_id'];

            if ($fromClassId === $toClassId) {
                echo json_encode(['success' => false, 'error' => 'Samma klass vald']);
                exit;
            }

            // Count before update
            $beforeCount = $db->getRow(
                "SELECT COUNT(*) as cnt FROM results WHERE event_id = ? AND class_id = ?",
                [$eventId, $fromClassId]
            );

            // Do the update
            $db->update('results',
                ['class_id' => $toClassId],
                'event_id = ? AND class_id = ?',
                [$eventId, $fromClassId]
            );

            echo json_encode(['success' => true, 'count' => (int)$beforeCount['cnt']]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Okänd action: ' . $action]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get selected event
$selectedEventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

// Get all events for dropdown
$allEvents = $db->getAll("
    SELECT e.id, e.name, e.date, COUNT(r.id) as result_count
    FROM events e
    LEFT JOIN results r ON e.id = r.event_id
    GROUP BY e.id
    HAVING result_count > 0
    ORDER BY e.date DESC
");

// Get classes for selected event (or all if none selected)
if ($selectedEventId > 0) {
    $classesQuery = "
        SELECT
            c.id as class_id,
            c.display_name as class_name,
            c.name as class_code,
            c.sort_order,
            COUNT(r.id) as result_count,
            e.id as event_id,
            e.name as event_name,
            e.date as event_date
        FROM results r
        JOIN classes c ON r.class_id = c.id
        JOIN events e ON r.event_id = e.id
        WHERE e.id = ?
        GROUP BY c.id, e.id
        ORDER BY c.sort_order, c.display_name
    ";
    $eventClasses = $db->getAll($classesQuery, [$selectedEventId]);
    $selectedEvent = $db->getRow("SELECT id, name, date FROM events WHERE id = ?", [$selectedEventId]);
} else {
    // Show summary of all events with class counts
    $eventClasses = [];
    $selectedEvent = null;
}

// Get ALL classes for dropdown (to change to)
$allClasses = $db->getAll("
    SELECT id, display_name, sort_order
    FROM classes
    WHERE active = 1
    ORDER BY sort_order, display_name
");

$page_title = 'Fixa Klasser per Event';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import.php'],
    ['label' => 'Fixa Klasser']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.event-filter {
    display: flex;
    gap: var(--space-md);
    align-items: center;
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
}
.event-filter select {
    min-width: 300px;
}
.class-table {
    width: 100%;
}
.class-table th,
.class-table td {
    padding: var(--space-sm) var(--space-md);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}
.class-table th {
    background: var(--color-surface);
    font-weight: 600;
}
.class-table tr:hover {
    background: var(--color-surface-hover);
}
.class-table .count {
    font-weight: 600;
    min-width: 60px;
}
.class-table .actions {
    display: flex;
    gap: var(--space-sm);
    align-items: center;
}
.class-table select {
    min-width: 180px;
}
.class-name.suspicious {
    color: var(--color-warning);
    font-weight: 600;
}
.event-summary {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-md);
}
.event-summary-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: var(--space-md);
}
.event-summary-card:hover {
    border-color: var(--color-accent);
}
.event-summary-card h4 {
    margin: 0 0 var(--space-sm) 0;
}
.event-summary-card .meta {
    color: var(--color-text);
    font-size: 0.875rem;
}
.saved-row {
    background: rgba(97, 206, 112, 0.15) !important;
    transition: background 0.3s;
}
</style>

<div class="card">
    <div class="card-header">
        <h2>
            <i data-lucide="layers"></i>
            Fixa Klasser per Event
        </h2>
    </div>
    <div class="card-body">
        <!-- Event Filter -->
        <div class="event-filter">
            <label class="label" style="margin:0;">Välj Event:</label>
            <select id="eventSelect" class="input" onchange="filterByEvent(this.value)">
                <option value="0">-- Välj ett event --</option>
                <?php foreach ($allEvents as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $selectedEventId == $e['id'] ? 'selected' : '' ?>>
                    <?= h($e['name']) ?> (<?= date('Y-m-d', strtotime($e['date'])) ?>) - <?= $e['result_count'] ?> resultat
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($selectedEventId > 0): ?>
            <a href="?event_id=0" class="btn btn--secondary btn--sm">
                <i data-lucide="x"></i> Rensa filter
            </a>
            <?php endif; ?>
        </div>

        <?php if ($selectedEventId > 0 && $selectedEvent): ?>
        <!-- Selected Event Classes -->
        <div class="mb-md">
            <h3><?= h($selectedEvent['name']) ?></h3>
            <p class="text-secondary"><?= date('Y-m-d', strtotime($selectedEvent['date'])) ?></p>
        </div>

        <?php if (!empty($eventClasses)): ?>
        <table class="class-table">
            <thead>
                <tr>
                    <th>Klass</th>
                    <th>Resultat</th>
                    <th>Byt till</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventClasses as $class): ?>
                <?php
                // Mark as suspicious if sort_order >= 900 (auto-created)
                $isSuspicious = $class['sort_order'] >= 900;
                ?>
                <tr data-event-id="<?= $class['event_id'] ?>" data-class-id="<?= $class['class_id'] ?>">
                    <td class="class-name <?= $isSuspicious ? 'suspicious' : '' ?>">
                        <?= h($class['class_name']) ?>
                        <?php if ($isSuspicious): ?>
                        <i data-lucide="alert-circle" style="width:14px;height:14px;color:var(--color-warning);vertical-align:middle;"></i>
                        <?php endif; ?>
                        <?php if ($class['class_code'] !== $class['class_name']): ?>
                        <span class="text-secondary text-sm">(<?= h($class['class_code']) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="count"><?= $class['result_count'] ?></td>
                    <td>
                        <select class="input input-sm change-to-select">
                            <option value="">-- Behåll --</option>
                            <?php foreach ($allClasses as $ac): ?>
                            <?php if ($ac['id'] != $class['class_id']): ?>
                            <option value="<?= $ac['id'] ?>"><?= h($ac['display_name']) ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <button type="button" class="btn btn--primary btn--sm change-btn">
                            <i data-lucide="save"></i> Spara
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-secondary">Inga klasser hittades för detta event.</p>
        <?php endif; ?>

        <?php else: ?>
        <!-- No event selected - show summary -->
        <p class="text-secondary mb-lg">Välj ett event i listan ovan för att se och ändra dess klasser.</p>

        <h3 class="mb-md">Senaste events med resultat</h3>
        <div class="event-summary">
            <?php foreach (array_slice($allEvents, 0, 12) as $e): ?>
            <a href="?event_id=<?= $e['id'] ?>" class="event-summary-card">
                <h4><?= h($e['name']) ?></h4>
                <div class="meta">
                    <span><?= date('Y-m-d', strtotime($e['date'])) ?></span>
                    <span> · <?= $e['result_count'] ?> resultat</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const csrfToken = '<?= csrf_token() ?>';

function filterByEvent(eventId) {
    window.location.href = '?event_id=' + eventId;
}

// Change class handler
document.querySelectorAll('.change-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const row = this.closest('tr');
        const eventId = row.dataset.eventId;
        const fromClassId = row.dataset.classId;
        const select = row.querySelector('.change-to-select');
        const toClassId = select.value;

        if (!toClassId) {
            alert('Välj en klass att byta till');
            return;
        }

        this.disabled = true;
        this.innerHTML = '<i data-lucide="loader"></i> Sparar...';

        const formData = new FormData();
        formData.append('action', 'change_class');
        formData.append('csrf_token', csrfToken);
        formData.append('event_id', eventId);
        formData.append('from_class_id', fromClassId);
        formData.append('to_class_id', toClassId);

        try {
            const response = await fetch(location.href, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                row.classList.add('saved-row');
                row.querySelector('.class-name').innerHTML = '✓ Bytt till "' + select.options[select.selectedIndex].text + '" (' + result.count + ' st)';
                this.innerHTML = '<i data-lucide="check"></i> Klart!';
                this.classList.remove('btn--primary');
                this.classList.add('btn--success');

                // Remove row after delay
                setTimeout(() => {
                    row.style.opacity = '0.5';
                }, 1500);
            } else {
                alert('Fel: ' + result.error);
                this.disabled = false;
                this.innerHTML = '<i data-lucide="save"></i> Spara';
            }
        } catch (err) {
            alert('Nätverksfel: ' + err.message);
            this.disabled = false;
            this.innerHTML = '<i data-lucide="save"></i> Spara';
        }

        if (typeof lucide !== 'undefined') lucide.createIcons();
    });
});

if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
