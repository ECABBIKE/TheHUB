<?php
/**
 * Venue Enrichment Tool
 *
 * Berikar destinations med data fran OpenStreetMap/Nominatim.
 * - Hittar GPS-koordinater baserat pa namn/stad
 * - Foreslår data som admin kan granska och godkanna
 * - Gratis, ingen API-nyckel kravs
 *
 * @package TheHUB
 * @version 1.0
 */

require_once __DIR__ . '/../../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    // Save enriched data
    if ($action === 'save_enrichment') {
        $venueId = (int)($_POST['venue_id'] ?? 0);
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;
        $osmId = $_POST['osm_id'] ?? null;

        if ($venueId > 0) {
            try {
                $updates = [];
                $params = [];

                if ($latitude !== '' && $longitude !== '') {
                    $updates[] = 'latitude = ?';
                    $updates[] = 'longitude = ?';
                    $params[] = (float)$latitude;
                    $params[] = (float)$longitude;
                }

                if ($osmId !== '') {
                    $updates[] = 'osm_id = ?';
                    $params[] = $osmId;
                }

                if (!empty($updates)) {
                    $params[] = $venueId;
                    $db->query(
                        "UPDATE venues SET " . implode(', ', $updates) . " WHERE id = ?",
                        $params
                    );
                    $message = 'Destination uppdaterad med GPS-koordinater.';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Fel: ' . $e->getMessage();
                $messageType = 'error';
            }
        }

        // Redirect to avoid resubmit
        header('Location: /admin/tools/venue-enrichment.php?msg=' . urlencode($message) . '&type=' . $messageType);
        exit;
    }
}

// Handle message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['type'] ?? 'info';
}

// Check if venues table has coordinate columns
$hasCoordinates = false;
try {
    $columns = $db->getAll("SHOW COLUMNS FROM venues");
    $columnNames = array_column($columns, 'Field');
    $hasCoordinates = in_array('latitude', $columnNames) && in_array('longitude', $columnNames);
    $hasOsmId = in_array('osm_id', $columnNames);
} catch (Exception $e) {
    // Ignore
}

// Add columns if missing
if (!$hasCoordinates) {
    try {
        $db->query("ALTER TABLE venues ADD COLUMN latitude DECIMAL(10, 8) NULL");
        $db->query("ALTER TABLE venues ADD COLUMN longitude DECIMAL(11, 8) NULL");
        $hasCoordinates = true;
    } catch (Exception $e) {
        $message = 'Kunde inte lagga till koordinat-kolumner: ' . $e->getMessage();
        $messageType = 'error';
    }
}

if (!$hasOsmId) {
    try {
        $db->query("ALTER TABLE venues ADD COLUMN osm_id VARCHAR(50) NULL");
        $hasOsmId = true;
    } catch (Exception $e) {
        // Ignore, not critical
    }
}

// Fetch venues missing coordinates
$venuesMissingData = $db->getAll("
    SELECT
        v.id,
        v.name,
        v.city,
        v.country,
        v.latitude,
        v.longitude,
        (SELECT COUNT(*) FROM events e WHERE e.venue_id = v.id) as event_count
    FROM venues v
    WHERE v.active = 1
      AND (v.latitude IS NULL OR v.longitude IS NULL OR v.latitude = 0)
    ORDER BY event_count DESC, v.name ASC
");

// Fetch venues with coordinates (for stats)
$venuesWithData = $db->getRow("
    SELECT COUNT(*) as count
    FROM venues
    WHERE active = 1 AND latitude IS NOT NULL AND latitude != 0
");

$totalVenues = $db->getRow("SELECT COUNT(*) as count FROM venues WHERE active = 1");

// Page config
$page_title = 'Berika Destinations';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Berika Destinations']
];
include __DIR__ . '/../components/unified-layout.php';
?>

<!-- Messages -->
<?php if ($message): ?>
    <div class="alert alert--<?= $messageType ?> mb-lg">
        <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Summary -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <i data-lucide="map-pin"></i>
            Berika Destinations med GPS-data
        </h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">
            Detta verktyg soker efter GPS-koordinater for dina destinations via OpenStreetMap.
            Data hamtas fran Nominatim (gratis, ingen API-nyckel kravs).
        </p>

        <div class="grid grid-stats grid-gap-md mb-lg">
            <div class="card" style="background: var(--color-bg-hover);">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold text-success"><?= $venuesWithData['count'] ?? 0 ?></div>
                    <div class="text-sm text-secondary">Med GPS-data</div>
                </div>
            </div>
            <div class="card" style="background: var(--color-bg-hover);">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold text-warning"><?= count($venuesMissingData) ?></div>
                    <div class="text-sm text-secondary">Saknar GPS-data</div>
                </div>
            </div>
            <div class="card" style="background: var(--color-bg-hover);">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold text-accent"><?= $totalVenues['count'] ?? 0 ?></div>
                    <div class="text-sm text-secondary">Totalt aktiva</div>
                </div>
            </div>
        </div>

        <?php if (count($venuesMissingData) === 0): ?>
            <div class="alert alert--success">
                <i data-lucide="check-circle"></i>
                Alla aktiva destinations har GPS-koordinater!
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (count($venuesMissingData) > 0): ?>
<!-- Venues Missing Data -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <i data-lucide="search"></i>
            Destinations utan GPS (<?= count($venuesMissingData) ?>)
        </h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table" id="venues-table">
                <thead>
                    <tr>
                        <th>Destination</th>
                        <th>Stad</th>
                        <th style="width: 80px;">Events</th>
                        <th style="width: 200px;">Status</th>
                        <th style="width: 150px;">Atgard</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($venuesMissingData as $venue): ?>
                    <tr id="venue-row-<?= $venue['id'] ?>">
                        <td>
                            <strong><?= htmlspecialchars($venue['name']) ?></strong>
                        </td>
                        <td class="text-secondary">
                            <?= htmlspecialchars($venue['city'] ?? '-') ?>
                        </td>
                        <td>
                            <span class="badge badge--secondary"><?= $venue['event_count'] ?></span>
                        </td>
                        <td>
                            <span class="search-status" id="status-<?= $venue['id'] ?>">
                                <span class="text-secondary">Vantar...</span>
                            </span>
                        </td>
                        <td>
                            <div class="flex gap-xs">
                                <button type="button"
                                        class="btn btn--primary btn--sm search-btn"
                                        data-venue-id="<?= $venue['id'] ?>"
                                        data-venue-name="<?= htmlspecialchars($venue['name']) ?>"
                                        data-venue-city="<?= htmlspecialchars($venue['city'] ?? '') ?>"
                                        data-venue-country="<?= htmlspecialchars($venue['country'] ?? 'Sverige') ?>">
                                    <i data-lucide="search"></i>
                                    Sok
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Search All Button -->
<div class="card mb-lg">
    <div class="card-body">
        <div class="flex gap-md items-center justify-between">
            <div>
                <strong>Sok alla automatiskt</strong>
                <p class="text-secondary text-sm mt-xs">Soker GPS-koordinater for alla destinations som saknar data. Respekterar Nominatims rate limit (1 req/sek).</p>
            </div>
            <button type="button" class="btn btn--primary" id="search-all-btn">
                <i data-lucide="search"></i>
                Sok alla (<?= count($venuesMissingData) ?>)
            </button>
        </div>
        <div id="search-progress" class="mt-md" style="display: none;">
            <div class="progress-bar" style="height: 8px; background: var(--color-bg-hover); border-radius: 4px; overflow: hidden;">
                <div id="progress-fill" style="height: 100%; background: var(--color-accent); width: 0%; transition: width 0.3s;"></div>
            </div>
            <div class="text-sm text-secondary mt-xs">
                <span id="progress-text">0 / <?= count($venuesMissingData) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form for saving -->
<form method="POST" id="save-form" style="display: none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_enrichment">
    <input type="hidden" name="venue_id" id="save-venue-id">
    <input type="hidden" name="latitude" id="save-latitude">
    <input type="hidden" name="longitude" id="save-longitude">
    <input type="hidden" name="osm_id" id="save-osm-id">
</form>

<!-- Result Modal -->
<div id="result-modal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="closeModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-title">Sokresultat</h3>
            <button type="button" class="btn btn--ghost btn--sm" onclick="closeModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="modal-body" id="modal-body">
            <!-- Results will be inserted here -->
        </div>
    </div>
</div>

<style>
.modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-md);
}
.modal-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
}
.modal-content {
    position: relative;
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    max-width: 600px;
    width: 100%;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}
.modal-header h3 {
    margin: 0;
    font-size: var(--text-lg);
}
.modal-body {
    padding: var(--space-lg);
    overflow-y: auto;
}
.result-item {
    padding: var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-sm);
    cursor: pointer;
    transition: all 0.15s;
}
.result-item:hover {
    border-color: var(--color-accent);
    background: var(--color-bg-hover);
}
.result-item.selected {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}
.result-coords {
    font-family: monospace;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
</style>

<script>
// Nominatim search function
async function searchNominatim(name, city, country) {
    // Build search query
    let query = name;
    if (city) query += ', ' + city;
    if (country) query += ', ' + country;

    const url = 'https://nominatim.openstreetmap.org/search?' + new URLSearchParams({
        q: query,
        format: 'json',
        limit: 5,
        addressdetails: 1
    });

    const response = await fetch(url, {
        headers: {
            'User-Agent': 'TheHUB/1.0 (https://thehub.gravityseries.se)'
        }
    });

    if (!response.ok) {
        throw new Error('Nominatim request failed');
    }

    return await response.json();
}

// Search button click handler
document.querySelectorAll('.search-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const venueId = this.dataset.venueId;
        const name = this.dataset.venueName;
        const city = this.dataset.venueCity;
        const country = this.dataset.venueCountry;

        const statusEl = document.getElementById('status-' + venueId);
        statusEl.innerHTML = '<span class="text-warning">Soker...</span>';

        try {
            const results = await searchNominatim(name, city, country);

            if (results.length === 0) {
                statusEl.innerHTML = '<span class="text-error">Inga resultat</span>';
                return;
            }

            // Show modal with results
            showResultModal(venueId, name, results);
            statusEl.innerHTML = '<span class="text-success">' + results.length + ' resultat</span>';

        } catch (error) {
            statusEl.innerHTML = '<span class="text-error">Fel: ' + error.message + '</span>';
        }
    });
});

// Show result modal
function showResultModal(venueId, venueName, results) {
    const modal = document.getElementById('result-modal');
    const title = document.getElementById('modal-title');
    const body = document.getElementById('modal-body');

    title.textContent = 'Resultat for: ' + venueName;

    let html = '';
    results.forEach((result, index) => {
        html += `
            <div class="result-item" onclick="selectResult(${venueId}, ${result.lat}, ${result.lon}, '${result.osm_type}/${result.osm_id}')">
                <div><strong>${result.display_name}</strong></div>
                <div class="result-coords">
                    <i data-lucide="map-pin" style="width: 14px; height: 14px;"></i>
                    ${parseFloat(result.lat).toFixed(6)}, ${parseFloat(result.lon).toFixed(6)}
                </div>
                <div class="text-sm text-secondary mt-xs">
                    Typ: ${result.type || 'unknown'} | OSM: ${result.osm_type}/${result.osm_id}
                </div>
            </div>
        `;
    });

    html += `
        <div class="mt-md text-sm text-secondary">
            <i data-lucide="info" style="width: 14px; height: 14px;"></i>
            Klicka pa ett resultat for att spara koordinaterna.
        </div>
    `;

    body.innerHTML = html;
    modal.style.display = 'flex';

    // Re-initialize Lucide icons
    if (window.lucide) lucide.createIcons();
}

// Select result and save
function selectResult(venueId, lat, lon, osmId) {
    document.getElementById('save-venue-id').value = venueId;
    document.getElementById('save-latitude').value = lat;
    document.getElementById('save-longitude').value = lon;
    document.getElementById('save-osm-id').value = osmId;
    document.getElementById('save-form').submit();
}

// Close modal
function closeModal() {
    document.getElementById('result-modal').style.display = 'none';
}

// Search all button
document.getElementById('search-all-btn').addEventListener('click', async function() {
    const buttons = document.querySelectorAll('.search-btn');
    const progressEl = document.getElementById('search-progress');
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');

    this.disabled = true;
    progressEl.style.display = 'block';

    let completed = 0;
    const total = buttons.length;

    for (const btn of buttons) {
        const venueId = btn.dataset.venueId;
        const name = btn.dataset.venueName;
        const city = btn.dataset.venueCity;
        const country = btn.dataset.venueCountry;

        const statusEl = document.getElementById('status-' + venueId);
        statusEl.innerHTML = '<span class="text-warning">Soker...</span>';

        try {
            const results = await searchNominatim(name, city, country);

            if (results.length > 0) {
                // Auto-save first result
                const result = results[0];

                // Submit via fetch to avoid page reload
                const formData = new FormData();
                formData.append('action', 'save_enrichment');
                formData.append('venue_id', venueId);
                formData.append('latitude', result.lat);
                formData.append('longitude', result.lon);
                formData.append('osm_id', result.osm_type + '/' + result.osm_id);
                formData.append('csrf_token', document.querySelector('[name="csrf_token"]').value);

                await fetch('/admin/tools/venue-enrichment.php', {
                    method: 'POST',
                    body: formData
                });

                statusEl.innerHTML = '<span class="text-success">Sparad!</span>';
                btn.disabled = true;
            } else {
                statusEl.innerHTML = '<span class="text-error">Inga resultat</span>';
            }

        } catch (error) {
            statusEl.innerHTML = '<span class="text-error">Fel</span>';
        }

        completed++;
        progressFill.style.width = ((completed / total) * 100) + '%';
        progressText.textContent = completed + ' / ' + total;

        // Rate limit: wait 1 second between requests (Nominatim requirement)
        if (completed < total) {
            await new Promise(resolve => setTimeout(resolve, 1100));
        }
    }

    this.disabled = false;
    progressText.textContent = 'Klar! ' + completed + ' destinations bearbetade.';
});

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>
<?php endif; ?>

<!-- Help Section -->
<div class="card">
    <div class="card-header">
        <h2>
            <i data-lucide="help-circle"></i>
            Om detta verktyg
        </h2>
    </div>
    <div class="card-body">
        <div class="grid grid-2 grid-gap-lg">
            <div>
                <h3 class="text-accent mb-sm">Hur fungerar det?</h3>
                <ul class="text-secondary" style="padding-left: var(--space-md);">
                    <li>Soker i OpenStreetMap via Nominatim API</li>
                    <li>Helt gratis, ingen API-nyckel kravs</li>
                    <li>Respekterar rate limit (1 request/sekund)</li>
                    <li>Du granskar och godkanner innan sparning</li>
                </ul>
            </div>
            <div>
                <h3 class="text-accent mb-sm">Tips for battre resultat</h3>
                <ul class="text-secondary" style="padding-left: var(--space-md);">
                    <li>Se till att destinationens stad ar korrekt</li>
                    <li>Svenska orter hittas oftast bra</li>
                    <li>Bikepark/skidanlaggningar kan vara svårare</li>
                    <li>Redigera manuellt om automatisk sokning misslyckas</li>
                </ul>
            </div>
        </div>

        <div class="alert alert--info mt-lg">
            <i data-lucide="info"></i>
            <strong>Datakalla:</strong> OpenStreetMap via Nominatim.
            <a href="https://www.openstreetmap.org/copyright" target="_blank">Licens och attribution</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>
