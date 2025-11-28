<?php
/**
 * Bildhantering - Ladda upp logotyper med light/dark-varianter
 */
require_once HUB_V3_ROOT . '/components/icons.php';

$pdo = hub_db();
$success = null;
$error = null;

// Skapa images tabell om den inte finns
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(50) NOT NULL,
            entity_id INT DEFAULT 0,
            variant VARCHAR(20) DEFAULT 'default',
            filename VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_image (type, entity_id, variant)
        )
    ");
} catch (Exception $e) {
    // Table probably already exists
}

// Hantera uppladdning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $type = $_POST['type'] ?? 'logo';
    $entityId = intval($_POST['entity_id'] ?? 0);
    $variant = $_POST['variant'] ?? 'default';

    $uploadDir = HUB_V3_ROOT . '/uploads/images/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Ett fel uppstod vid uppladdningen.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'svg', 'webp'])) {
            $error = 'Endast JPG, PNG, SVG och WebP tillats.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'Max filstorlek ar 5MB.';
        } else {
            $filename = $type . '-' . $entityId . '-' . $variant . '-' . time() . '.' . $ext;
            $filepath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                try {
                    // Ta bort gammal bild om den finns
                    $stmt = $pdo->prepare("SELECT filename FROM images WHERE type = ? AND entity_id = ? AND variant = ?");
                    $stmt->execute([$type, $entityId, $variant]);
                    $oldImage = $stmt->fetchColumn();
                    if ($oldImage && file_exists($uploadDir . $oldImage)) {
                        unlink($uploadDir . $oldImage);
                    }

                    // Spara i databasen
                    $stmt = $pdo->prepare("
                        INSERT INTO images (type, entity_id, variant, filename, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE filename = VALUES(filename), created_at = NOW()
                    ");
                    $stmt->execute([$type, $entityId, $variant, $filename]);

                    $success = 'Bilden har laddats upp!';
                } catch (Exception $e) {
                    $error = 'Kunde inte spara bilden i databasen.';
                }
            } else {
                $error = 'Kunde inte ladda upp filen.';
            }
        }
    }
}

// Hantera borttagning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = intval($_POST['delete_id']);
    try {
        $stmt = $pdo->prepare("SELECT filename FROM images WHERE id = ?");
        $stmt->execute([$deleteId]);
        $filename = $stmt->fetchColumn();

        if ($filename) {
            $filepath = HUB_V3_ROOT . '/uploads/images/' . $filename;
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            $pdo->prepare("DELETE FROM images WHERE id = ?")->execute([$deleteId]);
            $success = 'Bilden har tagits bort.';
        }
    } catch (Exception $e) {
        $error = 'Kunde inte ta bort bilden.';
    }
}

// Hamta befintliga bilder
$images = [];
try {
    $images = $pdo->query("
        SELECT i.*,
               CASE
                   WHEN i.type = 'series' THEN (SELECT name FROM series WHERE id = i.entity_id)
                   WHEN i.type = 'club' THEN (SELECT name FROM clubs WHERE id = i.entity_id)
                   WHEN i.type = 'site' THEN 'Sajt-logotyp'
                   ELSE 'Okand'
               END as entity_name
        FROM images i
        ORDER BY i.created_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
}

// Hamta serier och klubbar for dropdown
$series = [];
$clubs = [];
try {
    $series = $pdo->query("SELECT id, name FROM series ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $clubs = $pdo->query("SELECT id, name FROM clubs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<div class="admin-page-header">
    <div>
        <h1>Bildhantering</h1>
        <p class="text-secondary">Ladda upp logotyper med light/dark-varianter</p>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert--success mb-lg"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert--error mb-lg"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Upload Form -->
<div class="admin-card mb-lg">
    <h2 class="admin-card-title">Ladda upp ny bild</h2>

    <form method="post" enctype="multipart/form-data" class="admin-form">
        <div class="form-grid">
            <div class="admin-form-group">
                <label for="image-type">Typ</label>
                <select name="type" id="image-type" class="admin-input" required>
                    <option value="">Valj typ...</option>
                    <option value="series">Serie-logotyp</option>
                    <option value="club">Klubb-logotyp</option>
                    <option value="sponsor">Sponsor</option>
                    <option value="site">Sajt-logotyp</option>
                </select>
            </div>

            <div class="admin-form-group" id="entity-select" style="display: none;">
                <label for="entity-id">Valj</label>
                <select name="entity_id" id="entity-id" class="admin-input">
                    <option value="0">Valj...</option>
                </select>
            </div>

            <div class="admin-form-group">
                <label for="variant">Variant</label>
                <select name="variant" id="variant" class="admin-input" required>
                    <option value="default">Standard (fungerar pa bada)</option>
                    <option value="light">Light mode</option>
                    <option value="dark">Dark mode</option>
                </select>
            </div>

            <div class="admin-form-group">
                <label for="image">Bild</label>
                <input type="file" name="image" id="image" accept=".jpg,.jpeg,.png,.svg,.webp" required class="admin-input">
                <span class="form-hint text-secondary text-sm">Max 5MB. JPG, PNG, SVG eller WebP.</span>
            </div>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="btn btn-primary">
                <?= hub_icon('upload', 'icon-sm') ?>
                Ladda upp
            </button>
        </div>
    </form>
</div>

<!-- Befintliga bilder -->
<div class="admin-card">
    <h2 class="admin-card-title">Uppladdade bilder</h2>

    <?php if (empty($images)): ?>
        <p class="text-muted">Inga bilder har laddats upp annu.</p>
    <?php else: ?>
        <div class="image-grid">
            <?php foreach ($images as $img): ?>
            <div class="image-card" data-variant="<?= htmlspecialchars($img['variant']) ?>">
                <div class="image-preview <?= $img['variant'] === 'dark' ? 'bg-dark' : ($img['variant'] === 'light' ? 'bg-light' : '') ?>">
                    <img src="<?= HUB_V3_URL ?>/uploads/images/<?= htmlspecialchars($img['filename']) ?>" alt="">
                </div>
                <div class="image-info">
                    <strong><?= htmlspecialchars($img['entity_name'] ?: $img['type']) ?></strong>
                    <span class="badge badge--<?= htmlspecialchars($img['variant']) ?>"><?= ucfirst($img['variant']) ?></span>
                    <span class="text-secondary text-sm"><?= date('Y-m-d', strtotime($img['created_at'])) ?></span>
                </div>
                <div class="image-actions">
                    <form method="post" style="display: inline;" onsubmit="return confirm('Ta bort denna bild?')">
                        <input type="hidden" name="delete_id" value="<?= $img['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">
                            <?= hub_icon('trash', 'icon-sm') ?>
                            Ta bort
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Data for dropdowns
const seriesData = <?= json_encode($series) ?>;
const clubsData = <?= json_encode($clubs) ?>;

document.getElementById('image-type').addEventListener('change', function() {
    const entitySelect = document.getElementById('entity-select');
    const entityId = document.getElementById('entity-id');

    entityId.innerHTML = '<option value="0">Valj...</option>';

    if (this.value === 'series') {
        seriesData.forEach(s => {
            entityId.innerHTML += `<option value="${s.id}">${s.name}</option>`;
        });
        entitySelect.style.display = 'block';
    } else if (this.value === 'club') {
        clubsData.forEach(c => {
            entityId.innerHTML += `<option value="${c.id}">${c.name}</option>`;
        });
        entitySelect.style.display = 'block';
    } else {
        entitySelect.style.display = 'none';
    }
});
</script>

<style>
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}

.form-hint {
    display: block;
    margin-top: var(--space-xs);
}

.mb-lg {
    margin-bottom: var(--space-lg);
}

.text-sm {
    font-size: var(--text-sm);
}
</style>
