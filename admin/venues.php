<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();


// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'rename') {
        $old_location = trim($_POST['old_location'] ?? '');
        $new_location = trim($_POST['new_location'] ?? '');

        if (empty($new_location)) {
            $message = 'Nytt platsnamn krävs';
            $messageType = 'error';
        } elseif ($old_location === $new_location) {
            $message = 'Nytt platsnamn är samma som det gamla';
            $messageType = 'error';
        } else {
            try {
                // Check if new location already exists (for merge case)
                $existingCount = $db->getOne(
                    "SELECT COUNT(*) as count FROM events WHERE location = ?",
                    [$new_location]
                );

                // Update all events with old location to new location
                $db->update('events', ['location' => $new_location], 'location = ?', [$old_location]);

                // Count affected events
                $affectedCount = $db->getConnection()->affectedRows();

                if ($existingCount['count'] > 0) {
                    $message = "Platser sammanslagna! {$affectedCount} tävlingar flyttade från \"{$old_location}\" till \"{$new_location}\"";
                } else {
                    $message = "Plats uppdaterad! {$affectedCount} tävlingar flyttade från \"{$old_location}\" till \"{$new_location}\"";
                }
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

    // Get unique venues from events (aggregated by location)
    $sql = "SELECT
                location as name,
                COUNT(DISTINCT id) as event_count,
                MIN(event_date) as first_event,
                MAX(event_date) as last_event,
                GROUP_CONCAT(DISTINCT event_type) as event_types
            FROM events
            WHERE location IS NOT NULL AND location != ''
            GROUP BY location
            ORDER BY location";

    $venues = $db->getAll($sql);

$pageTitle = 'Venues';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="mountain"></i>
                    Venues
                </h1>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Info Alert -->
            <div class="gs-alert gs-alert-warning gs-mb-lg">
                <i data-lucide="alert-triangle"></i>
                <div>
                    <strong>Virtual Venue Management</strong><br>
                    <p class="gs-mb-sm">Platser hanteras för närvarande via tävlingarnas <code>location</code>-fält. Detta är en enkel lösning som fungerar, men har begränsningar:</p>
                    <ul class="gs-list-disc gs-ml-lg gs-mb-sm gs-text-sm">
                        <li>Ingen centraliserad platsinformation (adress, GPS, faciliter, etc.)</li>
                        <li>Risk för stavfel och inkonsekvent namngivning</li>
                        <li>Svårt att hantera platshistorik och relationer</li>
                    </ul>
                    <p class="gs-text-sm gs-mb-xs"><strong>Rekommendation:</strong> Skapa en dedikerad <code>venues</code>-tabell för bättre datahantering.</p>
                    <details class="gs-text-sm" style="margin-top: var(--gs-space-sm);">
                        <summary style="cursor: pointer; font-weight: 500;">
                            <i data-lucide="code" style="width: 14px; height: 14px;"></i>
                            Visa SQL för venues-tabell
                        </summary>
                        <pre style="background: var(--gs-bg-secondary); padding: var(--gs-space-md); border-radius: var(--gs-radius-md); margin-top: var(--gs-space-sm); overflow-x: auto; font-size: 12px;">CREATE TABLE venues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(100),
    address VARCHAR(255),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    description TEXT,
    facilities TEXT,
    website VARCHAR(255),
    active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_city (city)
);

-- Add foreign key to events table
ALTER TABLE events ADD COLUMN venue_id INT AFTER location;
ALTER TABLE events ADD FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL;</pre>
                    </details>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="mountain" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($venues) ?></div>
                    <div class="gs-stat-label">Totalt venues</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($venues, 'event_count')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt events</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="map-pin" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count($venues) > 0 ? round(array_sum(array_column($venues, 'event_count')) / count($venues), 1) : 0 ?>
                    </div>
                    <div class="gs-stat-label">Snitt events/venue</div>
                </div>
            </div>

            <!-- Venues Table -->
            <?php if (empty($venues)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="map" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga venues hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="mountain"></i>
                                        Namn/Plats
                                    </th>
                                    <th>
                                        <i data-lucide="flag"></i>
                                        Discipliner
                                    </th>
                                    <th>
                                        <i data-lucide="calendar"></i>
                                        Antal events
                                    </th>
                                    <th>
                                        <i data-lucide="calendar-clock"></i>
                                        Första event
                                    </th>
                                    <th>
                                        <i data-lucide="calendar-check"></i>
                                        Senaste event
                                    </th>
                                    <th style="width: 150px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($venues as $venue): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($venue['name']) ?></strong>
                                        </td>
                                        <td>
                                            <?php
                                            $types = explode(',', $venue['event_types']);
                                            foreach (array_slice($types, 0, 3) as $type):
                                            ?>
                                                <span class="gs-badge gs-badge-primary gs-text-xs">
                                                    <?= h(str_replace('_', ' ', $type)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($types) > 3): ?>
                                                <span class="gs-text-secondary gs-text-xs">+<?= count($types) - 3 ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-center">
                                            <strong class="gs-text-primary"><?= $venue['event_count'] ?></strong>
                                        </td>
                                        <td class="gs-text-secondary gs-text-sm" style="font-family: monospace;">
                                            <?= formatDate($venue['first_event'], 'd M Y') ?>
                                        </td>
                                        <td class="gs-text-secondary gs-text-sm" style="font-family: monospace;">
                                            <?= formatDate($venue['last_event'], 'd M Y') ?>
                                        </td>
                                        <td style="text-align: right;">
                                                <div class="gs-flex gs-gap-sm gs-justify-end">
                                                    <a
                                                        href="/admin/events.php?location=<?= urlencode($venue['name']) ?>"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        title="Visa tävlingar för denna plats"
                                                    >
                                                        <i data-lucide="calendar"></i>
                                                        Events
                                                    </a>
                                                    <button
                                                        type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        onclick="openRenameModal('<?= addslashes(h($venue['name'])) ?>', <?= $venue['event_count'] ?>)"
                                                        title="Byt namn på plats"
                                                    >
                                                        <i data-lucide="edit"></i>
                                                        Byt namn
                                                    </button>
                                                </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Rename Location Modal -->
                <div id="renameModal" class="gs-modal" style="display: none;">
                    <div class="gs-modal-overlay" onclick="closeRenameModal()"></div>
                    <div class="gs-modal-content" style="max-width: 500px;">
                        <div class="gs-modal-header">
                            <h2 class="gs-modal-title">
                                <i data-lucide="edit"></i>
                                Byt namn på plats
                            </h2>
                            <button type="button" class="gs-modal-close" onclick="closeRenameModal()">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                        <form method="POST" id="renameForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="old_location" id="oldLocation" value="">

                            <div class="gs-modal-body">
                                <div class="gs-alert gs-alert-info gs-mb-md" id="affectedEventsInfo">
                                    <i data-lucide="info"></i>
                                    <span id="affectedEventsText"></span>
                                </div>

                                <div class="gs-mb-md">
                                    <label class="gs-label">
                                        <i data-lucide="map-pin"></i>
                                        Nuvarande platsnamn
                                    </label>
                                    <input
                                        type="text"
                                        id="currentLocationDisplay"
                                        class="gs-input"
                                        disabled
                                        style="background-color: var(--gs-bg-secondary); cursor: not-allowed;"
                                    >
                                </div>

                                <div>
                                    <label for="new_location" class="gs-label">
                                        <i data-lucide="edit"></i>
                                        Nytt platsnamn <span class="gs-text-error">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="new_location"
                                        name="new_location"
                                        class="gs-input"
                                        required
                                        placeholder="Ange nytt platsnamn"
                                        autocomplete="off"
                                    >
                                    <p class="gs-text-secondary gs-text-xs gs-mt-xs">
                                        Tips: Om du anger ett befintligt platsnamn kommer platserna att slås samman.
                                    </p>
                                </div>
                            </div>

                            <div class="gs-modal-footer">
                                <button type="button" class="gs-btn gs-btn-outline" onclick="closeRenameModal()">
                                    Avbryt
                                </button>
                                <button type="submit" class="gs-btn gs-btn-primary">
                                    <i data-lucide="check"></i>
                                    Uppdatera
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    // Open rename modal
                    function openRenameModal(locationName, eventCount) {
                        document.getElementById('renameModal').style.display = 'flex';
                        document.getElementById('oldLocation').value = locationName;
                        document.getElementById('currentLocationDisplay').value = locationName;
                        document.getElementById('new_location').value = '';
                        document.getElementById('affectedEventsText').textContent =
                            `Detta kommer att påverka ${eventCount} tävling${eventCount !== 1 ? 'ar' : ''}.`;

                        // Focus on new location input
                        setTimeout(() => {
                            document.getElementById('new_location').focus();
                        }, 100);

                        // Re-initialize Lucide icons
                        if (typeof lucide !== 'undefined') {
                            lucide.createIcons();
                        }
                    }

                    // Close rename modal
                    function closeRenameModal() {
                        document.getElementById('renameModal').style.display = 'none';
                    }

                    // Close modal when clicking outside
                    document.addEventListener('DOMContentLoaded', function() {
                        const modal = document.getElementById('renameModal');
                        if (modal) {
                            modal.addEventListener('click', function(e) {
                                if (e.target === modal) {
                                    closeRenameModal();
                                }
                            });
                        }
                    });

                    // Close modal with Escape key
                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') {
                            const modal = document.getElementById('renameModal');
                            if (modal && modal.style.display === 'flex') {
                                closeRenameModal();
                            }
                        }
                    });
                </script>
        </div>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>
