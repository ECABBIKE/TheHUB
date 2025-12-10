<?php
/**
 * Admin Event Map Management - Multi-track support
 */
require_once __DIR__ . '/../config.php';
require_admin();
require_once INCLUDES_PATH . '/map_functions.php';

$db = getDB();
global $pdo;

// Get event ID
$eventId = intval($_GET['id'] ?? $_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    set_flash('error', 'Ogiltigt event-ID');
    header('Location: /admin/events');
    exit;
}

$event = $db->getRow("SELECT id, name, date FROM events WHERE id = ?", [$eventId]);
if (!$event) {
    set_flash('error', 'Event hittades inte');
    header('Location: /admin/events');
    exit;
}

$message = '';
$messageType = '';

// Selected track for editing
$selectedTrackId = intval($_GET['track'] ?? 0);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'upload_gpx':
                if (!isset($_FILES['gpx_file']) || $_FILES['gpx_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ingen fil uppladdad');
                }
                $file = $_FILES['gpx_file'];
                if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'gpx') {
                    throw new Exception('Endast GPX-filer');
                }
                $uploadDir = getGpxUploadPath();
                $filename = 'event_' . $eventId . '_' . time() . '.gpx';
                $filepath = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                    throw new Exception('Kunde inte spara');
                }
                $parsedData = parseGpxFile($filepath);
                $trackName = trim($_POST['track_name'] ?? '') ?: $event['name'];
                $options = [
                    'route_type' => trim($_POST['route_type'] ?? ''),
                    'route_label' => trim($_POST['route_label'] ?? '') ?: $trackName,
                    'color' => $_POST['track_color'] ?? '#3B82F6',
                    'is_primary' => !empty($_POST['is_primary'])
                ];
                $newTrackId = saveEventTrack($pdo, $eventId, $trackName, $filename, $parsedData, $options);
                $selectedTrackId = $newTrackId;
                $message = 'GPX uppladdad!';
                $messageType = 'success';
                break;

            case 'update_track':
                $trackId = intval($_POST['track_id'] ?? 0);
                if ($trackId > 0) {
                    updateTrack($pdo, $trackId, [
                        'name' => trim($_POST['track_name'] ?? ''),
                        'route_label' => trim($_POST['route_label'] ?? ''),
                        'color' => $_POST['track_color'] ?? '#3B82F6',
                        'is_primary' => !empty($_POST['is_primary'])
                    ]);
                    $message = 'Bana uppdaterad';
                    $messageType = 'success';
                }
                break;

            case 'delete_track':
                $trackId = intval($_POST['track_id'] ?? 0);
                if ($trackId > 0) {
                    deleteEventTrack($pdo, $trackId);
                    if ($selectedTrackId == $trackId) $selectedTrackId = 0;
                }
                $message = 'Bana borttagen';
                $messageType = 'success';
                break;

            case 'add_poi':
                $poiData = [
                    'poi_type' => $_POST['poi_type'] ?? '',
                    'label' => trim($_POST['poi_label'] ?? ''),
                    'lat' => floatval($_POST['poi_lat'] ?? 0),
                    'lng' => floatval($_POST['poi_lng'] ?? 0),
                ];
                if (empty($poiData['poi_type']) || $poiData['lat'] == 0) {
                    throw new Exception('Typ och koordinater krävs');
                }
                addEventPoi($pdo, $eventId, $poiData);
                $message = 'POI tillagd';
                $messageType = 'success';
                break;

            case 'delete_poi':
                $poiId = intval($_POST['poi_id'] ?? 0);
                if ($poiId > 0) deleteEventPoi($pdo, $poiId);
                $message = 'POI borttagen';
                $messageType = 'success';
                break;

            case 'delete_segment':
                $segmentId = intval($_POST['segment_id'] ?? 0);
                if ($segmentId > 0) {
                    $pdo->prepare("DELETE FROM event_track_segments WHERE id = ?")->execute([$segmentId]);
                }
                $message = 'Segment borttaget';
                $messageType = 'success';
                break;

            case 'update_segment_type':
                $segmentId = intval($_POST['segment_id'] ?? 0);
                $newType = $_POST['new_type'] ?? '';
                if ($segmentId > 0 && in_array($newType, ['stage', 'liaison', 'lift'])) {
                    $colors = ['stage' => '#EF4444', 'liaison' => '#61CE70', 'lift' => '#F59E0B'];
                    $pdo->prepare("UPDATE event_track_segments SET segment_type = ?, color = ? WHERE id = ?")
                        ->execute([$newType, $colors[$newType], $segmentId]);
                }
                $message = 'Sektionstyp uppdaterad';
                $messageType = 'success';
                break;

            case 'add_segment_visual':
                $trackId = intval($_POST['track_id'] ?? 0);
                $segmentName = trim($_POST['segment_name'] ?? '');
                $segmentType = $_POST['segment_type'] ?? 'stage';
                $startIdx = intval($_POST['start_index'] ?? -1);
                $endIdx = intval($_POST['end_index'] ?? -1);
                if ($trackId <= 0 || $startIdx < 0 || $endIdx < 0 || $endIdx <= $startIdx) {
                    throw new Exception('Ogiltig sträckdata');
                }
                addSegmentByWaypointIndex($pdo, $trackId, [
                    'name' => $segmentName ?: ($segmentType === 'stage' ? 'SS' : 'Liaison'),
                    'type' => $segmentType,
                    'start_index' => $startIdx,
                    'end_index' => $endIdx
                ]);
                $message = 'Sträcka tillagd!';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get all tracks for event
$allTracks = getEventTracks($pdo, $eventId);
$pois = getEventPois($pdo, $eventId, false) ?: [];
$poiTypes = getPoiTypesForSelect() ?: [];

// Select first track if none selected
if ($selectedTrackId <= 0 && !empty($allTracks)) {
    $selectedTrackId = $allTracks[0]['id'];
}

// Get selected track details
$currentTrack = null;
$trackWaypoints = [];
foreach ($allTracks as $t) {
    if ($t['id'] == $selectedTrackId) {
        $currentTrack = $t;
        break;
    }
}
if ($currentTrack) {
    try {
        $trackWaypoints = getTrackWaypointsForEditor($pdo, $currentTrack['id']);
    } catch (Exception $e) {
        $trackWaypoints = [];
    }
}

// Get map data for display (all tracks)
$mapData = getEventMapDataMultiTrack($pdo, $eventId);

// Track colors for selection
$trackColors = [
    '#3B82F6' => 'Blå',
    '#61CE70' => 'Grön',
    '#EF4444' => 'Röd',
    '#F59E0B' => 'Orange',
    '#8B5CF6' => 'Lila',
    '#EC4899' => 'Rosa',
    '#14B8A6' => 'Teal',
    '#6B7280' => 'Grå'
];

// Page setup
$page_title = 'Karta - ' . htmlspecialchars($event['name']);
$breadcrumbs = [
    ['label' => 'Events', 'url' => '/admin/events'],
    ['label' => htmlspecialchars($event['name']), 'url' => '/admin/events/edit/' . $eventId],
    ['label' => 'Karta']
];
$page_actions = '<a href="/admin/events/edit/' . $eventId . '" class="btn-admin btn-admin-secondary">Tillbaka</a>';

include __DIR__ . '/components/unified-layout.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'error' ? 'error' : 'success' ?>" style="margin-bottom: var(--space-md);">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 280px 1fr; gap: var(--space-md);">
    <!-- Left: Compact Controls (collapsible panels) -->
    <div style="display: flex; flex-direction: column; gap: 6px;">

        <!-- 1. GPX/Banor -->
        <details class="admin-collapse" <?= empty($allTracks) ? 'open' : '' ?>>
            <summary class="admin-collapse-header">
                <span>📍 Banor (<?= count($allTracks) ?>)</span>
            </summary>
            <div class="admin-collapse-body">
                <?php if (!empty($allTracks)): ?>
                <div style="display: flex; flex-direction: column; gap: 3px; margin-bottom: 8px;">
                    <?php foreach ($allTracks as $t): ?>
                    <div style="display: flex; align-items: center; gap: 4px; padding: 4px 6px; border: 1px solid <?= $t['id'] == $selectedTrackId ? 'var(--color-accent)' : 'var(--color-border)' ?>; border-radius: 3px; background: <?= $t['id'] == $selectedTrackId ? 'rgba(97,206,112,0.1)' : 'transparent' ?>; font-size: 0.8rem;">
                        <span style="width: 8px; height: 8px; background: <?= htmlspecialchars($t['color'] ?? '#3B82F6') ?>; border-radius: 2px;"></span>
                        <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($t['route_label'] ?? $t['name']) ?></span>
                        <span style="color: var(--color-text); font-size: 0.7rem;"><?= number_format($t['total_distance_km'], 1) ?>km</span>
                        <?php if ($t['id'] != $selectedTrackId): ?>
                        <a href="?id=<?= $eventId ?>&track=<?= $t['id'] ?>" style="font-size: 0.65rem; color: var(--color-accent);">Välj</a>
                        <?php endif; ?>
                        <form method="POST" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_track">
                            <input type="hidden" name="track_id" value="<?= $t['id'] ?>">
                            <button type="submit" style="background: none; border: none; color: var(--color-danger); cursor: pointer; padding: 0 2px; font-size: 0.7rem;" onclick="return confirm('Ta bort?')">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_gpx">
                    <input type="text" name="track_name" class="admin-form-input" placeholder="Namn" style="padding: 4px 6px; font-size: 0.75rem; margin-bottom: 4px;">
                    <div style="display: flex; gap: 4px; margin-bottom: 4px;">
                        <select name="track_color" class="admin-form-select" style="padding: 4px; font-size: 0.75rem; flex: 1;">
                            <?php foreach ($trackColors as $hex => $name): ?>
                            <option value="<?= $hex ?>"><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label style="font-size: 0.7rem; display: flex; align-items: center; gap: 2px;">
                            <input type="checkbox" name="is_primary" value="1" <?= empty($allTracks) ? 'checked' : '' ?>>
                            Primär
                        </label>
                    </div>
                    <input type="file" name="gpx_file" accept=".gpx" required style="font-size: 0.7rem; width: 100%; margin-bottom: 4px;">
                    <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm" style="width: 100%; font-size: 0.75rem; padding: 4px;">Ladda upp GPX</button>
                </form>
            </div>
        </details>

        <?php if ($currentTrack): ?>
        <!-- 2. Redigera bana -->
        <details class="admin-collapse">
            <summary class="admin-collapse-header">
                <span style="display: flex; align-items: center; gap: 4px;">
                    <span style="width: 8px; height: 8px; background: <?= htmlspecialchars($currentTrack['color'] ?? '#3B82F6') ?>; border-radius: 2px;"></span>
                    ⚙️ <?= htmlspecialchars($currentTrack['route_label'] ?? $currentTrack['name']) ?>
                </span>
            </summary>
            <div class="admin-collapse-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_track">
                    <input type="hidden" name="track_id" value="<?= $currentTrack['id'] ?>">
                    <input type="text" name="track_name" class="admin-form-input" value="<?= htmlspecialchars($currentTrack['name']) ?>" placeholder="Namn" style="padding: 4px 6px; font-size: 0.75rem; margin-bottom: 4px;">
                    <div style="display: flex; gap: 4px; margin-bottom: 4px;">
                        <select name="track_color" class="admin-form-select" style="padding: 4px; font-size: 0.75rem; flex: 1;">
                            <?php foreach ($trackColors as $hex => $name): ?>
                            <option value="<?= $hex ?>" <?= ($currentTrack['color'] ?? '#3B82F6') === $hex ? 'selected' : '' ?>><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label style="font-size: 0.7rem; display: flex; align-items: center; gap: 2px;">
                            <input type="checkbox" name="is_primary" value="1" <?= $currentTrack['is_primary'] ? 'checked' : '' ?>>
                            Primär
                        </label>
                    </div>
                    <button type="submit" class="btn-admin btn-admin-secondary btn-admin-sm" style="width: 100%; font-size: 0.75rem; padding: 4px;">Spara ändringar</button>
                </form>
            </div>
        </details>

        <!-- 3. Sektioner -->
        <details class="admin-collapse" open>
            <summary class="admin-collapse-header">
                <span>🛤️ Sektioner (<?= count($currentTrack['segments']) ?>)</span>
            </summary>
            <div class="admin-collapse-body">
                <!-- Status -->
                <div id="segment-status" style="font-size: 0.7rem; color: var(--color-text); margin-bottom: 4px; padding: 3px 6px; background: var(--color-bg-tertiary); border-radius: 3px;">
                    Klicka på banan
                </div>

                <!-- Typ-väljare -->
                <div style="display: flex; gap: 2px; margin-bottom: 4px;">
                    <button type="button" class="section-type-btn active" data-type="liaison" style="flex:1; padding: 3px; font-size: 0.65rem; background: #61CE70; color: white; border: none; border-radius: 2px; cursor: pointer;">🚴 Transp</button>
                    <button type="button" class="section-type-btn" data-type="stage" style="flex:1; padding: 3px; font-size: 0.65rem; background: #EF4444; color: white; border: none; border-radius: 2px; cursor: pointer; opacity: 0.5;">🏁 SS</button>
                    <button type="button" class="section-type-btn" data-type="lift" style="flex:1; padding: 3px; font-size: 0.65rem; background: #F59E0B; color: white; border: none; border-radius: 2px; cursor: pointer; opacity: 0.5;">🚡 Lift</button>
                </div>

                <!-- Pending segment actions -->
                <div id="pending-actions" style="display: none; margin-bottom: 4px; padding: 4px 6px; background: rgba(59, 130, 246, 0.1); border-radius: 3px;">
                    <div style="font-size: 0.7rem; margin-bottom: 4px;"><span id="pending-info">Dra markören</span></div>
                    <div style="display: flex; gap: 3px;">
                        <button type="button" onclick="savePendingSegment()" class="btn-admin btn-admin-primary btn-admin-sm" style="flex: 1; font-size: 0.65rem; padding: 3px;">✓ Spara</button>
                        <button type="button" onclick="cancelPendingSegment()" class="btn-admin btn-admin-secondary btn-admin-sm" style="flex: 1; font-size: 0.65rem; padding: 3px;">✕ Avbryt</button>
                    </div>
                </div>

                <!-- Segment-lista -->
                <div style="max-height: 150px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: 3px;">
                    <?php if (!empty($currentTrack['segments'])): ?>
                    <?php foreach ($currentTrack['segments'] as $seg):
                        $icon = $seg['segment_type'] === 'stage' ? '🏁' : ($seg['segment_type'] === 'lift' ? '🚡' : '🚴');
                    ?>
                    <div class="seg-row" style="display: flex; align-items: center; gap: 3px; padding: 3px 5px; border-bottom: 1px solid var(--color-border); font-size: 0.75rem;">
                        <span style="width: 6px; height: 6px; background: <?= htmlspecialchars($seg['color']) ?>; border-radius: 1px;"></span>
                        <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?= $icon ?> <?= htmlspecialchars($seg['segment_name'] ?: '#' . $seg['sequence_number']) ?>
                        </span>
                        <span style="color: var(--color-text); font-size: 0.65rem;"><?= number_format($seg['distance_km'], 1) ?>km</span>
                        <select onchange="changeSegmentType(<?= $seg['id'] ?>, this.value)" style="padding: 1px 2px; font-size: 0.6rem; border: 1px solid var(--color-border); border-radius: 2px;">
                            <option value="liaison" <?= $seg['segment_type'] === 'liaison' ? 'selected' : '' ?>>T</option>
                            <option value="stage" <?= $seg['segment_type'] === 'stage' ? 'selected' : '' ?>>SS</option>
                            <option value="lift" <?= $seg['segment_type'] === 'lift' ? 'selected' : '' ?>>L</option>
                        </select>
                        <form method="POST" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_segment">
                            <input type="hidden" name="segment_id" value="<?= $seg['id'] ?>">
                            <button type="submit" style="background: none; border: none; color: var(--color-danger); cursor: pointer; padding: 0 2px; font-size: 0.65rem;" onclick="return confirm('Ta bort?')">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <p style="padding: 6px; margin: 0; color: var(--color-text); font-size: 0.7rem;">Klicka på banan för att markera</p>
                    <?php endif; ?>
                </div>

                <form method="POST" id="segment-form" style="display: none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_segment_visual">
                    <input type="hidden" name="track_id" value="<?= $currentTrack['id'] ?>">
                    <input type="hidden" name="start_index" id="start-index" value="">
                    <input type="hidden" name="end_index" id="end-index" value="">
                    <input type="hidden" name="segment_type" id="segment-type" value="liaison">
                    <input type="hidden" name="segment_name" id="segment-name-hidden" value="">
                </form>
                <form method="POST" id="update-type-form" style="display: none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_segment_type">
                    <input type="hidden" name="segment_id" id="update-seg-id" value="">
                    <input type="hidden" name="new_type" id="update-new-type" value="">
                </form>
            </div>
        </details>
        <?php endif; ?>

        <!-- 4. POIs -->
        <details class="admin-collapse">
            <summary class="admin-collapse-header">
                <span>📍 POIs (<?= count($pois) ?>)</span>
            </summary>
            <div class="admin-collapse-body">
                <?php if (!empty($pois)): ?>
                <div style="display: flex; flex-direction: column; gap: 2px; margin-bottom: 6px;">
                    <?php foreach ($pois as $poi): ?>
                    <div style="display: flex; align-items: center; gap: 3px; padding: 3px 5px; background: var(--color-bg-secondary); border-radius: 2px; font-size: 0.7rem;">
                        <span><?= $poi['type_emoji'] ?? '📍' ?></span>
                        <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($poi['label'] ?: $poi['type_label'] ?? $poi['poi_type']) ?></span>
                        <form method="POST" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_poi">
                            <input type="hidden" name="poi_id" value="<?= $poi['id'] ?>">
                            <button type="submit" style="background: none; border: none; color: var(--color-danger); cursor: pointer; padding: 0 2px; font-size: 0.65rem;" onclick="return confirm('Ta bort?')">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="POST" id="poi-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_poi">
                    <input type="hidden" name="poi_lat" id="poi-lat">
                    <input type="hidden" name="poi_lng" id="poi-lng">
                    <div style="display: flex; gap: 4px; margin-bottom: 4px;">
                        <select name="poi_type" class="admin-form-select" required style="padding: 4px; font-size: 0.7rem; flex: 1;">
                            <option value="">Typ...</option>
                            <?php foreach ($poiTypes as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="poi_label" class="admin-form-input" placeholder="Etikett" style="padding: 4px 6px; font-size: 0.7rem; width: 80px;">
                    </div>
                    <p style="font-size: 0.65rem; color: var(--color-text); margin-bottom: 4px;">Klicka på kartan</p>
                    <button type="submit" id="poi-btn" disabled class="btn-admin btn-admin-primary btn-admin-sm" style="width: 100%; font-size: 0.7rem; padding: 3px;">Lägg till</button>
                </form>
            </div>
        </details>

    </div>

    <!-- Right: Map -->
    <div>
        <div style="background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); overflow: hidden;">
            <div id="map" style="height: calc(100vh - 180px); min-height: 400px;"></div>
        </div>
    </div>
</div>

<!-- Collapse styles -->
<style>
.admin-collapse {
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
.admin-collapse-header {
    padding: 8px 10px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    background: var(--color-bg-secondary);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.admin-collapse-header::after {
    content: '▸';
    font-size: 0.7rem;
    transition: transform 0.2s;
}
.admin-collapse[open] .admin-collapse-header::after {
    transform: rotate(90deg);
}
.admin-collapse-body {
    padding: 8px 10px;
    border-top: 1px solid var(--color-border);
}
</style>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
const mapData = <?= json_encode($mapData) ?>;
const waypoints = <?= json_encode($trackWaypoints) ?>;
const currentTrackId = <?= $selectedTrackId ?: 'null' ?>;
let map, startMarker, endMarker, previewLine, tempMarker, baseTrackLine;
let mode = 'start';
let startIdx = -1, endIdx = -1;

// Segment colors
const SEGMENT_COLORS = {
    stage: '#EF4444',    // Red
    liaison: '#61CE70',  // Green
    lift: '#F59E0B'      // Orange/Yellow
};

function init() {
    map = L.map('map').setView([62, 15], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    if (mapData && mapData.tracks) {
        // Draw all tracks
        mapData.tracks.forEach(track => {
            if (track.geojson && track.geojson.features) {
                // First draw base track (gray), then segments on top
                track.geojson.features.forEach(feature => {
                    const props = feature.properties;
                    const isBase = props.type === 'base_track';
                    const isCurrent = track.id === currentTrackId;

                    L.geoJSON(feature, {
                        style: () => ({
                            color: props.color || track.color,
                            weight: isBase ? 4 : 6,
                            opacity: isCurrent ? (isBase ? 0.6 : 1) : 0.4
                        })
                    }).addTo(map);
                });
            }
        });

        // Draw POIs
        if (mapData.pois) {
            mapData.pois.forEach(p => {
                L.marker([p.lat, p.lng])
                    .bindPopup((p.type_emoji || '') + ' ' + (p.label || p.type_label || p.poi_type))
                    .addTo(map);
            });
        }

        if (mapData.bounds) map.fitBounds(mapData.bounds, {padding: [30, 30]});
    }

    // Draw invisible clickable track for segment selection (wide hit area)
    if (waypoints && waypoints.length) {
        const coords = waypoints.map(w => [w.lat, w.lng]);
        baseTrackLine = L.polyline(coords, {
            color: 'transparent',
            weight: 20,  // Wide for easy clicking
            opacity: 0,
            className: 'clickable-track'
        }).addTo(map);
        baseTrackLine.on('click', onTrackClick);
    }

    map.on('click', onMapClick);

    // Setup section type buttons
    document.querySelectorAll('.section-type-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.section-type-btn').forEach(b => {
                b.classList.remove('active');
                b.style.opacity = '0.6';
            });
            btn.classList.add('active');
            btn.style.opacity = '1';
            currentSegmentType = btn.dataset.type;
            document.getElementById('segment-type').value = currentSegmentType;
        });
    });
}

// Section-based state
let sectionMarkers = [];
let sectionLines = [];
let currentSegmentType = 'liaison';

// Pending segment state (before saving)
let pendingMarker = null;
let pendingLine = null;
let pendingStartIdx = -1;
let pendingEndIdx = -1;
let pendingType = 'liaison';

// Find where the last existing segment ends (to continue from there)
let lastSectionIndex = 0;
// Count existing segments by type for auto-naming
let existingSegmentCounts = { stage: 0, liaison: 0, lift: 0 };
<?php
if ($currentTrack && !empty($currentTrack['segments'])) {
    $lastSegment = end($currentTrack['segments']);
    $lastEndIndex = $lastSegment['end_index'] ?? 0;
    echo "lastSectionIndex = " . intval($lastEndIndex) . ";\n";

    // Count existing segments by type
    $typeCounts = ['stage' => 0, 'liaison' => 0, 'lift' => 0];
    foreach ($currentTrack['segments'] as $seg) {
        $type = $seg['segment_type'] ?? 'liaison';
        if (isset($typeCounts[$type])) {
            $typeCounts[$type]++;
        }
    }
    echo "existingSegmentCounts = " . json_encode($typeCounts) . ";\n";
}
?>

function onTrackClick(e) {
    L.DomEvent.stopPropagation(e);
    const nearest = findNearest(e.latlng);
    if (!nearest) return;

    // Don't allow clicking before the last section point
    if (nearest.index <= lastSectionIndex && lastSectionIndex > 0) {
        document.getElementById('segment-status').innerHTML =
            '<span style="color: var(--color-danger);">Måste klicka längre fram på banan!</span>';
        return;
    }

    // If there's already a pending segment, save it first or cancel
    if (pendingMarker) {
        // Update the pending marker position instead
        updatePendingMarker(nearest);
        return;
    }

    // Create pending segment from lastSectionIndex to this point
    pendingStartIdx = lastSectionIndex;
    pendingEndIdx = nearest.index;
    pendingType = currentSegmentType;

    // Add draggable marker
    pendingMarker = L.marker([nearest.wp.lat, nearest.wp.lng], {
        draggable: true,
        icon: L.divIcon({
            className: 'segment-marker',
            html: `<div style="width: 20px; height: 20px; background: ${SEGMENT_COLORS[pendingType]}; border: 3px solid white; border-radius: 50%; box-shadow: 0 2px 6px rgba(0,0,0,0.4); cursor: grab;"></div>`,
            iconSize: [20, 20],
            iconAnchor: [10, 10]
        })
    }).addTo(map);

    // Handle marker drag
    pendingMarker.on('drag', onMarkerDrag);
    pendingMarker.on('dragend', onMarkerDragEnd);

    // Draw pending segment line
    drawPendingLine();

    // Show pending actions UI
    updatePendingUI();
}

function updatePendingMarker(nearest) {
    pendingEndIdx = nearest.index;
    pendingMarker.setLatLng([nearest.wp.lat, nearest.wp.lng]);
    drawPendingLine();
    updatePendingUI();
}

function onMarkerDrag(e) {
    // Find nearest waypoint while dragging
    const nearest = findNearest(e.latlng);
    if (nearest && nearest.index > pendingStartIdx) {
        pendingEndIdx = nearest.index;
        drawPendingLine();
        updatePendingUI();
    }
}

function onMarkerDragEnd(e) {
    // Snap to nearest waypoint
    const nearest = findNearest(e.target.getLatLng());
    if (nearest && nearest.index > pendingStartIdx) {
        pendingEndIdx = nearest.index;
        pendingMarker.setLatLng([nearest.wp.lat, nearest.wp.lng]);
        drawPendingLine();
        updatePendingUI();
    } else {
        // Reset to previous valid position
        const wp = waypoints[pendingEndIdx];
        pendingMarker.setLatLng([wp.lat, wp.lng]);
    }
}

function drawPendingLine() {
    // Remove old pending line
    if (pendingLine) {
        map.removeLayer(pendingLine);
    }

    // Draw new pending line
    const coords = waypoints.slice(pendingStartIdx, pendingEndIdx + 1).map(w => [w.lat, w.lng]);
    pendingLine = L.polyline(coords, {
        color: SEGMENT_COLORS[pendingType],
        weight: 6,
        opacity: 0.7,
        dashArray: '10, 10' // Dashed to show it's pending
    }).addTo(map);
}

function updatePendingUI() {
    const dist = (waypoints[pendingEndIdx].distance_km - waypoints[pendingStartIdx].distance_km).toFixed(2);
    const typeLabel = pendingType === 'stage' ? 'SS' : (pendingType === 'lift' ? 'Lift' : 'Transport');

    document.getElementById('pending-actions').style.display = 'block';
    document.getElementById('pending-info').innerHTML =
        `<strong>${typeLabel}</strong>: ${dist} km — Dra markören för att justera`;
    document.getElementById('segment-status').innerHTML =
        `<span style="color: ${SEGMENT_COLORS[pendingType]};">Förhandsvisning</span>`;
}

function savePendingSegment() {
    if (!pendingMarker) return;

    // Auto-generate name (count existing from DB + any added this session)
    let autoName = '';
    const dbCount = existingSegmentCounts[pendingType] || 0;
    const sessionCount = sectionMarkers.filter(m => m.type === pendingType).length;
    const totalOfType = dbCount + sessionCount;

    if (pendingType === 'stage') {
        autoName = 'SS' + (totalOfType + 1);
    } else if (pendingType === 'lift') {
        autoName = 'Lift ' + (totalOfType + 1);
    } else {
        autoName = 'Transport';
    }

    // Save segment to server via form
    document.getElementById('start-index').value = pendingStartIdx;
    document.getElementById('end-index').value = pendingEndIdx;
    document.getElementById('segment-type').value = pendingType;
    document.getElementById('segment-name-hidden').value = autoName;
    document.getElementById('segment-form').submit();
}

function cancelPendingSegment() {
    // Remove pending marker and line
    if (pendingMarker) {
        map.removeLayer(pendingMarker);
        pendingMarker = null;
    }
    if (pendingLine) {
        map.removeLayer(pendingLine);
        pendingLine = null;
    }

    // Reset state
    pendingStartIdx = -1;
    pendingEndIdx = -1;

    // Hide pending actions UI
    document.getElementById('pending-actions').style.display = 'none';
    document.getElementById('segment-status').innerHTML = 'Klicka på banan';
}

function onMapClick(e) {
    document.getElementById('poi-lat').value = e.latlng.lat;
    document.getElementById('poi-lng').value = e.latlng.lng;
    document.getElementById('poi-btn').disabled = false;
    if (tempMarker) map.removeLayer(tempMarker);
    tempMarker = L.circleMarker(e.latlng, {radius: 6, color: '#3B82F6', fillOpacity: 1}).addTo(map);
}

function findNearest(latlng) {
    if (!waypoints || !waypoints.length) return null;
    let best = {dist: Infinity};
    waypoints.forEach((wp, i) => {
        const d = latlng.distanceTo(L.latLng(wp.lat, wp.lng));
        if (d < best.dist) best = {index: i, wp: wp, dist: d};
    });
    return best.dist < 200 ? best : null;
}

function undoLastSection() {
    // This would need server-side support to delete the last segment
    // For now, just reload the page
    if (confirm('Vill du ta bort senaste sektionen? (Sidan laddas om)')) {
        const lastSegment = document.querySelector('[data-segment-id]:last-child form');
        if (lastSegment) {
            lastSegment.submit();
        } else {
            location.reload();
        }
    }
}

function resetAllSections() {
    if (confirm('Vill du ta bort ALLA sektioner och börja om?')) {
        location.reload();
    }
}

function changeSegmentType(segId, newType) {
    document.getElementById('update-seg-id').value = segId;
    document.getElementById('update-new-type').value = newType;
    document.getElementById('update-type-form').submit();
}

document.addEventListener('DOMContentLoaded', init);
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>
