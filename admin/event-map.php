<?php
/**
 * Admin Event Map Management
 *
 * Manage GPX tracks, segments, and POIs for events.
 *
 * @since 2025-12-09
 */
require_once __DIR__ . '/../config.php';
require_admin();
require_once INCLUDES_PATH . '/map_functions.php';

$db = getDB();
global $pdo;

// Get event ID from URL
$eventId = 0;
if (isset($_GET['id'])) {
    $eventId = intval($_GET['id']);
} elseif (isset($_GET['event_id'])) {
    $eventId = intval($_GET['event_id']);
} else {
    $uri = $_SERVER['REQUEST_URI'];
    if (preg_match('#/admin/events?/map/(\d+)#', $uri, $matches)) {
        $eventId = intval($matches[1]);
    }
}

if ($eventId <= 0) {
    set_flash('error', 'Ogiltigt event-ID');
    header('Location: /admin/events');
    exit;
}

// Fetch event
$event = $db->getRow("SELECT id, name, date FROM events WHERE id = ?", [$eventId]);

if (!$event) {
    set_flash('error', 'Event hittades inte');
    header('Location: /admin/events');
    exit;
}

// Initialize message
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // Upload GPX file
            case 'upload_gpx':
                if (!isset($_FILES['gpx_file']) || $_FILES['gpx_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ingen fil uppladdad eller uppladdningsfel');
                }

                $file = $_FILES['gpx_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if ($ext !== 'gpx') {
                    throw new Exception('Endast GPX-filer ar tillatna');
                }

                // Check file size (max 10MB)
                if ($file['size'] > 10 * 1024 * 1024) {
                    throw new Exception('Filen ar for stor (max 10MB)');
                }

                // Create upload directory
                $uploadDir = getGpxUploadPath();

                // Generate unique filename
                $filename = 'event_' . $eventId . '_' . time() . '.gpx';
                $filepath = $uploadDir . '/' . $filename;

                if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                    throw new Exception('Kunde inte spara filen');
                }

                // Delete existing track for this event (if any)
                $existingTrack = getEventTrack($pdo, $eventId);
                if ($existingTrack) {
                    deleteEventTrack($pdo, $existingTrack['id']);
                }

                // Parse and save to database
                $parsedData = parseGpxFile($filepath);
                $trackName = trim($_POST['track_name'] ?? '') ?: pathinfo($file['name'], PATHINFO_FILENAME);
                saveEventTrack($pdo, $eventId, $trackName, $filename, $parsedData);

                $message = 'GPX-fil uppladdad och bearbetad!';
                $messageType = 'success';
                break;

            // Delete track
            case 'delete_track':
                $trackId = intval($_POST['track_id'] ?? 0);
                if ($trackId > 0) {
                    deleteEventTrack($pdo, $trackId);
                    $message = 'Bana borttagen';
                    $messageType = 'success';
                }
                break;

            // Add POI
            case 'add_poi':
                $poiData = [
                    'poi_type' => $_POST['poi_type'] ?? '',
                    'label' => trim($_POST['poi_label'] ?? ''),
                    'description' => trim($_POST['poi_description'] ?? ''),
                    'lat' => floatval($_POST['poi_lat'] ?? 0),
                    'lng' => floatval($_POST['poi_lng'] ?? 0),
                ];

                if (empty($poiData['poi_type']) || $poiData['lat'] == 0 || $poiData['lng'] == 0) {
                    throw new Exception('Typ och koordinater kravs');
                }

                addEventPoi($pdo, $eventId, $poiData);
                $message = 'POI tillagd';
                $messageType = 'success';
                break;

            // Delete POI
            case 'delete_poi':
                $poiId = intval($_POST['poi_id'] ?? 0);
                if ($poiId > 0) {
                    deleteEventPoi($pdo, $poiId);
                    $message = 'POI borttagen';
                    $messageType = 'success';
                }
                break;

            // Update segment
            case 'update_segment':
                $segmentId = intval($_POST['segment_id'] ?? 0);
                $segmentType = $_POST['segment_type'] ?? 'stage';
                $segmentName = trim($_POST['segment_name'] ?? '');
                $timingId = trim($_POST['timing_id'] ?? '');

                if ($segmentId > 0) {
                    updateSegmentClassification($pdo, $segmentId, $segmentType, $segmentName, $timingId ?: null);
                    $message = 'Segment uppdaterat';
                    $messageType = 'success';
                }
                break;

            // Define segments from distances
            case 'define_segments':
                $trackId = intval($_POST['track_id'] ?? 0);
                $segmentCount = intval($_POST['segment_count'] ?? 0);

                if ($trackId <= 0) {
                    throw new Exception('Ogiltigt track-ID');
                }

                $segmentDefs = [];
                for ($i = 1; $i <= $segmentCount; $i++) {
                    $name = trim($_POST["seg_{$i}_name"] ?? '');
                    $type = $_POST["seg_{$i}_type"] ?? 'stage';
                    $startKm = floatval($_POST["seg_{$i}_start"] ?? 0);
                    $endKm = floatval($_POST["seg_{$i}_end"] ?? 0);

                    if ($endKm > $startKm) {
                        $segmentDefs[] = [
                            'name' => $name ?: ($type === 'stage' ? "SS$i" : "L$i"),
                            'type' => $type,
                            'start_km' => $startKm,
                            'end_km' => $endKm
                        ];
                    }
                }

                if (empty($segmentDefs)) {
                    throw new Exception('Inga giltiga segment definierade');
                }

                defineTrackSegmentsFromDistances($pdo, $trackId, $segmentDefs);
                $message = count($segmentDefs) . ' segment definierade!';
                $messageType = 'success';
                break;

            // Add single segment
            case 'add_segment':
                $trackId = intval($_POST['track_id'] ?? 0);
                $segmentName = trim($_POST['segment_name'] ?? '');
                $segmentType = $_POST['segment_type'] ?? 'stage';
                $startKm = floatval($_POST['start_km'] ?? 0);
                $endKm = floatval($_POST['end_km'] ?? 0);

                if ($trackId <= 0) {
                    throw new Exception('Ogiltigt track-ID');
                }
                if ($endKm <= $startKm) {
                    throw new Exception('Slut-km måste vara större än start-km');
                }

                addSegmentByDistance($pdo, $trackId, [
                    'name' => $segmentName ?: ($segmentType === 'stage' ? 'SS' : 'Liaison'),
                    'type' => $segmentType,
                    'start_km' => $startKm,
                    'end_km' => $endKm
                ]);
                $message = 'Segment tillagt!';
                $messageType = 'success';
                break;
        }

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get current map data
$track = getEventTrack($pdo, $eventId);
$pois = getEventPois($pdo, $eventId, false); // Include hidden
$poiTypes = getPoiTypesForSelect();

// Get map data for preview
$mapData = getEventMapData($pdo, $eventId);
$mapDataJson = $mapData ? json_encode($mapData) : 'null';

// Page config
$page_title = 'Karta - ' . htmlspecialchars($event['name']);
$breadcrumbs = [
    ['label' => 'Events', 'url' => '/admin/events'],
    ['label' => htmlspecialchars($event['name']), 'url' => '/admin/events/edit/' . $eventId],
    ['label' => 'Karta']
];
$page_actions = '<a href="/admin/events/edit/' . $eventId . '" class="btn-admin btn-admin-secondary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="m15 18-6-6 6-6"/></svg>
    Tillbaka till event
</a>';

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<!-- Map CSS -->
<link rel="stylesheet" href="<?= hub_asset('css/map.css') ?>">

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'error' ? 'error' : 'success' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="admin-grid admin-grid-2">
    <!-- Left Column: GPX Upload & Segments -->
    <div>
        <!-- GPX Upload Card -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h2>GPX-fil</h2>
            </div>
            <div class="admin-card-body">
                <?php if ($track): ?>
                <div class="admin-info-box" style="margin-bottom: var(--space-md);">
                    <strong>Nuvarande bana:</strong> <?= htmlspecialchars($track['name']) ?><br>
                    <span class="admin-text-muted">
                        <?= number_format($track['total_distance_km'], 1) ?> km &bull;
                        <?= number_format($track['total_elevation_m']) ?>m hojd &bull;
                        <?= count($track['segments']) ?> segment
                    </span>
                </div>
                <form method="POST" style="margin-bottom: var(--space-md);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_track">
                    <input type="hidden" name="track_id" value="<?= $track['id'] ?>">
                    <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm"
                            onclick="return confirm('Ta bort befintlig bana? Detta raderar alla segment och waypoints.')">
                        Ta bort bana
                    </button>
                </form>
                <hr style="margin: var(--space-md) 0; border-color: var(--color-border);">
                <p class="admin-text-muted" style="margin-bottom: var(--space-sm);">Ladda upp ny GPX-fil (ersatter befintlig):</p>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_gpx">

                    <div class="admin-form-group">
                        <label class="admin-form-label">Namn pa banan</label>
                        <input type="text" name="track_name" class="admin-form-input"
                               placeholder="T.ex. Enduro 2025" value="<?= htmlspecialchars($event['name']) ?>">
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-form-label">GPX-fil</label>
                        <input type="file" name="gpx_file" accept=".gpx" required class="admin-form-input">
                        <span class="admin-form-hint">Max 10MB</span>
                    </div>

                    <button type="submit" class="btn-admin btn-admin-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                        Ladda upp GPX
                    </button>
                </form>
            </div>
        </div>

        <?php if ($track): ?>
        <!-- Define Segments Card -->
        <div class="admin-card" style="margin-top: var(--space-lg);">
            <div class="admin-card-header">
                <h2>Definiera sträckor</h2>
            </div>
            <div class="admin-card-body">
                <p class="admin-text-muted" style="margin-bottom: var(--space-md);">
                    Total distans: <strong><?= number_format($track['total_distance_km'], 1) ?> km</strong>
                </p>

                <form method="POST" id="define-segments-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="define_segments">
                    <input type="hidden" name="track_id" value="<?= $track['id'] ?>">
                    <input type="hidden" name="segment_count" id="segment-count" value="1">

                    <div id="segment-definitions">
                        <div class="segment-row" data-index="1" style="display: grid; grid-template-columns: auto 1fr 100px 100px 40px; gap: var(--space-sm); align-items: center; margin-bottom: var(--space-sm); padding: var(--space-sm); background: var(--color-bg-hover); border-radius: var(--radius-sm);">
                            <select name="seg_1_type" class="admin-form-select admin-form-select-sm" style="width: 100px;">
                                <option value="stage">Tävling</option>
                                <option value="liaison">Transport</option>
                            </select>
                            <input type="text" name="seg_1_name" class="admin-form-input admin-form-input-sm" placeholder="Namn (t.ex. SS1)">
                            <input type="number" name="seg_1_start" class="admin-form-input admin-form-input-sm" placeholder="Start km" step="0.1" min="0" value="0">
                            <input type="number" name="seg_1_end" class="admin-form-input admin-form-input-sm" placeholder="Slut km" step="0.1" min="0" value="<?= number_format($track['total_distance_km'], 1) ?>">
                            <button type="button" class="btn-admin btn-admin-ghost btn-admin-sm remove-segment" style="padding: 4px;" title="Ta bort" disabled>
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
                            </button>
                        </div>
                    </div>

                    <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-md);">
                        <button type="button" id="add-segment-row" class="btn-admin btn-admin-secondary btn-admin-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            Lägg till sträcka
                        </button>
                        <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">
                            Spara sträckor
                        </button>
                    </div>
                </form>

                <details style="margin-top: var(--space-md);">
                    <summary style="cursor: pointer; color: var(--color-text-secondary); font-size: var(--text-sm);">
                        Tips för segment-definition
                    </summary>
                    <div style="margin-top: var(--space-sm); padding: var(--space-sm); background: var(--color-bg-sunken); border-radius: var(--radius-sm); font-size: var(--text-sm);">
                        <ul style="margin: 0; padding-left: var(--space-md);">
                            <li><strong>Tävling (Stage)</strong> = tidstagning, visas med grön färg</li>
                            <li><strong>Transport (Liaison)</strong> = överföring, visas med grå färg</li>
                            <li>Segmenten behöver inte täcka hela banan</li>
                            <li>Du kan ha flera segment av samma typ i rad</li>
                        </ul>
                    </div>
                </details>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($track && !empty($track['segments'])): ?>
        <!-- Segments Card -->
        <div class="admin-card" style="margin-top: var(--space-lg);">
            <div class="admin-card-header">
                <h2>Nuvarande sträckor (<?= count($track['segments']) ?>)</h2>
            </div>
            <div class="admin-card-body" style="padding: 0;">
                <div class="admin-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Typ</th>
                                <th>Namn</th>
                                <th>Distans</th>
                                <th>Hojd</th>
                                <th style="width: 100px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($track['segments'] as $segment): ?>
                            <tr>
                                <td><?= $segment['sequence_number'] ?></td>
                                <td>
                                    <span class="segment-color-preview" style="background: <?= htmlspecialchars($segment['color']) ?>"></span>
                                </td>
                                <td>
                                    <form method="POST" class="admin-inline-form" id="segment-form-<?= $segment['id'] ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_segment">
                                        <input type="hidden" name="segment_id" value="<?= $segment['id'] ?>">
                                        <select name="segment_type" class="admin-form-select admin-form-select-sm segment-type-select"
                                                onchange="document.getElementById('segment-form-<?= $segment['id'] ?>').submit()">
                                            <option value="stage" <?= $segment['segment_type'] === 'stage' ? 'selected' : '' ?>>Tavling</option>
                                            <option value="liaison" <?= $segment['segment_type'] === 'liaison' ? 'selected' : '' ?>>Transport</option>
                                        </select>
                                        <input type="text" name="segment_name" class="admin-form-input admin-form-input-sm"
                                               value="<?= htmlspecialchars($segment['segment_name'] ?? '') ?>"
                                               placeholder="SS<?= $segment['sequence_number'] ?>"
                                               style="width: 100px; margin-left: var(--space-xs);">
                                        <input type="hidden" name="timing_id" value="<?= htmlspecialchars($segment['timing_id'] ?? '') ?>">
                                    </form>
                                </td>
                                <td><?= number_format($segment['distance_km'], 1) ?> km</td>
                                <td>
                                    <?php if ($segment['segment_type'] === 'stage'): ?>
                                    +<?= $segment['elevation_gain_m'] ?>m
                                    <?php else: ?>
                                    <span class="admin-text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="submit" form="segment-form-<?= $segment['id'] ?>" class="btn-admin btn-admin-sm btn-admin-ghost">
                                        Spara
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Map Preview & POIs -->
    <div>
        <!-- Map Preview Card -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h2>Kartforhandsvisning</h2>
            </div>
            <div class="admin-card-body" style="padding: 0;">
                <div class="admin-map-instructions">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/></svg>
                    Klicka pa kartan for att placera en ny POI
                </div>
                <div id="admin-map" class="admin-map-container"></div>
            </div>
        </div>

        <!-- Add POI Card -->
        <div class="admin-card" style="margin-top: var(--space-lg);">
            <div class="admin-card-header">
                <h2>Lagg till POI</h2>
            </div>
            <div class="admin-card-body">
                <form method="POST" id="poi-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_poi">
                    <input type="hidden" name="poi_lat" id="poi_lat" value="">
                    <input type="hidden" name="poi_lng" id="poi_lng" value="">

                    <div class="admin-form-row">
                        <div class="admin-form-group">
                            <label class="admin-form-label">Typ *</label>
                            <select name="poi_type" id="poi_type" class="admin-form-select" required>
                                <option value="">Valj typ...</option>
                                <?php foreach ($poiTypes as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Etikett</label>
                            <input type="text" name="poi_label" id="poi_label" class="admin-form-input"
                                   placeholder="T.ex. Parkering Nord">
                        </div>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-form-label">Beskrivning</label>
                        <textarea name="poi_description" id="poi_description" class="admin-form-textarea"
                                  rows="2" placeholder="Valfri beskrivning..."></textarea>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-form-label">Koordinater</label>
                        <div class="admin-form-row">
                            <input type="text" id="poi_lat_display" class="admin-form-input" placeholder="Lat" readonly>
                            <input type="text" id="poi_lng_display" class="admin-form-input" placeholder="Lng" readonly>
                        </div>
                        <span class="admin-form-hint">Klicka pa kartan for att valja position</span>
                    </div>

                    <button type="submit" id="add-poi-btn" class="btn-admin btn-admin-primary" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        Lagg till POI
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($pois)): ?>
        <!-- POI List Card -->
        <div class="admin-card" style="margin-top: var(--space-lg);">
            <div class="admin-card-header">
                <h2>POIs (<?= count($pois) ?>)</h2>
            </div>
            <div class="admin-card-body" style="padding: 0;">
                <div class="admin-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Typ</th>
                                <th>Etikett</th>
                                <th>Koordinater</th>
                                <th style="width: 80px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pois as $poi): ?>
                            <tr>
                                <td>
                                    <span class="poi-type-badge" style="background: <?= htmlspecialchars($poi['type_color']) ?>">
                                        <?= $poi['type_emoji'] ?> <?= htmlspecialchars($poi['type_label']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($poi['label'] ?: '-') ?></td>
                                <td>
                                    <span class="admin-text-muted" style="font-size: var(--text-xs);">
                                        <?= round($poi['lat'], 5) ?>, <?= round($poi['lng'], 5) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_poi">
                                        <input type="hidden" name="poi_id" value="<?= $poi['id'] ?>">
                                        <button type="submit" class="btn-admin btn-admin-sm btn-admin-danger"
                                                onclick="return confirm('Ta bort denna POI?')">
                                            Ta bort
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
// Admin map initialization
(function() {
    const mapData = <?= $mapDataJson ?>;
    let map, tempMarker;

    function initMap() {
        // Default center (Sweden)
        let center = [62.0, 15.0];
        let zoom = 5;

        // Create map
        map = L.map('admin-map').setView(center, zoom);

        // Add tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        // If we have map data, add it
        if (mapData) {
            // Add segments
            const segments = mapData.geojson.features.filter(f => f.properties.type === 'segment');
            if (segments.length > 0) {
                L.geoJSON({ type: 'FeatureCollection', features: segments }, {
                    style: f => ({ color: f.properties.color, weight: 4 })
                }).addTo(map);
            }

            // Add existing POIs
            const pois = mapData.geojson.features.filter(f => f.properties.type === 'poi');
            if (pois.length > 0) {
                L.geoJSON({ type: 'FeatureCollection', features: pois }, {
                    pointToLayer: (f, ll) => {
                        const icon = L.divIcon({
                            className: 'event-map-poi-icon',
                            html: `<div class="event-map-poi-pin" style="background: ${f.properties.color}">${f.properties.emoji}</div>`,
                            iconSize: [32, 40],
                            iconAnchor: [16, 40]
                        });
                        return L.marker(ll, { icon: icon }).bindPopup(f.properties.emoji + ' ' + f.properties.label);
                    }
                }).addTo(map);
            }

            // Fit bounds
            if (mapData.bounds) {
                map.fitBounds(mapData.bounds, { padding: [30, 30] });
            }
        }

        // Click to add POI
        map.on('click', function(e) {
            const lat = e.latlng.lat.toFixed(7);
            const lng = e.latlng.lng.toFixed(7);

            // Update form fields
            document.getElementById('poi_lat').value = lat;
            document.getElementById('poi_lng').value = lng;
            document.getElementById('poi_lat_display').value = lat;
            document.getElementById('poi_lng_display').value = lng;
            document.getElementById('add-poi-btn').disabled = false;

            // Remove existing temp marker
            if (tempMarker) {
                map.removeLayer(tempMarker);
            }

            // Add temp marker
            const tempIcon = L.divIcon({
                className: 'temp-marker-icon',
                html: '<div class="temp-marker-dot"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            });

            tempMarker = L.marker(e.latlng, { icon: tempIcon }).addTo(map);
        });
    }

    // Initialize when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMap);
    } else {
        initMap();
    }
})();

// Segment definition handling
(function() {
    const container = document.getElementById('segment-definitions');
    const countInput = document.getElementById('segment-count');
    const addBtn = document.getElementById('add-segment-row');
    const totalDistance = <?= $track ? $track['total_distance_km'] : 0 ?>;

    if (!container || !addBtn) return;

    let segmentIndex = 1;

    addBtn.addEventListener('click', function() {
        segmentIndex++;
        countInput.value = segmentIndex;

        // Get the last row's end value to use as the new start
        const lastRow = container.querySelector('.segment-row:last-child');
        const lastEnd = lastRow ? parseFloat(lastRow.querySelector('[name$="_end"]').value) || 0 : 0;

        const row = document.createElement('div');
        row.className = 'segment-row';
        row.dataset.index = segmentIndex;
        row.style.cssText = 'display: grid; grid-template-columns: auto 1fr 100px 100px 40px; gap: var(--space-sm); align-items: center; margin-bottom: var(--space-sm); padding: var(--space-sm); background: var(--color-bg-hover); border-radius: var(--radius-sm);';

        row.innerHTML = `
            <select name="seg_${segmentIndex}_type" class="admin-form-select admin-form-select-sm" style="width: 100px;">
                <option value="stage">Tävling</option>
                <option value="liaison">Transport</option>
            </select>
            <input type="text" name="seg_${segmentIndex}_name" class="admin-form-input admin-form-input-sm" placeholder="Namn (t.ex. SS${segmentIndex})">
            <input type="number" name="seg_${segmentIndex}_start" class="admin-form-input admin-form-input-sm" placeholder="Start km" step="0.1" min="0" value="${lastEnd.toFixed(1)}">
            <input type="number" name="seg_${segmentIndex}_end" class="admin-form-input admin-form-input-sm" placeholder="Slut km" step="0.1" min="0" value="${totalDistance.toFixed(1)}">
            <button type="button" class="btn-admin btn-admin-ghost btn-admin-sm remove-segment" style="padding: 4px;" title="Ta bort">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        `;

        container.appendChild(row);
        updateRemoveButtons();
    });

    container.addEventListener('click', function(e) {
        if (e.target.closest('.remove-segment')) {
            const row = e.target.closest('.segment-row');
            if (row) {
                row.remove();
                updateRemoveButtons();
                // Update count
                countInput.value = container.querySelectorAll('.segment-row').length;
            }
        }
    });

    function updateRemoveButtons() {
        const rows = container.querySelectorAll('.segment-row');
        rows.forEach((row, i) => {
            const btn = row.querySelector('.remove-segment');
            if (btn) {
                btn.disabled = rows.length <= 1;
            }
        });
    }
})();
</script>

<?php
// Close the unified layout
include __DIR__ . '/components/unified-layout-footer.php';
?>
