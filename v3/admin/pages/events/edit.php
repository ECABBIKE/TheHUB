<?php
/**
 * Admin - Edit Event
 */
$pdo = hub_db();
$eventId = $route['id'] ?? null;
$errors = [];
$success = false;

if (!$eventId) {
    header('Location: ' . admin_url('events'));
    exit;
}

// Load event data
try {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        header('Location: ' . admin_url('events'));
        exit;
    }
} catch (Exception $e) {
    header('Location: ' . admin_url('events'));
    exit;
}

// Get series for dropdown
$series = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM series ORDER BY name");
    $series = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get classes for this event
$eventClasses = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, ec.sort_order
        FROM classes c
        JOIN event_classes ec ON c.id = ec.class_id
        WHERE ec.event_id = ?
        ORDER BY ec.sort_order, c.name
    ");
    $stmt->execute([$eventId]);
    $eventClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get all available classes
$allClasses = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM classes ORDER BY name");
    $allClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get registrations count
$regCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $regCount = $stmt->fetchColumn();
} catch (Exception $e) {}

// Get results count
$resultCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $resultCount = $stmt->fetchColumn();
} catch (Exception $e) {}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        // Delete event
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM event_classes WHERE event_id = ?")->execute([$eventId]);
            $pdo->prepare("DELETE FROM event_registrations WHERE event_id = ?")->execute([$eventId]);
            $pdo->prepare("DELETE FROM results WHERE event_id = ?")->execute([$eventId]);
            $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$eventId]);
            $pdo->commit();

            header('Location: ' . admin_url('events') . '?deleted=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Kunde inte ta bort tavlingen: ' . $e->getMessage();
        }
    } else {
        // Save changes
        $name = trim($_POST['name'] ?? '');
        $date = $_POST['date'] ?? '';
        $location = trim($_POST['location'] ?? '');
        $seriesId = $_POST['series_id'] ?: null;
        $description = trim($_POST['description'] ?? '');
        $registrationOpen = isset($_POST['registration_open']) ? 1 : 0;
        $registrationDeadline = $_POST['registration_deadline'] ?: null;

        // Validation
        if (empty($name)) {
            $errors[] = 'Namn ar obligatoriskt';
        }
        if (empty($date)) {
            $errors[] = 'Datum ar obligatoriskt';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE events SET
                        name = ?,
                        date = ?,
                        location = ?,
                        series_id = ?,
                        description = ?,
                        registration_open = ?,
                        registration_deadline = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name,
                    $date,
                    $location,
                    $seriesId,
                    $description,
                    $registrationOpen,
                    $registrationDeadline,
                    $eventId
                ]);

                // Reload event data
                $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
                $stmt->execute([$eventId]);
                $event = $stmt->fetch(PDO::FETCH_ASSOC);

                $success = true;
            } catch (Exception $e) {
                $errors[] = 'Kunde inte spara: ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="admin-event-edit">

    <!-- Back link -->
    <a href="<?= admin_url('events') ?>" class="admin-back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
            <path d="m12 19-7-7 7-7"/>
            <path d="M19 12H5"/>
        </svg>
        Tillbaka till tavlingar
    </a>

    <?php if ($success): ?>
    <div class="admin-alert admin-alert-success">
        Tavlingen har sparats!
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="admin-alert admin-alert-error">
        <ul>
            <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" class="admin-form">
        <div class="admin-grid">
            <!-- Main Info -->
            <div class="admin-card">
                <h2 class="admin-card-title">Grundinformation</h2>

                <div class="admin-form-group">
                    <label for="name">Namn *</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($event['name']) ?>" required class="admin-input">
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label for="date">Datum *</label>
                        <input type="date" id="date" name="date" value="<?= htmlspecialchars($event['date']) ?>" required class="admin-input">
                    </div>

                    <div class="admin-form-group">
                        <label for="location">Plats</label>
                        <input type="text" id="location" name="location" value="<?= htmlspecialchars($event['location'] ?? '') ?>" class="admin-input">
                    </div>
                </div>

                <div class="admin-form-group">
                    <label for="series_id">Serie</label>
                    <select id="series_id" name="series_id" class="admin-input">
                        <option value="">Ingen serie</option>
                        <?php foreach ($series as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($event['series_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label for="description">Beskrivning</label>
                    <textarea id="description" name="description" rows="4" class="admin-input"><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Registration Settings -->
            <div class="admin-card">
                <h2 class="admin-card-title">Anmalningsinstellningar</h2>

                <div class="admin-form-group">
                    <label class="admin-checkbox">
                        <input type="checkbox" name="registration_open" <?= ($event['registration_open'] ?? 0) ? 'checked' : '' ?>>
                        <span>Anmalan oppen</span>
                    </label>
                </div>

                <div class="admin-form-group">
                    <label for="registration_deadline">Sista anmalningsdag</label>
                    <input type="date" id="registration_deadline" name="registration_deadline"
                           value="<?= htmlspecialchars($event['registration_deadline'] ?? '') ?>" class="admin-input">
                </div>

                <!-- Stats -->
                <div class="admin-info-box">
                    <div class="admin-info-row">
                        <span>Anmalningar:</span>
                        <strong><?= $regCount ?></strong>
                    </div>
                    <div class="admin-info-row">
                        <span>Resultat:</span>
                        <strong><?= $resultCount ?> starter</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Classes -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h2>Klasser</h2>
            </div>

            <?php if (empty($eventClasses)): ?>
                <p class="text-muted">Inga klasser kopplade till denna tavling.</p>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Klass</th>
                                <th>Ordning</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventClasses as $class): ?>
                            <tr>
                                <td><?= htmlspecialchars($class['name']) ?></td>
                                <td><?= $class['sort_order'] ?? '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="admin-form-actions">
            <button type="submit" name="action" value="save" class="btn btn-primary">
                Spara andringar
            </button>

            <a href="<?= HUB_V3_URL ?>/calendar/<?= $eventId ?>" class="btn" target="_blank">
                Visa pa sidan
            </a>

            <button type="submit" name="action" value="delete" class="btn btn-danger"
                    onclick="return confirm('Ar du saker pa att du vill ta bort denna tavling? Detta gar inte att angra.')">
                Ta bort tavling
            </button>
        </div>
    </form>

</div>

<style>
.admin-back-link {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    color: var(--color-text-secondary);
    text-decoration: none;
    margin-bottom: var(--space-lg);
}

.admin-back-link:hover {
    color: var(--color-accent);
}

.admin-alert {
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}

.admin-alert-success {
    background: var(--color-success-bg, #dcfce7);
    color: var(--color-success, #16a34a);
    border: 1px solid var(--color-success, #16a34a);
}

.admin-alert-error {
    background: var(--color-error-bg, #fef2f2);
    color: var(--color-error);
    border: 1px solid var(--color-error);
}

.admin-alert ul {
    margin: 0;
    padding-left: var(--space-lg);
}

.admin-form-group {
    margin-bottom: var(--space-md);
}

.admin-form-group label {
    display: block;
    font-weight: var(--weight-medium);
    margin-bottom: var(--space-xs);
    color: var(--color-text-secondary);
}

.admin-form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
}

.admin-input {
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: inherit;
    font-size: inherit;
}

.admin-input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-alpha, rgba(59, 130, 246, 0.1));
}

textarea.admin-input {
    resize: vertical;
    min-height: 100px;
}

.admin-checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    cursor: pointer;
}

.admin-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

.admin-info-box {
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-top: var(--space-lg);
}

.admin-info-row {
    display: flex;
    justify-content: space-between;
    padding: var(--space-xs) 0;
}

.admin-info-row + .admin-info-row {
    border-top: 1px solid var(--color-border);
}

.admin-form-actions {
    display: flex;
    gap: var(--space-md);
    margin-top: var(--space-lg);
    flex-wrap: wrap;
}

.btn-danger {
    background: var(--color-error);
    border-color: var(--color-error);
    color: white;
    margin-left: auto;
}

.btn-danger:hover {
    opacity: 0.9;
}

@media (max-width: 768px) {
    .admin-form-actions {
        flex-direction: column;
    }

    .admin-form-actions .btn {
        width: 100%;
        justify-content: center;
    }

    .btn-danger {
        margin-left: 0;
    }
}
</style>
