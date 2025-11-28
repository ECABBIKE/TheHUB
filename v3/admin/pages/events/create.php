<?php
/**
 * Admin - Create Event
 */
$pdo = hub_db();
$errors = [];

// Get series for dropdown
$series = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM series ORDER BY name");
    $series = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get all available classes
$allClasses = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM classes ORDER BY name");
    $allClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $date = $_POST['date'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $seriesId = $_POST['series_id'] ?: null;
    $description = trim($_POST['description'] ?? '');
    $registrationOpen = isset($_POST['registration_open']) ? 1 : 0;
    $registrationDeadline = $_POST['registration_deadline'] ?: null;
    $selectedClasses = $_POST['classes'] ?? [];

    // Validation
    if (empty($name)) {
        $errors[] = 'Namn ar obligatoriskt';
    }
    if (empty($date)) {
        $errors[] = 'Datum ar obligatoriskt';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO events (name, date, location, series_id, description, registration_open, registration_deadline, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $name,
                $date,
                $location,
                $seriesId,
                $description,
                $registrationOpen,
                $registrationDeadline
            ]);

            $eventId = $pdo->lastInsertId();

            // Add classes if selected
            if (!empty($selectedClasses)) {
                $stmt = $pdo->prepare("INSERT INTO event_classes (event_id, class_id, sort_order) VALUES (?, ?, ?)");
                $order = 1;
                foreach ($selectedClasses as $classId) {
                    $stmt->execute([$eventId, $classId, $order++]);
                }
            }

            $pdo->commit();

            header('Location: ' . admin_url('events/' . $eventId) . '?created=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Kunde inte skapa tavlingen: ' . $e->getMessage();
        }
    }
}

// Default values for form
$formData = [
    'name' => $_POST['name'] ?? '',
    'date' => $_POST['date'] ?? '',
    'location' => $_POST['location'] ?? '',
    'series_id' => $_POST['series_id'] ?? '',
    'description' => $_POST['description'] ?? '',
    'registration_open' => isset($_POST['registration_open']),
    'registration_deadline' => $_POST['registration_deadline'] ?? '',
    'classes' => $_POST['classes'] ?? []
];
?>

<div class="admin-event-create">

    <!-- Back link -->
    <a href="<?= admin_url('events') ?>" class="admin-back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
            <path d="m12 19-7-7 7-7"/>
            <path d="M19 12H5"/>
        </svg>
        Tillbaka till tavlingar
    </a>

    <h2 class="admin-page-heading">Skapa ny tavling</h2>

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
                <h3 class="admin-card-title">Grundinformation</h3>

                <div class="admin-form-group">
                    <label for="name">Namn *</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($formData['name']) ?>" required class="admin-input">
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label for="date">Datum *</label>
                        <input type="date" id="date" name="date" value="<?= htmlspecialchars($formData['date']) ?>" required class="admin-input">
                    </div>

                    <div class="admin-form-group">
                        <label for="location">Plats</label>
                        <input type="text" id="location" name="location" value="<?= htmlspecialchars($formData['location']) ?>" class="admin-input">
                    </div>
                </div>

                <div class="admin-form-group">
                    <label for="series_id">Serie</label>
                    <select id="series_id" name="series_id" class="admin-input">
                        <option value="">Ingen serie</option>
                        <?php foreach ($series as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $formData['series_id'] == $s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label for="description">Beskrivning</label>
                    <textarea id="description" name="description" rows="4" class="admin-input"><?= htmlspecialchars($formData['description']) ?></textarea>
                </div>
            </div>

            <!-- Registration Settings -->
            <div class="admin-card">
                <h3 class="admin-card-title">Anmalningsinstellningar</h3>

                <div class="admin-form-group">
                    <label class="admin-checkbox">
                        <input type="checkbox" name="registration_open" <?= $formData['registration_open'] ? 'checked' : '' ?>>
                        <span>Anmalan oppen</span>
                    </label>
                </div>

                <div class="admin-form-group">
                    <label for="registration_deadline">Sista anmalningsdag</label>
                    <input type="date" id="registration_deadline" name="registration_deadline"
                           value="<?= htmlspecialchars($formData['registration_deadline']) ?>" class="admin-input">
                </div>
            </div>
        </div>

        <!-- Classes -->
        <div class="admin-card">
            <h3 class="admin-card-title">Klasser</h3>
            <p class="text-secondary" style="margin-bottom: var(--space-md);">Valj vilka klasser som ska finnas pa tavlingen.</p>

            <?php if (empty($allClasses)): ?>
                <p class="text-muted">Inga klasser finns i systemet.</p>
            <?php else: ?>
                <div class="admin-class-grid">
                    <?php foreach ($allClasses as $class): ?>
                    <label class="admin-checkbox-card">
                        <input type="checkbox" name="classes[]" value="<?= $class['id'] ?>"
                               <?= in_array($class['id'], $formData['classes']) ? 'checked' : '' ?>>
                        <span class="admin-checkbox-card-content">
                            <?= htmlspecialchars($class['name']) ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="admin-form-actions">
            <button type="submit" class="btn btn-primary">
                Skapa tavling
            </button>
            <a href="<?= admin_url('events') ?>" class="btn">
                Avbryt
            </a>
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
    margin-bottom: var(--space-md);
}

.admin-back-link:hover {
    color: var(--color-accent);
}

.admin-page-heading {
    font-size: var(--text-xl);
    font-weight: var(--weight-bold);
    margin-bottom: var(--space-lg);
}

.admin-alert {
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
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

.admin-class-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: var(--space-sm);
}

.admin-checkbox-card {
    display: block;
    cursor: pointer;
}

.admin-checkbox-card input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.admin-checkbox-card-content {
    display: block;
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken);
    border: 2px solid transparent;
    border-radius: var(--radius-md);
    text-align: center;
    transition: all var(--transition-fast);
}

.admin-checkbox-card input:checked + .admin-checkbox-card-content {
    background: var(--color-accent-alpha, rgba(59, 130, 246, 0.1));
    border-color: var(--color-accent);
    color: var(--color-accent);
}

.admin-checkbox-card:hover .admin-checkbox-card-content {
    border-color: var(--color-border);
}

.admin-form-actions {
    display: flex;
    gap: var(--space-md);
    margin-top: var(--space-lg);
}

@media (max-width: 768px) {
    .admin-form-actions {
        flex-direction: column;
    }

    .admin-form-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>
